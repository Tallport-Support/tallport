<?php

namespace App\Nostr;

use App\Conversation;
use App\Misc\ChatDelivery;
use App\SendLog;
use App\Thread;
use App\Nostr\CustomerKey;
use App\Nostr\NostrEvent;
use App\Nostr\NostrMailbox;

/**
 * Sends agent replies and auto replies as NIP-17 private messages.
 */
class OutgoingMessageSender
{
    // Line item shown in the conversation when the auto reply went out.
    const ACTION_TYPE_AUTO_REPLY = Thread::ACTION_TYPE_NOSTR_AUTO_REPLY;

    /**
     * Logs what happens (optional).
     *
     * @var callable|null
     */
    protected $logger;

    /**
     * Whether this try is in nostr_events already (sendText() saved it).
     */
    protected $recorded = false;

    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Send a reply (the first of the replies) if it hasn't been yet.
     *
     * @param Conversation $conversation
     * @param \Illuminate\Support\Collection $replies threads, the one to send first
     *
     * @return bool|null true when delivered, false when failed, null when not applicable
     */
    public function handleSendReply($conversation, $replies)
    {
        if (!$conversation || (int) $conversation->channel !== (int) config('nostr.channel')) {
            return null;
        }
        $thread = $replies ? $replies->first() : null;
        if (!$thread || $thread->type != Thread::TYPE_MESSAGE) {
            return null;
        }
        if (NostrEvent::sentForThread($thread->id)) {
            return true;
        }

        return $this->sendThread($conversation, $thread);
    }

    /**
     * Send one agent reply to the customer key that wrote last.
     */
    public function sendThread(Conversation $conversation, Thread $thread)
    {
        $this->recorded = false;
        $cfg = NostrMailbox::forMailbox($conversation->mailbox_id, false);
        if (!$cfg || !$cfg->pubkey || !$cfg->getPrivateKey()) {
            $this->fail($thread, __('Nostr is not set up for this mailbox'));

            return false;
        }

        $last = NostrEvent::lastIncoming($conversation->id);
        $pubkey = $last->pubkey ?? null;
        if (!$pubkey) {
            $first = CustomerKey::forCustomer($conversation->customer_id)->first();
            $pubkey = $first->pubkey ?? null;
        }
        if (!$pubkey) {
            $this->fail($thread, __('The customer has no Nostr public key'));

            return false;
        }

        try {
            // Files go in the encrypted message's tags, in a format a module
            // provides (an app's own); never as links.
            $attachments = \Eventy::filter('nostr.reply_attachment_tags', false, $thread);
            if ($attachments === false) {
                if (count($thread->all_attachments) || preg_match('#<img\b#i', (string) $thread->body)) {
                    throw new \InvalidArgumentException(__('Files can\'t be sent over Nostr. Nothing was sent.'));
                }
                $attachments = [];
            }
            $text = $this->threadToText($thread);
        } catch (\Throwable $error) {
            $this->fail($thread, $error->getMessage(), $pubkey);

            return false;
        }
        if ($text === '' && !$attachments) {
            $this->fail($thread, __('Empty message'), $pubkey);

            return false;
        }

        $options = [
            'subject' => $conversation->subject,
            'reply_to' => ($last && $last->rumor_id) ? [$last->rumor_id, $last->relay] : null,
            'extra_relays' => ($last && $last->relay) ? [$last->relay] : [],
            // Answer from the key the customer wrote to, even if it has been retired since.
            'from_pubkey' => $last->mailbox_pubkey ?? null,
            'conversation_id' => $conversation->id,
            'thread_id' => $thread->id,
            'attachments' => $attachments,
        ];
        try {
            $result = $this->sendText($cfg, $pubkey, $text, $options);
        } catch (\Throwable $error) {
            $this->fail($thread, $error->getMessage(), $pubkey);

            return false;
        }

        $thread->headers = $this->headersFor($result, $cfg, $pubkey, $last);
        if ($result['ok']) {
            ChatDelivery::recordStatus($thread, SendLog::STATUS_ACCEPTED);
        } else {
            $this->fail($thread, __('Could not deliver the message to any relay').': '.$this->summary($result['results']));
        }

        return $result['ok'];
    }

    /**
     * Pseudo email headers for a sent message ("Show original" » headers).
     */
    protected function headersFor(array $result, NostrMailbox $cfg, $pubkey, $last = null)
    {
        $relays = [];
        foreach ($result['results'] as $url => $r) {
            $relays[] = $url.' ('.(!empty($r['ok']) ? 'accepted' : 'failed: '.($r['message'] ?? '')).')';
        }
        $from = $result['event']->mailbox_pubkey ?? $cfg->pubkey;

        return IncomingMessageHandler::formatHeaders([
            'Nostr-Protocol' => 'NIP-17 (gift wrap kind 1059, seal kind 13, message kind 14)',
            'Nostr-Sender' => Keys::npub($from).' ('.$from.', '.($from === $cfg->pubkey ? 'current key' : 'retired key').')',
            'Nostr-Recipient' => Keys::npub($pubkey).' ('.$pubkey.')',
            'Nostr-Relays' => implode(', ', $relays) ?: 'none',
            'Nostr-Sent' => now()->toIso8601String(),
            'Nostr-Rumor-Id' => $result['rumor']['id'] ?? '',
            'Nostr-Wrap-Id' => $result['wrap']['id'] ?? '',
            'Nostr-Parent-Id' => $last->rumor_id ?? '',
            'Nostr-Tags' => isset($result['rumor']['tags']) ? json_encode(IncomingMessageHandler::headerTags($result['rumor']['tags']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
        ]);
    }

    /**
     * Wrap and publish a text message.
     *
     * @return array ['ok' => bool, 'results' => [relay => result], 'relays' => [...], 'event' => NostrEvent]
     */
    public function sendText(NostrMailbox $cfg, $pubkey, $text, array $options = [])
    {
        $pubkey = strtolower($pubkey);

        $fromPubkey = strtolower((string) ($options['from_pubkey'] ?? ''));
        $priv = $fromPubkey !== '' ? $cfg->getPrivateKeyFor($fromPubkey) : null;
        if (!$priv) {
            $fromPubkey = $cfg->pubkey;
            $priv = $cfg->getPrivateKey();
        }

        $tags = [['p', $pubkey]];
        if (!empty($options['subject'])) {
            $tags[] = ['subject', mb_substr((string) $options['subject'], 0, 200)];
        }
        if (!empty($options['reply_to'][0])) {
            $tags[] = ['e', $options['reply_to'][0], (string) ($options['reply_to'][1] ?? ''), 'reply'];
        }
        // Modules may add tags (options: conversation_id, thread_id; none for
        // automated messages).
        $tags = \Eventy::filter('nostr.rumor_tags', $tags, $options);
        $tags = array_merge($tags, $options['attachments'] ?? []);

        list($wrap, $rumor) = GiftWrap::wrap([
            'kind' => GiftWrap::KIND_DM,
            'content' => $text,
            'tags' => $tags,
            'created_at' => time(),
        ], $priv, $pubkey);

        $relays = $this->targetRelays($cfg, $pubkey, $options['extra_relays'] ?? []);
        $client = new RelayClient(RelayClient::authSignerForKey($priv), $this->logger);
        $results = $relays ? $client->publish($wrap, $relays) : [];
        $ok = RelayClient::anySucceeded($results);

        // A retry sends the same message (rumor) again: its row is updated.
        $event = NostrEvent::where('rumor_id', $rumor['id'])->where('direction', NostrEvent::DIRECTION_OUT)->first() ?: new NostrEvent();
        $event->mailbox_id = $cfg->mailbox_id;
        $event->mailbox_pubkey = $fromPubkey;
        $event->direction = NostrEvent::DIRECTION_OUT;
        $event->wrap_id = $wrap['id'];
        $event->rumor_id = $rumor['id'];
        $event->pubkey = $pubkey;
        $event->kind = GiftWrap::KIND_DM;
        $event->conversation_id = $options['conversation_id'] ?? null;
        $event->thread_id = $options['thread_id'] ?? null;
        $event->relays = json_encode($results);
        $event->status = $ok ? NostrEvent::STATUS_OK : NostrEvent::STATUS_FAILED;
        $event->error = $ok ? null : ($relays ? mb_substr($this->summary($results), 0, 1000) : 'no relays');
        $event->event_created_at = now();
        $event->save();
        $this->recorded = true;

        $this->log(sprintf('reply to %s: %s', Keys::shortNpub($pubkey), $ok ? 'delivered' : 'failed'));

        return ['ok' => $ok, 'results' => $results, 'relays' => $relays, 'event' => $event, 'wrap' => $wrap, 'rumor' => $rumor];
    }

    /**
     * Background action "nostr.auto_reply": one-time greeting on a new conversation.
     */
    public function sendAutoReply($conversationId, $cfgId, $pubkey)
    {
        $conversation = Conversation::find($conversationId);
        $cfg = NostrMailbox::find($cfgId);
        $text = $cfg ? trim((string) $cfg->auto_reply_text) : '';
        if (!$conversation || !$cfg || !$cfg->auto_reply_enabled || $text === '' || !$cfg->getPrivateKey()) {
            return false;
        }

        $last = NostrEvent::lastIncoming($conversation->id);
        $result = $this->sendText($cfg, $pubkey, $text, [
            'subject' => $conversation->subject,
            'reply_to' => ($last && $last->rumor_id) ? [$last->rumor_id, $last->relay] : null,
            'extra_relays' => ($last && $last->relay) ? [$last->relay] : [],
            'from_pubkey' => $last->mailbox_pubkey ?? null,
            'conversation_id' => $conversation->id,
        ]);

        if ($result['ok']) {
            $thread = new Thread();
            $thread->conversation_id = $conversation->id;
            $thread->type = Thread::TYPE_LINEITEM;
            $thread->action_type = self::ACTION_TYPE_AUTO_REPLY;
            $thread->body = $text;
            $thread->status = $conversation->status;
            $thread->state = Thread::STATE_PUBLISHED;
            $thread->source_via = Thread::PERSON_USER;
            $thread->source_type = Thread::SOURCE_TYPE_API;
            // No person: the line item reads "System sent the Nostr auto reply".
            $thread->customer_id = null;
            $thread->created_by_user_id = null;
            $thread->headers = $this->headersFor($result, $cfg, $pubkey, $last);
            $thread->save();

            $result['event']->thread_id = $thread->id;
            $result['event']->save();
        }

        return $result['ok'];
    }

    /**
     * Agent reply as plain text; files are carried separately inside the encrypted rumor.
     */
    public function threadToText(Thread $thread)
    {
        return ChatDelivery::plainText($thread);
    }

    /**
     * Where to deliver: the customer's kind 10050 relays (cached), plus the relay
     * their last message arrived on. Falls back to our own inbox relays.
     */
    public function targetRelays(NostrMailbox $cfg, $pubkey, array $extra = [])
    {
        $key = CustomerKey::byPubkey($pubkey);
        if ($key && $key->dmRelaysAreFresh()) {
            $dmRelays = $key->getDmRelays();
        } else {
            $client = new RelayClient(RelayClient::authSignerForKey($cfg->getPrivateKey()), $this->logger);
            $dmRelays = (new RelayDiscovery($client))->dmRelays($pubkey, $cfg->getAllRelays());
            if ($key) {
                $key->setDmRelays($dmRelays);
                $key->save();
            }
        }

        $extra = NostrMailbox::normalizeRelays($extra);
        if ($dmRelays) {
            $relays = array_merge($dmRelays, $extra);
        } else {
            // No DM relay list published: use our own inbox relays plus wherever they wrote from.
            $relays = array_merge($cfg->getInboxRelays(), $extra);
        }

        return array_values(array_unique($relays));
    }

    protected function summary(array $results)
    {
        $parts = [];
        foreach ($results as $url => $result) {
            $parts[] = $url.': '.(!empty($result['ok']) ? 'ok' : ($result['message'] ?? 'failed'));
        }

        return implode('; ', $parts);
    }

    protected function fail(Thread $thread, $message, $pubkey = '')
    {
        $this->log('thread '.$thread->id.' not sent: '.$message);
        // Not sent to any relay: the try is logged all the same (Manage » Logs » Outgoing Nostr).
        if (!$this->recorded) {
            $event = new NostrEvent();
            $event->mailbox_id = $thread->conversation->mailbox_id;
            $event->direction = NostrEvent::DIRECTION_OUT;
            $event->pubkey = strtolower((string) $pubkey);
            $event->kind = GiftWrap::KIND_DM;
            $event->conversation_id = $thread->conversation_id;
            $event->thread_id = $thread->id;
            $event->status = NostrEvent::STATUS_FAILED;
            $event->error = mb_substr((string) $message, 0, 1000);
            $event->event_created_at = now();
            $event->save();
            $this->recorded = true;
        }
        ChatDelivery::recordStatus($thread, SendLog::STATUS_SEND_ERROR, ['msg' => $message]);

        ChatDelivery::reopenConversation($thread->conversation);
    }

    protected function log($message)
    {
        if ($this->logger) {
            call_user_func($this->logger, $message);
        }
    }
}
