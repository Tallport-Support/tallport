<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\SendLog;
use App\Telegram\Telegram;
use App\Thread;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Telegram bots as a channel, with the Bot API faked: settings, the
 * webhook (customers' messages), and sending agents' replies.
 */
class TelegramTest extends FeatureTestCase
{
    const TOKEN = '123456:SECRET-token';

    protected $admin;
    protected $agent;
    protected $mailbox;
    protected $update_id = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
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
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $method = basename($path);
            if (str_starts_with($path, '/file/bot')) {
                return $responses['file'] ?? Http::response('FILE-'.$method);
            }
            if (isset($responses[$method])) {
                return is_callable($responses[$method]) ? $responses[$method]($request) : $responses[$method];
            }
            switch ($method) {
                case 'getMe':
                    return $this->ok(['id' => 1, 'is_bot' => true, 'first_name' => 'Help', 'username' => 'help_bot']);
                case 'getWebhookInfo':
                    return $this->ok(['url' => Telegram::webhookUrl($this->mailbox), 'pending_update_count' => 2]);
                case 'getFile':
                    return $this->ok(['file_id' => $request['file_id'], 'file_size' => 10, 'file_path' => 'documents/'.$request['file_id'].'.jpg']);
                case 'getUserProfilePhotos':
                    return $this->ok(['total_count' => 0, 'photos' => []]);
                case 'setWebhook':
                case 'deleteWebhook':
                    return $this->ok(true);
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

    protected function update(array $message = [], $key = 'message')
    {
        return [
            'update_id' => ++$this->update_id,
            $key        => array_merge([
                'message_id' => $this->update_id,
                'date'       => time(),
                'chat'       => ['id' => 555, 'type' => 'private', 'first_name' => 'Casey'],
                'from'       => ['id' => 555, 'is_bot' => false, 'first_name' => 'Casey', 'last_name' => 'Lee', 'username' => 'caseylee'],
                'text'       => 'Hello, my app <does not> work',
            ], $message),
        ];
    }

    protected function postUpdate(array $update, $secret = null)
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $secret ?? Telegram::webhookSecret($this->mailbox)])
            ->postJson('/telegram/webhook/'.$this->mailbox->id, $update);
    }

    protected function conversation()
    {
        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
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
     * An agent's reply in the customer's Telegram conversation, its job
     * waiting in the database queue.
     */
    protected function reply($body = '<p>Hi <b>Casey</b></p>')
    {
        $this->postUpdate($this->update());
        $conversation = $this->conversation();
        config(['queue.default' => 'database']);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => $body,
        ]);

        return $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
    }

    protected function runQueue()
    {
        \DB::table('jobs')->where('queue', 'emails')->update(['available_at' => time() - 1]);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true]);
    }

    // Settings.

    public function testSettingsConnectTheBot()
    {
        $this->actingAs($this->admin)->get('/mailbox/'.$this->mailbox->id.'/telegram')
            ->assertStatus(200)->assertSee('@help_bot')->assertSee('Receiving messages')->assertDontSee(self::TOKEN);
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/telegram')->assertStatus(403);

        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', [
            'enabled' => 1, 'token' => '******', 'auto_reply' => ' Welcome! ', 'ignore_start' => 1,
        ])->assertRedirect(route('mailboxes.telegram', ['id' => $this->mailbox->id]));

        $mailbox = $this->mailbox->fresh();
        $this->assertSame(['enabled' => true, 'token' => self::TOKEN, 'auto_reply' => 'Welcome!', 'auto_replies' => [], 'ignore_start' => true], Telegram::settings($mailbox));
        $this->assertStringNotContainsString(self::TOKEN, json_encode($mailbox->meta));
        $set = $this->sentTo('setWebhook')->last();
        $this->assertSame(route('telegram.webhook', ['mailbox_id' => $mailbox->id]), $set['url']);
        $this->assertSame(Telegram::webhookSecret($mailbox), $set['secret_token']);

        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', ['token' => '******']);
        $this->assertCount(1, $this->sentTo('deleteWebhook'));
        $this->assertFalse(Telegram::settings($this->mailbox->fresh())['enabled']);
    }

    public function testWrongTokenIsShown()
    {
        $this->fakeTelegram(['getMe' => $this->error(401, 'Unauthorized')]);

        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', ['enabled' => 1, 'token' => 'wrong'])
            ->assertSessionHas('flash_error_floating', 'Telegram: Unauthorized');
        $this->actingAs($this->admin)->get('/mailbox/'.$this->mailbox->id.'/telegram')->assertSee('Unauthorized');
    }

    public function testExistingBotsAreConnectedByTheUpdate()
    {
        if (!class_exists('CreateTelegramUpdatesTable')) {
            require base_path('database/migrations/2026_10_05_010101_create_telegram_updates_table.php');
        }
        $other = $this->createMailbox();

        \CreateTelegramUpdatesTable::registerWebhooks();

        $this->assertSame([route('telegram.webhook', ['mailbox_id' => $this->mailbox->id])], $this->sentTo('setWebhook')->pluck('url')->all());
        $this->assertFalse(Telegram::isEnabled($other));
    }

    // Customers' messages.

    public function testWebhookNeedsTheSecret()
    {
        $this->postUpdate($this->update(), 'wrong')->assertStatus(403);
        $this->withHeaders([])->postJson('/telegram/webhook/'.$this->mailbox->id, $this->update())->assertStatus(403);
        $this->assertNull($this->conversation());
    }

    public function testMessageStartsAConversation()
    {
        $this->postUpdate($this->update())->assertStatus(200);

        $conversation = $this->conversation();
        $this->assertSame(Telegram::CHANNEL, (int) $conversation->channel);
        $this->assertSame('Hello, my app <does not> work', $conversation->subject);
        $thread = $conversation->threads()->first();
        $this->assertSame('Hello, my app &lt;does not&gt; work', $thread->body);

        $customer = $conversation->customer;
        $this->assertSame('Casey Lee', $customer->getFullName());
        $this->assertSame('555', (string) $customer->getChannelId(Telegram::CHANNEL));
        $this->assertSame('caseylee', $customer->getSocialProfiles()[0]['value']);

        $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)
            ->assertStatus(200)->assertSee('Hello, my app &lt;does not&gt; work', false)->assertSee('Telegram');
    }

    public function testNextMessagesJoinTheConversationOnce()
    {
        $this->postUpdate($this->update());
        $update = $this->update(['text' => 'Still broken']);
        $this->postUpdate($update)->assertStatus(200);
        // Telegram sends an update again when it didn't get our answer.
        $this->postUpdate($update)->assertStatus(200);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(2, $this->conversation()->threads()->count());
    }

    public function testStartCommand()
    {
        Telegram::saveSettings($this->mailbox, ['auto_reply' => 'Welcome to support!', 'ignore_start' => true]);
        $this->postUpdate($this->update(['text' => '/start']));
        $this->assertNull($this->conversation());
        $this->assertSame('Welcome to support!', $this->sentTo('sendMessage')->last()['text']);

        Telegram::saveSettings($this->mailbox, ['ignore_start' => false]);
        $this->postUpdate($this->update(['text' => '/start']));
        $this->assertSame('/start', $this->conversation()->threads()->first()->body);
        $this->assertCount(2, $this->sentTo('sendMessage'));
    }

    public function testFilesAreSavedWithTheMessage()
    {
        $this->postUpdate($this->update([
            'text'     => null,
            'caption'  => 'My screen',
            'photo'    => [['file_id' => 'small', 'width' => 90], ['file_id' => 'large', 'width' => 1280]],
            'document' => ['file_id' => 'doc', 'file_name' => 'log.txt'],
        ]));

        $thread = $this->conversation()->threads()->first();
        $this->assertSame('My screen', $thread->body);
        $this->assertEquals(['large.jpg', 'log.txt'], $thread->attachments->pluck('file_name')->sort()->values()->all());
        $this->assertSame('FILE-large.jpg', $thread->attachments->firstWhere('file_name', 'large.jpg')->getFileContents());
        // The token is in Telegram's file URLs: never stored.
        $this->assertStringNotContainsString(self::TOKEN, json_encode($thread->attachments));
    }

    public function testFileTooLargeIsNoted()
    {
        $this->fakeTelegram(['getFile' => $this->ok(['file_id' => 'big', 'file_size' => 30000000, 'file_path' => 'videos/big.mp4'])]);

        $this->postUpdate($this->update(['text' => null, 'video' => ['file_id' => 'big']]));

        $thread = $this->conversation()->threads()->first();
        $this->assertStringContainsString('A file could not be downloaded from Telegram', $thread->body);
        $this->assertSame(0, $thread->attachments()->count());
    }

    public function testEditedMessageIsAdded()
    {
        $this->postUpdate($this->update(['text' => 'Hello']));
        $this->postUpdate($this->update(['text' => 'Hello, I fixed it'], 'edited_message'));

        $this->assertSame('<p><em>Edited message</em></p>Hello, I fixed it', $this->conversation()->threads()->orderBy('id', 'desc')->first()->body);
    }

    public function testLocationAndContact()
    {
        $this->postUpdate($this->update(['text' => null, 'location' => ['latitude' => 52.37, 'longitude' => 4.89]]));
        $this->postUpdate($this->update(['text' => null, 'contact' => ['first_name' => 'Sam', 'phone_number' => '+31 6 1234']]));

        $bodies = $this->conversation()->threads()->orderBy('id')->pluck('body')->all();
        $this->assertStringContainsString('https://www.openstreetmap.org/?mlat=52.37&amp;mlon=4.89', $bodies[0]);
        $this->assertSame('Contact: Sam, +31 6 1234', $bodies[1]);
    }

    public function testGroupsAndBotsAreIgnored()
    {
        $this->postUpdate($this->update(['chat' => ['id' => -100, 'type' => 'group']]))->assertStatus(200);
        $this->postUpdate($this->update(['from' => ['id' => 9, 'is_bot' => true, 'first_name' => 'Bot']]))->assertStatus(200);

        $this->assertNull($this->conversation());
    }

    public function testCustomerFoundByTheirUsername()
    {
        $customer = $this->createCustomer('casey@customer.example.org');
        $customer->setSocialProfiles([['type' => Customer::SOCIAL_TYPE_TELEGRAM, 'value' => '@CaseyLee']]);
        $customer->save();

        $this->postUpdate($this->update());

        $this->assertSame($customer->id, $this->conversation()->customer_id);
        $this->assertSame('555', (string) $customer->getChannelId(Telegram::CHANNEL));

        // A customer already on Telegram is not taken for another user.
        $this->postUpdate($this->update(['from' => ['id' => 777, 'first_name' => 'Other', 'username' => 'caseylee']]));
        $this->assertNotSame($customer->id, $this->conversation()->customer_id);
    }

    public function testFailedUpdateIsSentAgainByTelegram()
    {
        \Eventy::addFilter('conversation.created_by_customer', function () {
            throw new \RuntimeException('Database gone');
        });
        $update = $this->update();

        $this->postUpdate($update)->assertStatus(500);
        $this->assertSame(0, \DB::table('telegram_updates')->where('update_id', $update['update_id'])->count());
    }

    public function testDisabledMailboxIgnoresUpdates()
    {
        Telegram::saveSettings($this->mailbox, ['enabled' => false]);

        $this->postUpdate($this->update())->assertStatus(200);
        $this->assertNull($this->conversation());
    }

    // Replies.

    public function testReplyIsSentWithFormattingAndFiles()
    {
        $reply = $this->reply('<p>Hi <b>Casey</b>,</p><ul><li>Restart <a href="https://x.org">the app</a></li></ul>');
        $file = \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);
        $reply->has_attachments = true;
        $reply->save();

        $this->runQueue();

        $message = $this->sentTo('sendMessage')->last();
        $this->assertSame('555', (string) $message['chat_id']);
        $this->assertSame('HTML', $message['parse_mode']);
        $this->assertSame("Hi <b>Casey</b>,\n\n• Restart <a href=\"https://x.org\">the app</a>", $message['text']);
        $this->assertCount(1, $this->sentTo('sendDocument'));
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $reply->fresh()->send_status);
        $this->assertTrue($reply->fresh()->isSendStatusSuccess());
        $this->assertNotNull($file);
    }

    public function testUndoDeletesTheReplyFromTheChat()
    {
        $this->fakeTelegram(['sendMessage' => $this->ok(['message_id' => 71]), 'sendDocument' => $this->ok(['message_id' => 72])]);
        $reply = $this->reply();
        \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);
        $this->runQueue();
        $this->assertTrue($reply->fresh()->isSendStatusSuccess());

        \Session::start();
        $this->actingAs($this->agent)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => csrf_token()])->assertRedirect();

        $this->assertEquals([71, 72], $this->sentTo('deleteMessage')->pluck('message_id')->all());
        $this->assertSame('555', (string) $this->sentTo('deleteMessage')->first()['chat_id']);
        $reply = $reply->fresh();
        $this->assertSame(Thread::STATE_DRAFT, (int) $reply->state);
        $this->assertFalse($reply->isSendStatusSuccess());
    }

    public function testUndoBeforeSendingSendsNothing()
    {
        $reply = $this->reply();
        \Session::start();
        $this->actingAs($this->agent)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => csrf_token()]);

        $this->runQueue();

        $this->assertCount(0, $this->sentTo('sendMessage'));
        $this->assertCount(0, $this->sentTo('deleteMessage'));
    }

    public function testFormattingTelegramRefusesIsSentAsPlainText()
    {
        $this->fakeTelegram(['sendMessage' => function (HttpRequest $request) {
            return isset($request['parse_mode']) ? $this->error(400, "Bad Request: can't parse entities") : $this->ok(['message_id' => 2]);
        }]);
        $reply = $this->reply('<p>Hi <b>Casey</b></p>');

        $this->runQueue();

        $this->assertSame('Hi Casey', $this->sentTo('sendMessage')->last()['text']);
        $this->assertTrue($reply->fresh()->isSendStatusSuccess());
    }

    public function testBlockedBotShowsReplyNotSentAndReopens()
    {
        $this->fakeTelegram(['sendMessage' => $this->error(403, 'Forbidden: bot was blocked by the user')]);
        $reply = $this->reply();
        $conversation = $reply->conversation;
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        $this->runQueue();

        $reply = $reply->fresh();
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->send_status);
        $this->assertSame('Forbidden: bot was blocked by the user', $reply->getSendStatusData()['msg']);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertNotNull($reply->getFailedJobId());

        // Retry, once the customer unblocked the bot.
        $this->fakeTelegram();
        config(['queue.default' => 'sync']);
        $this->assertSame('success', $this->postAjax($this->agent, '/conversation/ajax', ['action' => 'retry_send', 'thread_id' => $reply->id])->json()['status']);
        $this->runQueue();
        $this->assertTrue($reply->fresh()->isSendStatusSuccess());
    }

    public function testTemporaryErrorIsRetriedWithoutRepeatingParts()
    {
        $this->fakeTelegram(['sendDocument' => $this->error(502, 'Bad Gateway')]);
        $reply = $this->reply();
        \App\Attachment::create('steps.pdf', 'application/pdf', null, 'PDF-DATA', null, false, $reply->id);

        $this->runQueue();
        $this->assertFalse($reply->fresh()->isSendStatusSuccess());
        $this->assertNotNull($reply->fresh()->getQueuedJobId());

        $this->fakeTelegram();
        $this->runQueue();

        // The text went out the first time: only the file now.
        $this->assertCount(0, $this->sentTo('sendMessage'));
        $this->assertCount(1, $this->sentTo('sendDocument'));
        $this->assertTrue($reply->fresh()->isSendStatusSuccess());
    }

    public function testUnsentRepliesAreFoundByCheckOutgoing()
    {
        $reply = $this->reply();
        \DB::table('jobs')->where('queue', 'emails')->delete();
        foreach ($reply->conversation->threads as $thread) {
            $thread->timestamps = false;
            $thread->created_at = $thread->created_at->subMinutes(20);
            $thread->saveQuietly();
        }

        $this->artisan('tallport:check-outgoing', ['--fix' => true])
            ->expectsOutputToContain('thread '.$reply->id)
            ->assertExitCode(0);
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
    }

    // Less common messages and failures.

    protected function telegramLog()
    {
        return \App\ActivityLog::where('log_name', Telegram::LOG)->orderBy('id')->pluck('description')->all();
    }

    public function testNewCustomersProfilePhotoIsSaved()
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        $this->fakeTelegram([
            'getUserProfilePhotos' => $this->ok(['total_count' => 1, 'photos' => [[['file_id' => 'p-small'], ['file_id' => 'p-large']]]]),
            'file' => Http::response($png),
        ]);

        $this->postUpdate($this->update());

        $customer = $this->conversation()->customer;
        $this->assertNotEmpty($customer->photo_url);
        $this->assertTrue(\Storage::disk('local')->exists(Customer::PHOTO_DIRECTORY.'/'.$customer->photo_url));
        $this->assertSame('p-large', $this->sentTo('getFile')->last()['file_id']);
    }

    public function testFailedProfilePhotoIsOnlyLogged()
    {
        $this->fakeTelegram(['getUserProfilePhotos' => $this->ok(['total_count' => 1, 'photos' => [[['file_id' => 'gone']]]]), 'getFile' => $this->ok(['file_id' => 'gone'])]);

        $this->postUpdate($this->update())->assertStatus(200);

        $this->assertNull($this->conversation()->customer->photo_url);
        $this->assertContains('('.$this->mailbox->name.') Profile photo of Telegram user 555 not saved: The file is not available.', $this->telegramLog());
    }

    public function testFileThatCanNotBeDownloadedIsNoted()
    {
        $this->fakeTelegram(['file' => Http::response('', 404)]);

        $this->postUpdate($this->update(['text' => null, 'document' => ['file_id' => 'doc', 'file_name' => 'report.pdf']]));

        $this->assertStringContainsString('A file could not be downloaded from Telegram: report.pdf (Could not download the file: HTTP 404)', html_entity_decode($this->conversation()->threads()->first()->body));
    }

    public function testFileWithoutCaptionIsNamedInTheBody()
    {
        $this->postUpdate($this->update(['text' => null, 'document' => ['file_id' => 'doc', 'file_name' => 'log & trace.txt']]));

        $thread = $this->conversation()->threads()->first();
        $this->assertSame('log &amp; trace.txt', $thread->body);
        $this->assertSame('log & trace.txt', $this->conversation()->subject);
    }

    public function testVenueAndUnsupportedMessages()
    {
        $this->postUpdate($this->update(['text' => null, 'venue' => ['title' => 'Café <Noord>', 'address' => 'Dam 1, Amsterdam'], 'location' => ['latitude' => 52.37, 'longitude' => 4.89]]));
        $this->assertStringStartsWith('Café &lt;Noord&gt;, Dam 1, Amsterdam', $this->conversation()->threads()->first()->body);

        $this->postUpdate($this->update(['text' => null, 'sticker' => ['emoji' => '👍']]))->assertStatus(200);
        $this->assertSame(1, $this->conversation()->threads()->count());
        $this->assertStringContainsString('Message of a type Tallport does not take (message_id, date, chat, from, text, sticker) from Telegram user 555 ignored.', implode("\n", $this->telegramLog()));
    }

    public function testKnownCustomerGetsTheirTelegramUsername()
    {
        $this->postUpdate($this->update(['from' => ['id' => 555, 'is_bot' => false, 'first_name' => 'Casey']]));
        $customer = $this->conversation()->customer;
        $this->assertSame([], $customer->getSocialProfiles());

        $this->postUpdate($this->update());
        $this->postUpdate($this->update());

        $profiles = $customer->fresh()->getSocialProfiles();
        $this->assertCount(1, $profiles);
        $this->assertSame('caseylee', $profiles[0]['value']);
    }

    public function testStartAutoReplyThatFailsIsLogged()
    {
        Telegram::saveSettings($this->mailbox, ['auto_reply' => 'Welcome!', 'ignore_start' => true]);
        $this->fakeTelegram(['sendMessage' => $this->error(403, 'Forbidden: bot was blocked by the user')]);

        $this->postUpdate($this->update(['text' => '/start']))->assertStatus(200);

        $this->assertContains('('.$this->mailbox->name.') Auto reply to /start not sent: Forbidden: bot was blocked by the user', $this->telegramLog());
    }

    public function testClientNeedsATokenAndHidesIt()
    {
        $this->expectExceptionMessage('The bot token is not set.');
        try {
            (new \App\Telegram\Client(''))->getMe();
        } finally {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(function () {
                throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect to api.telegram.org/bot'.self::TOKEN.'/getMe');
            });
            try {
                (new \App\Telegram\Client(self::TOKEN))->getMe();
                $this->fail('A failed connection must throw.');
            } catch (\App\Telegram\TelegramException $e) {
                $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
                $this->assertStringContainsString('Failed to connect', $e->getMessage());
            }
        }
    }
}
