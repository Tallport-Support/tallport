<?php

namespace Tests\Feature;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ConversationSummarizer;
use App\Ai\Agents\LanguageRecognizer;
use App\Ai\Agents\ReplyDrafter;
use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\Providers;
use App\Ai\Translations;
use App\Conversation;
use App\Events\RealtimeConvTranslating;
use App\Option;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * AI Assistant speed: translations ask the models for the least reasoning they allow (fast
 * mode), and answers are streamed: customers' messages' translations reach open conversations
 * as they're written (throttled). Providers are faked: laravel/ai's fakes, or their HTTP API.
 */
class AiFastStreamingTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        Option::$cache = [];
    }

    /**
     * One provider, its model for every feature; translations into English, no summaries.
     */
    protected function useModel($provider, $model, $base_url = '', $fast_mode = false)
    {
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => $provider, 'api_key' => encrypt('sk-test'), 'base_url' => $base_url, 'fast_mode' => $fast_mode]]);
        Option::set('aiassistant.models', array_fill_keys(['summaries', 'translations', 'drafts', 'language'], ['primary' => ['provider' => 'p1', 'model' => $model]]));
        Option::set('aiassistant.translation_language', 'en');
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries']]);
        Option::$cache = [];
    }

    protected function receiveCustomerEmail($body = "Hallo,\n\nWaar blijft mijn bestelling?")
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
            'body' => $body,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * An OpenAI Responses API answer.
     */
    protected function openAiResponse(array $answer)
    {
        return Http::response([
            'id' => 'resp_1', 'model' => 'gpt-5-mini', 'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]],
            'usage'  => ['input_tokens' => 10, 'output_tokens' => 2],
        ]);
    }

    /**
     * The fast options for each provider and model: only for models known to take them.
     */
    public function testFastOptionsByProviderAndModel()
    {
        $effort = fn ($value) => ['reasoning' => ['effort' => $value]];
        $cases = [
            // OpenAI: GPT-5 "minimal", GPT-5.1 and later "none", o-series "low"; none for others.
            ['openai', 'gpt-5-mini', $effort('minimal')],
            ['openai', 'gpt-5-2025-08-07', $effort('minimal')],
            ['openai', 'gpt-5.1', $effort('none')],
            ['openai', 'gpt-5.4-mini', $effort('none')],
            ['openai', 'gpt-6', $effort('none')],
            ['openai', 'o4-mini', $effort('low')],
            ['openai', 'gpt-4.1-mini', []],
            ['openai', 'gpt-4o', []],
            ['openai', 'gpt-5-pro', []],
            ['openai', 'gpt-5.1-codex', []],
            ['openai', 'gpt-5-chat-latest', []],
            // Gemini: thinking_level "minimal" where there is one, else "low".
            ['gemini', 'gemini-3-flash-preview', ['generation_config' => ['thinking_level' => 'minimal']]],
            ['gemini', 'gemini-2.5-flash-lite', ['generation_config' => ['thinking_level' => 'minimal']]],
            ['gemini', 'gemini-3-pro-preview', ['generation_config' => ['thinking_level' => 'low']]],
            ['gemini', 'gemini-3.8-flash', ['generation_config' => ['thinking_level' => 'low']]],
            ['gemini', 'gemma-3-27b-it', []],
            // xAI: Grok 4.3 "none", 4.5 and later and 3 Mini "low"; others refuse it.
            ['xai', 'grok-4.3', $effort('none')],
            ['xai', 'grok-4.5', $effort('low')],
            ['xai', 'grok-3-mini', $effort('low')],
            ['xai', 'grok-4', []],
            ['xai', 'grok-4-1-fast-reasoning', []],
            ['xai', 'grok-4.20-0309-reasoning', []],
            ['xai', 'grok-4-fast-non-reasoning', []],
            ['groq', 'qwen/qwen3-32b', ['reasoning_effort' => 'none']],
            ['groq', 'openai/gpt-oss-120b', ['reasoning_effort' => 'low']],
            ['groq', 'llama-3.3-70b-versatile', []],
            ['mistral', 'mistral-medium-3.5', ['reasoning_effort' => 'none']],
            ['mistral', 'mistral-small-latest', ['reasoning_effort' => 'none']],
            ['mistral', 'mistral-large-latest', []],
            ['deepseek', 'deepseek-v4-flash', ['thinking' => ['type' => 'disabled']]],
            ['deepseek', 'deepseek-chat', []],
            ['deepseek', 'deepseek-reasoner', []],
            // OpenRouter: as the model's own provider; Claude would start reasoning when given an effort.
            ['openrouter', 'openai/gpt-5-mini', $effort('minimal')],
            ['openrouter', 'google/gemini-3-pro-preview', $effort('low')],
            ['openrouter', 'x-ai/grok-4.3', $effort('none')],
            ['openrouter', 'anthropic/claude-sonnet-4.5', []],
            ['openrouter', 'meta-llama/llama-3.3-70b-instruct', []],
            ['anthropic', 'claude-haiku-4-5', []],
            // OpenAI-compatible hosts: OpenAI's models, by their prefixed names, as on OpenAI.
            ['digitalocean', 'openai-gpt-6-luna', ['reasoning_effort' => 'none']],
            ['digitalocean', 'openai-gpt-5-mini', ['reasoning_effort' => 'minimal']],
            ['digitalocean', 'openai-gpt-4o', []],
            ['digitalocean', 'anthropic-claude-4.5-sonnet', []],
            ['digitalocean', 'llama3.3-70b-instruct', []],
            ['custom', 'gpt-5.2', ['reasoning_effort' => 'none']],
            ['together', 'openai/gpt-oss-120b', []],
            ['ollama', 'qwen3:8b', []],
        ];
        foreach ($cases as [$provider, $model, $options]) {
            $this->assertSame($options, Providers::fastOptions($provider, $model), $provider.' '.$model);
        }
    }

    /**
     * Translations (and recognising languages) are fast; summaries and drafts think as usual.
     */
    public function testTranslationAgentsAreFast()
    {
        $this->assertTrue((new ThreadTranslator('en'))->fast());
        $this->assertTrue((new ChatTranslator('en'))->fast());
        $this->assertTrue((new ReplyTranslator('nl'))->fast());
        $this->assertTrue((new LanguageRecognizer(['de']))->fast());
        $this->assertFalse((new ReplyDrafter('en'))->fast());
        $this->assertFalse((new ConversationSummarizer('en'))->fast());
    }

    /**
     * The fast options go into the provider's request, for the fast agents only.
     */
    public function testFastOptionsGoInTheRequest()
    {
        $this->useModel('openai', 'gpt-5-mini');
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->pushResponse($this->openAiResponse(['language' => 'de']))
            ->pushResponse($this->openAiResponse(['one_liner' => 'Casey asks', 'background' => '']))]);

        $this->assertSame('de', (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo, wo bleibt meine Bestellung?')['language']);
        (new ConversationSummarizer('en'))->prompt('A conversation');

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertSame(['effort' => 'minimal'], $requests[0]['reasoning']);
        $this->assertArrayNotHasKey('reasoning', $requests[1]);
        $this->assertArrayNotHasKey('service_tier', $requests[0]);
    }

    /**
     * A model that refuses the fast options (HTTP 400) is called again without them, and
     * without them from then on.
     */
    public function testAModelThatRefusesTheFastOptionsIsCalledWithoutThem()
    {
        $this->useModel('openai', 'gpt-5-mini');
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported value: 'minimal'", 'type' => 'invalid_request_error']], 400)
            ->pushResponse($this->openAiResponse(['language' => 'de']))
            ->pushResponse($this->openAiResponse(['language' => 'nl']))]);

        $this->assertSame('de', (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo')['language']);
        $this->assertSame('nl', (new LanguageRecognizer(['de', 'nl']))->prompt('Hoi')['language']);

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertCount(3, $requests);
        $this->assertArrayHasKey('reasoning', $requests[0]);
        $this->assertArrayNotHasKey('reasoning', $requests[1]);
        $this->assertArrayNotHasKey('reasoning', $requests[2]);
        $this->assertTrue(Providers::fastRejected(Providers::textName('p1'), 'gpt-5-mini'));
    }

    /**
     * Fast mode (the provider's faster, pricier tier): OpenAI's priority processing, Anthropic's
     * fast mode, and OpenAI's models on the OpenAI-compatible hosts that serve them.
     */
    public function testFastTierOptionsByProviderAndModel()
    {
        $priority = ['service_tier' => 'priority'];
        $cases = [
            ['openai', 'gpt-5-mini', $priority],
            ['openai', 'gpt-4.1', $priority],
            ['anthropic', 'claude-opus-4-6', ['speed' => 'fast']],
            ['digitalocean', 'openai-gpt-6-luna', $priority],
            ['digitalocean', 'openai-gpt-4o', $priority],
            ['digitalocean', 'anthropic-claude-4.5-sonnet', []],
            ['digitalocean', 'llama3.3-70b-instruct', []],
            ['custom', 'gpt-5.2', $priority],
            ['custom', 'openai/o4-mini', $priority],
            ['custom', 'openai/gpt-oss-120b', []],
            ['together', 'openai/gpt-oss-120b', []],
            ['gemini', 'gemini-3-flash-preview', []],
            ['xai', 'grok-4.3', []],
        ];
        foreach ($cases as [$provider, $model, $options]) {
            $this->assertSame($options, Providers::fastTierOptions($provider, $model), $provider.' '.$model);
        }
        $this->assertSame(['openai', 'anthropic', 'digitalocean', 'custom'], array_keys(array_filter(array_map([Providers::class, 'hasFastTier'], array_combine(array_keys(Providers::PRESETS), array_keys(Providers::PRESETS))))));
    }

    /**
     * With the provider's fast mode on, the translation agents ask for the faster tier, besides
     * the least reasoning; summaries don't. Off (the default): no tier.
     */
    public function testFastModeGoesInTheRequestOfTranslationsWhenSwitchedOn()
    {
        $this->useModel('openai', 'gpt-5-mini', '', true);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->pushResponse($this->openAiResponse(['language' => 'de']))
            ->pushResponse($this->openAiResponse(['one_liner' => 'Casey asks', 'background' => '']))
            ->pushResponse($this->openAiResponse(['language' => 'de']))]);

        (new LanguageRecognizer(['de', 'nl']))->recordFor(\App\Ai\Usage::FEATURE_LANGUAGE, null, $this->mailbox->id)->prompt('Hallo');
        (new ConversationSummarizer('en'))->prompt('A conversation');
        $this->useModel('openai', 'gpt-5-mini');
        (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo');

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertSame('priority', $requests[0]['service_tier']);
        $this->assertSame(['effort' => 'minimal'], $requests[0]['reasoning']);
        $this->assertArrayNotHasKey('service_tier', $requests[1]);
        $this->assertArrayNotHasKey('reasoning', $requests[1]);
        $this->assertArrayNotHasKey('service_tier', $requests[2]);
        $this->assertSame(['effort' => 'minimal'], $requests[2]['reasoning']);
        $call = \App\Ai\Usage::orderBy('id')->first();
        $this->assertSame([true, true], [$call->fast, $call->fast_tier]);
    }

    /**
     * Anthropic: speed "fast" and its beta header, besides laravel/ai's own beta; neither when off.
     */
    public function testAnthropicFastMode()
    {
        $answer = Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-4-6', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['language' => 'de'])]],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 2],
        ]);
        Http::fake(['https://api.anthropic.com/v1/messages' => Http::sequence()->pushResponse($answer)->pushResponse($answer)->pushResponse($answer)])->preventStrayRequests();

        $this->useModel('anthropic', 'claude-opus-4-6', '', true);
        $this->assertSame('de', (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo')['language']);
        (new ConversationSummarizer('en'))->prompt('A conversation');
        $this->useModel('anthropic', 'claude-opus-4-6');
        (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo');

        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertSame('fast', $requests[0]['speed']);
        $this->assertSame(['web-fetch-2025-09-10,fast-mode-2026-02-01'], $requests[0]->header('anthropic-beta'));
        $this->assertArrayNotHasKey('speed', $requests[1]->data());
        $this->assertArrayNotHasKey('speed', $requests[2]->data());
        $this->assertSame(['web-fetch-2025-09-10'], $requests[2]->header('anthropic-beta'));
    }

    /**
     * A model that refuses fast mode (HTTP 400) is called again without it, keeping the least
     * reasoning, and without it from then on; the log says which was refused.
     */
    public function testAModelThatRefusesFastModeIsCalledWithoutIt()
    {
        $this->useModel('openai', 'gpt-5-mini', '', true);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => "Unsupported value: 'service_tier' does not support 'priority' with this model.", 'type' => 'invalid_request_error']], 400)
            ->pushResponse($this->openAiResponse(['language' => 'de']))
            ->pushResponse($this->openAiResponse(['language' => 'nl']))]);

        (new LanguageRecognizer(['de', 'nl']))->recordFor(\App\Ai\Usage::FEATURE_LANGUAGE, null, $this->mailbox->id)->prompt('Hallo');
        (new LanguageRecognizer(['de', 'nl']))->prompt('Hoi');

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertCount(3, $requests);
        $this->assertSame('priority', $requests[0]['service_tier']);
        foreach ([1, 2] as $i) {
            $this->assertArrayNotHasKey('service_tier', $requests[$i]);
            $this->assertSame(['effort' => 'minimal'], $requests[$i]['reasoning']);
        }
        $this->assertTrue(Providers::fastTierRejected(Providers::textName('p1'), 'gpt-5-mini'));
        $this->assertFalse(Providers::fastRejected(Providers::textName('p1'), 'gpt-5-mini'));
        [$refused, $ok] = \App\Ai\Usage::orderBy('id')->get()->all();
        $this->assertSame([\App\Ai\Usage::STATUS_FAST_TIER_REFUSED, true, true], [$refused->status, $refused->fast, $refused->fast_tier]);
        $this->assertSame([\App\Ai\Usage::STATUS_OK, true, false], [$ok->status, $ok->fast, $ok->fast_tier]);
    }

    /**
     * Both refused, the error not saying which: fast mode is dropped first, then the least
     * reasoning; each refusal is remembered and logged on its own. A reasoning error drops only that.
     */
    public function testEachRefusedOptionIsDroppedOnItsOwn()
    {
        $this->useModel('openai', 'gpt-5-mini', '', true);
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()
            ->push(['error' => ['message' => 'Bad request', 'type' => 'invalid_request_error']], 400)
            ->push(['error' => ['message' => 'Bad request', 'type' => 'invalid_request_error']], 400)
            ->pushResponse($this->openAiResponse(['language' => 'de']))
            ->push(['error' => ['message' => "Unsupported value: 'none' for reasoning.effort", 'type' => 'invalid_request_error']], 400)
            ->pushResponse($this->openAiResponse(['language' => 'de']))]);

        (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo');

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]->data())->values();
        $this->assertSame([true, true], [isset($requests[0]['service_tier']), isset($requests[0]['reasoning'])]);
        $this->assertSame([false, true], [isset($requests[1]['service_tier']), isset($requests[1]['reasoning'])]);
        $this->assertSame([false, false], [isset($requests[2]['service_tier']), isset($requests[2]['reasoning'])]);
        $this->assertSame([\App\Ai\Usage::STATUS_FAST_TIER_REFUSED, \App\Ai\Usage::STATUS_FAST_REFUSED, \App\Ai\Usage::STATUS_OK], \App\Ai\Usage::orderBy('id')->pluck('status')->all());

        // Another model, its error about the reasoning: fast mode is kept.
        \App\Ai\Usage::query()->delete();
        $this->useModel('openai', 'gpt-5.1', '', true);

        (new LanguageRecognizer(['de', 'nl']))->prompt('Hallo');

        $last = Http::recorded()->map(fn ($pair) => $pair[0]->data())->last();
        $this->assertSame([true, false], [isset($last['service_tier']), isset($last['reasoning'])]);
        $this->assertSame([\App\Ai\Usage::STATUS_FAST_REFUSED, \App\Ai\Usage::STATUS_OK], \App\Ai\Usage::orderBy('id')->pluck('status')->all());
        $this->assertFalse(Providers::fastTierRejected(Providers::textName('p1'), 'gpt-5.1'));
    }

    /**
     * A streamed translation through an OpenAI-compatible server (its HTTP API faked): the
     * server is asked for the stream's tokens, which count as usual.
     */
    public function testStreamedTranslationThroughTheProvidersApi()
    {
        $this->useModel('ollama', 'qwen3:8b');
        $answer = json_encode(['translation' => "Hello,\n\nWhere is my order?", 'same_language' => false, 'detected_language' => 'nl']);
        $events = array_map(fn ($piece) => 'data: '.json_encode(['model' => 'qwen3:8b', 'choices' => [['index' => 0, 'delta' => ['content' => $piece]]]]), str_split($answer, 12));
        $events[] = 'data: '.json_encode(['model' => 'qwen3:8b', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]);
        $events[] = 'data: '.json_encode(['model' => 'qwen3:8b', 'choices' => [], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 25]]);
        $events[] = 'data: [DONE]';
        Http::fake(['http://localhost:11434/v1/chat/completions' => Http::response(implode("\n\n", $events)."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $conversation = $this->receiveCustomerEmail();

        $this->assertSame("Hello,\n\nWhere is my order?", Translations::get($conversation->threads()->first(), 'en'));
        Http::assertSent(fn (HttpRequest $request) => $request['stream'] === true && $request['stream_options'] == ['include_usage' => true] && !isset($request['reasoning_effort']));
        $this->assertSame(325, \App\Ai\Usage::forConversation($conversation));
    }

    /**
     * DigitalOcean's OpenAI models (openai-gpt-6-luna): the translation asks for the least
     * reasoning, in the Chat Completions request, and the log says so.
     */
    public function testFastModeThroughDigitalOcean()
    {
        $this->useModel('digitalocean', 'openai-gpt-6-luna');
        $answer = json_encode(['translation' => "Hello,\n\nWhere is my order?", 'same_language' => false, 'detected_language' => 'nl']);
        $events = ['data: '.json_encode(['model' => 'openai-gpt-6-luna', 'choices' => [['index' => 0, 'delta' => ['content' => $answer]]]])];
        $events[] = 'data: '.json_encode(['model' => 'openai-gpt-6-luna', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]);
        $events[] = 'data: '.json_encode(['model' => 'openai-gpt-6-luna', 'choices' => [], 'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 25]]);
        $events[] = 'data: [DONE]';
        Http::fake(['https://inference.do-ai.run/v1/chat/completions' => Http::response(implode("\n\n", $events)."\n\n", 200, ['Content-Type' => 'text/event-stream'])]);

        $conversation = $this->receiveCustomerEmail();

        $this->assertSame("Hello,\n\nWhere is my order?", Translations::get($conversation->threads()->first(), 'en'));
        Http::assertSent(fn (HttpRequest $request) => ($request['reasoning_effort'] ?? null) === 'none' && !isset($request['service_tier']));
        $this->assertTrue((bool) \App\Ai\Usage::where('conversation_id', $conversation->id)->where('feature', \App\Ai\Usage::FEATURE_TRANSLATION)->value('fast'));

        // Its fast mode on: priority processing too.
        $this->useModel('digitalocean', 'openai-gpt-6-luna', '', true);
        $conversation = $this->receiveCustomerEmail("Hallo,\n\nNog iets.");

        Http::assertSent(fn (HttpRequest $request) => ($request['reasoning_effort'] ?? null) === 'none' && ($request['service_tier'] ?? null) === 'priority');
        $this->assertTrue((bool) \App\Ai\Usage::where('conversation_id', $conversation->id)->where('feature', \App\Ai\Usage::FEATURE_TRANSLATION)->value('fast_tier'));
    }

    /**
     * A customer's message being translated: open conversations get the translation as it's
     * written, at most every half second (here: once, the clock stands still), then the
     * finished one. Only users who can see the conversation, in that language.
     */
    public function testTranslationsAreBroadcastAsTheyAreWritten()
    {
        $this->useModel('openai', 'gpt-5-mini');
        $this->freezeTime();
        ThreadTranslator::fake([['translation' => '<p>Hello,</p><p>where is <b>my order</b>?</p>', 'same_language' => false, 'detected_language' => 'nl']]);
        \DB::table('polycast_events')->delete();

        $conversation = $this->receiveCustomerEmail();
        $thread = $conversation->threads()->first();

        $writing = \DB::table('polycast_events')->where('event', RealtimeConvTranslating::class)->get();
        $this->assertCount(1, $writing);
        $payload = json_decode($writing[0]->payload);
        $this->assertSame([$thread->id], array_column((array) $payload->translations, 'thread_id'));
        $this->assertSame('<p>Hello,</p><p>where', $payload->translations[0]->text);
        $this->assertSame(1, \DB::table('polycast_events')->where('event', \App\Events\RealtimeConvNewThread::class)->where('payload', 'like', '%ai_updated%')->count());

        // What the browser gets: made safe, a tag being written left out.
        $this->actingAs($this->agent);
        $payload->translations[0]->text = '<p>Hello <script>alert(1)</script><b';
        $shown = RealtimeConvTranslating::processPayload(json_decode(json_encode($payload)));
        $this->assertSame('<div class="ai-translation-html"><p>Hello </div>', $shown->translations[0]->content);
        $this->assertFalse(property_exists($shown->translations[0], 'text'));

        $this->actingAs($this->createUser());
        $this->assertSame([], RealtimeConvTranslating::processPayload(json_decode(json_encode($payload))));
        $this->actingAs($this->agent);
        $payload->language = 'de';
        $this->assertSame([], RealtimeConvTranslating::processPayload(json_decode(json_encode($payload))));
    }

    /**
     * Chats: their messages' translations are broadcast together, as they're written.
     */
    public function testChatTranslationsAreBroadcastAsTheyAreWritten()
    {
        $this->useModel('openai', 'gpt-5-mini');
        Option::set('aiassistant.mailbox_chat_translation', [$this->mailbox->id => 1]);
        Option::$cache = [];
        ThreadTranslator::fake([['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']]);
        $conversation = $this->receiveCustomerEmail();
        $conversation->channel = \App\Telegram\Telegram::CHANNEL;
        $conversation->save();
        $threads = collect(['Hallo?', 'Is daar iemand?'])->map(fn ($text) => \App\Thread::create($conversation, \App\Thread::TYPE_CUSTOMER, $text, ['customer_id' => $conversation->customer_id, 'source_via' => \App\Thread::PERSON_CUSTOMER, 'source_type' => \App\Thread::SOURCE_TYPE_WEB]));
        $this->freezeTime();
        \DB::table('polycast_events')->delete();
        ChatTranslator::fake([['messages' => [['id' => $threads[0]->id, 'translation' => 'Hello?', 'same_language' => false], ['id' => $threads[1]->id, 'translation' => 'Is anyone there?', 'same_language' => false]], 'detected_language' => 'nl']]);

        (new \App\Jobs\AiTranslateChat($conversation->id, 'en'))->handle();

        $this->assertSame(['Hello?', 'Is anyone there?'], $threads->map(fn ($thread) => Translations::get($thread->fresh(), 'en'))->all());
        $writing = \DB::table('polycast_events')->where('event', RealtimeConvTranslating::class)->get();
        $this->assertCount(1, $writing);
        // As far as they were written (the fake writes word by word: the first piece goes up to "Is").
        $this->assertSame([
            ['thread_id' => $threads[0]->id, 'text' => 'Hello?', 'html' => false],
            ['thread_id' => $threads[1]->id, 'text' => 'Is', 'html' => false],
        ], json_decode($writing[0]->payload, true)['translations']);
    }

    /**
     * Answers being written are passed on at most once per interval.
     */
    public function testStreamThrottle()
    {
        $this->freezeTime();
        $throttle = new \App\Ai\StreamThrottle(0.5);

        $this->assertTrue($throttle->ready());
        $this->assertFalse($throttle->ready());
        \Carbon\Carbon::setTestNow(now()->addMilliseconds(400));
        $this->assertFalse($throttle->ready());
        \Carbon\Carbon::setTestNow(now()->addMilliseconds(100));
        $this->assertTrue($throttle->ready());
        $this->assertFalse($throttle->ready());
    }

    /**
     * The primary model failing halfway (an answer that isn't the JSON asked for): the backup's
     * translation is kept.
     */
    public function testTheBackupTranslatesWhenThePrimaryFailsHalfway()
    {
        $this->useModel('openai', 'gpt-5-mini');
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-test'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-ant'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['translations' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-5-mini'], 'backup' => ['provider' => 'p2', 'model' => 'claude-haiku-4-5']]]);
        Option::$cache = [];
        ThreadTranslator::fake(fn ($prompt, $attachments, $provider, $model) => $model == 'gpt-5-mini'
            ? '{"translation": "Hello, wh'
            : ['translation' => 'Hello, where is my order?', 'same_language' => false, 'detected_language' => 'nl']);

        $conversation = $this->receiveCustomerEmail();

        $this->assertSame('Hello, where is my order?', Translations::get($conversation->threads()->first(), 'en'));
        ThreadTranslator::assertPromptedTimes(2);
    }
}
