<?php

namespace Tests\Feature;

use App\Ai\Agents\ConversationSummarizer;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\Settings;
use App\Ai\Summaries;
use App\Ai\Translations;
use App\Conversation;
use App\Option;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * AI Assistant: settings, conversation summaries and translations of
 * customers' messages, with the AI provider faked.
 */
class AiAssistantTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        Option::$cache = [];
    }

    protected function configureAi(array $options = [])
    {
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.summary_conversation_threshold', 0);
        foreach ($options as $name => $value) {
            Option::set($name, $value);
        }
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
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

    protected function fakeAi()
    {
        ConversationSummarizer::fake([['one_liner' => 'Customer asks where the order is', 'background' => '']]);
        ThreadTranslator::fake([['translation' => "Hello,\n\nWhere is my order?", 'same_language' => false, 'detected_language' => 'nl']]);
    }

    protected function getConversationPage($user, Conversation $conversation)
    {
        $response = $this->actingAs($user)->get('/conversation/'.$conversation->id);
        for ($i = 0; $i < 3 && $response->isRedirect(); $i++) {
            $response = $this->actingAs($user)->get($response->headers->get('Location'));
        }

        return $response;
    }

    // Settings.

    public function testSettingsPageRenders()
    {
        $this->actingAs($this->admin)->get('/app-settings/ai')
            ->assertStatus(200)
            ->assertSee('Embedding Provider')
            ->assertSee($this->mailbox->name);
        $this->actingAs($this->agent)->get('/app-settings/ai')->assertStatus(403);
    }

    public function testSaveSettings()
    {
        $other = $this->createMailbox();

        $this->postForm($this->admin, '/app-settings/ai', ['settings' => [
            'aiassistant.providers'                       => [
                'p1'  => ['provider' => 'Anthropic', 'api_key' => 'sk-secret', 'base_url' => 'https://ai.example.org/v1/'],
                'new' => ['provider' => 'openai', 'api_key' => 'sk-two', 'base_url' => ''],
            ],
            'aiassistant.models'                          => [
                'summaries'    => ['primary' => ['provider' => 'p1', 'model' => ' claude-test '], 'backup' => ['provider' => 'p2', 'model' => 'gpt-backup']],
                'translations' => ['primary' => ['provider' => 'p2', 'model' => 'gpt-cheap'], 'backup' => ['provider' => '', 'model' => 'ignored']],
            ],
            'aiassistant.documentation.embedding_provider' => 'same',
            'aiassistant.documentation.chunk_size'        => 99,
            'aiassistant.summary_conversation_threshold'  => 50,
            'aiassistant.translation_language'            => 'nl',
            'aiassistant.drafts_per_day'                  => 7,
            'aiassistant.daily_tokens'                    => 50000,
            'aiassistant.translations_per_customer_hour'  => 20,
        ]])->assertRedirect(route('settings', ['section' => 'ai']));

        Option::$cache = [];
        $this->assertSame(['p1', 'p2'], array_keys(Settings::providers()));
        $this->assertSame('anthropic', Settings::provider());
        $this->assertSame('sk-secret', Settings::apiKey());
        $this->assertNotSame('sk-secret', Option::get('aiassistant.providers')[0]['api_key']);
        $this->assertSame('https://ai.example.org/v1', Settings::baseUrl());
        $this->assertSame(['primary' => ['p1', 'claude-test'], 'backup' => ['p2', 'gpt-backup']], Settings::featureModels('summaries'));
        $this->assertSame(['primary' => ['p2', 'gpt-cheap'], 'backup' => null], Settings::featureModels('translations'));
        $this->assertSame([['tallport-p1', 'claude-test'], ['tallport-p2', 'gpt-backup']], Settings::attempts('summaries'));
        $this->assertSame(500, (int) Option::get('aiassistant.documentation.chunk_size'));
        $this->assertSame(10, Settings::summaryThreshold());
        $this->assertSame(7, Settings::draftsPerDay(null));
        $this->assertSame(50000, Settings::dailyTokens());
        $this->assertSame(20, Settings::translationsPerCustomerHour());

        // The masked key keeps the key; a provider removed: its features use the first.
        $this->postForm($this->admin, '/app-settings/ai', ['settings' => [
            'aiassistant.providers' => ['p1' => ['provider' => 'anthropic', 'api_key' => '******'], 'p2' => ['provider' => 'openai', 'api_key' => '******', 'remove' => 1]],
            'aiassistant.models'    => Option::get('aiassistant.models'),
        ]]);
        Option::$cache = [];
        $this->assertSame('sk-secret', Settings::apiKey());
        $this->assertSame(['p1'], array_keys(Settings::providers()));
        $this->assertSame(['primary' => ['p1', 'gpt-cheap'], 'backup' => null], Settings::featureModels('translations'));
        $this->assertNull(Settings::featureModels('summaries')['backup']);
    }

    /**
     * Each mailbox's AI Assistant is on its own page (a row on the mailbox's page, and in Settings ›
     * AI Assistant): features, language and glossary, chat translation; admins only.
     */
    public function testMailboxAiPage()
    {
        $this->configureAi(['aiassistant.translation_language' => 'nl']);
        $page = route('mailboxes.ai', ['id' => $this->mailbox->id]);

        $this->actingAs($this->admin)->get(route('mailboxes.update', ['id' => $this->mailbox->id]))->assertSeeInOrder(['AI Assistant', 'On · Dutch']);
        $this->actingAs($this->admin)->get('/app-settings/ai')->assertSee('href="'.$page.'"', false)->assertSeeInOrder([$this->mailbox->name, 'On · Dutch']);
        $this->actingAs($this->admin)->get($page)->assertOk()
            ->assertSee('app-sidebar__back', false)
            ->assertSeeInOrder(['<h1>AI Assistant</h1>', 'Features', 'Summaries', 'Language', 'Default (Dutch)', 'Glossary', 'Chat Translation', 'Translate Chats', 'Mark Translated Replies', 'Customer Context'], false);
        $this->actingAs($this->agent)->get($page)->assertForbidden();

        $this->postForm($this->admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), [
            'features' => ['summaries' => 1], 'language' => 'de', 'glossary' => " 12VPX\n", 'chat_translation' => 1, 'translation_note' => 1,
        ])->assertRedirect($page);
        Option::$cache = [];
        $this->assertSame('de', Settings::language($this->mailbox));
        $this->assertTrue(Settings::enabled('summaries', $this->mailbox));
        $this->assertFalse(Settings::enabled('translations', $this->mailbox));
        $this->assertFalse(Settings::enabled('drafts', $this->mailbox));
        $this->assertTrue(Settings::chatTranslation($this->mailbox));
        $this->assertTrue(Settings::translationNote($this->mailbox));
        $this->assertSame('12VPX', Settings::glossary($this->mailbox));

        // Chat translation off: the note kept (its control was off); everything off: "Off".
        $this->postForm($this->admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['language' => '']);
        Option::$cache = [];
        $this->assertFalse(Settings::chatTranslation($this->mailbox));
        $this->assertTrue(Settings::translationNote($this->mailbox));
        $this->assertSame('nl', Settings::language($this->mailbox));
        $this->assertSame('Off', Settings::mailboxSummary($this->mailbox));

        // Not set up: says so, and saving changes nothing.
        Option::set('aiassistant.api_key', '');
        Option::$cache = [];
        $this->actingAs($this->admin)->get($page)->assertSee('The AI Assistant isn&#039;t set up yet.', false)->assertDontSee('form="page-form"', false);
        $this->postForm($this->admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['features' => ['drafts' => 1]]);
        Option::$cache = [];
        $this->assertFalse(Settings::enabled('drafts', $this->mailbox));
        $this->assertSame('Not set up', Settings::mailboxSummary($this->mailbox));
    }

    /**
     * A feature's primary model failing (for any reason): its backup answers.
     */
    public function testTheBackupModelAnswersWhenThePrimaryFails()
    {
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-one'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-two'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['translations' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-x'], 'backup' => ['provider' => 'p2', 'model' => 'claude-y']]]);
        Option::$cache = [];
        $calls = 0;
        ThreadTranslator::fake(function () use (&$calls) {
            if (++$calls == 1) {
                throw new \RuntimeException('Incorrect API key provided');
            }

            return ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl'];
        });

        $thread = $this->receiveCustomerEmail()->threads()->first();

        $this->assertSame(2, $calls);
        $this->assertSame('Where is my order?', Translations::get($thread, 'en'));

        // No backup: the failure shows.
        Option::set('aiassistant.models', ['translations' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-x']]]);
        Option::$cache = [];
        ThreadTranslator::fake(function () {
            throw new \RuntimeException('Incorrect API key provided');
        });
        $other = $this->receiveCustomerEmail("Hallo,\n\nNog iets.")->threads()->first();
        $this->assertSame(['error', 'Incorrect API key provided'], Translations::reason($other, 'en'));
    }

    public function testSettingsRejectNonHttpUrls()
    {
        $this->postForm($this->admin, '/app-settings/ai', ['settings' => ['aiassistant.providers' => ['p1' => ['provider' => 'openai', 'base_url' => 'javascript:alert(1)']]]])
            ->assertSessionHasErrors('settings.aiassistant.providers.0.base_url');
    }

    public function testLanguageFromUserElseMailboxElseInstallation()
    {
        $this->assertSame('en', Settings::language($this->mailbox, $this->agent));

        Option::set('aiassistant.translation_language', 'fr');
        $this->assertSame('fr', Settings::language($this->mailbox, $this->agent));

        Option::set('aiassistant.mailbox_language', [$this->mailbox->id => 'de']);
        $this->assertSame('de', Settings::language($this->mailbox, $this->agent));

        $this->agent->ai_language = 'ja';
        $this->assertSame('ja', Settings::language($this->mailbox, $this->agent));
    }

    public function testUserSetsOwnLanguageAndAdminSetsDraftLimit()
    {
        $data = ['first_name' => 'Agent', 'email' => $this->agent->email, 'timezone' => 'UTC', 'time_format' => 2];

        $this->postForm($this->agent, '/users/profile/'.$this->agent->id, $data + ['ai_language' => 'nl', 'ai_drafts_per_day' => 999]);
        $this->agent->refresh();
        $this->assertSame('nl', $this->agent->ai_language);
        $this->assertNull($this->agent->ai_drafts_per_day);

        $this->postForm($this->admin, '/users/profile/'.$this->agent->id, $data + ['ai_language' => '', 'ai_drafts_per_day' => 0]);
        $this->agent->refresh();
        $this->assertNull($this->agent->ai_language);
        $this->assertSame(0, Settings::draftsPerDay($this->agent));

        $this->postForm($this->agent, '/users/profile/'.$this->agent->id, $data + ['ai_language' => 'xx'])
            ->assertSessionHasErrors('ai_language');
    }

    // Summaries and translations.

    public function testNothingIsSentToAiUntilConfigured()
    {
        $this->fakeAi();

        $conversation = $this->receiveCustomerEmail();
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)->assertDontSee('f-message__translation', false);

        ConversationSummarizer::assertNeverPrompted();
        ThreadTranslator::assertNeverPrompted();
    }

    public function testCustomerMessageIsSummarizedAndTranslated()
    {
        $this->configureAi();
        $this->fakeAi();

        $conversation = $this->receiveCustomerEmail();

        ConversationSummarizer::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, '<conversation>') && str_contains($prompt->prompt, 'Waar blijft mijn bestelling?');
        });
        ThreadTranslator::assertPrompted(function ($prompt) {
            return str_contains($prompt->prompt, '<message>') && $prompt->agent->language == 'en';
        });

        $conversation->refresh();
        $this->assertSame('Customer asks where the order is', Summaries::get($conversation, 'en')['one_liner']);
        $this->assertFalse(Summaries::isStale($conversation, 'en'));
        $thread = $conversation->threads()->first();
        $this->assertSame("Hello,\n\nWhere is my order?", Translations::get($thread, 'en'));
        $this->assertFalse(Translations::isMissing($thread, 'en'));

        $this->getConversationPage($this->agent, $conversation)
            ->assertStatus(200)
            ->assertSee('ai-summary-line', false)->assertSee('<span>Customer asks where the order is</span>', false)
            ->assertDontSee('ai-summary-line__background', false)
            ->assertSee('f-message__translation', false)
            ->assertSee('Where is my order?');
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id)
            ->assertSee('Customer asks where the order is');

        // Shown once: viewing doesn't ask again.
        ConversationSummarizer::assertPromptedTimes(1);
        ThreadTranslator::assertPromptedTimes(1);
    }

    /**
     * A message's menu offers Translate when it has no translation for the user: also an
     * agent's reply (e.g. written with another translator). It is translated right away.
     */
    public function testTranslateOnRequestAlsoForReplies()
    {
        $this->configureAi();
        $this->fakeAi();
        $conversation = $this->receiveCustomerEmail();
        $reply = \App\Thread::createExtended(['type' => \App\Thread::TYPE_MESSAGE, 'body' => '<p>Uw bestelling is onderweg.</p>', 'created_by_user_id' => $this->agent->id], $conversation, $conversation->customer);
        $note = \App\Thread::createExtended(['type' => \App\Thread::TYPE_NOTE, 'body' => '<p>Intern</p>', 'created_by_user_id' => $this->agent->id], $conversation, $conversation->customer);

        $thread = \Livewire\Livewire::actingAs($this->agent)->test(\App\Livewire\ConversationThread::class, ['conversation' => $conversation])
            ->assertSee('translate('.$reply->id.')', false)
            ->assertDontSee('translate('.$note->id.')', false);

        ThreadTranslator::fake([['translation' => 'Your order is on its way.', 'same_language' => false, 'detected_language' => 'nl']]);
        $thread->call('translate', $reply->id)
            ->assertSee('Your order is on its way.')
            ->assertDontSee('translate('.$reply->id.')', false);
        $this->assertSame('Your order is on its way.', Translations::get($reply->fresh(), 'en'));

        // Notes stay untranslated, even when asked.
        $thread->call('translate', $note->id);
        $this->assertNull(Translations::get($note->fresh(), 'en'));
    }

    /**
     * A message is translated as it looks: its links, images and layout (a signature's
     * business card) go as simple HTML and come back translated, shown made safe.
     */
    public function testTranslationKeepsLinksAndLayout()
    {
        $this->configureAi();
        $this->fakeAi();
        $conversation = $this->receiveCustomerEmail();
        $thread = \App\Thread::createExtended(['type' => \App\Thread::TYPE_CUSTOMER, 'body' => '<p style="color:red">Bedankt!</p><table><tr><td><b>Jane</b><br><a href="https://acme.test/card" onclick="x()">Mijn visitekaartje</a></td></tr></table><script>alert(1)</script>'], $conversation, $conversation->customer);

        ThreadTranslator::fake([['translation' => '<p>Thanks!</p><table><tr><td><b>Jane</b><br><a href="https://acme.test/card">My business card</a><script>alert(2)</script></td></tr></table>', 'same_language' => false, 'detected_language' => 'nl']]);
        Translations::translate($thread->fresh(), 'en');
        ThreadTranslator::assertPrompted(function ($prompt) {
            return $prompt->agent->html && str_contains($prompt->prompt, 'acme.test/card') && str_contains($prompt->prompt, 'Mijn visitekaartje</a>')
                && !str_contains($prompt->prompt, 'onclick') && !str_contains($prompt->prompt, '<script') && !str_contains($prompt->prompt, 'style=');
        });
        $this->assertTrue(Translations::isHtml($thread->fresh(), 'en'));

        $this->getConversationPage($this->agent, $conversation)
            ->assertSee('<a href="https://acme.test/card"', false)
            ->assertSee('My business card')
            ->assertDontSee('alert(2)', false);
    }

    /**
     * The tokens of each AI call are recorded, and the conversation's total is shown,
     * quietly, in its sidebar.
     */
    public function testTokensUsedPerConversation()
    {
        $this->configureAi(['aiassistant.translation_language' => 'en']);
        ThreadTranslator::fake([new \Laravel\Ai\Responses\StructuredTextResponse(
            ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl'], '{}',
            new \Laravel\Ai\Responses\Data\TextUsage(1200, 34), new \Laravel\Ai\Responses\Data\Meta
        )]);

        $conversation = $this->receiveCustomerEmail();

        $this->assertSame(1234, \App\Ai\Usage::forConversation($conversation));
        $this->assertDatabaseHas('aiassistant_usage', ['conversation_id' => $conversation->id, 'mailbox_id' => $this->mailbox->id, 'customer_id' => $conversation->customer_id, 'feature' => 'translation', 'input_tokens' => 1200, 'output_tokens' => 34]);
        $this->getConversationPage($this->agent, $conversation)->assertSee('AI Assistant: 1,234 tokens');
    }

    /**
     * Over the mailbox's daily tokens, or the customer's translations per hour, a message
     * isn't translated (no AI call), and says why; drafts and summaries wait too.
     */
    public function testLimitsKeepTheAiFromBeingCalled()
    {
        $this->configureAi(['aiassistant.translation_language' => 'en', 'aiassistant.daily_tokens' => 1000]);
        $this->fakeAi();
        \App\Ai\Usage::create(['mailbox_id' => $this->mailbox->id, 'feature' => 'summary', 'input_tokens' => 900, 'output_tokens' => 100]);

        $conversation = $this->receiveCustomerEmail();
        ThreadTranslator::assertNeverPrompted();
        $this->getConversationPage($this->agent, $conversation)->assertSee('Not translated: this mailbox has used its AI tokens for today.');
        $this->postAjax($this->agent, route('ai.drafts.store', ['id' => $conversation->id]), [])->assertStatus(429);
        // System Status says which mailboxes.
        $problems = collect(\App\Http\Controllers\SystemController::problems(\App\Http\Controllers\SystemController::statusData()))->keyBy(0);
        $this->assertSame($this->mailbox->name, $problems['ai_budget'][3]);

        // Tomorrow: translated.
        $this->travel(1)->days();
        $this->getConversationPage($this->agent, $conversation);
        ThreadTranslator::assertPromptedTimes(1);

        // A customer's flood: translated up to the hourly limit.
        Option::set('aiassistant.translations_per_customer_hour', 1);
        Option::$cache = [];
        $flood = $this->receiveCustomerEmail("Hallo,\n\nNog een vraag.");
        ThreadTranslator::assertPromptedTimes(1);
        $this->getConversationPage($this->agent, $flood)->assertSee('Not translated: this customer sent more messages in the last hour than are translated.');
    }

    public function testMessageInTheTargetLanguageIsNotTranslated()
    {
        $this->configureAi(['aiassistant.translation_language' => 'nl']);
        $this->fakeAi();
        ThreadTranslator::fake([['translation' => '', 'same_language' => true, 'detected_language' => 'nl']]);

        $conversation = $this->receiveCustomerEmail();
        $thread = $conversation->threads()->first();

        $this->assertNull(Translations::get($thread, 'nl'));
        $this->assertFalse(Translations::isMissing($thread->fresh(), 'nl'));
        $this->getConversationPage($this->agent, $conversation)->assertDontSee('f-message__translation', false)->assertDontSee('Not translated');
        ThreadTranslator::assertPromptedTimes(1);
    }

    /**
     * A "translation" into the language the message was detected in (one quoting an
     * email in another language, say) is no translation: the message is left as it is.
     */
    public function testMessageDetectedInTheTargetLanguageIsNotTranslated()
    {
        $this->configureAi(['aiassistant.translation_language' => 'nl']);
        $this->fakeAi();
        ThreadTranslator::fake([['translation' => 'Waar is mijn bestelling?', 'same_language' => false, 'detected_language' => 'nl']]);

        $thread = $this->receiveCustomerEmail()->threads()->first();

        $this->assertNull(Translations::get($thread, 'nl'));
        $this->assertFalse(Translations::isMissing($thread->fresh(), 'nl'));
    }

    /**
     * A message without a translation says why.
     */
    public function testWhyAMessageIsNotTranslated()
    {
        $this->configureAi();
        $this->fakeAi();

        // Taken to be in the language already, in another one.
        ThreadTranslator::fake([['translation' => '', 'same_language' => true, 'detected_language' => 'nl']]);
        $conversation = $this->receiveCustomerEmail();
        $this->getConversationPage($this->agent, $conversation)
            ->assertSee('Not translated: the AI Assistant took this message to be in English already, though it detected Dutch.');

        // Failed: tried again when opened.
        ThreadTranslator::fake(function () {
            throw new \RuntimeException('Rate limit reached');
        });
        $conversation = $this->receiveCustomerEmail("Hallo,\n\nEen andere vraag.");
        $thread = $conversation->threads()->first();
        $this->assertSame(['error', 'Rate limit reached'], Translations::reason($thread->fresh(), 'en'));
        $this->getConversationPage($this->agent, $conversation)
            ->assertSee('Not translated: the AI Assistant failed (Rate limit reached). It tries again when the conversation is opened.');
        ThreadTranslator::fake([['translation' => 'Another question.', 'same_language' => false, 'detected_language' => 'nl']]);
        $this->getConversationPage($this->agent, $conversation);
        $this->getConversationPage($this->agent, $conversation)->assertSee('Another question.')->assertDontSee('Not translated');
        $this->assertArrayNotHasKey('errors', \App\Ai\Summaries::data($thread->fresh()));

        // Only the start of a long message.
        ThreadTranslator::fake([['translation' => 'Long', 'same_language' => false, 'detected_language' => 'nl']]);
        $conversation = $this->receiveCustomerEmail(str_repeat('Waar blijft mijn bestelling? ', 200));
        $this->getConversationPage($this->agent, $conversation)->assertSee('Only the first 4000 characters were translated.');

        // Nothing to translate.
        $thread = $conversation->threads()->first();
        \DB::table('threads')->where('id', $thread->id)->update(['body' => '<p> </p>', 'ai_assistant' => null]);
        Translations::translate($thread->fresh(), 'en');
        $this->assertSame(['no_text'], Translations::reason($thread->fresh(), 'en'));
        $this->assertFalse(Translations::isMissing($thread->fresh(), 'en'));

        // Not sent yet.
        \DB::table('threads')->where('id', $thread->id)->update(['ai_assistant' => null]);
        $this->assertSame(['waiting'], Translations::reason($thread->fresh(), 'en'));
    }

    public function testViewerGetsTheirOwnLanguage()
    {
        $this->configureAi();
        $this->fakeAi();
        $conversation = $this->receiveCustomerEmail();

        $this->agent->ai_language = 'de';
        $this->agent->save();
        ConversationSummarizer::fake([['one_liner' => 'Kunde fragt nach der Bestellung', 'background' => '']]);
        ThreadTranslator::fake([['translation' => 'Wo bleibt meine Bestellung?', 'same_language' => false, 'detected_language' => 'nl']]);

        // The first view asks for them (queued), later views show them.
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200);
        $this->getConversationPage($this->agent, $conversation)
            ->assertSee('Kunde fragt nach der Bestellung')
            ->assertSee('Wo bleibt meine Bestellung?');
        $this->assertNotNull(Summaries::get($conversation->fresh(), 'en'));
    }

    public function testFeaturesCanBeTurnedOffPerMailbox()
    {
        $this->configureAi(['aiassistant.mailbox_features_off' => [$this->mailbox->id => ['summaries', 'translations']]]);
        $this->fakeAi();

        $conversation = $this->receiveCustomerEmail();
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200);

        ConversationSummarizer::assertNeverPrompted();
        ThreadTranslator::assertNeverPrompted();
    }

    public function testNewMessageMakesTheSummaryStale()
    {
        $this->configureAi();
        $this->fakeAi();
        $conversation = $this->receiveCustomerEmail();
        $this->assertFalse(Summaries::isStale($conversation->fresh(), 'en'));

        ConversationSummarizer::fake([['one_liner' => 'Customer answered', 'background' => '']]);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Our answer</p>',
        ]);

        $conversation->refresh();
        $this->assertSame('Customer answered', Summaries::get($conversation, 'en')['one_liner']);
        $this->assertFalse(Summaries::isStale($conversation, 'en'));
    }

    public function testFailingProviderIsLogged()
    {
        $this->configureAi();
        ConversationSummarizer::fake(function () {
            throw new \RuntimeException('Provider down');
        });
        ThreadTranslator::fake([['translation' => 'x', 'same_language' => false, 'detected_language' => 'nl']]);

        $conversation = $this->receiveCustomerEmail();

        $this->assertNull(Summaries::get($conversation->fresh(), 'en'));
        $this->assertNotNull(Translations::get($conversation->threads()->first(), 'en'));
    }

    public function testAiWorkerRunsOnceConfigured()
    {
        $workers = function () {
            $schedule = new \Illuminate\Console\Scheduling\Schedule();
            $method = new \ReflectionMethod(\App\Console\Kernel::class, 'schedule');
            $method->invoke($this->app->make(\Illuminate\Contracts\Console\Kernel::class), $schedule);

            return collect($schedule->events())->pluck('command')->filter(function ($command) {
                return str_contains((string) $command, 'queue:work');
            })->values();
        };

        $this->assertCount(1, $workers());
        Livewire::withoutLazyLoading();
        $this->actingAs($this->admin)->get('/system/status')->assertDontSee('queue:work (AI)');

        $this->configureAi();
        $this->assertCount(2, $workers());
        $this->assertStringContainsString("--queue='ai-drafts,ai,".\Helper::getWorkerIdentifier(\App\Console\Kernel::AI_WORKER)."'", $workers()[1]);
        Livewire::withoutLazyLoading();
        $this->actingAs($this->admin)->get('/system/status')->assertSee('queue:work (AI)');
    }

    // The earlier assistant's data.

    public function testConvertedSummaryWithoutDateIsShown()
    {
        $conversation = $this->receiveCustomerEmail();
        \DB::table('conversations')->where('id', $conversation->id)->update([
            'ai_assistant' => '{"summaries":{"en":{"one_liner":"Old one-liner","summary":"- Old summary"}}}',
        ]);

        // The one-liner shows; the old chronological summary doesn't, and it's made again.
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)->assertSee('Old one-liner')->assertDontSee('Old summary');
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id)->assertSee('Old one-liner');
        $this->assertTrue(Summaries::isStale($conversation->fresh(), 'en'));
    }

    /**
     * A translation on its way shows in the translation's card; when it's done, open pages
     * show it without a reload (RealtimeConvNewThread, public/js/realtime.js).
     */
    public function testTranslationOnItsWayAndThenShown()
    {
        $this->configureAi();
        \Queue::fake([\App\Jobs\AiTranslateThread::class]);
        $conversation = $this->receiveCustomerEmail();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)
            ->assertSee('ai-translation-waiting', false)->assertDontSee('Waiting for the AI Assistant');

        $broadcast = [];
        \Event::listen(\App\Events\RealtimeConvNewThread::class, function ($event) use (&$broadcast) {
            $broadcast[] = $event->data;
        });
        ThreadTranslator::fake([['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']]);
        (new \App\Jobs\AiTranslateThread($thread->id, 'en'))->handle();

        $this->assertContains($thread->id, array_column(array_filter($broadcast, fn ($data) => !empty($data['ai_updated'])), 'thread_id'));
        $this->getConversationPage($this->agent, $conversation)->assertSee('Where is my order?')->assertDontSee('ai-translation-waiting', false);
    }

    /**
     * Where it stands, always; what's been tried and what's open only for long conversations.
     */
    public function testBackgroundOnlyForLongConversations()
    {
        $this->configureAi();
        $conversation = $this->receiveCustomerEmail();

        ConversationSummarizer::fake([['one_liner' => 'Short one', 'background' => '- Should not be kept']]);
        Summaries::summarize($conversation, 'en');
        ConversationSummarizer::assertPrompted(fn ($prompt) => $prompt->agent->with_background === false);
        $this->assertSame('', Summaries::get($conversation->fresh(), 'en')['background']);

        for ($i = 0; $i < Summaries::BACKGROUND_MIN_MESSAGES; $i++) {
            $this->postAjax($this->agent, '/conversation/ajax', [
                'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Note '.$i.'</p>', 'is_note' => 1,
            ]);
        }
        ConversationSummarizer::fake([['one_liner' => 'Long one', 'background' => "- Restarting did not help\n- Still open: other users"]]);
        Summaries::summarize($conversation->fresh(), 'en');
        ConversationSummarizer::assertPrompted(fn ($prompt) => $prompt->agent->with_background === true);

        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)
            ->assertSeeInOrder(['Long one', 'Background', 'Restarting did not help', 'Still open: other users']);
    }

    public function testEarlierDataIsConvertedByLanguage()
    {
        if (!class_exists('AddAiAssistantColumns')) {
            require base_path('database/migrations/2026_10_04_010101_add_ai_assistant_columns.php');
        }

        $this->assertSame(
            ['summaries' => ['en' => ['one_liner' => 'Short', 'summary' => '- Long']]],
            json_decode(\AddAiAssistantColumns::summaries('{"one_liner":"Short","summary":"- Long"}'), true)
        );
        $this->assertNull(\AddAiAssistantColumns::summaries('{"summaries":{}}'));
        $this->assertNull(\AddAiAssistantColumns::summaries(null));

        $this->assertSame(
            ['translations' => ['nl' => 'Hallo']],
            json_decode(\AddAiAssistantColumns::translations('{"translation":"Hallo"}', 'nl'), true)
        );
        $this->assertSame(
            ['language' => 'nl', 'translations' => []],
            json_decode(\AddAiAssistantColumns::translations(null, 'nl'), true)
        );
        $this->assertNull(\AddAiAssistantColumns::translations('{"translations":{}}', 'nl'));
    }
}
