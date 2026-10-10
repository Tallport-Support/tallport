<?php

namespace Tests\Feature;

use App\Ai\Agents\ReplyDrafter;
use App\Ai\CustomerContext;
use App\Ai\Document;
use App\Ai\Documents;
use App\Ai\DraftJob;
use App\Conversation;
use App\Option;
use App\Thread;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\FeatureTestCase;

/**
 * AI Assistant reply drafts (AI faked): who can draft, what the draft is
 * made from, the daily limit, customer context and keeping the translation.
 */
class AiDraftsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        Option::$cache = [];

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
            'body' => "Hallo,\n\nMijn Android app start niet.",
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        // Configured after receiving, so no summary or translation is made.
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations']]);
        ReplyDrafter::fake([$this->draft()]);
        Embeddings::fake(function ($prompt) {
            return array_map(function ($text) {
                return [str_contains(strtolower($text), 'android') ? 1.0 : 0.0, 0.1];
            }, $prompt->inputs);
        });
    }

    protected function draft(array $values = [])
    {
        return array_merge([
            'draft'              => "Hallo Casey,\n\n- Herstart de app\n- **Update** Android",
            'translation'        => "Hello Casey,\n\n- Restart the app\n- Update Android",
            'language'           => 'nl',
            'confidence'         => 'medium',
            'documentation_urls' => ['https://docs.example.org/android', 'javascript:alert(1)'],
            'staff_notes'        => ['Check the app version.'],
        ], $values);
    }

    protected function getConversationPage($user)
    {
        $response = $this->actingAs($user)->get('/conversation/'.$this->conversation->id);
        for ($i = 0; $i < 3 && $response->isRedirect(); $i++) {
            $response = $this->actingAs($user)->get($response->headers->get('Location'));
        }

        return $response;
    }

    protected function requestDraft($user)
    {
        \Session::start();

        return $this->actingAs($user)->postJson('/ai-assistant/conversations/'.$this->conversation->id.'/draft-reply', ['_token' => csrf_token()]);
    }

    /**
     * A draft written into the browser: its server-sent events (the draft so far, then the
     * draft and its details or the error).
     */
    protected function draftEvents($user)
    {
        $response = $this->requestDraft($user)->assertStatus(200)->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

        return array_map(function ($event) {
            $this->assertStringStartsWith('data: ', $event);

            return json_decode(substr($event, 6), true);
        }, array_values(array_filter(explode("\n\n", $response->streamedContent()))));
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    public function testButtonOnlyWhereDraftingIsAllowed()
    {
        $this->getConversationPage($this->agent)->assertSee('ai-draft-action')->assertSee('tallportAiDraft', false)->assertSee('Drafting…');

        Option::set('aiassistant.drafts_per_day', 0);
        $this->getConversationPage($this->agent)->assertDontSee('ai-draft-action');

        Option::set('aiassistant.drafts_per_day', 5);
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['drafts']]);
        $this->getConversationPage($this->agent)->assertDontSee('ai-draft-action');
        $this->requestDraft($this->agent)->assertStatus(403);
    }

    /**
     * In a chat, Draft with AI is in the chat field too and writes into it (no card); with
     * chat translation the agent's version goes in, as it's translated on sending.
     */
    public function testInAChatTheDraftGoesIntoTheField()
    {
        $config = fn ($inline, $translated) => '{\\u0022inline\\u0022:'.($inline ? 'true' : 'false').',\\u0022translated\\u0022:'.($translated ? 'true' : 'false').'}';
        $this->assertStringContainsString($config(false, false), $this->getConversationPage($this->agent)->getContent());

        $this->conversation->channel = \App\Telegram\Telegram::CHANNEL;
        $this->conversation->save();
        $chat_page = $this->getConversationPage($this->agent)->getContent();
        $this->assertStringContainsString($config(true, false), $chat_page);
        $this->assertSame(2, substr_count($chat_page, 'ai-draft-action'), 'In the toolbar and the chat field.');

        Option::set('aiassistant.mailbox_chat_translation', [$this->mailbox->id => true]);
        \App\Ai\ChatTranslation::setCustomerLanguage($this->conversation->customer, 'zh-Hans', true);
        $this->assertStringContainsString($config(true, true), $this->getConversationPage($this->agent)->getContent());
    }

    public function testUserWithoutAccessCannotDraft()
    {
        $this->requestDraft($this->createUser())->assertStatus(403);
        ReplyDrafter::assertNeverPrompted();
    }

    /**
     * The draft is written into the browser as the AI writes it, then its translation and details.
     */
    public function testDraftIsWrittenAsItIsMade()
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id,
            'body' => '<p>Secret internal note</p>', 'is_note' => 1,
        ]);

        $this->freezeTime();
        $events = $this->draftEvents($this->agent);

        ReplyDrafter::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, 'Mijn Android app start niet.')
                && !str_contains($prompt->prompt, 'Secret internal note')
                && $prompt->agent->language == 'en';
        });
        // The draft so far (its start; the rest comes within 0.1 s, so it's held back).
        $this->assertSame(['draft' => 'Hallo'], $events[0]);
        $this->assertCount(2, $events);
        $this->assertEquals([
            'status'               => 'success',
            'draft_status'         => DraftJob::STATUS_COMPLETED,
            'draft'                => "Hallo Casey,\n\n- Herstart de app\n- **Update** Android",
            'translation'          => "Hello Casey,\n\n- Restart the app\n- Update Android",
            'translation_language' => 'en',
            'language'             => 'nl',
            'confidence'           => 'medium',
            'documentation_urls'   => ['https://docs.example.org/android'],
            'staff_notes'          => ['Check the app version.'],
            'documentation_status' => 'no_matches',
        ], array_diff_key($events[1], array_flip(['retrieved_documents', 'customer_context_status'])));
        // Kept, for the drafts per day.
        $draft_job = DraftJob::where('user_id', $this->agent->id)->first();
        $this->assertSame(DraftJob::STATUS_COMPLETED, $draft_job->status);
        $this->assertSame('medium', $draft_job->result['confidence']);
        $this->assertDatabaseHas('aiassistant_usage', ['conversation_id' => $this->conversation->id, 'feature' => 'draft']);
    }

    /**
     * Its translation is written too, after the draft (a chat translated on sending shows it in the field).
     */
    public function testTranslationIsWrittenAsItIsMade()
    {
        // Each moment a second later: every piece is passed on.
        $clock = now();
        \Illuminate\Support\Carbon::setTestNow(function () use (&$clock) {
            return $clock = $clock->copy()->addSecond();
        });
        $events = $this->draftEvents($this->agent);
        \Illuminate\Support\Carbon::setTestNow();

        $partial = array_slice($events, 0, -1);
        $translated = array_values(array_filter($partial, fn ($event) => isset($event['translation'])));
        $this->assertNotEmpty($translated);
        $this->assertArrayNotHasKey('translation', $partial[0]);
        foreach ($translated as $event) {
            $this->assertSame("Hallo Casey,\n\n- Herstart de app\n- **Update** Android", $event['draft']);
            $this->assertStringStartsWith($event['translation'], "Hello Casey,\n\n- Restart the app\n- Update Android");
        }
        $this->assertSame('success', end($events)['status']);
    }

    public function testAnInProgressDraftCannotRestoreDataAfterConversationDeletion()
    {
        ReplyDrafter::fake(function () {
            $this->conversation->deleteForever();

            return $this->draft(['draft' => 'Private reply']);
        });

        $events = $this->draftEvents($this->agent);
        $this->assertSame('Private reply', end($events)['draft']);

        $draft_job = DraftJob::where('user_id', $this->agent->id)->first();
        $this->assertSame(1, DraftJob::countToday($this->agent));
        $this->assertSame(DraftJob::STATUS_DELETED, $draft_job->status);
        $this->assertNull($draft_job->conversation_id);
        $this->assertNull($draft_job->result);
        $this->assertNull($draft_job->error_message);
        $this->assertNull($draft_job->error_detail);
    }

    public function testDraftCreatedJustAfterConversationDeletionKeepsOnlyTheQuotaCount()
    {
        DraftJob::creating(function () {
            $this->conversation->deleteForever();
        });
        try {
            $events = $this->draftEvents($this->agent);
        } finally {
            DraftJob::flushEventListeners();
        }

        $this->assertSame('success', end($events)['status']);
        $draft_job = DraftJob::where('user_id', $this->agent->id)->first();
        $this->assertSame(1, DraftJob::countToday($this->agent));
        $this->assertSame(DraftJob::STATUS_DELETED, $draft_job->status);
        $this->assertNull($draft_job->conversation_id);
        $this->assertNull($draft_job->result);
        $this->assertNull($draft_job->error_message);
        $this->assertNull($draft_job->error_detail);
    }

    /**
     * An answer that isn't the JSON asked for: the backup model writes the draft (shown again from the start).
     */
    public function testDraftFromTheBackupWhenThePrimaryFails()
    {
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-test'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-ant'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['drafts' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-5-mini'], 'backup' => ['provider' => 'p2', 'model' => 'claude-haiku-4-5']]]);
        Option::$cache = [];
        ReplyDrafter::fake(fn ($prompt, $attachments, $provider, $model) => $model == 'gpt-5-mini' ? 'Sorry, here is your draft: Hallo' : $this->draft(['draft' => 'Hallo van de backup']));

        $events = $this->draftEvents($this->agent);

        $this->assertSame('Hallo van de backup', end($events)['draft']);
        ReplyDrafter::assertPromptedTimes(2);
    }

    public function testInvalidCompletedDraftsUseTheBackupModel()
    {
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-test'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-ant'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['drafts' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-5-mini'], 'backup' => ['provider' => 'p2', 'model' => 'claude-haiku-4-5']]]);
        Option::$cache = [];
        $valid = $this->draft();
        $invalid = [
            $this->draft(['documentation_urls' => [42]]),
            $this->draft(['confidence' => 'certain']),
            $this->draft(['staff_notes' => 'Check the app version.']),
            $this->draft(['draft' => '  ']),
            $this->draft(['language' => '']),
        ];

        foreach ($invalid as $bad_answer) {
            $calls = 0;
            ReplyDrafter::fake(function () use (&$calls, $bad_answer, $valid) {
                return ++$calls == 1 ? $bad_answer : $valid;
            });

            [$answer] = (new ReplyDrafter('en'))->streamJson('Draft a reply');

            $this->assertSame($valid, $answer);
            $this->assertSame(2, $calls);
        }
    }

    public function testAiJobsHaveTheirOwnQueues()
    {
        \Queue::fake();
        Option::set('aiassistant.mailbox_features_off', []);

        \App\Jobs\AiSummarizeConversation::request($this->conversation, 'en');
        \Queue::assertPushedOn('ai', \App\Jobs\AiSummarizeConversation::class);
        \App\Jobs\AiTranslateThread::request($this->conversation->threads()->first(), 'de');
        \Queue::assertPushedOn('ai', \App\Jobs\AiTranslateThread::class);
        \App\Jobs\AiIndexDocument::dispatch(1);
        \Queue::assertPushedOn('ai', \App\Jobs\AiIndexDocument::class);
    }

    public function testDailyLimit()
    {
        $this->agent->ai_drafts_per_day = 1;
        $this->agent->save();

        $this->draftEvents($this->agent);
        $this->requestDraft($this->agent)->assertStatus(429)->assertJson(['msg' => 'You have made the most drafts allowed for today.']);
        ReplyDrafter::assertPromptedTimes(1);
    }

    public function testRunningDraftClaimsTheLastDailyAllowance()
    {
        $this->agent->ai_drafts_per_day = 1;
        $this->agent->save();

        $first = DraftJob::reserve($this->agent, $this->conversation);

        $this->assertSame(DraftJob::STATUS_RUNNING, $first->status);
        $this->assertNull(DraftJob::reserve($this->agent, $this->conversation));
        $this->requestDraft($this->agent)->assertStatus(429);
        $this->assertSame(1, DraftJob::countToday($this->agent));
        ReplyDrafter::assertNeverPrompted();
    }

    public function testInterruptedDraftIsMarkedFailedWithoutChangingRecentWork()
    {
        $old = DraftJob::reserve($this->agent, $this->conversation);
        \DB::table('aiassistant_draft_jobs')->where('id', $old->id)->update(['started_at' => now()->subMinutes(10)]);
        $recent = DraftJob::reserve($this->agent, $this->conversation);

        $this->assertSame(1, DraftJob::failAbandoned(now()->subMinutes(5)));
        $this->assertSame(DraftJob::STATUS_FAILED, $old->fresh()->status);
        $this->assertSame('interrupted', $old->fresh()->error_type);
        $this->assertMatchesRegularExpression('/^ID: [A-F0-9]{12}$/', $old->fresh()->error_detail);
        $this->assertSame(DraftJob::STATUS_RUNNING, $recent->fresh()->status);
        $this->assertSame(0, DraftJob::failAbandoned(now()->subMinutes(5)));
    }

    public function testAnInterruptedDraftCannotBeCompletedByALateStream()
    {
        ReplyDrafter::fake(function () {
            \DB::table('aiassistant_draft_jobs')->where('user_id', $this->agent->id)->update(['started_at' => now()->subMinutes(10)]);
            DraftJob::failAbandoned(now()->subMinutes(5));

            return $this->draft();
        });

        $events = $this->draftEvents($this->agent);

        $this->assertSame('error', end($events)['status']);
        $this->assertSame(DraftJob::STATUS_FAILED, DraftJob::where('user_id', $this->agent->id)->value('status'));
    }

    public function testFailedDraftIsReported()
    {
        $key = 'sk-secret-provider-key-0001';
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt($key), 'base_url' => '']]);
        Option::set('aiassistant.models', ['drafts' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-x']]]);
        Option::$cache = [];
        \Log::spy();
        ReplyDrafter::fake(function () use ($key) {
            throw new \RuntimeException('Provider down: '.$key.' Authorization: Bearer opaque-token-123');
        });

        [$error] = $this->draftEvents($this->agent);
        $this->assertSame(['status' => 'error', 'draft_status' => DraftJob::STATUS_FAILED, 'msg' => 'Could not draft a reply.'], array_diff_key($error, ['detail' => true]));
        $this->assertMatchesRegularExpression('/^ID: [A-F0-9]{12}$/', $error['detail']);
        $draft_job = DraftJob::where('user_id', $this->agent->id)->first();
        $this->assertSame(DraftJob::STATUS_FAILED, $draft_job->status);
        $this->assertSame($error['detail'], $draft_job->error_detail);
        $this->assertStringNotContainsString($key, json_encode($error).json_encode($draft_job->toArray()));
        $this->assertStringNotContainsString('opaque-token-123', json_encode($error).json_encode($draft_job->toArray()));
        $this->assertStringNotContainsString($key, (string) \App\Ai\Usage::orderByDesc('id')->value('error'));
        $reference = substr($error['detail'], 4);
        \Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, '['.$reference.']') && str_contains($message, 'Provider down: [redacted] Authorization: Bearer [redacted]') && !str_contains($message, $key) && !str_contains($message, 'opaque-token-123'));
    }

    public function testDraftUsesDocumentationAndCustomerContext()
    {
        $document = new Document();
        $document->forceFill(['mailbox_id' => $this->mailbox->id, 'title' => 'Android app', 'source_url' => 'https://docs.example.org/en/android', 'source_type' => 'url', 'localized_urls' => Document::localizedUrlsFor('https://docs.example.org/en/android'), 'content' => 'Restart the Android app.'])->save();
        Documents::index($document);

        Option::set('aiassistant.customer_context_url', [$this->mailbox->id => 'https://crm.example.org/context']);
        Option::set('aiassistant.customer_context_secret_key', [$this->mailbox->id => encrypt('s3cret')]);
        Option::set('aiassistant.customer_context_guidance', [$this->mailbox->id => 'We sell apps.']);
        Http::fake(['https://crm.example.org/*' => Http::response(['plan' => 'Pro'])]);

        $events = $this->draftEvents($this->agent);

        Http::assertSent(function (HttpRequest $request) {
            return $request->hasHeader('X-FREESCOUT-SIGNATURE', base64_encode(hash_hmac('sha1', $request->body(), 's3cret', true)))
                && $request['emails'] == ['casey@customer.example.org'];
        });
        ReplyDrafter::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, 'Restart the Android app.')
                && str_contains($prompt->prompt, '"plan": "Pro"')
                && str_contains($prompt->prompt, 'We sell apps.');
        });
        $draft = end($events);
        $this->assertSame('available', $draft['documentation_status']);
        $this->assertSame('available', $draft['customer_context_status']);
        $this->assertSame(['Android app', 'https://docs.example.org/en/android'], [$draft['retrieved_documents'][0]['title'], $draft['retrieved_documents'][0]['url']]);
    }

    public function testReplyFromDraftKeepsTheTranslation()
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id,
            'body'   => '<p>Hallo Casey</p>', 'ai_draft_translation' => 'Hello <b>Casey</b>', 'ai_draft_translation_language' => 'en',
        ]);

        $reply = Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertSame('Hello Casey', json_decode($reply->ai_assistant, true)['translations']['en']);
        $this->getConversationPage($this->agent)->assertSee('f-message__translation', false)->assertSee('Hello Casey');
    }

    // Customer context settings.

    /**
     * A test's long answer is shown in part: the first 4 KB.
     */
    public function testCustomerContextTestShowsALongAnswerInPart()
    {
        $admin = $this->createAdmin();
        Http::fake(['https://crm.example.org/*' => Http::response(str_repeat('x', 10000))]);

        $response = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);
        $this->assertSame(200, $response->json('http_status'));
        $this->assertSame(10000, $response->json('bytes'));
        $this->assertSame(str_repeat('x', 4096).'…', $response->json('body'));

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['https://crm.example.org/*' => Http::response('Authorization: Bearer secret-from-error', 500)]);
        $failed = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);
        $this->assertSame('error', $failed->json('status'));
        $this->assertSame(500, $failed->json('http_status'));
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $failed->json('msg'));
        $this->assertStringNotContainsString('secret-from-error', $failed->getContent());
    }

    public function testCustomerContextSettings()
    {
        $admin = $this->createAdmin();
        $id = $this->mailbox->id;
        // On the mailbox's AI Assistant page; only with Drafts on.
        $form = function (array $context) {
            return [
                'features'                          => ['summaries' => 1, 'translations' => 1, 'drafts' => 1],
                'customer_context_url'              => $context['url'],
                'customer_context_secret_key'       => $context['secret'],
                'customer_context_signature_header' => 'X-HELPSCOUT-SIGNATURE',
                'customer_context_guidance'         => ' Be brief. ',
            ];
        };
        $page = route('mailboxes.ai.save', ['id' => $id]);

        $this->postForm($admin, $page, $form(['url' => 'https://crm.example.org/context', 'secret' => 's3cret']));
        Option::$cache = [];
        $this->assertSame([
            'url' => 'https://crm.example.org/context', 'secret_key' => 's3cret', 'signature_header' => 'X-HELPSCOUT-SIGNATURE', 'guidance' => 'Be brief.',
        ], CustomerContext::settings($this->mailbox));
        $this->assertNotSame('s3cret', Option::get('aiassistant.customer_context_secret_key')[$id]);

        // A masked secret is kept.
        $this->postForm($admin, $page, $form(['url' => 'https://crm.example.org/context', 'secret' => '******']));
        Option::$cache = [];
        $this->assertSame('s3cret', CustomerContext::settings($this->mailbox)['secret_key']);

        // Drafts off: the group's off, and kept as it was.
        $this->postForm($admin, $page, ['features' => ['summaries' => 1]]);
        Option::$cache = [];
        $this->assertSame('https://crm.example.org/context', CustomerContext::settings($this->mailbox)['url']);

        $this->postForm($admin, $page, $form(['url' => 'file:///etc/passwd', 'secret' => '']))
            ->assertSessionHasErrors('customer_context_url');
    }

    public function testCustomerContextTest()
    {
        $admin = $this->createAdmin();
        Http::fake(['https://crm.example.org/*' => Http::response('{"plan":"Pro"}')]);

        $response = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);
        $this->assertSame('success', $response->json('status'));
        $this->assertSame(200, $response->json('http_status'));
        $this->assertSame('{"plan":"Pro"}', $response->json('body'));
        $this->assertSame(14, $response->json('bytes'));
        $this->assertIsInt($response->json('ms'));
        $this->assertNull($response->json('payload'));
        Http::assertSent(function (HttpRequest $request) {
            return $request['test'] === true && $request->hasHeader('X-FREESCOUT-SIGNATURE');
        });

        $this->assertSame('error', $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'gopher://x',
        ])->json('status'));
        $this->assertSame(403, $this->postAjax($this->agent, '/ai-assistant/customer-context/test', ['mailbox_id' => $this->mailbox->id])->status());
    }

    public function testCustomerContextDoesNotForwardSignedPayloadOnRedirect()
    {
        $admin = $this->createAdmin();
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($request->hasHeader('X-FREESCOUT-SIGNATURE'));

            return Http::response('', 307, ['Location' => 'https://other.example.org/collect']);
        });

        $response = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);

        $this->assertSame('error', $response->json('status'));
        $this->assertSame(307, $response->json('http_status'));
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'other.example.org'));
    }

    public function testCustomerContextTransferStopsAtByteLimit()
    {
        $admin = $this->createAdmin();
        Http::fake(function ($request, $options) {
            $this->assertSame('identity', $request->header('Accept-Encoding')[0]);
            $this->assertFalse(($options['progress'])(0, CustomerContext::MAX_RESPONSE_BYTES, 0, 0));
            $this->assertTrue(($options['progress'])(0, CustomerContext::MAX_RESPONSE_BYTES + 1, 0, 0));

            throw new \RuntimeException('Transfer aborted');
        });

        $response = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);

        $this->assertSame('error', $response->json('status'));
        $this->assertMatchesRegularExpression('/^Error occurred \\(ID: [A-F0-9]{12}\\)$/', $response->json('msg'));
    }

    public function testPlainTextSecretsAreEncrypted()
    {
        if (!class_exists('CreateAiDraftJobsTable')) {
            require base_path('database/migrations/2026_10_04_010103_create_ai_draft_jobs_table.php');
        }
        $encrypted = encrypt('already');

        $secrets = \CreateAiDraftJobsTable::encryptSecrets([1 => 'plain', 2 => $encrypted, 3 => '']);

        $this->assertSame('plain', decrypt($secrets[1]));
        $this->assertSame($encrypted, $secrets[2]);
        $this->assertSame('', $secrets[3]);
    }

    // Documentation and customer context, when they aren't there.

    /**
     * The documentation in the customer's language: the one detected when their message
     * was translated, else told by its script (Korean, Japanese, Chinese), else English.
     */
    public function testDocumentationInTheCustomersLanguage()
    {
        $document = new Document();
        $document->forceFill(['mailbox_id' => $this->mailbox->id, 'title' => 'Android app', 'source_url' => 'https://docs.example.org/en/android', 'source_type' => 'url', 'localized_urls' => Document::localizedUrlsFor('https://docs.example.org/en/android'), 'content' => 'Restart the Android app.'])->save();
        Documents::index($document);
        $thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $url = function ($body, $language = null) use ($thread) {
            \DB::table('threads')->where('id', $thread->id)->update(['body' => $body, 'ai_assistant' => $language ? json_encode(['language' => $language]) : null]);
            ReplyDrafter::fake([$this->draft()]);

            return \App\Ai\Drafts::draft($this->conversation->fresh(), 'en')['retrieved_documents'][0]['url'];
        };

        $this->assertSame('https://docs.example.org/ko/android', $url('Android 앱이 시작되지 않아요'));
        $this->assertSame('https://docs.example.org/ja/android', $url('Android アプリが起動しません'));
        $this->assertSame('https://docs.example.org/zh/android', $url('Android 应用无法启动'));
        $this->assertSame('https://docs.example.org/en/android', $url('Mijn Android app start niet.'));
        $this->assertSame('https://docs.example.org/ja/android', $url('Mijn Android app start niet.', 'ja'));
        $this->assertSame([], Document::localizedUrlsFor('https://docs.example.org/android'), 'Without /en/: no other languages.');
    }

    public function testDraftWithoutDocumentation()
    {
        Embeddings::fake(function () {
            throw new \RuntimeException('Embeddings down');
        });
        $this->assertMatchesRegularExpression('/^failed: Error occurred \(ID: [A-F0-9]{12}\)$/', \App\Ai\Drafts::draft($this->conversation, 'en')['documentation_status']);

        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'anthropic', 'api_key' => encrypt('sk-ant'), 'base_url' => '']]);
        Option::$cache = [];
        ReplyDrafter::fake([$this->draft()]);
        $draft = \App\Ai\Drafts::draft($this->conversation, 'en');
        $this->assertSame('disabled', $draft['documentation_status']);
        $this->assertSame([], $draft['retrieved_documents']);
    }

    /**
     * The customer context service failing: the draft is made without it, and says why.
     * A long answer goes to the AI in part.
     */
    public function testCustomerContextFailures()
    {
        $status = function ($url, $response = null) {
            Option::set('aiassistant.customer_context_url', [$this->mailbox->id => $url]);
            Option::$cache = [];
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['https://crm.example.org/*' => $response]);

            return CustomerContext::forConversation($this->conversation);
        };

        $this->assertSame('disabled', $status('')['status']);
        foreach ([
            $status('https://crm.example.org/context', Http::response(['error' => 'down'], 500)),
            $status('https://crm.example.org/context', Http::response('"'.str_repeat('x', CustomerContext::MAX_RESPONSE_BYTES).'"')),
            $status('https://crm.example.org/context', Http::response('<html>')),
            $status('ftp://crm.example.org/context'),
        ] as $failed) {
            $this->assertMatchesRegularExpression('/^failed: Error occurred \(ID: [A-F0-9]{12}\)$/', $failed['status']);
        }
        Http::assertNothingSent();

        $context = $status('https://crm.example.org/context', Http::response(['notes' => str_repeat('n', CustomerContext::MAX_PROMPT_CHARS)]));
        $this->assertSame('available', $context['status']);
        $this->assertTrue($context['data']['truncated']);
        $this->assertSame(CustomerContext::MAX_PROMPT_CHARS, mb_strlen($context['data']['json_excerpt']));
    }
}
