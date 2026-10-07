<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\Jobs\SendReplyToTelegram;
use App\SendLog;
use App\Telegram\Telegram;
use App\Telegram\TelegramSend;
use App\Thread;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * The SendReplyToTelegram job's less common paths, with the Bot API faked
 * (the main ones are in TelegramTest): a customer without a chat, missing
 * files, Undo while sending, retries and giving up.
 */
class SendReplyToTelegramJobTest extends FeatureTestCase
{
    const TOKEN = '123456:SECRET-token';

    protected $agent;
    protected $mailbox;
    protected $update_id = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Bot Desk']);
        Telegram::saveSettings($this->mailbox, ['enabled' => true, 'token' => self::TOKEN, 'auto_reply' => '', 'ignore_start' => false]);
        $this->fakeTelegram();
    }

    /**
     * Fake the Bot API; $responses: method => response (or closure).
     */
    protected function fakeTelegram(array $responses = [])
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(function (HttpRequest $request) use ($responses) {
            $method = basename((string) parse_url($request->url(), PHP_URL_PATH));
            if (isset($responses[$method])) {
                return is_callable($responses[$method]) ? $responses[$method]($request) : $responses[$method];
            }
            switch ($method) {
                case 'getMe':
                    return $this->ok(['id' => 1, 'is_bot' => true, 'first_name' => 'Help', 'username' => 'help_bot']);
                case 'getUserProfilePhotos':
                    return $this->ok(['total_count' => 0, 'photos' => []]);
                default:
                    return $this->ok(['message_id' => 1]);
            }
        });
    }

    protected function ok($result)
    {
        return Http::response(['ok' => true, 'result' => $result]);
    }

    protected function error($code, $description)
    {
        return Http::response(['ok' => false, 'error_code' => $code, 'description' => $description], $code);
    }

    protected function sentTo($method)
    {
        return collect(Http::recorded())->filter(function ($pair) use ($method) {
            return str_ends_with((string) parse_url($pair[0]->url(), PHP_URL_PATH), '/'.$method);
        })->map(function ($pair) {
            return $pair[0];
        })->values();
    }

    /**
     * An agent's reply in a customer's Telegram conversation, not sent yet.
     */
    protected function reply($body = '<p>Hi <b>Casey</b></p>')
    {
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => Telegram::webhookSecret($this->mailbox)])
            ->postJson('/telegram/webhook/'.$this->mailbox->id, [
                'update_id' => ++$this->update_id,
                'message'   => [
                    'message_id' => $this->update_id,
                    'date'       => time(),
                    'chat'       => ['id' => 555, 'type' => 'private', 'first_name' => 'Casey'],
                    'from'       => ['id' => 555, 'is_bot' => false, 'first_name' => 'Casey', 'last_name' => 'Lee'],
                    'text'       => 'My app does not work',
                ],
            ]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        config(['queue.default' => 'database']);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => $body,
        ]);
        config(['queue.default' => 'sync']);
        \DB::table('jobs')->where('queue', 'emails')->delete();

        return $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
    }

    protected function telegramLog()
    {
        return ActivityLog::where('log_name', Telegram::LOG)->pluck('description')->all();
    }

    public function testCustomerWithoutTelegramChatIsNotSentTo()
    {
        $reply = $this->reply();
        \App\CustomerChannel::where('customer_id', $reply->conversation->customer_id)->delete();

        (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions()->handle();

        $this->assertCount(0, $this->sentTo('sendMessage'));
        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $reply->send_status);
        $this->assertSame('The customer has no Telegram chat.', $reply->getSendStatusData()['msg']);
        $this->assertSame(['(Bot Desk) Reply '.$reply->id.' in conversation #'.$reply->conversation->number.' not sent (try 1): The customer has no Telegram chat.'], $this->telegramLog());
    }

    public function testMissingFileIsNotSent()
    {
        $reply = $this->reply();
        $file = \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);
        \App\Attachment::getDisk()->delete($file->getStorageFilePath());
        $job = (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
        $this->assertCount(1, $this->sentTo('sendMessage'), 'The text went out.');
        $this->assertCount(0, $this->sentTo('sendDocument'));
        $this->assertSame('The file steps.pdf is missing.', $reply->fresh()->getSendStatusData()['msg']);
    }

    public function testUndoWhileSendingStopsBeforeTheNextPart()
    {
        // Longer than a Telegram message: sent in parts.
        $reply = $this->reply('<p>'.str_repeat('Restart the app and try again. ', 200).'</p>');
        \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);
        $this->fakeTelegram(['sendMessage' => function () use ($reply) {
            // The agent clicks Undo while the first part is being sent.
            \DB::table('threads')->where('id', $reply->id)->update(['state' => Thread::STATE_DRAFT]);

            return $this->ok(['message_id' => 81]);
        }]);

        (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions()->handle();

        $this->assertCount(1, $this->sentTo('sendMessage'));
        $this->assertCount(0, $this->sentTo('sendDocument'));
        $reply->refresh();
        $this->assertNull($reply->send_status, 'Not marked as sent.');
        $this->assertSame([81], $reply->getSendStatusData()['telegram_messages'], 'Remembered for Undo.');
    }

    public function testUndoWhileSendingTheLastPartLeavesItUnsent()
    {
        $reply = $this->reply();
        $this->fakeTelegram(['sendMessage' => function () use ($reply) {
            \DB::table('threads')->where('id', $reply->id)->update(['state' => Thread::STATE_DRAFT]);

            return $this->ok(['message_id' => 82]);
        }]);

        (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions()->handle();

        $this->assertNull($reply->fresh()->send_status);
    }

    public function testThirdTryShowsTheReplyAsNotSentYet()
    {
        $this->fakeTelegram(['sendMessage' => $this->error(502, 'Bad Gateway')]);
        $reply = $this->reply();
        $reply->conversation->setStatus(Conversation::STATUS_CLOSED);
        $reply->conversation->save();
        $job = (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        $job->handle();

        $job->assertReleased();
        $job->assertNotFailed();
        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_SEND_INTERMEDIATE_ERROR, $reply->send_status);
        $this->assertSame('Bad Gateway', $reply->getSendStatusData()['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $reply->conversation->fresh()->status);
    }

    public function testGivingUpMarksTheReplyNotSent()
    {
        $reply = $this->reply();

        (new SendReplyToTelegram($reply->id))->failed(new \Exception('Timed out'));

        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $reply->send_status);
        $this->assertSame('Timed out', $reply->getSendStatusData()['msg']);
    }

    public function testUndoLogsMessagesThatCouldNotBeDeleted()
    {
        $this->fakeTelegram(['sendMessage' => $this->ok(['message_id' => 91])]);
        $reply = $this->reply();
        (new SendReplyToTelegram($reply->id))->handle();
        $this->fakeTelegram(['deleteMessage' => $this->error(400, 'Bad Request: message can\'t be deleted')]);

        SendReplyToTelegram::undo($reply->fresh());

        $this->assertContains('(Bot Desk) Undo: message 91 of reply '.$reply->id.' not deleted: Bad Request: message can\'t be deleted', $this->telegramLog());
        $reply->refresh();
        $this->assertNull($reply->send_status);
        $this->assertSame([], $reply->getSendStatusData()['telegram_messages']);
    }

    /**
     * Manage » Logs » Outgoing Telegram: every try, sent (its messages and files) or failed
     * (tried again, or for good when the bot is blocked or the job gives up).
     */
    public function testEachTryIsLogged()
    {
        $this->fakeTelegram(['sendMessage' => $this->error(502, 'Bad Gateway')]);
        $reply = $this->reply();
        \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);
        $job = (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions();
        $job->handle();

        $this->fakeTelegram(['sendMessage' => $this->ok(['message_id' => 71]), 'sendDocument' => $this->ok(['message_id' => 72])]);
        $job = (new SendReplyToTelegram($reply->id))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle();

        [$retry, $sent] = TelegramSend::orderBy('id')->get()->all();
        $this->assertSame([TelegramSend::STATUS_RETRYING, 1, 'Bad Gateway', null, 1], [$retry->status, $retry->attempt, $retry->error, $retry->message_ids, $retry->files]);
        $this->assertSame([TelegramSend::STATUS_SENT, 2, null, [71, 72]], [$sent->status, $sent->attempt, $sent->error, $sent->message_ids]);
        $this->assertSame([$this->mailbox->id, $reply->conversation_id, $reply->id, $reply->conversation->customer_id], [$sent->mailbox_id, $sent->conversation_id, $sent->thread_id, $sent->customer_id]);

        // Blocked by the customer: failed for good.
        $this->fakeTelegram(['sendMessage' => $this->error(403, 'Forbidden: bot was blocked by the user')]);
        $this->reply();
        $blocked = Thread::where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
        (new SendReplyToTelegram($blocked->id))->withFakeQueueInteractions()->handle();
        $this->assertSame([TelegramSend::STATUS_FAILED, 'Forbidden: bot was blocked by the user', 0], TelegramSend::where('thread_id', $blocked->id)->get(['status', 'error', 'files'])->map(fn ($row) => [$row->status, $row->error, $row->files])->first());

        // The job gave up (timed out): one more failure, once.
        $this->reply();
        $given_up = Thread::where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
        $job = new SendReplyToTelegram($given_up->id);
        $job->failed(new \Exception('Timed out'));
        $job->failed(new \Exception('Timed out'));
        $this->assertSame(['Timed out'], TelegramSend::where('thread_id', $given_up->id)->pluck('error')->all());
    }
}
