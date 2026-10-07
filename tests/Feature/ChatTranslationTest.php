<?php

namespace Tests\Feature;

use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\ChatTranslation;
use App\Ai\Translations;
use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Option;
use App\Telegram\Telegram;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Chats translated both ways (App\Ai\ChatTranslation): the agent reads and writes in their own
 * language; a reply goes out in the chat's language after a preview, or as written.
 */
class ChatTranslationTest extends FeatureTestCase
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
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.translation_language', 'en');
        Option::set('aiassistant.mailbox_chat_translation', [$this->mailbox->id => 1]);
        Option::$cache = [];
        ThreadTranslator::fake(fn () => ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Bestelling', 'body' => 'Waar blijft mijn bestelling?',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->conversation->channel = Telegram::CHANNEL;
        $this->conversation->save();
    }

    protected function composer()
    {
        return Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh(), 'chat' => true]);
    }

    /**
     * The chat's language: the first detected in the customer's messages, not switched by a
     * later one, and the agent's choice over both (empty: replies go out as written).
     */
    public function testTheChatsLanguage()
    {
        $thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        Translations::translate($thread, 'en');
        $this->assertSame('nl', ChatTranslation::customerLanguage($this->conversation->fresh()));
        $this->assertTrue(ChatTranslation::needed($this->conversation->fresh(), $this->agent));

        ChatTranslation::setCustomerLanguage($this->conversation->fresh(), 'de');
        $this->assertSame('nl', ChatTranslation::customerLanguage($this->conversation->fresh()));

        $this->composer()->call('setCustomerLanguage', '');
        $this->assertFalse(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
        Translations::translate($thread, 'en');
        $this->assertSame('', ChatTranslation::customerLanguage($this->conversation->fresh()));

        // Off for the mailbox: not translated.
        $this->composer()->call('setCustomerLanguage', 'nl');
        Option::set('aiassistant.mailbox_chat_translation', []);
        Option::$cache = [];
        $this->assertFalse(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
    }

    /**
     * The chat's language follows the customer: Chinese as detected ("zh" is Simplified
     * Chinese), and another language once two messages in a row are in it (a first "/start"
     * or "Hi" doesn't decide a chat); an agent's choice stays.
     */
    public function testTheChatsLanguageFollowsTheCustomer()
    {
        $this->assertSame('zh-Hans', \App\Ai\Settings::detectedLanguage('zh'));
        $this->assertSame('zh-Hant', \App\Ai\Settings::detectedLanguage('zh-TW'));
        $this->assertNull(\App\Ai\Settings::detectedLanguage('xx'));

        $chat = $this->conversation;
        ChatTranslation::setCustomerLanguage($chat, 'en');
        $this->assertSame('en', ChatTranslation::customerLanguage($chat->fresh()));
        ChatTranslation::setCustomerLanguage($chat, 'zh-Hans');
        $this->assertSame('en', ChatTranslation::customerLanguage($chat->fresh()), 'One message: not yet.');
        ChatTranslation::setCustomerLanguage($chat, 'zh-Hans');
        $this->assertSame('zh-Hans', ChatTranslation::customerLanguage($chat->fresh()));
        ChatTranslation::setCustomerLanguage($chat, 'en');
        ChatTranslation::setCustomerLanguage($chat, 'zh-Hans');
        $this->assertSame('zh-Hans', ChatTranslation::customerLanguage($chat->fresh()), 'Not two in a row.');

        $this->composer()->call('setCustomerLanguage', 'ja');
        ChatTranslation::setCustomerLanguage($chat, 'zh-Hans');
        ChatTranslation::setCustomerLanguage($chat, 'zh-Hans');
        $this->assertSame('ja', ChatTranslation::customerLanguage($chat->fresh()));
    }

    /**
     * A reply: its translation for a look, then sent in the chat's language with the agent's
     * text beside it; tokens counted for the conversation.
     */
    public function testAReplyGoesOutTranslatedAfterAPreview()
    {
        ChatTranslation::setCustomerLanguage($this->conversation, 'nl');
        ReplyTranslator::fake([new \Laravel\Ai\Responses\TextResponse(
            json_encode(['translation' => '<p>Ik zoek het voor u uit.</p>', 'same_language' => false, 'note' => '']),
            new \Laravel\Ai\Responses\Data\TextUsage(300, 20), new \Laravel\Ai\Responses\Data\Meta
        )]);
        // The translation shown as it's written (Livewire's stream): its start, the rest within 0.1 s.
        $this->freezeTime();

        $composer = $this->composer()->assertSet('translating', true)
            ->assertSee('Translate Replies Into')->assertSee('wire:stream.replace="translation"', false);
        $streamed = $this->streamed(fn () => $composer->call('previewTranslation', '<p>I will look into it.</p>'));
        $composer->assertReturned('ready')->assertSee('Ik zoek het voor u uit.')->assertSee('Sent in Dutch');
        $this->assertSame([['type' => 'directive', 'content' => '<p>Ik', 'mode' => 'replace', 'name' => 'translation']], $streamed);
        ReplyTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Waar blijft mijn bestelling?') && str_contains($prompt->prompt, 'I will look into it.'));

        // Changed since: translated again first.
        $composer->call('sendTranslation', '<p>I will look into it today.</p>')->assertReturned('changed');
        $composer->call('sendTranslation', '<p>I will look into it.</p>')->assertReturned('sent')->assertSet('translation', null);

        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('Ik zoek het voor u uit.', $reply->body);
        $this->assertSame('I will look into it.', Translations::get($reply, 'en'));

        // Shown as written: the agent's text is the message, what the customer got beside it.
        $html = $this->actingAs($this->agent)->followingRedirects()->get($this->conversation->url())->getContent();
        $this->assertMatchesRegularExpression('#class="ai-sent-in">Sent to the customer in Dutch</span>.*?Ik zoek het voor u uit\..*?thread-content[^>]*>(?:\s|<!--.*?-->)*I will look into it\.#s', $html);
        $this->assertSame(320, (int) \App\Ai\Usage::where('conversation_id', $this->conversation->id)->where('feature', 'reply_translation')->sum(\DB::raw('input_tokens + output_tokens')));
    }

    /**
     * The AI unavailable (or the mailbox's tokens used up): the reply isn't sent; the agent
     * sees why and can send it as written. One in the chat's language already goes as written.
     */
    public function testWhenTheAiFailsTheReplyCanBeSentAsWritten()
    {
        ChatTranslation::setCustomerLanguage($this->conversation, 'nl');
        ReplyTranslator::fake(function () {
            throw new \RuntimeException('Service unavailable');
        });
        $composer = $this->composer()
            ->call('previewTranslation', '<p>I will look into it.</p>')->assertReturned('error')
            ->assertSee('The reply could not be translated: Service unavailable')->assertSee('Send as Written');
        $this->assertSame(0, $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());

        // As written: the reply as it is, no translation kept.
        $composer->call('send', null, '<p>I will look into it.</p>')->assertReturned(true)->assertSet('translation', null);
        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('I will look into it.', $reply->body);
        $this->assertNull(Translations::get($reply, 'en'));

        // Over the mailbox's tokens: no AI call.
        Option::set('aiassistant.daily_tokens', 1);
        Option::$cache = [];
        \App\Ai\Usage::create(['mailbox_id' => $this->mailbox->id, 'feature' => 'summary', 'input_tokens' => 5]);
        ReplyTranslator::fake([['translation' => 'x', 'same_language' => false]]);
        $this->composer()->call('previewTranslation', '<p>Thanks!</p>')->assertReturned('error')->assertSee('This mailbox has used its AI tokens for today.');
        ReplyTranslator::assertNotPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Thanks!'));

        // In Dutch already: sent as written (the browser sends it).
        Option::set('aiassistant.daily_tokens', 0);
        Option::$cache = [];
        ReplyTranslator::fake([['translation' => '', 'same_language' => true]]);
        $this->composer()->call('previewTranslation', '<p>Bedankt!</p>')->assertReturned('same')->assertSet('translation', null);
    }

    /**
     * A customer's burst of messages: translated together, a few seconds after the latest,
     * with the chat before them for context; the customer's messages per hour count each.
     */
    public function testACustomersMessagesAreTranslatedTogether()
    {
        $first = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        Translations::translate($first, 'en');
        $burst = collect(['Hallo?', 'Is daar iemand?', 'Het pakket is nog niet aangekomen.'])
            ->map(fn ($text) => Thread::create($this->conversation, Thread::TYPE_CUSTOMER, $text, ['customer_id' => $this->conversation->customer_id, 'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_WEB]));

        \App\Ai\Agents\ChatTranslator::fake([new \Laravel\Ai\Responses\TextResponse(json_encode([
            'messages' => $burst->map(fn ($thread, $i) => ['id' => $thread->id, 'translation' => ['Hello?', 'Is anyone there?', 'The parcel has not arrived yet.'][$i], 'same_language' => false])->all(),
            'detected_language' => 'nl',
        ]), new \Laravel\Ai\Responses\Data\TextUsage(400, 30), new \Laravel\Ai\Responses\Data\Meta)]);
        (new \App\Jobs\AiTranslateChat($this->conversation->id, 'en'))->handle();

        \App\Ai\Agents\ChatTranslator::assertPromptedTimes(1);
        \App\Ai\Agents\ChatTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Waar blijft mijn bestelling?') && str_contains($prompt->prompt, 'Is daar iemand?'));
        $this->assertSame(['Hello?', 'Is anyone there?', 'The parcel has not arrived yet.'], $burst->map(fn ($thread) => Translations::get($thread->fresh(), 'en'))->all());
        $this->assertSame(5, \App\Ai\Usage::customerTranslationsLastHour($this->conversation->customer_id));

        // Over the customer's messages per hour: the latest translated, the earlier ones say why.
        Option::set('aiassistant.translations_per_customer_hour', 6);
        Option::$cache = [];
        $more = collect(['Een', 'Twee', 'Drie'])->map(fn ($text) => Thread::create($this->conversation, Thread::TYPE_CUSTOMER, $text, ['customer_id' => $this->conversation->customer_id, 'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_WEB]));
        \App\Ai\Agents\ChatTranslator::fake(fn () => ['messages' => [['id' => $more->last()->id, 'translation' => 'Three', 'same_language' => false]], 'detected_language' => 'nl']);
        \App\Jobs\AiTranslateChat::dispatch($this->conversation->id, 'en');
        $this->assertSame('Three', Translations::get($more->last()->fresh(), 'en'));
        $this->assertSame(['customer_limit'], Translations::reason($more->first()->fresh(), 'en'));

        // A chat's new message: the chat's translation, a few seconds later.
        $next = Thread::create($this->conversation, Thread::TYPE_CUSTOMER, 'Vier', ['customer_id' => $this->conversation->customer_id, 'source_via' => Thread::PERSON_CUSTOMER, 'source_type' => Thread::SOURCE_TYPE_WEB]);
        \Illuminate\Support\Facades\Queue::fake();
        \App\Jobs\AiTranslateThread::request($next, 'en');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AiTranslateChat::class, fn ($job) => $job->conversation_id == $this->conversation->id && $job->delay);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\AiTranslateThread::class);
    }

    /**
     * The mailbox's glossary goes with every translation; translated replies say so when the
     * mailbox wants it, in the customer's language.
     */
    public function testGlossaryAndTranslatedNote()
    {
        Option::set('aiassistant.translation_glossary', [$this->mailbox->id => "12VPX\nserver = Server"]);
        Option::set('aiassistant.mailbox_translation_note', [$this->mailbox->id => 1]);
        Option::$cache = [];
        ChatTranslation::setCustomerLanguage($this->conversation, 'nl');
        ReplyTranslator::fake([['translation' => '<p>Herstart de 12VPX Server.</p>', 'same_language' => false, 'note' => 'Automatisch vertaald']]);

        $composer = $this->composer();
        $this->streamed(fn () => $composer->call('previewTranslation', '<p>Please restart the 12VPX server.</p>'));
        $composer->assertReturned('ready')
            ->assertSet('translation.html', '<p>Herstart de 12VPX Server.</p><p><em>(Automatisch vertaald)</em></p>');
        ReplyTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, "<glossary>") && str_contains($prompt->prompt, 'server = Server'));

        $thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        Translations::translate($thread, 'en');
        ThreadTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'server = Server'));
    }

    /**
     * Notes, and chats in the agent's own language, aren't translated.
     */
    public function testNotesAndTheAgentsOwnLanguageAreNotTranslated()
    {
        ChatTranslation::setCustomerLanguage($this->conversation, 'nl');
        $this->composer()->call('switchToNote')->assertSet('translating', false)
            ->call('previewTranslation', '<p>Internal</p>')->assertReturned('off');

        ChatTranslation::setCustomerLanguage($this->conversation->fresh(), 'en', true);
        $this->composer()->assertSet('translating', false)->assertDontSee('data-translating', false);
    }
}
