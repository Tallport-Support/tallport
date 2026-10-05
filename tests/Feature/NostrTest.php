<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Nostr\CustomerKey;
use App\Nostr\EventBuilder;
use App\Nostr\GiftWrap;
use App\Nostr\IncomingMessageHandler;
use App\Nostr\Keys;
use App\Nostr\ListenerStatus;
use App\Nostr\NostrEvent;
use App\Nostr\NostrMailbox;
use App\Nostr\OutgoingMessageSender;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Nostr as a channel, offline (relays on a closed port): the mailbox's
 * identity, receiving messages, customers' keys, sending replies and the
 * hooks modules use.
 */
class NostrTest extends FeatureTestCase
{
    const UNREACHABLE = 'ws://127.0.0.1:1';

    protected $admin;
    protected $agent;
    protected $mailbox;
    protected $customer_private;
    protected $customer_pubkey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['password' => \Hash::make('correct horse')]);
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        $this->customer_private = Keys::generatePrivateKey();
        $this->customer_pubkey = Keys::pubkeyFromPrivate($this->customer_private);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function settingsUrl()
    {
        return '/mailbox/settings/'.$this->mailbox->id.'/nostr';
    }

    /**
     * Generate the mailbox's key and turn Nostr on.
     */
    protected function setUpNostr(array $settings = [])
    {
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'generate']);
        $this->postForm($this->admin, $this->settingsUrl(), array_merge([
            'action' => 'save', 'enabled' => '1', 'inbox_relays' => self::UNREACHABLE, 'announce_relays' => self::UNREACHABLE,
            'profile_name' => 'Test Support', 'profile_about' => '', 'profile_picture' => '', 'nip05' => '',
            'auto_reply_enabled' => '0', 'auto_reply_text' => '', 'reopen_days' => '30',
        ], $settings));

        return NostrMailbox::forMailbox($this->mailbox->id, false);
    }

    protected function receive(NostrMailbox $cfg, $content, array $tags = [], $kind = 14, $private = null)
    {
        $private = $private ?: $this->customer_private;
        [$wrap] = GiftWrap::wrap(['kind' => $kind, 'content' => $content, 'tags' => array_merge([['p', $cfg->pubkey]], $tags)], $private, $cfg->pubkey);

        return (new IncomingMessageHandler())->handleGiftWrap($cfg, $wrap, 'wss://relay.example.org');
    }

    // The mailbox's identity.

    public function testSettingsKeysAndAddress()
    {
        $this->actingAs($this->admin)->get($this->settingsUrl())->assertStatus(200)->assertSee('Generate Keypair');
        $this->actingAs($this->agent)->get($this->settingsUrl())->assertStatus(403);

        $cfg = $this->setUpNostr(['inbox_relays' => self::UNREACHABLE."\nrelay.example.org/", 'nip05' => 'Support@Example.COM']);
        $this->assertTrue((bool) $cfg->enabled);
        $this->assertSame(Keys::pubkeyFromPrivate($cfg->getPrivateKey()), $cfg->pubkey);
        $this->assertStringNotContainsString($cfg->getPrivateKey(), $cfg->private_key);
        $this->assertSame([self::UNREACHABLE, 'wss://relay.example.org'], $cfg->getInboxRelays());
        $this->assertSame('support@example.com', $cfg->nip05);

        // Enabling needs relays.
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'save', 'enabled' => '1', 'inbox_relays' => '', 'reopen_days' => '30'])
            ->assertSessionHasErrors('inbox_relays');

        $this->actingAs($this->admin)->get($this->settingsUrl())->assertSee($cfg->getNpub())->assertSee('https://example.com/.well-known/nostr.json');

        // NIP-05.
        $json = $this->getJson('/.well-known/nostr.json?name=support')->assertStatus(200)->assertHeader('Access-Control-Allow-Origin', '*')->json();
        $this->assertSame($cfg->pubkey, $json['names']['support']);
        $this->assertSame([], $this->getJson('/.well-known/nostr.json?name=nobody')->json()['names']);
    }

    public function testKeysAreHardToLose()
    {
        $cfg = $this->setUpNostr();
        $first = $cfg->pubkey;
        $first_private = $cfg->getPrivateKey();

        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'generate'])->assertSessionHas('flash_error_floating');
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'replace', 'password' => 'wrong', 'confirm' => 'REPLACE']);
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'replace', 'password' => 'correct horse', 'confirm' => 'replace please']);
        $this->assertSame($first, NostrMailbox::forMailbox($this->mailbox->id, false)->pubkey);

        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'reveal'])->assertSessionMissing('nostr_reveal_nsec');
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'reveal', 'password' => 'correct horse'])->assertSessionHas('nostr_reveal_nsec', $cfg->getNsec());

        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'replace', 'password' => 'correct horse', 'confirm' => ' replace ']);
        $cfg = NostrMailbox::forMailbox($this->mailbox->id, false);
        $this->assertNotSame($first, $cfg->pubkey);
        $retired = $cfg->getRetiredKeys();
        $this->assertSame($first, $retired[0]->pubkey);
        $this->assertSame($first_private, $retired[0]->getPrivateKey());
        $this->assertSame([$cfg->pubkey, $first], $cfg->getAllPubkeys());

        // Messages to the retired key still arrive; deleting it needs the password and DELETE.
        $thread = $this->receive($cfg, 'To your old key', [['p', $first]]);
        $this->assertNotNull($thread);
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'delete_key', 'key_id' => $retired[0]->id, 'password' => 'correct horse', 'confirm' => 'nope']);
        $this->assertCount(1, $cfg->getRetiredKeys());
        $this->postForm($this->admin, $this->settingsUrl(), ['action' => 'delete_key', 'key_id' => $retired[0]->id, 'password' => 'correct horse', 'confirm' => 'delete']);
        $this->assertCount(0, $cfg->getRetiredKeys());
    }

    public function testListenerStatusOnTheSettingsPage()
    {
        $this->setUpNostr();
        \Option::set('nostr.listener', null);
        $this->actingAs($this->admin)->get($this->settingsUrl())->assertSee('Not started yet');

        ListenerStatus::write(['pid' => 4242, 'host' => 'box', 'started_at' => time() - 100, 'lifetime' => 1200, 'ends_at' => time() + 1100, 'stopped_at' => null, 'stop_reason' => null,
            'connections' => [['mailbox_id' => $this->mailbox->id, 'url' => self::UNREACHABLE, 'state' => 'reconnecting', 'since' => null, 'caught_up' => false, 'authed' => false, 'events' => 0, 'last_event_at' => null, 'error' => 'Connection refused', 'retry_in' => 20]],]);
        $this->actingAs($this->admin)->get($this->settingsUrl())->assertSee('>Running<', false)->assertSee('Process 4242 on box')->assertSee('Connection refused');
        $this->actingAs($this->admin)->get('/system/status')->assertSee('tallport:nostr-listen');
    }

    // Customers' messages.

    public function testMessageStartsAConversationWithANewCustomer()
    {
        $cfg = $this->setUpNostr();

        $thread = $this->receive($cfg, "Hello, my VPN won't connect.\nhttps://example.com/help", [['subject', 'VPN problem']]);

        $conversation = $thread->conversation;
        $this->assertSame(Conversation::TYPE_CHAT, (int) $conversation->type);
        $this->assertSame(90, (int) $conversation->channel);
        $this->assertSame('VPN problem', $conversation->subject);
        $this->assertStringContainsString('won&#039;t connect', $thread->body);
        $this->assertMatchesRegularExpression('#<a\s+href="https://example.com/help"#', $thread->body);
        $customer = $conversation->customer;
        $this->assertSame(Keys::shortNpub($this->customer_pubkey), $customer->first_name);
        $this->assertSame($customer->id, CustomerChannel::where('channel', 90)->where('channel_id', $this->customer_pubkey)->value('customer_id'));
        $this->assertStringContainsString('Nostr-Wrap-Id: ', $thread->fresh()->headers);

        $this->followingRedirects()->actingAs($this->agent)->get('/conversation/'.$conversation->id)
            ->assertStatus(200)->assertSee('Nostr')->assertSee(Keys::shortNpub($this->customer_pubkey))
            ->assertDontSee('@endif', false);
    }

    public function testDuplicatesNextMessagesAndReopenWindow()
    {
        $cfg = $this->setUpNostr();
        [$wrap, $rumor] = GiftWrap::wrap(['kind' => 14, 'content' => 'First', 'tags' => [['p', $cfg->pubkey]], 'created_at' => time() - 5], $this->customer_private, $cfg->pubkey);
        [$wrap2] = GiftWrap::wrap($rumor + ['kind' => 14], $this->customer_private, $cfg->pubkey);
        $handler = new IncomingMessageHandler();

        $thread = $handler->handleGiftWrap($cfg, $wrap, 'wss://a');
        $this->assertNull($handler->handleGiftWrap($cfg, $wrap, 'wss://b'));
        $conversation = $thread->conversation;

        $conversation->status = Conversation::STATUS_CLOSED;
        $conversation->save();
        $next = $this->receive($cfg, 'Still broken');
        $this->assertSame($conversation->id, $next->conversation_id);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);

        Conversation::where('id', $conversation->id)->update(['last_reply_at' => now()->subDays(31)]);
        $this->assertNotSame($conversation->id, $this->receive($cfg, 'Months later')->conversation_id);

        // Someone else's message and other kinds are left out.
        $other = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        [$elsewhere] = GiftWrap::wrap(['kind' => 14, 'content' => 'x', 'tags' => [['p', $other]]], $this->customer_private, $other);
        $this->assertNull($handler->handleGiftWrap($cfg, $elsewhere, null));
        $this->assertNull($this->receive($cfg, 'x', [], 4));
    }

    public function testCustomersKeysAndMerging()
    {
        $cfg = $this->setUpNostr();
        $customer = $this->receive($cfg, 'Hello')->conversation->customer;
        $second = Keys::generatePrivateKey();
        CustomerKey::link($customer, Keys::pubkeyFromPrivate($second), CustomerKey::SOURCE_MANUAL, 'Android app');
        $this->assertSame($customer->id, $this->receive($cfg, 'From my phone', [], 14, $second)->conversation->customer_id);

        $this->actingAs($this->agent)->get('/customers/'.$customer->id.'/nostr')->assertStatus(200)->assertSee('Android app');
        $this->actingAs($this->agent)->get('/customers/'.$customer->id.'/edit')->assertSee('customers/'.$customer->id.'/nostr');

        $new = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        $this->postForm($this->agent, '/customers/'.$customer->id.'/nostr', ['action' => 'add', 'pubkey' => Keys::npub($new), 'label' => 'Laptop']);
        $this->assertSame('Laptop', CustomerKey::byPubkey($new)->label);
        $this->postForm($this->agent, '/customers/'.$customer->id.'/nostr', ['action' => 'remove', 'key_id' => CustomerKey::byPubkey($new)->id]);
        $this->assertNull(CustomerKey::byPubkey($new));

        $duplicate = Customer::createWithoutEmail(['first_name' => 'Dup']);
        $duplicate_key = Keys::pubkeyFromPrivate(Keys::generatePrivateKey());
        CustomerKey::link($duplicate, $duplicate_key, 'auto');
        $customer->fresh()->mergeWith($duplicate);
        $this->assertSame($customer->id, CustomerKey::byPubkey($duplicate_key)->customer_id);
        $this->assertSame(1, CustomerChannel::where('customer_id', $customer->id)->where('channel', 90)->count());
    }

    public function testEncryptedFilesAreDownloadedSafely()
    {
        $cfg = $this->setUpNostr();
        $plain = random_bytes(1000);
        $key = random_bytes(32);
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag).$tag;
        Http::fake(['https://93.184.215.14/*' => Http::response($encrypted)]);
        $file_tags = function ($url) use ($key, $nonce, $encrypted) {
            return [['file-type', 'image/png'], ['encryption-algorithm', 'aes-gcm'], ['decryption-key', bin2hex($key)], ['decryption-nonce', bin2hex($nonce)], ['x', hash('sha256', $encrypted)], ['size', (string) strlen($encrypted)]];
        };

        $thread = $this->receive($cfg, 'https://93.184.215.14/f.bin', $file_tags(''), 15);
        $attachment = $thread->attachments()->first();
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame($plain, $attachment->getFileContents());
        $this->assertStringContainsString('Sent a file', $thread->body);

        // A private address is not fetched.
        $thread = $this->receive($cfg, 'http://127.0.0.1/secret.bin', $file_tags(''), 15);
        $this->assertStringContainsString('could not be retrieved', $thread->body);
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '127.0.0.1');
        });
    }

    public function testEmailAutoReplyIsNotSentToNostrCustomers()
    {
        $cfg = $this->setUpNostr();
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'Thanks';
        $this->mailbox->auto_reply_message = 'We got it';
        $this->mailbox->save();
        $customer = $this->receive($cfg, 'Hello')->conversation->customer;
        $customer->addEmail('nostr-customer@example.org', true);
        Conversation::where('customer_id', $customer->id)->update(['last_reply_at' => now()->subDays(40)]);

        $this->receive($cfg, 'Hello again');

        $this->assertCount(0, $this->sentEmailsTo('nostr-customer@example.org'));
    }

    // Replies.

    public function testReplyCantBeUndoneAndFailureIsShown()
    {
        $cfg = $this->setUpNostr();
        $conversation = $this->receive($cfg, 'Hello')->conversation;
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
            'body' => '<p>We are <b>on it</b>.</p>', 'status' => Conversation::STATUS_CLOSED,
        ]);
        $this->assertStringNotContainsString('Undo', (string) session('flash_success_floating'));
        $this->assertStringContainsString('Message sent', (string) session('flash_success_floating'));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->send_status);
        $this->assertStringContainsString('relay', $reply->getSendStatusData()['msg']);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertStringContainsString('Nostr-Relays: ', (string) $reply->headers);

        \Session::start();
        $this->actingAs($this->agent)->get(route('conversations.undo', ['thread_id' => $reply->id, 'token' => csrf_token()]));
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $reply->fresh()->state);

        // Retry queues it again.
        $this->assertSame('success', $this->postAjax($this->agent, '/conversation/ajax', ['action' => 'retry_send', 'thread_id' => $reply->id])->json('status'));
    }

    public function testRepliesWithFilesNeedAModule()
    {
        $cfg = $this->setUpNostr();
        $conversation = $this->receive($cfg, 'Hello')->conversation;
        $reply = Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => '<p>See file</p>', 'created_by_user_id' => $this->agent->id], $conversation, $conversation->customer);
        \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF', null, false, $reply->id);
        $reply = $reply->fresh();

        (new OutgoingMessageSender())->sendThread($conversation, $reply);
        $this->assertStringContainsString("Files can't be sent over Nostr", $reply->fresh()->getSendStatusData()['msg']);

        // A module that carries files in tags.
        \Eventy::addFilter('nostr.reply_attachment_tags', function ($tags, $thread) {
            return [['app_file', 'steps.pdf']];
        }, 10, 2);
        \Eventy::addFilter('nostr.rumor_tags', function ($tags, $options) {
            $tags[] = ['support_agent', 'Agent'];

            return $tags;
        }, 10, 2);
        \Eventy::addFilter('nostr.header_tags', function ($tags) {
            return array_values(array_filter($tags, function ($tag) {
                return $tag[0] != 'app_file';
            }));
        });
        $result = (new OutgoingMessageSender())->sendText($cfg, $this->customer_pubkey, 'Hi', ['attachments' => [['app_file', 'steps.pdf']], 'thread_id' => $reply->id]);
        $this->assertContains(['support_agent', 'Agent'], $result['rumor']['tags']);
        $this->assertContains(['app_file', 'steps.pdf'], $result['rumor']['tags']);
        $this->assertNotContains(['app_file', 'steps.pdf'], IncomingMessageHandler::headerTags($result['rumor']['tags']));
    }

    public function testModulesReadIncomingTags()
    {
        $cfg = $this->setUpNostr();
        \Eventy::addFilter('nostr.incoming_message', function ($message, $rumor) {
            if (EventBuilder::firstTag($rumor, 'app_log')) {
                $message['text'] = 'Sent logs.';
                $message['attachments'][] = ['file_name' => 'log.txt', 'data' => base64_encode(EventBuilder::firstTag($rumor, 'app_log'))];
            }

            return $message;
        }, 10, 2);

        $thread = $this->receive($cfg, '', [['app_log', 'LOG LINES']]);

        $this->assertSame('Sent logs.', $thread->body);
        $this->assertSame('LOG LINES', $thread->attachments()->first()->getFileContents());
    }

    public function testAutoReplyLineItem()
    {
        $cfg = $this->setUpNostr(['auto_reply_enabled' => '1', 'auto_reply_text' => 'Thanks, we will get back to you.']);
        $conversation = $this->receive($cfg, 'Hello')->conversation;

        // Not delivered (no relay): no line item.
        $this->assertSame(0, $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->count());

        $line = new Thread();
        $line->conversation_id = $conversation->id;
        $line->type = Thread::TYPE_LINEITEM;
        $line->action_type = Thread::ACTION_TYPE_NOSTR_AUTO_REPLY;
        $line->body = 'Thanks, we will get back to you.';
        $line->state = Thread::STATE_PUBLISHED;
        $line->save();
        $this->assertSame('System sent the Nostr auto reply: "Thanks, we will get back to you."', html_entity_decode($line->getActionText('', true)));
    }

    // Settings and commands.

    public function testNewMailboxStartsWithTheDefaultRelays()
    {
        $cfg = NostrMailbox::forMailbox(999999);
        $this->assertSame(config('nostr.default_inbox_relays'), $cfg->getInboxRelays());
        $this->assertSame(config('nostr.default_announce_relays'), $cfg->getAnnounceRelays());

        // Relays are set per mailbox only.
        $this->actingAs($this->admin)->get('/app-settings/nostr')->assertStatus(404);
    }

    public function testCommands()
    {
        $this->setUpNostr();

        $this->artisan('tallport:nostr-announce')->assertExitCode(0);
        $this->artisan('tallport:nostr-diagnose')->assertExitCode(0);
        $this->artisan('tallport:nostr-listen', ['--once' => true])->assertExitCode(0);
    }
}
