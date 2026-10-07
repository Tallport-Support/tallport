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

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    public function testButtonOnlyWhereDraftingIsAllowed()
    {
        $this->getConversationPage($this->agent)->assertSee('ai-draft-action')->assertSee('tallportAiDraft', false)->assertSee('Waiting in the queue…');

        Option::set('aiassistant.drafts_per_day', 0);
        $this->getConversationPage($this->agent)->assertDontSee('ai-draft-action');

        Option::set('aiassistant.drafts_per_day', 5);
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['drafts']]);
        $this->getConversationPage($this->agent)->assertDontSee('ai-draft-action');
        $this->requestDraft($this->agent)->assertStatus(403);
    }

    public function testUserWithoutAccessCannotDraft()
    {
        $this->requestDraft($this->createUser())->assertStatus(403);
        ReplyDrafter::assertNeverPrompted();
    }

    public function testDraftIsMadeAndFetchedByItsUser()
    {
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $this->conversation->id,
            'body' => '<p>Secret internal note</p>', 'is_note' => 1,
        ]);

        $response = $this->requestDraft($this->agent)->assertStatus(200)->assertJson(['status' => 'success']);
        $poll_url = $response->json('poll_url');

        ReplyDrafter::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, 'Mijn Android app start niet.')
                && !str_contains($prompt->prompt, 'Secret internal note')
                && $prompt->agent->language == 'en';
        });

        $this->actingAs($this->agent)->getJson($poll_url)->assertJson([
            'status'               => 'success',
            'draft_status'         => DraftJob::STATUS_COMPLETED,
            'translation'          => "Hello Casey,\n\n- Restart the app\n- Update Android",
            'translation_language' => 'en',
            'documentation_urls'   => ['https://docs.example.org/android'],
            'staff_notes'          => ['Check the app version.'],
            'documentation_status' => 'no_matches',
        ]);

        $this->actingAs($this->createAdmin())->getJson($poll_url)->assertStatus(403);
    }

    public function testAiJobsHaveTheirOwnQueues()
    {
        \Queue::fake();
        Option::set('aiassistant.mailbox_features_off', []);

        $this->requestDraft($this->agent);
        \Queue::assertPushedOn('ai-drafts', \App\Jobs\AiDraftReply::class);

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

        $this->requestDraft($this->agent)->assertStatus(200);
        $this->requestDraft($this->agent)->assertStatus(429);
    }

    public function testFailedDraftIsReported()
    {
        ReplyDrafter::fake(function () {
            throw new \RuntimeException('Provider down');
        });

        $poll_url = $this->requestDraft($this->agent)->json('poll_url');

        $this->actingAs($this->agent)->getJson($poll_url)->assertJson([
            'status'       => 'error',
            'draft_status' => DraftJob::STATUS_FAILED,
            'detail'       => 'Provider down',
        ]);
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

        $poll_url = $this->requestDraft($this->agent)->json('poll_url');

        Http::assertSent(function (HttpRequest $request) {
            return $request->hasHeader('X-FREESCOUT-SIGNATURE', base64_encode(hash_hmac('sha1', $request->body(), 's3cret', true)))
                && $request['emails'] == ['casey@customer.example.org'];
        });
        ReplyDrafter::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, 'Restart the Android app.')
                && str_contains($prompt->prompt, '"plan": "Pro"')
                && str_contains($prompt->prompt, 'We sell apps.');
        });
        $this->actingAs($this->agent)->getJson($poll_url)->assertJson([
            'documentation_status'    => 'available',
            'customer_context_status' => 'available',
            'retrieved_documents'     => [['title' => 'Android app', 'url' => 'https://docs.example.org/en/android']],
        ]);
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
        Http::fake(['https://crm.example.org/*' => Http::response(str_repeat('x', 10000), 500)]);

        $response = $this->postAjax($admin, '/ai-assistant/customer-context/test', [
            'mailbox_id' => $this->mailbox->id, 'email' => 'casey@customer.example.org', 'url' => 'https://crm.example.org/context', 'secret_key' => 'k',
        ]);
        $this->assertSame(500, $response->json('http_status'));
        $this->assertSame(10000, $response->json('bytes'));
        $this->assertSame(str_repeat('x', 4096).'…', $response->json('body'));
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
}
