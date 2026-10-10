<?php

namespace Tests\Feature;

use App\Ai\Agents\ReplyTranslator;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\ChatTranslation;
use App\Ai\Settings;
use App\Ai\Translations;
use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Option;
use App\Telegram\Telegram;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Email conversations translated both ways (App\Ai\ChatTranslation, the mailbox's Translate
 * Emails): the agent reads and writes in their own language; a reply goes out in the
 * customer's language after a preview, or as written. Forwards and notes stay as written.
 */
class EmailTranslationTest extends FeatureTestCase
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
        $this->mailbox->signature = '<p>Kind regards, Support</p>';
        $this->mailbox->save();
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.translation_language', 'en');
        Option::set('aiassistant.mailbox_email_translation', [$this->mailbox->id => 1]);
        Option::$cache = [];
        ThreadTranslator::fake(fn () => ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Bestelling', 'body' => 'Waar blijft mijn bestelling?',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
    }

    protected function composer()
    {
        return Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation->fresh()]);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function setCustomerLanguage($language, array $languages = [])
    {
        $customer = $this->conversation->customer;
        $customer->language = $language;
        $customer->languages = $languages ?: null;
        $customer->save();
    }

    public function testTheMailboxSwitch()
    {
        $admin = $this->createUser(['role' => \App\User::ROLE_ADMIN]);
        $page = route('mailboxes.ai', ['id' => $this->mailbox->id]);
        Option::set('aiassistant.mailbox_email_translation', []);
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => Settings::FEATURES]);
        Option::$cache = [];
        $this->assertSame('Off', Settings::mailboxSummary($this->mailbox));

        $this->actingAs($admin)->get($page)->assertOk()
            ->assertSeeInOrder(['Translation', 'Translate Chats', 'Translate Emails', 'Email conversations, both ways', 'Mark Translated Replies', 'Replies sent translated end with'], false);

        // Emails alone: the note goes with them.
        $this->postForm($admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), ['email_translation' => 1, 'translation_note' => 1])->assertRedirect($page);
        Option::$cache = [];
        $this->assertTrue(Settings::emailTranslation($this->mailbox));
        $this->assertFalse(Settings::chatTranslation($this->mailbox));
        $this->assertTrue(Settings::translationNote($this->mailbox));
        $this->assertStringStartsWith('On', Settings::mailboxSummary($this->mailbox));

        $this->postForm($admin, route('mailboxes.ai.save', ['id' => $this->mailbox->id]), []);
        Option::$cache = [];
        $this->assertFalse(Settings::emailTranslation($this->mailbox));
        $this->assertTrue(Settings::translationNote($this->mailbox), 'Kept while its control is off.');
    }

    /**
     * A reply: its translation for a look, then emailed in the customer's language with the
     * status the agent chose; the agent's text kept beside it. Only the reply's text is
     * translated: the signature and subject go as they are.
     */
    public function testAReplyGoesOutTranslatedWithTheChosenStatus()
    {
        $this->setCustomerLanguage('nl');
        ReplyTranslator::fake([['translation' => '<p>Ik zoek het voor u uit.</p>', 'same_language' => false, 'note' => '']]);

        $composer = $this->composer()->call('open', 'reply')->assertSet('translating', true)
            ->assertSee('data-translating', false)->assertSee('wire:stream.replace="translation"', false);
        $this->streamed(fn () => $composer->call('previewTranslation', '<p>I will look into it.</p>'));
        $composer->assertReturned('ready')->assertSee('Sent in Dutch')->assertDontSee('Translate Replies Into');
        ReplyTranslator::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Waar blijft mijn bestelling?') && str_contains($prompt->prompt, 'I will look into it.')
            && !str_contains($prompt->prompt, 'Kind regards'));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));

        $composer->call('sendTranslation', '<p>I will look into it.</p>', Conversation::STATUS_PENDING)->assertReturned('sent')->assertRedirect();

        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('Ik zoek het voor u uit.', $reply->body);
        $this->assertSame('I will look into it.', Translations::get($reply, 'en'));
        $this->assertSame(Conversation::STATUS_PENDING, $this->conversation->fresh()->status);

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('Ik zoek het voor u uit.', $emails[0]->getBody());
        $this->assertStringContainsString('Kind regards, Support', $emails[0]->getBody());
        $this->assertStringNotContainsString('I will look into it.', $emails[0]->getBody());
        $this->assertStringContainsString('Bestelling', $emails[0]->getSubject());
    }

    /**
     * The AI unavailable: nothing is sent until the agent sends it as written, with their status.
     */
    public function testWhenTheAiFailsTheReplyCanBeSentAsWritten()
    {
        $this->setCustomerLanguage('nl');
        ReplyTranslator::fake(function () {
            throw new \RuntimeException('Service unavailable');
        });
        $composer = $this->composer()->call('open', 'reply')
            ->call('previewTranslation', '<p>I will look into it.</p>')->assertReturned('error')->assertSee('Send as Written');
        $this->assertSame(0, $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());

        $composer->call('send', Conversation::STATUS_CLOSED, '<p>I will look into it.</p>')->assertReturned(true);
        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('I will look into it.', $reply->body);
        $this->assertNull(Translations::get($reply, 'en'));
        $this->assertSame(Conversation::STATUS_CLOSED, $this->conversation->fresh()->status);
    }

    public function testForwardsAndNotesAreNotTranslated()
    {
        $this->setCustomerLanguage('nl');

        $this->composer()->call('open', 'forward')->assertSet('translating', false)->assertDontSee('data-translating', false)
            ->call('previewTranslation', '<p>Please check this.</p>')->assertReturned('off');
        $this->composer()->call('open', 'note')->assertSet('translating', false)->assertDontSee('conv-chat-translation', false)
            ->call('previewTranslation', '<p>Internal</p>')->assertReturned('off');
        ReplyTranslator::assertNeverPrompted();
    }

    /**
     * Not translated when the customer reads the agent's language, or theirs is unknown.
     */
    public function testNotTranslatedWhenTheCustomerReadsTheAgentsLanguage()
    {
        $this->setCustomerLanguage('nl', ['en']);
        $this->composer()->call('open', 'reply')->assertSet('translating', false);
        $this->setCustomerLanguage('en');
        $this->assertFalse(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
        $this->setCustomerLanguage(null);
        $this->assertFalse(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
        $this->composer()->call('open', 'reply')->assertSet('translating', false)->assertDontSee('data-translating', false);
    }

    /**
     * The chat switch translates chats only, the email switch emails only.
     */
    public function testEachSwitchTranslatesItsOwnConversations()
    {
        $this->setCustomerLanguage('nl');
        $chat = Conversation::find($this->conversation->id)->replicate();
        $chat->channel = Telegram::CHANNEL;
        $chat->save();

        $this->assertTrue(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
        $this->assertFalse(ChatTranslation::needed($chat, $this->agent));

        Option::set('aiassistant.mailbox_email_translation', []);
        Option::set('aiassistant.mailbox_chat_translation', [$this->mailbox->id => 1]);
        Option::$cache = [];
        $this->assertFalse(ChatTranslation::needed($this->conversation->fresh(), $this->agent));
        $this->assertTrue(ChatTranslation::needed($chat, $this->agent));
        $this->composer()->call('open', 'reply')->assertSet('translating', false);
    }

    /**
     * The customer's language is set in the conversation's sidebar by agents who may edit the
     * customer; an open composer's next preview is in it.
     */
    public function testTheSidebarSetsTheCustomersLanguage()
    {
        $this->setCustomerLanguage('nl', ['de']);
        $this->actingAs($this->agent)->get($this->conversation->url())->assertOk()
            ->assertSee('customer-language__select', false)->assertSee('Detect Automatically')->assertSee('also reads German');

        $this->postAjax($this->agent, '/customers/ajax', ['action' => 'set_language', 'customer_id' => $this->conversation->customer_id, 'language' => 'ja'])->assertJson(['status' => 'success']);
        $this->assertSame('ja', $this->conversation->customer->fresh()->language);
        $this->assertSame(['de'], $this->conversation->customer->fresh()->languages, 'Those they read besides are kept.');
        $this->postAjax($this->agent, '/customers/ajax', ['action' => 'set_language', 'customer_id' => $this->conversation->customer_id, 'language' => 'klingon'])->assertJson(['status' => 'error']);
        $this->assertSame('ja', $this->conversation->customer->fresh()->language);
        // An agent without access to the customer can't.
        config(['app.limit_user_customer_visibility' => true]);
        $this->postAjax($this->createUser(), '/customers/ajax', ['action' => 'set_language', 'customer_id' => $this->conversation->customer_id, 'language' => 'fr'])->assertJson(['status' => 'error']);
        $this->assertSame('ja', $this->conversation->customer->fresh()->language);
        config(['app.limit_user_customer_visibility' => false]);

        // An open composer: a preview in the language before is dropped.
        ReplyTranslator::fake([['translation' => '<p>調べます。</p>', 'same_language' => false, 'note' => '']]);
        $composer = $this->composer()->call('open', 'reply');
        $this->streamed(fn () => $composer->call('previewTranslation', '<p>I will look into it.</p>'));
        $composer->assertSee('Sent in Japanese');
        $this->postAjax($this->agent, '/customers/ajax', ['action' => 'set_language', 'customer_id' => $this->conversation->customer_id, 'language' => 'en'])->assertJson(['status' => 'success']);
        $composer->dispatch('customer-language-changed')->assertSet('translation', null)->assertSet('translating', false);

        // Detect Automatically: none until their next message says.
        $this->postAjax($this->agent, '/customers/ajax', ['action' => 'set_language', 'customer_id' => $this->conversation->customer_id, 'language' => ''])->assertJson(['status' => 'success']);
        $this->assertNull($this->conversation->customer->fresh()->language);
        $this->actingAs($this->agent)->get($this->conversation->url())->assertSee('customer-language__select', false);

        // Without AI set up: no select.
        Option::set('aiassistant.api_key', '');
        Option::$cache = [];
        $this->actingAs($this->agent)->get($this->conversation->url())->assertDontSee('customer-language__select', false);
    }

    /**
     * Customer emails are translated for reading with email translation on (the mailbox's
     * Translations feature off), one message at a time, not as a chat's batch.
     */
    public function testIncomingEmailsAreTranslatedForReading()
    {
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['translations']]);
        Option::$cache = [];
        $thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertTrue(Translations::isWanted($thread));

        \Illuminate\Support\Facades\Queue::fake();
        \App\Jobs\AiTranslateThread::request($thread, 'de');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AiTranslateThread::class);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\AiTranslateChat::class);

        Option::set('aiassistant.mailbox_email_translation', []);
        Option::$cache = [];
        $this->assertFalse(Translations::isWanted($thread->fresh()));
    }
}
