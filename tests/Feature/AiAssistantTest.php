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
        ConversationSummarizer::fake([['one_liner' => 'Customer asks where the order is', 'summary' => "- Order is late\n- Wants an update"]]);
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
            'aiassistant.provider'                        => 'Anthropic',
            'aiassistant.api_key'                         => 'sk-secret',
            'aiassistant.base_url'                        => 'https://ai.example.org/v1/',
            'aiassistant.model'                           => ' claude-test ',
            'aiassistant.documentation.embedding_provider' => 'same',
            'aiassistant.documentation.chunk_size'        => 99,
            'aiassistant.summary_conversation_threshold'  => 50,
            'aiassistant.translation_language'            => 'nl',
            'aiassistant.drafts_per_day'                  => 7,
            'aiassistant.mailbox_language'                => [$this->mailbox->id => 'de', $other->id => ''],
            'aiassistant.mailbox_features_on'             => [$this->mailbox->id => ['summaries' => 1]],
        ]])->assertRedirect(route('settings', ['section' => 'ai']));

        Option::$cache = [];
        $this->assertSame('anthropic', Settings::provider());
        $this->assertSame('sk-secret', Settings::apiKey());
        $this->assertNotSame('sk-secret', Option::get('aiassistant.api_key'));
        $this->assertSame('https://ai.example.org/v1', Settings::baseUrl());
        $this->assertSame('claude-test', Settings::model());
        $this->assertSame(500, (int) Option::get('aiassistant.documentation.chunk_size'));
        $this->assertSame(10, Settings::summaryThreshold());
        $this->assertSame(7, Settings::draftsPerDay(null));
        $this->assertSame('de', Settings::language($this->mailbox));
        $this->assertSame('nl', Settings::language($other));
        $this->assertTrue(Settings::enabled('summaries', $this->mailbox));
        $this->assertFalse(Settings::enabled('translations', $this->mailbox));
        $this->assertFalse(Settings::enabled('drafts', $other));

        // The masked key keeps the key.
        $this->postForm($this->admin, '/app-settings/ai', ['settings' => ['aiassistant.api_key' => '******']]);
        Option::$cache = [];
        $this->assertSame('sk-secret', Settings::apiKey());
    }

    public function testSettingsRejectNonHttpUrls()
    {
        $this->postForm($this->admin, '/app-settings/ai', ['settings' => ['aiassistant.base_url' => 'javascript:alert(1)']])
            ->assertSessionHasErrors('settings.aiassistant.base_url');
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
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)->assertDontSee('AI Translation');

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
            ->assertSee('Order is late')
            ->assertSee('AI Translation')
            ->assertSee('Where is my order?');
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id)
            ->assertSee('Customer asks where the order is');

        // Shown once: viewing doesn't ask again.
        ConversationSummarizer::assertPromptedTimes(1);
        ThreadTranslator::assertPromptedTimes(1);
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
        $this->getConversationPage($this->agent, $conversation)->assertDontSee('AI Translation');
        ThreadTranslator::assertPromptedTimes(1);
    }

    public function testViewerGetsTheirOwnLanguage()
    {
        $this->configureAi();
        $this->fakeAi();
        $conversation = $this->receiveCustomerEmail();

        $this->agent->ai_language = 'de';
        $this->agent->save();
        ConversationSummarizer::fake([['one_liner' => 'Kunde fragt nach der Bestellung', 'summary' => '- Bestellung verspätet']]);
        ThreadTranslator::fake([['translation' => 'Wo bleibt meine Bestellung?', 'same_language' => false, 'detected_language' => 'nl']]);

        // The first view asks for them (queued), later views show them.
        $this->getConversationPage($this->agent, $conversation)->assertStatus(200);
        $this->getConversationPage($this->agent, $conversation)
            ->assertSee('Bestellung verspätet')
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

        ConversationSummarizer::fake([['one_liner' => 'Customer answered', 'summary' => '- New info']]);
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

    // The earlier assistant's data.

    public function testConvertedSummaryWithoutDateIsShown()
    {
        $conversation = $this->receiveCustomerEmail();
        \DB::table('conversations')->where('id', $conversation->id)->update([
            'ai_assistant' => '{"summaries":{"en":{"one_liner":"Old one-liner","summary":"- Old summary"}}}',
        ]);

        $this->getConversationPage($this->agent, $conversation)->assertStatus(200)->assertSee('Old summary');
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id)->assertSee('Old one-liner');
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
