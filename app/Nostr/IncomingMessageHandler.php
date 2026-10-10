<?php

namespace App\Nostr;

use App\Conversation;
use App\Customer;
use App\Misc\ChatConversations;
use App\Thread;
use Illuminate\Support\Carbon;
use App\Nostr\CustomerKey;
use App\Nostr\NostrEvent;
use App\Nostr\NostrMailbox;

/**
 * Turns a received gift wrap into a customer message in a conversation.
 */
class IncomingMessageHandler
{
    // Rumors dated further in the future than this are clamped to now.
    const MAX_FUTURE_SKEW = 900;

    // Legacy NIP-04 direct message. Not supported: recorded and reported, never decrypted.
    const KIND_LEGACY_DM = 4;

    /**
     * Logs what happens (optional).
     *
     * @var callable|null
     */
    protected $logger;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Turn a received gift wrap into a thread.
     *
     * @param NostrMailbox $cfg      mailbox the wrap is addressed to
     * @param array        $wrap     kind 1059 event as received from the relay
     * @param string|null  $relayUrl relay the wrap arrived on
     *
     * @return Thread|null the created thread, or null when nothing was created
     */
    public function handleGiftWrap(NostrMailbox $cfg, array $wrap, $relayUrl = null)
    {
        $wrapId = $wrap['id'] ?? '';
        if (!EventBuilder::isHex($wrapId, 64)) {
            return null;
        }

        // Which of the mailbox's keys (current or retired) is this for?
        $targets = array_values(array_intersect(array_map('strtolower', EventBuilder::tagValues($wrap, 'p')), $cfg->getAllPubkeys()));
        $mailboxPubkey = $targets[0] ?? null;

        // Reserve the wrap first: relays deliver the same wrap more than once, and two
        // listener processes may see it at the same time. Only one claim succeeds.
        $claim = NostrEvent::claim($cfg->mailbox_id, $wrapId, (string) ($wrap['pubkey'] ?? ''), GiftWrap::KIND_WRAP, $relayUrl, $mailboxPubkey);
        if (!$claim) {
            return null;
        }

        if (!$mailboxPubkey) {
            return $this->finish($claim, NostrEvent::STATUS_FAILED, 'not addressed to mailbox');
        }
        $priv = $cfg->getPrivateKeyFor($mailboxPubkey);
        if (!$priv) {
            return $this->finish($claim, NostrEvent::STATUS_FAILED, 'no key for recipient');
        }

        try {
            $unwrapped = GiftWrap::unwrap($wrap, $priv, $mailboxPubkey);
        } catch (\Throwable $e) {
            $this->log('wrap '.substr($wrapId, 0, 8).' rejected: '.$e->getMessage());

            return $this->finish($claim, NostrEvent::STATUS_FAILED, $e->getMessage());
        }

        $rumor = $unwrapped['rumor'];
        $pubkey = $rumor['pubkey'];
        $kind = $rumor['kind'];
        $claim->pubkey = $pubkey;
        $claim->kind = $kind;

        // The same message may come in another wrap (one per relay from some clients).
        if (!$claim->claimRumor($rumor['id'])) {
            return $this->finish($claim, NostrEvent::STATUS_OK, 'duplicate');
        }
        if ($cfg->hasPubkey($pubkey)) {
            return $this->finish($claim, NostrEvent::STATUS_OK, 'own message');
        }
        if ($kind !== GiftWrap::KIND_DM) {
            $this->log('unsupported kind '.$kind.' from '.Keys::shortNpub($pubkey));

            return $this->finish($claim, NostrEvent::STATUS_FAILED, 'unsupported kind');
        }
        if (!in_array($mailboxPubkey, array_map('strtolower', EventBuilder::tagValues($rumor, 'p')))) {
            return $this->finish($claim, NostrEvent::STATUS_FAILED, 'not addressed to mailbox');
        }

        $createdAt = min((int) $rumor['created_at'] ?: time(), time() + self::MAX_FUTURE_SKEW);

        list($customer, $customerIsNew) = $this->findOrCreateCustomer($pubkey);

        // Modules may read inline files from the message's tags (an app's own formats).
        $message = \Eventy::filter('nostr.incoming_message', ['text' => trim($rumor['content']), 'attachments' => []], $rumor, $cfg);
        $text = (string) ($message['text'] ?? '');
        $attachments = (array) ($message['attachments'] ?? []);
        $body = $this->textToHtml($text);
        if ($body === '') {
            $body = '<i>'.__('(empty message)').'</i>';
        }

        $subject = trim((string) EventBuilder::firstTag($rumor, 'subject'));
        if ($subject === '') {
            $subject = Conversation::subjectFromText($text);
        }
        if ($subject === '') {
            $subject = __('Nostr message');
        }

        $conversation = $this->findOpenConversation($cfg, $customer);
        $new = !$conversation;
        $result = ChatConversations::receive($conversation, $customer, [
            'subject' => $subject,
            'mailbox_id' => $cfg->mailbox_id,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'channel' => config('nostr.channel'),
            'status' => Conversation::STATUS_ACTIVE,
        ], [
            'body' => $body,
            'attachments' => $attachments,
        ]);
        $conversation = $result['conversation'] ?? null;
        $thread = $result['thread'] ?? null;

        if (!$thread || !$conversation) {
            $this->log('could not create a thread for message from '.Keys::shortNpub($pubkey));

            return $this->finish($claim, NostrEvent::STATUS_FAILED, 'could not create thread');
        }

        $claim->conversation_id = $conversation->id;
        $claim->thread_id = $thread->id;
        $claim->event_created_at = Carbon::createFromTimestamp($createdAt);
        $this->finish($claim, NostrEvent::STATUS_OK, null);

        // Shown under "Show original" » headers.
        try {
            $thread->headers = self::formatHeaders([
                'Nostr-Protocol' => 'NIP-17 (gift wrap kind 1059, seal kind 13, message kind '.$kind.')',
                'Nostr-Sender' => Keys::npub($pubkey).' ('.$pubkey.')',
                'Nostr-Recipient' => Keys::npub($mailboxPubkey).' ('.$mailboxPubkey.', '.($mailboxPubkey === $cfg->pubkey ? 'current key' : 'retired key').')',
                'Nostr-Relay' => $relayUrl ?: 'unknown',
                'Nostr-Received' => now()->toIso8601String(),
                'Nostr-Sent' => Carbon::createFromTimestamp((int) $rumor['created_at'])->toIso8601String().' (as stated by the sender)',
                'Nostr-Rumor-Id' => $rumor['id'],
                'Nostr-Seal-Id' => $unwrapped['seal']['id'] ?? '',
                'Nostr-Wrap-Id' => $wrapId,
                'Nostr-Wrap-Created' => Carbon::createFromTimestamp((int) ($wrap['created_at'] ?? 0))->toIso8601String().' (randomized by the sender)',
                'Nostr-Subject-Tag' => EventBuilder::firstTag($rumor, 'subject'),
                'Nostr-Parent-Id' => EventBuilder::firstTag($rumor, 'e'),
                'Nostr-Tags' => json_encode(self::headerTags($rumor['tags'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            $thread->save();
        } catch (\Throwable $e) {
            $this->log('could not store headers: '.$e->getMessage());
        }

        $key = CustomerKey::byPubkey($pubkey);
        if ($key) {
            $key->last_seen_at = now();
            $key->save();
        }
        $cfg->last_event_at = now();
        $cfg->save();

        if ($new && $cfg->auto_reply_enabled && trim((string) $cfg->auto_reply_text) !== '') {
            \App\Jobs\NostrTask::dispatch('auto_reply', [$conversation->id, $cfg->id, $pubkey]);
        }
        if ($customerIsNew) {
            \App\Jobs\NostrTask::dispatch('fetch_profile', [$customer->id, $pubkey, $cfg->id]);
        }

        $this->log(sprintf('message from %s -> conversation #%s (%s)',
        Keys::shortNpub($pubkey), $conversation->number, $new ? 'new' : 'reopened'));

        return $thread;
    }

    /**
     * Pseudo email headers describing a Nostr message. Empty values are skipped.
     */
    /**
     * A message's tags as shown under "Show original": modules may leave out
     * what is too large to show (files in tags).
     */
    public static function headerTags(array $tags)
    {
        return \Eventy::filter('nostr.header_tags', $tags);
    }

    public static function formatHeaders(array $headers)
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $lines[] = $name.': '.str_replace(["\r", "\n"], ' ', $value);
        }

        return implode("\n", $lines);
    }

    /**
     * Final state of a claimed event. Returns null so callers can "return $this->finish(...)".
     */
    protected function finish(NostrEvent $event, $status, $error)
    {
        try {
            $event->status = $status;
            $event->error = $error ? mb_substr($error, 0, 1000) : null;
            $event->save();
        } catch (\Throwable $e) {
            $this->log('could not record event: '.$e->getMessage());
        }

        return null;
    }

    /**
     * A legacy NIP-04 message (kind 4) addressed to one of the mailbox keys: recorded once so the
     * settings page can show that someone writes with an unsupported protocol.
     *
     * @return bool true when it was new and recorded
     */
    public function handleLegacyMessage(NostrMailbox $cfg, array $event, $relayUrl = null)
    {
        $id = $event['id'] ?? '';
        if (!EventBuilder::isHex($id, 64) || NostrEvent::seenWrap($id)) {
            return false;
        }
        if (!EventBuilder::verify($event)) {
            return false;
        }
        $targets = array_values(array_intersect(array_map('strtolower', EventBuilder::tagValues($event, 'p')), $cfg->getAllPubkeys()));
        if (!$targets || $cfg->hasPubkey($event['pubkey'])) {
            return false;
        }

        $claim = NostrEvent::claim($cfg->mailbox_id, $id, $event['pubkey'], self::KIND_LEGACY_DM, $relayUrl, $targets[0]);
        if (!$claim) {
            return false;
        }
        $this->log('legacy NIP-04 message from '.Keys::shortNpub($event['pubkey']).' ignored (unsupported protocol)');
        $claim->event_created_at = !empty($event['created_at']) ? Carbon::createFromTimestamp((int) $event['created_at']) : null;
        $this->finish($claim, NostrEvent::STATUS_FAILED, 'NIP-04 not supported');

        return true;
    }

    /**
     * The customer with this key, or a new one.
     *
     * @return array [Customer, bool created]
     */
    public function findOrCreateCustomer($pubkey)
    {
        $key = CustomerKey::byPubkey($pubkey);
        if ($key && $key->customer) {
            return [$key->customer, false];
        }

        $customer = Customer::getCustomerByChannel(config('nostr.channel'), $pubkey);
        $created = false;
        if (!$customer) {
            $customer = Customer::createWithoutEmail(['first_name' => Keys::shortNpub($pubkey)]);
            $created = true;
        }
        CustomerKey::link($customer, $pubkey, CustomerKey::SOURCE_AUTO);

        return [$customer, $created];
    }

    /**
     * Latest Nostr conversation of this customer in this mailbox, if it is recent enough to reopen.
     */
    public function findOpenConversation(NostrMailbox $cfg, Customer $customer)
    {
        return ChatConversations::latest($cfg->mailbox()->first(), $customer, config('nostr.channel'));
    }

    /**
     * Plain text from a Nostr client to the HTML FreeScout stores.
     */
    public function textToHtml($text)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $html = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        try {
            $html = \Helper::linkify($html);
        } catch (\Throwable $e) {
            // Keep the escaped text.
        }

        return nl2br($html);
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
