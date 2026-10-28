<?php

namespace Tests\Feature;

use App\Ai\Agents\ChatIntake;
use App\Ai\Agents\ReplyDrafter;
use App\Ai\ChatAgent;
use App\Conversation;
use App\Customer;
use App\Misc\ChatConversations;
use App\Option;
use App\Thread;
use App\WebChat\WebChat;
use Tests\FeatureTestCase;

/**
 * The AI agent (App\Ai\ChatAgent, AI faked): takes new chats where it's on, without notifying
 * anyone, asks, then hands the chat to the team with a reply drafted for them; lets go to a person.
 */
class AiChatAgentTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;
    protected $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Forma']);
        // Alex hears of new chats, and of what happens in assigned ones.
        foreach ([\App\Subscription::EVENT_NEW_CONVERSATION, \App\Subscription::EVENT_CUSTOMER_REPLIED_TO_ASSIGNED, \App\Subscription::EVENT_USER_REPLIED_TO_ASSIGNED] as $event) {
            \App\Subscription::create(['user_id' => $this->agent->id, 'medium' => \App\Subscription::MEDIUM_EMAIL, 'event' => $event]);
        }
        WebChat::saveSettings($this->mailbox, ['enabled' => true, 'widget' => 'forma', 'origins' => []]);
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_message = 'We will answer within a day.';
        $this->mailbox->save();
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations']]);
        Option::set('aiassistant.mailbox_agent_channels', [$this->mailbox->id => ['webchat']]);
        // Its silence checks only when a test runs them (the sync queue doesn't wait).
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\AiChatAgentSilence::class]);
        ReplyDrafter::fake(fn () => $this->draft());
        $this->customer = Customer::createWithoutEmail(['channel' => WebChat::CHANNEL, 'channel_id' => 'v1', 'first_name' => 'Casey']);
    }

    /**
     * The team's draft at handover (Agents\ReplyDrafter's answer).
     */
    protected function draft()
    {
        return [
            'draft'              => 'Hi Casey, I’m sorry order 1042 hasn’t arrived. I’m checking with the carrier now.',
            'translation'        => '',
            'language'           => 'en',
            'confidence'         => 'medium',
            'documentation_urls' => [],
            'staff_notes'        => ['Check order 1042 in the shop.'],
        ];
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function answer(array $values = [])
    {
        return array_merge([
            'action'      => 'ask',
            'message'     => 'Before I hand this issue off to my human colleagues, please tell me what you need help with.',
            'has_request' => false,
            'subject'     => '',
            'language'    => 'en',
            'translation' => '',
        ], $values);
    }

    /**
     * The customer writes in the website chat.
     */
    protected function write($text, $conversation = null)
    {
        $received = ChatConversations::receive($conversation, $this->customer, [
            'subject' => $text, 'mailbox_id' => $this->mailbox->id, 'source_type' => Conversation::SOURCE_TYPE_WEB, 'channel' => WebChat::CHANNEL,
        ], ['body' => e($text)]);
        \App\Subscription::processEvents();

        return $received['conversation']->fresh();
    }

    protected function messages(Conversation $conversation, $type = Thread::TYPE_MESSAGE)
    {
        return $conversation->threads()->where('type', $type)->orderBy('id')->pluck('body')->all();
    }

    public function testItAsksThenHandsTheChatToTheTeam()
    {
        $prompts = [];
        ChatIntake::fake(function ($prompt) use (&$prompts) {
            $prompts[] = (string) $prompt;

            return count($prompts) == 1 ? $this->answer() : $this->answer([
                'action' => 'hand_over', 'message' => 'Thanks! My human colleagues will reply here.', 'has_request' => true, 'subject' => 'Order 1042 hasn’t arrived',
            ]);
        })->preventStrayPrompts();

        // A greeting only: Assigned to the AI, its question (the auto reply goes out as always), nobody told.
        $conversation = $this->write('hello');
        $ai = ChatAgent::user();
        $this->assertSame([$ai->id, 'AI', \App\User::TYPE_ROBOT, \App\User::STATUS_DELETED], [(int) $conversation->user_id, $ai->getFullName(), (int) $ai->type, (int) $ai->status]);
        $this->assertSame(['<p>Before I hand this issue off to my human colleagues, please tell me what you need help with.</p>'], $this->messages($conversation));
        $this->assertTrue($conversation->threads()->where('action_type', Thread::ACTION_TYPE_AUTO_REPLY)->exists());
        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));
        $this->assertSame(1, $conversation->getMeta(ChatAgent::META)['questions']);
        $this->assertStringContainsString('hello', $prompts[0]);
        // Sent at once (no Undo), for the website chat's window.
        $this->assertEquals(\App\SendLog::STATUS_ACCEPTED, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first()->send_status);

        // The customer says what they need: handed over with a reply drafted for the team (no note),
        // unassigned, people told.
        $conversation = $this->write('My order 1042 didn\'t arrive', $conversation);
        $this->assertStringContainsString('My order 1042', $prompts[1]);
        $this->assertNull($conversation->user_id);
        $this->assertSame('Order 1042 hasn’t arrived', $conversation->subject);
        $this->assertSame('<p>Thanks! My human colleagues will reply here.</p>', $this->messages($conversation)[1]);
        $this->assertSame([], $this->messages($conversation, Thread::TYPE_NOTE));
        $this->assertSame([$this->draft()['draft'], ['Check order 1042 in the shop.']], [ChatAgent::handoverDraft($conversation, $this->agent)['draft'], ChatAgent::handoverDraft($conversation, $this->agent)['staff_notes']]);
        $this->assertTrue($conversation->threads()->where('action_type', Thread::ACTION_TYPE_USER_CHANGED)->where('created_by_user_id', $ai->id)->exists());
        $this->assertNotEmpty($this->sentEmailsTo($this->agent->email));
        $this->assertTrue($conversation->getMeta(ChatAgent::META)['done']);
        $this->assertSame('Agent', \App\Ai\Usage::featureName(\App\Ai\Usage::FEATURE_AGENT));

        // Then it's the team's: the AI stays out.
        $this->write('Hello?', $conversation);
        $this->assertCount(2, $prompts);
    }

    public function testAPersonReplyingTakesTheChat()
    {
        ChatIntake::fake([$this->answer()])->preventStrayPrompts();
        $conversation = $this->write('hello');

        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi, Alex here.</p>', 'user_id' => ChatAgent::userId()]);

        $conversation = $conversation->fresh();
        $this->assertFalse(ChatAgent::holds($conversation));
        $this->assertTrue($conversation->getMeta(ChatAgent::META)['done']);
        // The customer's next message is for the team.
        $this->write('I need help with my order', $conversation);
        $this->assertCount(2, $this->messages($conversation));
    }

    /**
     * The silence checks run for real here: the sync queue runs each at once, as if the customer
     * never answered.
     */
    public function testSilence()
    {
        \Illuminate\Support\Facades\Bus::swap(\Illuminate\Support\Facades\Bus::getFacadeRoot()->dispatcher);
        ChatIntake::fake([
            $this->answer(['has_request' => true, 'message' => 'Which order is it?']),
            $this->answer(),
            $this->answer(['message' => 'What can we help you with?']),
        ])->preventStrayPrompts();

        // Not answering about a request: the team gets it, with a draft.
        $conversation = $this->write('I want a refund');
        $this->assertNull($conversation->user_id);
        $this->assertNotNull(ChatAgent::handoverDraft($conversation, $this->agent));
        $this->assertNotEmpty($this->sentEmailsTo($this->agent->email));

        // A greeting only: closed, still the AI's; it carries on when they write again.
        $this->customer = Customer::createWithoutEmail(['channel' => WebChat::CHANNEL, 'channel_id' => 'v2']);
        $conversation = $this->write('hi');
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        $this->assertTrue(ChatAgent::holds($conversation));
        $conversation = $this->write('hello again', $conversation);
        $this->assertSame('<p>What can we help you with?</p>', $this->messages($conversation)[1]);
        $this->assertTrue(ChatAgent::holds($conversation));
    }

    public function testFailingOrOutOfQuestionsItHandsOver()
    {
        // Failing on its first message: the team gets the chat.
        ChatIntake::fake(fn () => throw new \RuntimeException('Provider down'));
        $conversation = $this->write('hello');
        $this->assertNull($conversation->user_id);
        $this->assertSame(['<p>The AI couldn’t continue this conversation.</p>'], $this->messages($conversation, Thread::TYPE_NOTE));
        $this->assertNull(ChatAgent::handoverDraft($conversation, $this->agent));
        $this->assertNotEmpty($this->sentEmailsTo($this->agent->email));

        // Three questions at most: then handed over, without a fourth.
        $this->customer = Customer::createWithoutEmail(['channel' => WebChat::CHANNEL, 'channel_id' => 'v2']);
        ChatIntake::fake(array_fill(0, 4, $this->answer()))->preventStrayPrompts();
        $conversation = $this->write('hi');
        $question = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        foreach (['hi?', 'hey', 'hello'] as $text) {
            $conversation = $this->write($text, $conversation);
            // A question that was answered: nothing when its time is up.
            if ($text == 'hi?') {
                ChatAgent::silence($conversation, $question->id);
                $this->assertSame([Conversation::STATUS_ACTIVE, true], [(int) $conversation->fresh()->status, ChatAgent::holds($conversation->fresh())]);
            }
        }
        $this->assertNull($conversation->user_id);
        $this->assertCount(3, $this->messages($conversation));
        $this->assertNotNull(ChatAgent::handoverDraft($conversation, $this->agent));
    }

    /**
     * Its draft at handover: whoever may draft finds it when they open the chat, as Draft with AI's,
     * until someone replies; not while they have a draft of their own there.
     */
    public function testTheTeamFindsItsDraft()
    {
        ChatIntake::fake([$this->answer(['action' => 'hand_over', 'message' => 'Thanks! My human colleagues will reply here.', 'has_request' => true])])->preventStrayPrompts();
        $conversation = $this->write('My order 1042 didn\'t arrive');
        $this->assertNull($conversation->user_id);
        $draft = ChatAgent::handoverDraft($conversation, $this->agent);
        $this->assertSame(['medium', 'en'], [$draft['confidence'], $draft['translation_language']]);

        $page = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id);
        for ($i = 0; $i < 3 && $page->isRedirect(); $i++) {
            $page = $this->actingAs($this->agent)->get($page->headers->get('Location'));
        }
        $page->assertOk()->assertSee('Check order 1042 in the shop.');

        $own = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Mine', ['source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB], false);
        $own->state = Thread::STATE_DRAFT;
        $own->created_by_user_id = $this->agent->id;
        $own->save();
        $this->assertNull(ChatAgent::handoverDraft($conversation, $this->agent));
        $this->assertNotNull(ChatAgent::handoverDraft($conversation, $this->admin));

        $this->sendReply($this->admin, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => 'Checking with the carrier.']);
        $this->assertNull(ChatAgent::handoverDraft($conversation->fresh(), $this->admin));
    }

    /**
     * No draft (the sources don't support an answer, or drafting failed): a note says why.
     */
    public function testWithoutADraftANoteSaysWhy()
    {
        $hand_over = $this->answer(['action' => 'hand_over', 'message' => 'Thanks! My human colleagues will reply here.', 'has_request' => true]);
        ChatIntake::fake([$hand_over, $hand_over])->preventStrayPrompts();
        ReplyDrafter::fake(fn () => ['draft' => '', 'translation' => '', 'language' => 'en', 'confidence' => 'low', 'documentation_urls' => [], 'staff_notes' => ['Nothing in the documentation about missing orders.']]);
        $conversation = $this->write('My order 1042 didn\'t arrive');
        $this->assertNull(ChatAgent::handoverDraft($conversation, $this->agent));
        $this->assertSame(['<p>The AI couldn’t draft a reply.</p><p>Nothing in the documentation about missing orders.</p>'], $this->messages($conversation, Thread::TYPE_NOTE));

        ReplyDrafter::fake(fn () => throw new \RuntimeException('Provider down'));
        $this->customer = Customer::createWithoutEmail(['channel' => WebChat::CHANNEL, 'channel_id' => 'v2']);
        $conversation = $this->write('Where is my order 1043?');
        $this->assertNull($conversation->user_id);
        $this->assertSame(['<p>The AI couldn’t draft a reply.</p>'], $this->messages($conversation, Thread::TYPE_NOTE));
    }

    /**
     * Email like the chats: its question is emailed at once, the customer's answer by email hands
     * over, and the email keeps its subject. Auto-responders are left to the team.
     */
    public function testEmailToo()
    {
        Option::set('aiassistant.mailbox_agent_channels', [$this->mailbox->id => ['email']]);
        Option::$cache = [];
        ChatIntake::fake([
            $this->answer(['message' => 'Before I hand this issue off to my human colleagues, please tell me your order number.', 'has_request' => true]),
            $this->answer(['action' => 'hand_over', 'message' => 'Thanks! My human colleagues will reply to this email.', 'subject' => 'Order 1042 missing']),
        ])->preventStrayPrompts();
        $email = fn (array $options = []) => $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Where is my order?', 'message_id' => 'first@customer.example.org',
        ], $options)));

        $email(['body' => 'Hello, my order didn’t arrive.']);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->firstOrFail();
        $this->assertTrue(ChatAgent::holds($conversation));
        // The auto reply (as always), and its question.
        $sent = fn () => implode("\n", array_map(fn ($message) => $message->getBody(), $this->sentEmailsTo('casey@customer.example.org')));
        $this->assertCount(2, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertStringContainsString('We will answer within a day.', $sent());
        $this->assertStringContainsString('please tell me your order number', $sent());
        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));

        $email(['message_id' => 'second@customer.example.org', 'in_reply_to' => 'first@customer.example.org', 'subject' => 'Re: Where is my order?', 'body' => 'It’s order 1042.']);
        $conversation = $conversation->fresh();
        $this->assertNull($conversation->user_id);
        $this->assertSame('Where is my order?', $conversation->subject);
        $this->assertStringContainsString('reply to this email', $sent());
        $this->assertNotEmpty($this->sentEmailsTo($this->agent->email));

        // An auto-responder's: the team's.
        $email(['from' => 'Robin <robin@customer.example.org>', 'message_id' => 'away@customer.example.org', 'subject' => 'Out of office', 'headers' => ['Auto-Submitted' => 'auto-replied'], 'body' => 'I’m away.']);
        $this->assertNull(Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first()->user_id);
    }

    /**
     * A message in another language than the team's: the team reads it in theirs, the one sent beside it.
     */
    public function testTheTeamReadsItsMessagesInTheirLanguage()
    {
        ChatIntake::fake([$this->answer([
            'message' => 'こんにちは。人間の同僚に引き継ぐ前に、ご用件を教えてください。', 'language' => 'ja',
            'translation' => 'Hello. Before I hand this over to my human colleagues, please tell me what you need.',
        ])])->preventStrayPrompts();

        $conversation = $this->write('こんにちは');
        $thread = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->firstOrFail();
        $data = \App\Ai\Summaries::data($thread);
        $this->assertSame(['Hello. Before I hand this over to my human colleagues, please tell me what you need.', 'en', 'ja'], [$data['translations']['en'], $data['written_in'], $data['sent_in']]);

        $page = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id);
        for ($i = 0; $i < 3 && $page->isRedirect(); $i++) {
            $page = $this->actingAs($this->agent)->get($page->headers->get('Location'));
        }
        $page->assertSee('Before I hand this over to my human colleagues, please tell me what you need.')->assertSee('Sent to the customer in Japanese');
    }

    /**
     * The customer sees it writing while it does (the website chat's window asks; Telegram is told),
     * and no longer once its message is there.
     */
    public function testTheCustomerSeesItWriting()
    {
        $typing = [];
        ChatIntake::fake(function () use (&$typing) {
            $typing[] = ChatAgent::isTyping(Conversation::orderBy('id', 'desc')->first());

            return $this->answer();
        })->preventStrayPrompts();

        $conversation = $this->write('hello');
        $this->assertSame([true], $typing);
        $this->assertFalse(ChatAgent::isTyping($conversation));

        Option::set('aiassistant.mailbox_agent_channels', [$this->mailbox->id => ['telegram']]);
        Option::$cache = [];
        \App\Telegram\Telegram::saveSettings($this->mailbox, ['enabled' => true, 'token' => '123:abc']);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendReplyToTelegram::class]);
        $actions = [];
        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory());
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$actions) {
            $actions[] = [basename(parse_url($request->url(), PHP_URL_PATH)), $request['chat_id'], $request['action']];

            return \Illuminate\Support\Facades\Http::response(['ok' => true, 'result' => true]);
        });
        $this->customer = Customer::createWithoutEmail(['channel' => \App\Telegram\Telegram::CHANNEL, 'channel_id' => '555']);
        ChatConversations::receive(null, $this->customer, [
            'subject' => 'Hi', 'mailbox_id' => $this->mailbox->id, 'source_type' => Conversation::SOURCE_TYPE_WEB, 'channel' => \App\Telegram\Telegram::CHANNEL,
        ], ['body' => 'Hi']);
        $this->assertSame([['sendChatAction', '555', 'typing']], $actions);
        $this->assertSame([true, true], $typing);
    }

    /**
     * Its messages go out at once on every channel; a person's wait for Undo Send.
     */
    public function testItsMessagesGoOutAtOnce()
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendReplyToTelegram::class]);
        $this->customer = Customer::createWithoutEmail(['channel' => \App\Telegram\Telegram::CHANNEL, 'channel_id' => '555']);
        $conversation = ChatConversations::receive(null, $this->customer, [
            'subject' => 'Hi', 'mailbox_id' => $this->mailbox->id, 'source_type' => Conversation::SOURCE_TYPE_WEB, 'channel' => \App\Telegram\Telegram::CHANNEL,
        ], ['body' => 'Hi'])['conversation'];

        $conversation->createUserThread(ChatAgent::user(), '<p>Before I hand this issue off to my human colleagues, please tell me what you need.</p>');
        $conversation->createUserThread($this->agent, '<p>Alex here.</p>');

        $delays = [];
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendReplyToTelegram::class, function ($job) use (&$delays) {
            $delays[] = (int) round(now()->diffInSeconds($job->delay));

            return true;
        });
        $this->assertSame([0, $this->mailbox->undoSeconds()], $delays);
    }

    public function testOnlyWhereItsOn()
    {
        ChatIntake::fake()->preventStrayPrompts();
        Option::set('aiassistant.mailbox_agent_channels', []);
        Option::$cache = [];

        $conversation = $this->write('hello');

        $this->assertNull($conversation->user_id);
        $this->assertTrue($conversation->threads()->where('action_type', Thread::ACTION_TYPE_AUTO_REPLY)->exists());
        $this->assertNotEmpty($this->sentEmailsTo($this->agent->email));
    }

    public function testTheMailboxsAiPageTurnsItOn()
    {
        Option::set('aiassistant.mailbox_agent_channels', []);
        Option::$cache = [];
        $this->actingAs($this->admin)->get(route('mailboxes.ai', ['id' => $this->mailbox->id]))
            ->assertOk()->assertSee('Agent')->assertSee('name="agent_channels[]"', false)->assertSee('value="email"', false)->assertSee('value="webchat"', false)->assertSee('Not set up');

        $this->postForm($this->admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['agent_channels' => ['webchat', 'telegram', 'sms']])
            ->assertSessionHasNoErrors();
        Option::$cache = [];
        $this->assertSame(['webchat', 'telegram'], ChatAgent::channels($this->mailbox));
        // With the other features off, it's what makes AI On for the mailbox.
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => \App\Ai\Settings::FEATURES]);
        Option::$cache = [];
        $this->assertStringStartsWith('On', \App\Ai\Settings::mailboxSummary($this->mailbox));

        $this->postForm($this->admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), []);
        Option::$cache = [];
        $this->assertSame([], ChatAgent::channels($this->mailbox));
        $this->assertSame('Off', \App\Ai\Settings::mailboxSummary($this->mailbox));
    }
}
