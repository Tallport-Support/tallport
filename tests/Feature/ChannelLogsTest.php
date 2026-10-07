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
        $this->actingAs($this->admin)->get(route('logs'))->assertOk()->assertDontSee(route('logs.telegram'));

        $conversation = $this->conversation($this->mailbox);
        $conversation->customer->setSocialProfiles([['type' => Customer::SOCIAL_TYPE_TELEGRAM, 'value' => 'casey_lee']]);
        $conversation->customer->save();
        $this->telegramSend($conversation, ['message_ids' => [71, 72], 'files' => 2, 'attempt' => 2]);
        $this->telegramSend($conversation, ['status' => TelegramSend::STATUS_FAILED, 'error' => 'Forbidden: bot was blocked by the user '.str_repeat('detail ', 30).'END']);
        $other = $this->conversation($this->other_mailbox);
        $this->telegramSend($other, ['status' => TelegramSend::STATUS_RETRYING, 'error' => 'Bad Gateway']);

        $this->actingAs($this->createUser())->get(route('logs.telegram'))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('logs.telegram'))->assertOk()
            ->assertSeeInOrder(['Other Desk', 'Failed, Will Retry', 'Bad Gateway', 'Bot Desk', 'Casey Lee', '@casey_lee', 'Failed', 'END', 'Bot Desk', '71, 72', 'Succeeded'])
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertSee('telegram-send-', false)
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
        $this->actingAs($this->admin)->get(route('logs.ai'))->assertOk()->assertDontSee(route('logs.nostr'));

        $conversation = $this->conversation($this->mailbox);
        $sent = $this->nostrEvent($conversation, ['relays' => json_encode([
            'wss://one.example.org' => ['ok' => true, 'message' => ''],
            'wss://two.example.org' => ['ok' => false, 'message' => 'blocked: rate-limited'],
        ])]);
        $this->nostrEvent($conversation, ['status' => NostrEvent::STATUS_FAILED, 'thread_id' => null, 'error' => 'no relays']);
        $other = $this->conversation($this->other_mailbox);
        $this->nostrEvent($other, ['status' => NostrEvent::STATUS_FAILED, 'pubkey' => '', 'error' => 'The customer has no Nostr public key']);
        // Incoming messages are not in it.
        $this->nostrEvent($conversation, ['direction' => NostrEvent::DIRECTION_IN, 'relay' => 'wss://incoming.example.org']);

        $this->actingAs($this->createUser())->get(route('logs.nostr'))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('logs.nostr'))->assertOk()
            ->assertSeeInOrder(['Other Desk', 'Casey Lee', 'Failed', 'no Nostr public key', 'Bot Desk', 'Auto Reply', 'Failed', 'no relays', 'Bot Desk', Keys::shortNpub($sent->pubkey), '1 of 2', 'Succeeded'])
            ->assertSeeInOrder(['wss://one.example.org', 'Accepted', 'wss://two.example.org', 'Failed: blocked: rate-limited'])
            ->assertSee(Keys::npub($sent->pubkey))
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertDontSee('incoming.example.org');

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
}
