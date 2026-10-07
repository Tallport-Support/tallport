<?php

namespace Tests\Feature;

use App\Ai\Agents\LanguageRecognizer;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\Usage;
use App\Conversation;
use App\Option;
use App\Retention\Retention;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * The AI log (App\Ai\Usage, Manage » Logs » AI): every call to a model is recorded, failed ones
 * too, without counting towards the budgets; errors without keys; each model's last calls on
 * Settings » AI; old rows cleaned up. Providers are faked.
 */
class AiLogTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->admin]);
        Option::$cache = [];
    }

    /**
     * A conversation from a customer's email, received before the AI is set up.
     */
    protected function conversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
            'body' => "Hallo,\n\nWaar blijft mijn bestelling?",
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Translations: gpt-x at p1 (OpenAI), and as backup claude-y at p2 (Anthropic).
     */
    protected function useModels($backup = true)
    {
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-one-secret-0001'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-two-secret-0002'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['translations' => array_filter([
            'primary' => ['provider' => 'p1', 'model' => 'gpt-x'],
            'backup'  => $backup ? ['provider' => 'p2', 'model' => 'claude-y'] : null,
        ])]);
        Option::$cache = [];
    }

    protected function translate(Conversation $conversation)
    {
        return (new ThreadTranslator('en'))->recordFor(Usage::FEATURE_TRANSLATION, $conversation)->streamJson('Hallo');
    }

    protected function logCall(array $attributes)
    {
        return Usage::create($attributes + ['mailbox_id' => $this->mailbox->id, 'feature' => Usage::FEATURE_TRANSLATION]);
    }

    public function testEveryCallIsRecordedThePrimaryFailingAndTheBackup()
    {
        $conversation = $this->conversation();
        $this->useModels();
        $calls = 0;
        ThreadTranslator::fake(function () use (&$calls) {
            if (++$calls == 1) {
                throw new \RuntimeException('Incorrect API key provided: sk-one-secret-0001');
            }

            return new \Laravel\Ai\Responses\TextResponse(
                json_encode(['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']),
                new \Laravel\Ai\Responses\Data\TextUsage(120, 30), new \Laravel\Ai\Responses\Data\Meta
            );
        });

        $this->translate($conversation);

        [$failed, $ok] = Usage::where('conversation_id', $conversation->id)->orderBy('id')->get()->all();
        $this->assertSame(Usage::STATUS_FAILED_THEN_BACKUP, $failed->status);
        $this->assertSame(['p1', 'openai', 'gpt-x', false, true], [$failed->provider_id, $failed->provider, $failed->model, $failed->backup, $failed->streamed]);
        $this->assertSame('Incorrect API key provided: [redacted]', $failed->error);
        $this->assertSame(0, $failed->input_tokens + $failed->output_tokens);
        $this->assertNotNull($failed->duration_ms);

        $this->assertSame(Usage::STATUS_OK, $ok->status);
        $this->assertSame(['p2', 'anthropic', 'claude-y', true, $this->mailbox->id, $conversation->customer_id], [$ok->provider_id, $ok->provider, $ok->model, $ok->backup, $ok->mailbox_id, $ok->customer_id]);
        $this->assertSame([120, 30, null], [$ok->input_tokens, $ok->output_tokens, $ok->error]);
        $this->assertFalse($ok->fast, 'Claude takes no fast options.');
        $this->assertSame(150, Usage::forConversation($conversation));

        // No backup: the failure is the last word.
        $this->useModels(false);
        ThreadTranslator::fake(function () {
            throw new \RuntimeException('Provider down');
        });
        try {
            $this->translate($conversation);
            $this->fail('The failure shows.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider down', $e->getMessage());
        }
        $last = Usage::orderBy('id', 'desc')->first();
        $this->assertSame([Usage::STATUS_FAILED, 'gpt-x', 'Provider down'], [$last->status, $last->model, $last->error]);
        $this->assertSame(150, Usage::forConversation($conversation));
    }

    /**
     * A model refusing the fast options: that call is recorded, then the one without them.
     */
    public function testAModelRefusingTheFastOptionsIsRecorded()
    {
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-test-key-123'), 'base_url' => '']]);
        Option::set('aiassistant.models', ['language' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-5-mini']]]);
        Option::$cache = [];
        $answer = Http::response([
            'id' => 'resp_1', 'model' => 'gpt-5-mini', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['language' => 'de'])]]]],
            'usage'  => ['input_tokens' => 10, 'output_tokens' => 2],
        ]);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported value: 'minimal'", 'type' => 'invalid_request_error']], 400)
            ->pushResponse($answer)])->preventStrayRequests();

        (new LanguageRecognizer(['de', 'nl']))->recordFor(Usage::FEATURE_LANGUAGE, null, $this->mailbox->id)->prompt('Hallo');

        [$refused, $ok] = Usage::orderBy('id')->get()->all();
        $this->assertSame([Usage::STATUS_FAST_REFUSED, true, false, Usage::FEATURE_LANGUAGE, $this->mailbox->id], [$refused->status, $refused->fast, $refused->streamed, $refused->feature, $refused->mailbox_id]);
        $this->assertStringContainsString('Unsupported value', $refused->error);
        $this->assertSame([Usage::STATUS_OK, false, 12], [$ok->status, $ok->fast, $ok->input_tokens + $ok->output_tokens]);
    }

    /**
     * The log tells less reasoning (the fast options) and fast mode (the faster tier) apart, and
     * their refusals.
     */
    public function testLogShowsLessReasoningAndFastMode()
    {
        $this->logCall(['provider' => 'openai', 'model' => 'gpt-reasoning', 'fast' => true]);
        $this->logCall(['provider' => 'openai', 'model' => 'gpt-tier', 'fast_tier' => true, 'status' => Usage::STATUS_FAST_TIER_REFUSED, 'error' => 'service_tier']);
        $this->logCall(['provider' => 'openai', 'model' => 'gpt-plain', 'status' => Usage::STATUS_FAST_REFUSED]);

        $this->actingAs($this->admin)->get(route('logs.ai'))->assertOk()
            ->assertSeeInOrder(['gpt-plain', 'Less Reasoning Refused', 'gpt-tier', 'Fast</', 'Fast Mode Refused', 'gpt-reasoning', 'Less Reasoning</', 'Succeeded'], false);
        $this->assertSame(['Fast Mode Refused', 'warning'], Usage::where('model', 'gpt-tier')->first()->statusName());
        $this->assertNull(Usage::modelStatus('translations', null, 'gpt-plain'), 'Refusals are not the model\'s last call.');
    }

    /**
     * Failed calls have no tokens and translated no messages: the daily budget, the customer's
     * hourly cap, the sidebar's tokens and System Status count as before.
     */
    public function testFailedCallsDontCountTowardsBudgetsAndCaps()
    {
        $conversation = $this->conversation();
        $this->logCall(['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'input_tokens' => 900, 'output_tokens' => 100, 'items' => 2]);
        $this->logCall(['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'items' => 5, 'status' => Usage::STATUS_FAILED, 'error' => 'Down']);
        $this->logCall(['conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'items' => 5, 'status' => Usage::STATUS_FAST_REFUSED]);

        $this->assertSame(2, Usage::customerTranslationsLastHour($conversation->customer_id));
        $this->assertSame(1000, Usage::mailboxToday($this->mailbox->id));
        $this->assertSame(1000, Usage::forConversation($conversation));

        Option::set('aiassistant.daily_tokens', 1000);
        Option::$cache = [];
        $this->assertFalse(\App\Ai\Settings::withinBudget($this->mailbox));
    }

    /**
     * Errors are kept without API keys or Authorization headers, and not too long; model names stay.
     */
    public function testKeysAreRedacted()
    {
        $this->useModels();
        Option::set('aiassistant.documentation.embedding_api_key', encrypt('emb-secret-key-42'));
        Option::$cache = [];

        $this->assertSame('Key [redacted] and [redacted] refused', Usage::redact('Key sk-two-secret-0002 and emb-secret-key-42 refused'));
        $this->assertSame('Authorization: Bearer [redacted] sent', Usage::redact('Authorization: Bearer abc.def-123 sent'));
        $this->assertSame('{"api_key": "[redacted]", "x-api-key":"[redacted]"} ?key=[redacted]&model=gpt-4.1-nano', Usage::redact('{"api_key": "plain", "x-api-key":"zzz"} ?key=AIzaSyA1b2c3&model=gpt-4.1-nano'));
        $this->assertSame('Incorrect API key provided: [redacted]. Model claude-sonnet-4-5-20250929 at [redacted]', Usage::redact('Incorrect API key provided: sk-proj-Ab12******************xY9z. Model claude-sonnet-4-5-20250929 at xai-0123456789abcdefABCDEF'));
        $this->assertSame('token [redacted] end', Usage::redact('token a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8 end'));
        $this->assertSame(Usage::ERROR_LENGTH, mb_strlen(Usage::redact(str_repeat('é ', 800))));
    }

    /**
     * Manage » Logs » AI: admins only, newest first, filtered by feature, outcome and model.
     */
    public function testLogPage()
    {
        $conversation = $this->conversation();
        $this->logCall(['feature' => Usage::FEATURE_SUMMARY, 'conversation_id' => $conversation->id, 'provider' => 'openai', 'model' => 'gpt-x', 'duration_ms' => 1234, 'input_tokens' => 1200, 'output_tokens' => 34]);
        $this->logCall(['provider' => 'anthropic', 'model' => 'claude-y', 'backup' => true, 'status' => Usage::STATUS_FAILED_THEN_BACKUP, 'error' => 'Overloaded '.str_repeat('detail ', 30).'END']);

        $this->actingAs($this->createUser())->get(route('logs.ai'))->assertStatus(403);
        $this->actingAs($this->admin)->get(route('logs.ai'))->assertOk()
            ->assertSeeInOrder(['Translations', 'claude-y', 'Backup', 'Failed, Backup Tried', 'Summaries', 'OpenAI', 'gpt-x', '1.2 s', '1,200 / 34', 'Succeeded'])
            ->assertSee(route('conversations.view', ['id' => $conversation->id]), false)
            ->assertSee('ai-call-', false)
            ->assertSee('END')
            ->assertSee('value="'.route('logs.ai').'"', false);

        $this->get(route('logs.ai', ['outcome' => 'errors']))->assertOk()->assertSee('claude-y')->assertDontSee('1,200 / 34');
        $this->get(route('logs.ai', ['feature' => Usage::FEATURE_SUMMARY]))->assertOk()->assertSee('1,200 / 34')->assertDontSee('Overloaded');
        $this->get(route('logs.ai', ['model' => 'claude-y']))->assertOk()->assertSee('Overloaded')->assertDontSee('1,200 / 34');
        $this->get(route('logs.ai', ['model' => 'none']))->assertOk()->assertSee('This log is empty.');

        // The other logs offer it too.
        $this->get(route('logs'))->assertOk()->assertSee('value="'.route('logs.ai').'"', false);
    }

    /**
     * Settings » AI: under each model, how its last calls went, linked to its log.
     */
    public function testSettingsShowEachModelsLastCalls()
    {
        $this->useModels();
        $this->logCall(['provider_id' => 'p1', 'model' => 'gpt-x', 'duration_ms' => 1200, 'created_at' => now()->subMinutes(2)]);
        // Another feature's call of the same model doesn't count for translations.
        $this->logCall(['feature' => Usage::FEATURE_SUMMARY, 'provider_id' => 'p2', 'model' => 'claude-y', 'status' => Usage::STATUS_FAILED, 'error' => 'Elsewhere']);
        $this->logCall(['feature' => Usage::FEATURE_REPLY_TRANSLATION, 'provider_id' => 'p2', 'model' => 'claude-y', 'duration_ms' => 400, 'created_at' => now()->subHours(3)]);
        $this->logCall(['provider_id' => 'p2', 'model' => 'claude-y', 'status' => Usage::STATUS_FAILED_THEN_BACKUP, 'error' => 'Out of credit', 'created_at' => now()->subMinutes(30)]);
        $this->logCall(['provider_id' => 'p2', 'model' => 'claude-y', 'status' => Usage::STATUS_FAILED, 'error' => 'Your credit balance is too low', 'created_at' => now()->subMinutes(5)]);

        $this->actingAs($this->admin)->get('/app-settings/ai')->assertOk()
            ->assertSee('Last call 2 minutes ago · 1.2 s')
            ->assertSee('2 failures in the last hour · Your credit balance is too low')
            ->assertSee(e(route('logs.ai', ['model' => 'gpt-x'])), false)
            ->assertSee(e(route('logs.ai', ['model' => 'claude-y', 'outcome' => 'errors'])), false)
            ->assertDontSee('Elsewhere');

        Usage::where('model', 'claude-y')->where('status', '!=', Usage::STATUS_OK)->update(['created_at' => now()->subHours(2)]);
        $this->assertSame('Last call failed 2 hours ago · Your credit balance is too low', Usage::modelStatus('translations', 'p2', 'claude-y')['text']);
        $this->assertNull(Usage::modelStatus('drafts', 'p1', 'gpt-x'), 'Not called yet.');
    }

    /**
     * After 90 days the log forgets failed calls and calls no conversation shows; a conversation's
     * own calls stay for its token count.
     */
    public function testOldRowsAreCleanedUp()
    {
        $conversation = $this->conversation();
        $old = now()->subDays(Usage::LOG_DAYS + 1);
        $kept = [
            $this->logCall(['conversation_id' => $conversation->id, 'input_tokens' => 50, 'created_at' => $old]),
            $this->logCall(['status' => Usage::STATUS_FAILED, 'created_at' => now()->subDays(10)]),
            $this->logCall(['feature' => Usage::FEATURE_LANGUAGE, 'created_at' => now()]),
        ];
        $this->logCall(['conversation_id' => $conversation->id, 'status' => Usage::STATUS_FAILED, 'created_at' => $old]);
        $this->logCall(['feature' => Usage::FEATURE_LANGUAGE, 'created_at' => $old]);
        $this->logCall(['conversation_id' => $conversation->id + 1000, 'input_tokens' => 50, 'created_at' => $old]);

        $this->assertSame(3, Retention::cleanLogs(true)['ai_log']);
        $this->assertSame(3, Retention::run()['logs']['ai_log']);
        $this->assertEqualsCanonicalizing(collect($kept)->pluck('id')->all(), Usage::pluck('id')->all());
        $this->assertSame(50, Usage::forConversation($conversation));
    }
}
