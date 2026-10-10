<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Nostr\CustomerKey;
use App\Nostr\EventBuilder;
use App\Nostr\GiftWrap;
use App\Nostr\IncomingMessageHandler;
use App\Nostr\Keys;
use App\Nostr\ListenerStatus;
use App\Nostr\NostrEvent;
use App\Nostr\NostrMailbox;
use App\Nostr\OutgoingMessageSender;
use App\Nostr\RelayLimits;
use App\SendLog;
use App\Thread;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Nostr messages that don't make a normal conversation (broken, duplicate,
 * own or legacy ones, URL-based files), replies that can't be
 * sent, the listener's status on the settings page and relays' size limits.
 * No relay is reachable here.
 */
class NostrMessagesTest extends FeatureTestCase
{
    const UNREACHABLE = 'ws://127.0.0.1:1';

    /**
     * A URL sent in an unsupported file message.
     */
    const FILE_HOST = 'https://93.184.215.14';

    protected $cfg;
    protected $customer_private;
    protected $log = [];
    protected $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $mailbox = $this->createMailbox();
        $this->cfg = NostrMailbox::forMailbox($mailbox->id);
        $this->cfg->setPrivateKey(Keys::generatePrivateKey());
        $this->cfg->setInboxRelays([self::UNREACHABLE]);
        $this->cfg->setAnnounceRelays([self::UNREACHABLE]);
        $this->cfg->enabled = true;
        $this->cfg->save();
        $this->customer_private = Keys::generatePrivateKey();
        $this->handler = new IncomingMessageHandler(function ($message) {
            $this->log[] = $message;
        });
        \Bus::fake([\App\Jobs\NostrTask::class]);
    }

    protected function wrap(array $rumor, $private = null)
    {
        $rumor += ['kind' => 14, 'content' => 'Hello', 'tags' => [['p', $this->cfg->pubkey]]];

        return GiftWrap::wrap($rumor, $private ?: $this->customer_private, $this->cfg->pubkey);
    }

    protected function recorded(array $wrap)
    {
        return NostrEvent::where('wrap_id', $wrap['id'])->first();
    }

    // Wraps that don't become messages.

    public function testBrokenWrapsAreRecordedAsFailed()
    {
        [$wrap] = $this->wrap([]);

        $this->assertNull($this->handler->handleGiftWrap($this->cfg, ['id' => 'not-an-id'] + $wrap));
        $this->assertSame(0, NostrEvent::where('mailbox_id', $this->cfg->mailbox_id)->count());

        $tampered = $wrap;
        $tampered['content'] = substr($wrap['content'], 0, -4).'AAAA';
        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $tampered, 'wss://relay.example.org'));
        $event = $this->recorded($tampered);
        $this->assertSame(NostrEvent::STATUS_FAILED, $event->status);
        $this->assertSame('Invalid gift wrap signature', $event->error);
        $this->assertSame('wss://relay.example.org', $event->relay);
        $this->assertSame('wrap '.substr($wrap['id'], 0, 8).' rejected: Invalid gift wrap signature', $this->log[0]);

        // Addressed to the mailbox in the wrap, to someone else inside.
        $other = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        [$redirected] = $this->wrap(['tags' => [['p', $other]]]);
        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $redirected));
        $this->assertSame('not addressed to mailbox', $this->recorded($redirected)->error);

        // Other kinds are reported.
        [$reaction] = $this->wrap(['kind' => 7, 'content' => '+']);
        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $reaction));
        $this->assertSame('unsupported kind', $this->recorded($reaction)->error);
        $this->assertContains('unsupported kind 7 from '.Keys::shortNpub(Keys::pubkeyFromPrivate($this->customer_private)), $this->log);

        $this->assertSame(0, Conversation::where('mailbox_id', $this->cfg->mailbox_id)->count());
    }

    public function testTheSameMessageInAnotherWrapAndOwnMessagesAreSkipped()
    {
        [$wrap, $rumor] = $this->wrap(['created_at' => time() - 60]);
        [$second] = GiftWrap::wrap($rumor, $this->customer_private, $this->cfg->pubkey);
        $this->assertNotSame($wrap['id'], $second['id']);

        $this->assertNotNull($this->handler->handleGiftWrap($this->cfg, $wrap));
        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $second));
        $this->assertSame(NostrEvent::STATUS_OK, $this->recorded($second)->status);
        $this->assertSame('duplicate', $this->recorded($second)->error);
        $this->assertTrue(NostrEvent::where('rumor_id', $rumor['id'])->exists());

        // A copy of what the mailbox sent itself (clients wrap one for the sender too).
        [$own] = $this->wrap([], $this->cfg->getPrivateKey());
        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $own));
        $this->assertSame('own message', $this->recorded($own)->error);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->cfg->mailbox_id)->count());
    }

    public function testEmptyMessageGetsAPlaceholderAndSubject()
    {
        $thread = $this->handler->handleGiftWrap($this->cfg, $this->wrap(['content' => "  \n "])[0]);

        $this->assertSame('<i>(empty message)</i>', $thread->body);
        $this->assertSame('Nostr message', $thread->conversation->subject);
        // A new customer's profile is looked up in the background.
        \Bus::assertDispatched(\App\Jobs\NostrTask::class, function ($job) use ($thread) {
            return $job->task === 'fetch_profile' && $job->params[0] === $thread->customer_id;
        });
    }

    public function testClosedConversationStartsANewOneWhenTheMailboxSaysSo()
    {
        $first = $this->handler->handleGiftWrap($this->cfg, $this->wrap(['content' => 'First'])[0])->conversation;
        $first->status = Conversation::STATUS_CLOSED;
        $first->save();
        $mailbox = $first->mailbox;
        $mailbox->setMetaParam('chat_start_new', true);
        $mailbox->save();

        $next = $this->handler->handleGiftWrap($this->cfg, $this->wrap(['content' => 'Second'])[0]);

        $this->assertNotSame($first->id, $next->conversation_id);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $first->fresh()->status);
    }

    public function testLegacyMessagesAreRecordedOnce()
    {
        $event = EventBuilder::finalize(['kind' => 4, 'content' => 'x?iv=y', 'tags' => [['p', $this->cfg->pubkey]]], $this->customer_private);

        $this->assertFalse($this->handler->handleLegacyMessage($this->cfg, ['id' => 'nope'] + $event));
        $forged = $event;
        $forged['content'] = 'changed';
        $this->assertFalse($this->handler->handleLegacyMessage($this->cfg, $forged));
        $elsewhere = EventBuilder::finalize(['kind' => 4, 'content' => 'x', 'tags' => [['p', str_repeat('a', 64)]]], $this->customer_private);
        $this->assertFalse($this->handler->handleLegacyMessage($this->cfg, $elsewhere));
        $own = EventBuilder::finalize(['kind' => 4, 'content' => 'x', 'tags' => [['p', $this->cfg->pubkey]]], $this->cfg->getPrivateKey());
        $this->assertFalse($this->handler->handleLegacyMessage($this->cfg, $own));
        $this->assertSame(0, NostrEvent::where('mailbox_id', $this->cfg->mailbox_id)->count());

        $this->assertTrue($this->handler->handleLegacyMessage($this->cfg, $event, 'wss://relay.example.org'));
        $this->assertFalse($this->handler->handleLegacyMessage($this->cfg, $event));
        $recorded = NostrEvent::where('wrap_id', $event['id'])->first();
        $this->assertSame(NostrEvent::STATUS_FAILED, $recorded->status);
        $this->assertSame('NIP-04 not supported', $recorded->error);
        $this->assertSame(IncomingMessageHandler::KIND_LEGACY_DM, (int) $recorded->kind);
    }

    public function testRetiredKeysShowTheirMessages()
    {
        $old = $this->cfg->pubkey;
        $this->cfg->replaceKey(Keys::generatePrivateKey());
        $this->cfg->save();
        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => 'To the old key', 'tags' => [['p', $old]]], $this->customer_private, $old);
        $thread = $this->handler->handleGiftWrap($this->cfg, $wrap);
        $this->assertStringContainsString('retired key', $thread->fresh()->headers);
        $admin = $this->createAdmin();

        $html = $this->actingAs($admin)->get('/mailbox/settings/'.$this->cfg->mailbox_id.'/nostr')->assertStatus(200)->getContent();

        $this->assertStringContainsString(Keys::npub($old).'</code>', $html);
        $this->assertMatchesRegularExpression('#<td>1 <small class="f-muted">\(last [A-Z][a-z]{2} \d{1,2}, \d{4}\)</small></td>#', $html);
    }

    // URL-based file messages.

    public function testFileMessageUrlsAreRejectedWithoutFetchingThem()
    {
        Http::fake();

        [$wrap] = $this->wrap(['kind' => GiftWrap::KIND_FILE, 'content' => self::FILE_HOST.'/file.bin']);

        $this->assertNull($this->handler->handleGiftWrap($this->cfg, $wrap));
        $event = $this->recorded($wrap);
        $this->assertSame(NostrEvent::STATUS_FAILED, $event->status);
        $this->assertSame('unsupported kind', $event->error);
        $this->assertSame(0, Conversation::where('mailbox_id', $this->cfg->mailbox_id)->count());
        Http::assertNothingSent();
    }

    // Replies that can't be sent.

    protected function nostrConversation(Customer $customer)
    {
        $conversation = Conversation::create([
            'type' => Conversation::TYPE_EMAIL,
            'subject' => 'Hello',
            'mailbox_id' => $this->cfg->mailbox_id,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'channel' => config('nostr.channel'),
            'status' => Conversation::STATUS_ACTIVE,
        ], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Hello', 'customer_id' => $customer->id]], $customer)['conversation'];
        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->save();

        return $conversation;
    }

    protected function reply(Conversation $conversation, $body)
    {
        $user = $this->createUser();

        return Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => $body, 'created_by_user_id' => $user->id], $conversation, $conversation->customer);
    }

    public function testRepliesThatCanNotBeSentAreMarkedFailed()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Nostr']);
        $conversation = $this->nostrConversation($customer);
        $sender = new OutgoingMessageSender();

        // The customer has no key.
        $reply = $this->reply($conversation, '<p>Hi</p>');
        $this->assertFalse($sender->sendThread($conversation, $reply));
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertSame('The customer has no Nostr public key', $reply->fresh()->getSendStatusData()['msg']);
        // The conversation comes back to the agents.
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);

        // An empty reply.
        CustomerKey::link($customer, Keys::pubkeyFromPrivate($this->customer_private));
        $empty = $this->reply($conversation, '<p> </p>');
        $this->assertFalse($sender->sendThread($conversation, $empty));
        $this->assertSame('Empty message', $empty->fresh()->getSendStatusData()['msg']);

        // An image in the text is a file too.
        $image = $this->reply($conversation, '<p>Look <img src="https://example.org/a.png"></p>');
        $this->assertFalse($sender->sendThread($conversation, $image));
        $this->assertSame("Files can't be sent over Nostr. Nothing was sent.", $image->fresh()->getSendStatusData()['msg']);

        // The mailbox has no key any more.
        $this->cfg->setPrivateKey('');
        $this->cfg->save();
        $none = $this->reply($conversation, '<p>Hi</p>');
        $this->assertFalse($sender->sendThread($conversation, $none));
        $this->assertSame('Nostr is not set up for this mailbox', $none->fresh()->getSendStatusData()['msg']);
    }

    public function testReplyGoesToTheCustomersFirstKeyWhenTheyNeverWrote()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Nostr']);
        $pubkey = Keys::pubkeyFromPrivate($this->customer_private);
        CustomerKey::link($customer, $pubkey);
        $conversation = $this->nostrConversation($customer);
        // Replies are sent as soon as they are saved (SendReplyToNostr).
        $reply = $this->reply($conversation, '<p>We wrote first.</p>');

        $sent = NostrEvent::where('thread_id', $reply->id)->first();
        $this->assertSame($pubkey, $sent->pubkey);
        $this->assertSame(NostrEvent::DIRECTION_OUT, $sent->direction);
        $this->assertStringContainsString('Nostr-Recipient: '.Keys::npub($pubkey), $reply->fresh()->headers);
        $this->assertStringContainsString('Could not deliver the message to any relay: '.self::UNREACHABLE.': ', $reply->fresh()->getSendStatusData()['msg']);
    }

    public function testRepliesLeaveOutLinksToTheirAttachments()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Nostr']);
        $reply = $this->reply($this->nostrConversation($customer), '<p>Text</p>');
        $attachment = \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF', null, false, $reply->id);
        $reply->body = '<p>See <a href="'.$attachment->url().'">'.$attachment->url().'</a> and <a href="'.$attachment->url().'">the steps</a></p>';
        $reply->save();

        $text = (new OutgoingMessageSender())->threadToText($reply->fresh());

        $this->assertStringNotContainsString($attachment->url(), $text);
        $this->assertMatchesRegularExpression('/^See +and the steps$/', $text);
    }

    public function testAutoReplyIsOnlySentWhenSetUp()
    {
        $customer = Customer::createWithoutEmail(['first_name' => 'Nostr']);
        $conversation = $this->nostrConversation($customer);
        $pubkey = Keys::pubkeyFromPrivate($this->customer_private);
        $sender = new OutgoingMessageSender();

        $this->assertFalse($sender->sendAutoReply($conversation->id, $this->cfg->id, $pubkey));
        $this->cfg->auto_reply_enabled = true;
        $this->cfg->auto_reply_text = '  ';
        $this->cfg->save();
        $this->assertFalse($sender->sendAutoReply($conversation->id, $this->cfg->id, $pubkey));
        $this->assertFalse($sender->sendAutoReply(0, $this->cfg->id, $pubkey));
        $this->assertSame(0, NostrEvent::where('conversation_id', $conversation->id)->count());

        // Set up, but no relay takes it: nothing in the conversation.
        $this->cfg->auto_reply_text = 'Thanks!';
        $this->cfg->save();
        $this->assertFalse($sender->sendAutoReply($conversation->id, $this->cfg->id, $pubkey));
        $this->assertSame(NostrEvent::STATUS_FAILED, NostrEvent::where('conversation_id', $conversation->id)->value('status'));
        $this->assertSame(0, $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->count());
    }

    // The listener's status.

    /**
     * What the settings page says about the listener, from its heartbeat.
     *
     * @dataProvider listenerStates
     */
    public function testListenerStateForTheSettingsPage($heartbeat_ago, $stopped_ago, $reason, $state)
    {
        \Option::set(ListenerStatus::OPTION, [
            'pid' => 1, 'heartbeat_at' => time() - $heartbeat_ago,
            'stopped_at' => $stopped_ago === null ? null : time() - $stopped_ago, 'stop_reason' => $reason,
        ]);

        $this->assertSame($state, ListenerStatus::forMailbox($this->cfg)['state']);
    }

    /**
     * Seconds since the last heartbeat, since it stopped, why, and the state shown.
     */
    public static function listenerStates()
    {
        return [
            'running' => [10, null, null, 'running'],
            'restarting after its lifetime' => [10, 10, 'lifetime', 'restarting'],
            'stopped by a signal' => [10, 10, 'signal', 'stopped'],
            'not restarted after its lifetime' => [500, 400, 'lifetime', 'stopped'],
            'no heartbeat' => [200, null, null, 'stale'],
        ];
    }

    public function testListenerStatusShowsTheCronAndTheLog()
    {
        $this->cfg->enabled = false;
        $this->cfg->save();
        \Option::set('fetch_emails_last_run', time() - 600);

        $summary = ListenerStatus::forMailbox($this->cfg);

        $this->assertSame('disabled', $summary['state']);
        $this->assertSame(['state' => 'none'], $summary['relays'][self::UNREACHABLE]);
        $this->assertFalse($summary['cron_ok']);
        $this->assertSame(time() - 600, $summary['cron_last_run']);

        // The end of the log, from a whole line.
        $storage = sys_get_temp_dir().'/tallport-nostr-log-'.getmypid();
        @mkdir($storage.'/logs', 0777, true);
        $this->app->useStoragePath($storage);
        try {
            $this->assertSame('', ListenerStatus::logTail());
            $lines = [];
            for ($i = 1; $i <= 400; $i++) {
                $lines[] = 'line '.$i.' '.str_repeat('.', 30);
            }
            file_put_contents($storage.'/logs/nostr-listen.log', implode("\n", $lines)."\n");
            $this->assertSame(array_slice($lines, -3), explode("\n", ListenerStatus::logTail(3)));
            $this->assertStringStartsWith('line ', ListenerStatus::logTail(1000));
        } finally {
            @unlink($storage.'/logs/nostr-listen.log');
            @rmdir($storage.'/logs');
            @rmdir($storage);
        }
    }

    // Relays' limits (NIP-11).

    protected function relayInformation(array $responses, &$history = [])
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $this->app->instance(\GuzzleHttp\Client::class, new \GuzzleHttp\Client(['handler' => $stack]));
    }

    public function testRelayLimitsComeFromTheRelaysInformation()
    {
        $this->relayInformation([
            new Response(200, [], json_encode(['limitation' => ['max_message_length' => 2000, 'max_content_length' => 50]])),
            new Response(200, [], json_encode(['limitation' => ['max_message_length' => 'big']])),
        ]);
        $limits = new RelayLimits();
        $small = EventBuilder::finalize(['kind' => 1059, 'content' => str_repeat('x', 40)], Keys::generatePrivateKey());
        $long = EventBuilder::finalize(['kind' => 1059, 'content' => str_repeat('x', 60)], Keys::generatePrivateKey());
        $huge = EventBuilder::finalize(['kind' => 1059, 'content' => str_repeat('x', 3000)], Keys::generatePrivateKey());

        $this->assertNull($limits->error($small, ['wss://a.example.org', 'wss://b.example.org']));
        $this->assertSame('Encrypted message content exceeds the relay limit of 50 characters', $limits->error($long, ['wss://a.example.org']));
        $this->assertSame('Encrypted message exceeds the relay limit of 2000 bytes', $limits->error($huge, ['wss://a.example.org']));
        // ws:// relays aren't asked.
        $this->assertNull($limits->error($long, [self::UNREACHABLE]));
    }

    public function testRelayLimitsSurviveAFailedRefresh()
    {
        $history = [];
        $this->relayInformation([
            new Response(200, [], json_encode(['limitation' => ['max_content_length' => 50]])),
            new Response(503),
            new Response(200, [], str_repeat(' ', 70000)),
        ], $history);
        $limits = new RelayLimits();
        $long = EventBuilder::finalize(['kind' => 1059, 'content' => str_repeat('x', 60)], Keys::generatePrivateKey());
        $key = 'nostr.relay_limits.'.hash('sha256', 'wss://a.example.org');

        $this->assertNotNull($limits->error($long, ['wss://a.example.org']));
        $this->assertSame('https://a.example.org', (string) $history[0]['request']->getUri());
        $this->assertSame('application/nostr+json', $history[0]['request']->getHeaderLine('Accept'));

        // Cached: not asked again until it's due.
        $this->assertNotNull($limits->error($long, ['wss://a.example.org']));
        $this->assertCount(1, $history);

        // Due, but the relay doesn't answer properly: the known limit stays, retried in 10 minutes.
        foreach ([1, 2] as $attempt) {
            \Cache::put($key, ['limits' => \Cache::get($key)['limits'], 'retry_at' => time() - 1], 60);
            $this->assertNotNull($limits->error($long, ['wss://a.example.org']));
            $this->assertEqualsWithDelta(time() + 600, \Cache::get($key)['retry_at'], 2);
        }
        $this->assertCount(3, $history);
    }
}
