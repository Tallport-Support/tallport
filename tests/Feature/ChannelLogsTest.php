<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\Customer;
use App\Nostr\GiftWrap;
use App\Nostr\Keys;
use App\Nostr\NostrEvent;
use App\Retention\Retention;
use App\Telegram\TelegramSend;
use Tests\FeatureTestCase;

/**
 * Manage » Logs » Outgoing Telegram (App\Telegram\TelegramSend) and Outgoing Nostr (outgoing
 * nostr_events): admins only, newest first, filtered by outcome and mailbox, in the Log menu
 * once something was sent that way; Telegram's rows are cleaned up with the email send log.
 * What sending records is tested in SendReplyToTelegramJobTest and NostrTest.
 */
class ChannelLogsTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;
    protected $other_mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->admin], ['name' => 'Bot Desk']);
        $this->other_mailbox = $this->createMailbox([$this->admin], ['name' => 'Other Desk']);
    }

    protected function conversation($mailbox)
    {
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'Casey Lee <casey'.$mailbox->id.'@customer.example.org>',
            'to'   => $mailbox->email,
            'body' => 'Hello',
        ]));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function telegramSend(Conversation $conversation, array $attributes)
    {
        return TelegramSend::create($attributes + [
            'mailbox_id'      => $conversation->mailbox_id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $conversation->threads()->first()->id,
            'customer_id'     => $conversation->customer_id,
            'status'          => TelegramSend::STATUS_SENT,
        ]);
    }

    protected function nostrEvent(Conversation $conversation, array $attributes)
    {
        $event = new NostrEvent();
        foreach ($attributes + [
            'mailbox_id'      => $conversation->mailbox_id,
            'direction'       => NostrEvent::DIRECTION_OUT,
            'pubkey'          => Keys::pubkeyFromPrivate(Keys::generatePrivateKey()),
            'kind'            => GiftWrap::KIND_DM,
            'conversation_id' => $conversation->id,
            'thread_id'       => $conversation->threads()->first()->id,
            'status'          => NostrEvent::STATUS_OK,
        ] as $name => $value) {
            $event->$name = $value;
        }
        $event->save();

        return $event;
    }

    public function testOutgoingTelegramLog()
    {
        $this->actingAs($this->admin)->get(route('logs'))->assertOk()->assertSee(route('logs.telegram'));

        $conversation = $this->conversation($this->mailbox);
        $conversation->customer->setSocialProfiles([['type' => Customer::SOCIAL_TYPE_TELEGRAM, 'value' => 'casey_lee']]);
        $conversation->customer->save();
        $this->telegramSend($conversation, ['message_ids' => [71, 72], 'files' => 2, 'attempt' => 2]);
        $this->telegramSend($conversation, ['status' => TelegramSend::STATUS_FAILED, 'error' => 'Forbidden: bot was blocked by the user '.str_repeat('detail ', 30).'END']);
        $other = $this->conversation($this->other_mailbox);
        $this->telegramSend($other, ['status' => TelegramSend::STATUS_RETRYING, 'error' => 'Bad Gateway']);

        $this->actingAs($this->createUser())->get(route('logs.telegram'))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('logs.telegram'))->assertOk()
            ->assertSeeInOrder(['Other Desk', 'Failed, Will Retry', 'Bad Gateway', 'Bot Desk', 'Casey Lee', '@casey_lee', 'Failed', 'END', 'Bot Desk', 'Succeeded', '71, 72'])
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertSee('channel-delivery-', false)
            ->assertSee('value="'.route('logs.telegram').'"', false);

        $this->get(route('logs.telegram', ['outcome' => 'failed']))->assertOk()->assertSee('Bad Gateway')->assertSee('blocked')->assertDontSee('71, 72');
        $this->get(route('logs.telegram', ['mailbox_id' => $this->other_mailbox->id]))->assertOk()->assertSee('Bad Gateway')->assertDontSee('blocked');
        $this->get(route('logs.telegram', ['mailbox_id' => 999999]))->assertOk()->assertSee('This log is empty.');

        // In the Log menu of the other logs, under Outgoing Emails.
        $this->get(route('logs'))->assertOk()->assertSeeInOrder(['Outgoing Emails', 'value="'.route('logs.telegram').'"'], false);
        $this->assertSame(['out_emails', 'out_telegram'], array_slice(ActivityLog::menuNames(), 0, 2));
    }

    public function testOutgoingNostrLog()
    {
        $this->actingAs($this->admin)->get(route('logs.ai'))->assertOk()->assertSee(route('logs.nostr'));

        $conversation = $this->conversation($this->mailbox);
        $sent = $this->nostrEvent($conversation, ['relays' => json_encode([
            'wss://one.example.org' => ['ok' => true, 'message' => ''],
            'wss://two.example.org' => ['ok' => false, 'message' => 'blocked: rate-limited'],
        ])]);
        $this->nostrEvent($conversation, ['status' => NostrEvent::STATUS_FAILED, 'thread_id' => null, 'error' => 'no relays']);
        $other = $this->conversation($this->other_mailbox);
        $this->nostrEvent($other, ['status' => NostrEvent::STATUS_FAILED, 'pubkey' => '', 'error' => 'The customer has no Nostr public key']);
        // Incoming messages share the same channel log.
        $this->nostrEvent($conversation, ['direction' => NostrEvent::DIRECTION_IN, 'relay' => 'wss://incoming.example.org']);

        $this->actingAs($this->createUser())->get(route('logs.nostr'))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('logs.nostr'))->assertOk()
            ->assertSeeInOrder(['Other Desk', 'Casey Lee', 'Failed', 'no Nostr public key', 'Bot Desk', 'Failed', 'no relays', 'Auto Reply', 'Bot Desk', Keys::shortNpub($sent->pubkey), 'Succeeded', '1 of 2'])
            ->assertSeeInOrder(['wss://one.example.org', 'Accepted', 'wss://two.example.org', 'Failed: blocked: rate-limited'])
            ->assertSee(Keys::npub($sent->pubkey))
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertSee('incoming.example.org');

        $this->get(route('logs.nostr', ['outcome' => 'failed']))->assertOk()->assertSee('no relays')->assertDontSee('1 of 2');
        $this->get(route('logs.nostr', ['mailbox_id' => $this->mailbox->id]))->assertOk()->assertSee('1 of 2')->assertDontSee('no Nostr public key');

        $this->get(route('logs.ai'))->assertOk()->assertSee('value="'.route('logs.nostr').'"', false);
    }

    /**
     * Outgoing Telegram is kept as long as the email send log (Settings » Retention).
     */
    public function testOldTelegramRowsAreCleanedUp()
    {
        $conversation = $this->conversation($this->mailbox);
        $kept = $this->telegramSend($conversation, ['created_at' => now()->subMonths(Retention::get('retention_send_log_months'))->addDay()]);
        $this->telegramSend($conversation, ['created_at' => now()->subMonths(Retention::get('retention_send_log_months'))->subDay()]);

        $this->assertSame(1, Retention::cleanLogs(true)['telegram_sends']);
        $this->assertSame(1, Retention::run()['logs']['telegram_sends']);
        $this->assertSame([$kept->id], TelegramSend::pluck('id')->all());
    }

    /** @dataProvider channels */
    public function testDiagnosticsStayInTheirChannelAndCanBeFiltered($channel)
    {
        \App\Misc\ChatLog::record($channel, $this->mailbox->id, 'connection', 'failed', 'Connection <script>failed</script>', ['code' => 503, 'password' => 'private-password', 'relay' => 'wss://user:private-password@relay.example.org/path?token=private-password']);
        \App\Misc\ChatLog::record($channel, $this->other_mailbox->id, 'receive', 'info', 'Other mailbox notice');
        \App\Misc\ChatLog::record($channel === 'matrix' ? 'telegram' : 'matrix', $this->mailbox->id, 'connection', 'failed', 'Different channel failure');
        $this->actingAs($this->createUser())->get(route('logs.'.$channel))->assertForbidden();
        $this->actingAs($this->admin)->get(route('logs.'.$channel))
            ->assertOk()->assertSee('Connection &lt;script&gt;failed&lt;/script&gt;', false)
            ->assertSee('Other mailbox notice')->assertDontSee('Different channel failure')->assertDontSee('private-password');
        $this->get(route('logs.'.$channel, ['outcome' => 'failed']))->assertOk()
            ->assertSee('Connection &lt;script&gt;failed&lt;/script&gt;', false)->assertDontSee('Other mailbox notice');
        $this->get(route('logs.'.$channel, ['mailbox_id' => $this->other_mailbox->id]))->assertOk()
            ->assertSee('Other mailbox notice')->assertDontSee('Connection &lt;script&gt;failed&lt;/script&gt;', false);
        $this->assertStringNotContainsString('private-password', ActivityLog::where('log_name', $channel)->get()->toJson());
    }

    /** @dataProvider channels */
    public function testEarlierDiagnosticNamesAppearInOneChannelLog($channel)
    {
        \App\Misc\ChatLog::record($channel, $this->mailbox->id, 'connection', 'failed', 'Earlier diagnostic');
        ActivityLog::where('log_name', $channel)->update(['log_name' => 'chat_'.$channel]);
        \App\Misc\ChatLog::record($channel, $this->mailbox->id, 'connection', 'failed', 'Current diagnostic');
        $this->actingAs($this->admin)->get(route('logs.'.$channel, ['outcome' => 'failed', 'mailbox_id' => $this->mailbox->id]))
            ->assertOk()->assertSee('Earlier diagnostic')->assertSee('Current diagnostic')->assertDontSee('Chat '.ucfirst($channel));
        $this->assertSame(1, count(array_filter(ActivityLog::menuNames(), fn ($name) => $name === 'out_'.$channel)));
        $this->assertNotContains('chat_'.$channel, ActivityLog::menuNames());
        $this->assertSame(2, ActivityLog::whereIn('log_name', [$channel, 'chat_'.$channel])->count());
    }

    public static function channels()
    {
        return [['telegram'], ['nostr'], ['matrix']];
    }

    public function testMatrixLogShowsDeliveryAndSyncFailuresWithoutDecryptingMessagePayloads()
    {
        $identity = \App\Matrix\MatrixMailbox::create(['mailbox_id' => $this->mailbox->id, 'active_mailbox_id' => $this->mailbox->id,
            'homeserver' => 'https://matrix.example.org', 'user_id' => '@support:example.org', 'user_hash' => hash('sha256', '@support:example.org'),
            'device_id' => 'TALLPORT', 'status' => 'verification', 'credentials' => ['access_token' => 'secret-token']]);
        $conversation = $this->conversation($this->mailbox);
        $event = \App\Matrix\MatrixEvent::outgoing($identity->id, 'reply', 'outgoing', [], '!room:example.org', $conversation->threads()->first()->id);
        $event->status = 'sent';
        $event->remote_id = '$sent-message';
        $event->save();
        \DB::table('matrix_events')->where('id', $event->id)->update(['payload' => 'undecryptable-message-secret']);
        $this->actingAs($this->admin)->get(route('logs.matrix'))->assertOk()->assertSee('$sent-message');
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['https://matrix.example.org/*' => \Illuminate\Support\Facades\Http::response(['errcode' => 'M_UNKNOWN', 'error' => 'private-response-body'], 503)]);
        \App\Jobs\SyncMatrixMailbox::dispatchSync($identity->id);
        $this->actingAs($this->admin)->get(route('logs.matrix'))->assertOk()
            ->assertSee('Connection')->assertSee('M_UNKNOWN')->assertSee('$sent-message')->assertSee('Succeeded')
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertDontSee('secret-token')->assertDontSee('private-response-body')->assertDontSee('undecryptable-message-secret');
        $this->get(route('logs.matrix', ['outcome' => 'failed']))->assertOk()->assertSee('M_UNKNOWN')->assertDontSee('$sent-message');
        $this->assertSame('failed', ActivityLog::where('log_name', 'matrix')->firstOrFail()->properties['status']);
    }

    public function testMixedHistoryPaginatesWithoutDroppingOrRepeatingEntries()
    {
        $conversation = $this->conversation($this->mailbox);
        $this->telegramSend($conversation, ['message_ids' => [987654], 'created_at' => now()->subHour()]);
        for ($i = 0; $i < 50; $i++) {
            \App\Misc\ChatLog::record('telegram', $this->mailbox->id, 'connection', 'failed', 'Recent connection failure');
        }
        $this->actingAs($this->admin)->get(route('logs.telegram'))->assertOk()->assertSee('Recent connection failure')->assertDontSee('987654');
        $this->get(route('logs.telegram', ['page' => 2, 'outcome' => '']))->assertOk()->assertSee('987654')->assertDontSee('Recent connection failure');
    }

    public function testUnexpectedExceptionsDoNotExposeTheirMessagesInDiagnostics()
    {
        \App\Misc\ChatLog::failure('nostr', $this->mailbox->id, 'receive', new \RuntimeException('secret-private-key-and-message', 42));
        $this->actingAs($this->admin)->get(route('logs.nostr'))->assertOk()
            ->assertSee('RuntimeException')->assertSee('42')->assertSee('Receive')->assertDontSee('secret-private-key-and-message');
    }
}
