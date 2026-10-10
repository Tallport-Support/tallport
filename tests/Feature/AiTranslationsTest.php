<?php

namespace Tests\Feature;

use App\Ai\Agents\ChatTranslator;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\ChatTranslation;
use App\Ai\Translations;
use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Livewire\ConversationThread;
use App\Option;
use App\Telegram\Telegram;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Translations when things don't go to plan (AI faked): limits, failures, a message the AI
 * left out, and what a message looks like when it goes to translation.
 */
class AiTranslationsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::fake();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Bestelling', 'body' => 'Waar blijft mijn bestelling?',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        // Configured after receiving: nothing translated yet.
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.translation_language', 'en');
        Option::set('aiassistant.mailbox_chat_translation', [$this->mailbox->id => 1]);
        // Nothing translated or summarized by itself: each test asks.
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['summaries', 'translations']]);
        Option::$cache = [];
        $this->conversation->channel = Telegram::CHANNEL;
        $this->conversation->save();
    }

    protected function customerMessage($text)
    {
        return Thread::create($this->conversation, Thread::TYPE_CUSTOMER, $text, [
            'customer_id' => $this->conversation->customer_id, 'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);
    }

    protected function first()
    {
        return $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->orderBy('id')->first();
    }

    /**
     * Simple HTML: unknown tags unwrapped, their text kept; nothing for an empty message.
     */
    public function testWhatGoesToTranslation()
    {
        $thread = new Thread();
        $thread->body = '<div><span style="color:red">Waar</span> <font face="Arial">blijft</font> <a href="https://shop.example.org/o/1" onclick="x()">mijn bestelling</a>?</div>';
        $this->assertSame('<div>Waar blijft <a href="https://shop.example.org/o/1">mijn bestelling</a>?</div>', Translations::sourceHtml($thread));

        $thread->body = '<p>Bekijk <img src="/images/invoice.png" alt="bestelling 42"> naast <img src="https://shop.example.org/logo.png" alt="logo"></p>';
        $this->assertSame('<p>Bekijk bestelling 42 naast <img src="https://shop.example.org/logo.png" alt="logo"></p>', Translations::sourceHtml($thread));

        $saved_thread = $this->first();
        \DB::table('threads')->where('id', $saved_thread->id)->update(['body' => $thread->body]);
        ThreadTranslator::fake([['translation' => '<p>View order 42 beside the logo.</p>', 'same_language' => false, 'detected_language' => 'nl']]);
        $this->assertSame('<p>View order 42 beside the logo.</p>', Translations::translate($saved_thread->fresh(), 'en'));
        ThreadTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'bestelling 42') && !str_contains($prompt->prompt, '/images/invoice.png'));

        $thread->body = '';
        $this->assertSame('', Translations::sourceHtml($thread));
    }

    /**
     * Translated again as text (a message too long to send as HTML): no longer marked HTML.
     */
    public function testATranslationAsTextReplacesOneAsHtml()
    {
        $thread = $this->first();
        ThreadTranslator::fake([
            ['translation' => '<p>Where is my order?</p>', 'same_language' => false, 'detected_language' => 'nl'],
            ['translation' => 'Where is my order? Long.', 'same_language' => false, 'detected_language' => 'nl'],
        ]);
        Translations::translate($thread, 'en');
        $this->assertTrue(Translations::isHtml($thread->fresh(), 'en'));

        \DB::table('threads')->where('id', $thread->id)->update(['body' => '<p>'.str_repeat('Waar blijft mijn bestelling? ', 500).'</p>']);
        Translations::translate($thread->fresh(), 'en');

        ThreadTranslator::assertPrompted(fn ($prompt) => !$prompt->agent->html);
        $this->assertFalse(Translations::isHtml($thread->fresh(), 'en'));
        $this->assertSame('Where is my order? Long.', Translations::get($thread->fresh(), 'en'));
        $this->assertTrue(\App\Ai\Summaries::data($thread->fresh())['truncated']);
    }

    public function testSlowTranslationKeepsAnotherLanguageAndAnAgentsLanguageChoice()
    {
        $thread = $this->first();
        $conversation = $this->conversation;
        $this->assertNull($thread->customer->language);
        ThreadTranslator::fake(function () use ($thread, $conversation) {
            Translations::store($thread->fresh(), 'de', 'nl', 'Wo bleibt meine Bestellung?');
            ChatTranslation::setCustomerLanguage($conversation->fresh()->customer, 'ja', true);

            return ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl'];
        });

        $this->assertSame('Where is my order?', Translations::translate($thread, 'en'));
        $this->assertSame('Wo bleibt meine Bestellung?', Translations::get($thread->fresh(), 'de'));
        $this->assertSame('ja', ChatTranslation::customerLanguage($conversation->fresh()));
    }

    public function testIncomingNothingToTranslate()
    {
        ChatTranslator::fake()->preventStrayPrompts();
        Translations::store($this->first(), 'en', 'nl', 'Where is my order?');

        $this->assertSame([], ChatTranslation::translateIncoming($this->conversation, 'en'));
        ChatTranslator::assertNeverPrompted();
    }

    /**
     * Over the mailbox's tokens for today: each message says so, no AI call.
     */
    public function testIncomingOverTheMailboxesTokens()
    {
        ChatTranslator::fake()->preventStrayPrompts();
        Option::set('aiassistant.daily_tokens', 1);
        Option::$cache = [];
        \App\Ai\Usage::create(['mailbox_id' => $this->mailbox->id, 'feature' => 'summary', 'input_tokens' => 5]);
        $second = $this->customerMessage('Hallo?');

        $this->assertCount(2, ChatTranslation::translateIncoming($this->conversation, 'en'));

        ChatTranslator::assertNeverPrompted();
        $this->assertSame(['budget'], Translations::reason($this->first()->fresh(), 'en'));
        $this->assertSame(['budget'], Translations::reason($second->fresh(), 'en'));
    }

    /**
     * The customer's messages per hour used up: all wait, no AI call. No limit set: all go.
     */
    public function testIncomingCustomerLimit()
    {
        Option::set('aiassistant.translations_per_customer_hour', 2);
        Option::$cache = [];
        \App\Ai\Usage::create(['mailbox_id' => $this->mailbox->id, 'customer_id' => $this->conversation->customer_id, 'feature' => 'translation', 'items' => 2, 'input_tokens' => 5]);
        ChatTranslator::fake()->preventStrayPrompts();
        $second = $this->customerMessage('Hallo?');

        $this->assertCount(2, ChatTranslation::translateIncoming($this->conversation, 'en'));
        ChatTranslator::assertNeverPrompted();
        $this->assertSame(['customer_limit'], Translations::reason($this->first()->fresh(), 'en'));
        $this->assertSame(['customer_limit'], Translations::reason($second->fresh(), 'en'));

        Option::set('aiassistant.translations_per_customer_hour', 0);
        Option::$cache = [];
        ChatTranslator::fake([['messages' => [
            ['id' => $this->first()->id, 'translation' => 'Where is my order?', 'same_language' => false],
            ['id' => $second->id, 'translation' => '', 'same_language' => true],
        ], 'detected_language' => 'nl'],]);
        ChatTranslation::translateIncoming($this->conversation, 'en');
        $this->assertSame('Where is my order?', Translations::get($this->first()->fresh(), 'en'));
        $this->assertFalse(Translations::isMissing($second->fresh(), 'en'), 'Taken to be in English already.');
        $this->assertNull(Translations::get($second->fresh(), 'en'));
    }

    /**
     * The AI failing, or leaving a message out: kept as the message's error (tried again later).
     */
    public function testIncomingFailures()
    {
        \Log::spy();
        ChatTranslator::fake(function () {
            throw new \RuntimeException('Service unavailable');
        });
        ChatTranslation::translateIncoming($this->conversation, 'en');
        [$kind, $message] = Translations::reason($this->first()->fresh(), 'en');
        $this->assertSame('error', $kind);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $message);
        \Log::shouldHaveReceived('error')->withArgs(fn ($logged) => str_contains($logged, '['.substr($message, -13, 12).']') && str_contains($logged, 'Service unavailable'))->once();

        $second = $this->customerMessage('Hallo?');
        ChatTranslator::fake([['messages' => [['id' => $second->id, 'translation' => 'Hello?', 'same_language' => false]], 'detected_language' => 'en']]);
        ChatTranslation::translateIncoming($this->conversation, 'en');
        $this->assertSame('Hello?', Translations::get($second->fresh(), 'en'));
        [$kind, $message] = Translations::reason($this->first()->fresh(), 'en');
        $this->assertSame('error', $kind);
        $this->assertMatchesRegularExpression('/^Error occurred \(ID: [A-F0-9]{12}\)$/', $message);
    }

    public function testInvalidChatBatchAnswersUseTheBackupModel()
    {
        $first_id = $this->first()->id;
        $second_id = $this->customerMessage('Hallo?')->id;
        Option::set('aiassistant.providers', [
            ['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-one'), 'base_url' => ''],
            ['id' => 'p2', 'provider' => 'anthropic', 'api_key' => encrypt('sk-two'), 'base_url' => ''],
        ]);
        Option::set('aiassistant.models', ['translations' => ['primary' => ['provider' => 'p1', 'model' => 'gpt-x'], 'backup' => ['provider' => 'p2', 'model' => 'claude-y']]]);
        Option::$cache = [];
        $valid = ['messages' => [
            ['id' => $first_id, 'translation' => '', 'same_language' => true],
            ['id' => $second_id, 'translation' => 'Hello?', 'same_language' => false],
        ], 'detected_language' => 'nl'];
        $invalid = [
            [['id' => $second_id + 100, 'translation' => 'Wrong message', 'same_language' => false]],
            [['id' => $first_id, 'translation' => 'Hello', 'same_language' => false], ['id' => $first_id, 'translation' => 'Hello again', 'same_language' => false]],
            [['id' => (string) $first_id, 'translation' => 'Hello', 'same_language' => false]],
            [['id' => $first_id, 'translation' => '', 'same_language' => false]],
            [['id' => $first_id, 'translation' => 'Hello', 'same_language' => true]],
        ];

        foreach ($invalid as $messages) {
            $calls = 0;
            ChatTranslator::fake(function () use (&$calls, $messages, $valid) {
                return ++$calls == 1 ? ['messages' => $messages, 'detected_language' => 'nl'] : $valid;
            });

            [$answer] = (new ChatTranslator('en', [$first_id, $second_id]))->streamJson('Translate the messages');

            $this->assertSame($valid, $answer);
            $this->assertSame(2, $calls);
        }

        // One omitted message does not discard its valid sibling or call the backup.
        $partial = ['messages' => [$valid['messages'][1]], 'detected_language' => 'nl'];
        $calls = 0;
        ChatTranslator::fake(function () use (&$calls, $partial) {
            $calls++;

            return $partial;
        });
        [$answer] = (new ChatTranslator('en', [$first_id, $second_id]))->streamJson('Translate the messages');
        $this->assertSame($partial, $answer);
        $this->assertSame(1, $calls);
    }

    /**
     * The chat before goes along for context, up to its size: the latest messages first.
     */
    public function testContextIsLimited()
    {
        $long = $this->customerMessage(str_repeat('a', 2000));
        $longer = $this->customerMessage(str_repeat('b', 2000));

        $context = ChatTranslation::context($this->conversation);

        $this->assertSame([['from' => 'customer', 'text' => str_repeat('b', 2000)]], $context);
        $this->assertSame(['Waar blijft mijn bestelling?', str_repeat('a', 2000)], array_column(ChatTranslation::context($this->conversation, $longer->id), 'text'));
    }

    /**
     * Translate on request: not over the mailbox's tokens; a failure is shown and logged.
     */
    public function testTranslateOnRequestFailures()
    {
        Option::set('aiassistant.mailbox_chat_translation', []);
        Option::$cache = [];
        $thread = $this->first();
        $history = fn () => Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation]);

        \Log::spy();
        ThreadTranslator::fake(function () {
            throw new \RuntimeException('Service unavailable');
        });
        $history()->call('translate', $thread->id)->assertDispatched('fruit-toast', fn ($name, $params) => ($params['tone'] ?? '') === 'danger'
            && preg_match('/^Could not translate the message: Error occurred \(ID: [A-F0-9]{12}\)$/', $params['message'] ?? ''));
        \Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Translation of thread '.$thread->id.':') && str_contains($message, 'Service unavailable'));

        Option::set('aiassistant.daily_tokens', 1);
        Option::$cache = [];
        \App\Ai\Usage::create(['mailbox_id' => $this->mailbox->id, 'feature' => 'summary', 'input_tokens' => 5]);
        ThreadTranslator::fake()->preventStrayPrompts();
        $history()->call('translate', $thread->id)->assertToasted('This mailbox has used its AI tokens for today.', 'danger');
    }

    /**
     * Without a model set up for a feature: an error that says so.
     */
    public function testNoModelForTheFeature()
    {
        Option::set('aiassistant.providers', [['id' => 'p1', 'provider' => 'openai', 'api_key' => encrypt('sk-one'), 'base_url' => '']]);
        Option::set('aiassistant.models', ['translations' => ['primary' => ['provider' => 'p9', 'model' => 'gone']]]);
        Option::$cache = [];

        $this->expectExceptionMessage('No AI model is set up for this.');
        (new ThreadTranslator('en', false))->prompt('Hallo');
    }

    /**
     * A provider given: that one, without the feature's models.
     */
    public function testAGivenProvider()
    {
        ThreadTranslator::fake([['translation' => 'Hello', 'same_language' => false, 'detected_language' => 'nl']]);
        \App\Ai\Providers::configure();

        $response = (new ThreadTranslator('en', false))->prompt('Hallo', provider: 'tallport-p1', model: 'gpt-test');

        $this->assertSame('Hello', $response['translation']);
    }

    /**
     * The composer's translation: an empty reply isn't translated; a preview can be dropped;
     * a language that isn't one isn't set.
     */
    public function testTheComposersTranslation()
    {
        ChatTranslation::setCustomerLanguage($this->conversation->customer, 'nl', true);
        $composer = Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh(), 'chat' => true]);
        \App\Ai\Agents\ReplyTranslator::fake([['translation' => '<p>Bedankt</p>', 'same_language' => false, 'note' => '']]);

        $composer->call('previewTranslation', '<p> </p>')->assertReturned('empty')->assertToasted('Please enter a message', 'danger');
        \App\Ai\Agents\ReplyTranslator::assertNeverPrompted();
        $this->assertSame([['type' => 'directive', 'content' => '<p>Bedankt</p>', 'mode' => 'replace', 'name' => 'translation']], $this->streamed(fn () => $composer->call('previewTranslation', '<p>Thanks</p>')));
        $composer->assertReturned('ready')->assertSet('translation.html', '<p>Bedankt</p>')
            ->call('discardTranslation')->assertSet('translation', null);

        $composer->call('setCustomerLanguage', 'klingon');
        $this->assertSame('nl', ChatTranslation::customerLanguage($this->conversation->fresh()));
        $composer->call('setCustomerLanguage', 'de');
        $this->assertSame('de', ChatTranslation::customerLanguage($this->conversation->fresh()));
    }
}
