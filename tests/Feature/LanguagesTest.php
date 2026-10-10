<?php

namespace Tests\Feature;

use App\Ai\Agents\LanguageRecognizer;
use App\Ai\Agents\ThreadTranslator;
use App\Ai\Translations;
use App\AutoReply\AutoReplies;
use App\Conversation;
use App\Customer;
use App\Events\RealtimeConvTranslating;
use App\Livewire\ConversationThread;
use App\MailboxAutoReply;
use App\Option;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Agents' and customers' languages: their own (an agent's is their interface's) and those they
 * need no translation of. A customer's is detected in their first message and kept on their
 * profile, where agents can change it.
 */
class LanguagesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Http::fake();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        Option::set('aiassistant.translation_language', 'en');
        Option::$cache = [];
        ThreadTranslator::fake(fn () => ['translation' => 'Where is my order?', 'same_language' => false, 'detected_language' => 'nl']);
    }

    protected function receive($from = 'Casey Customer <casey@customer.example.org>', $body = 'Waar blijft mijn bestelling?')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $from, 'to' => $this->mailbox->email, 'subject' => 'Bestelling', 'body' => $body,
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function page($user, Conversation $conversation)
    {
        // (Models' cached queries last a request.)
        \Cache::store('array')->flush();

        return $this->actingAs($user)->followingRedirects()->get($conversation->url());
    }

    /**
     * A message in a language the agent reads isn't translated for them (on request it is);
     * other agents still get the translation.
     */
    public function testAnAgentsLanguagesNeedNoTranslation()
    {
        $conversation = $this->receive();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->assertSame('Where is my order?', Translations::get($thread, 'en'));

        $other = $this->createUser();
        $this->mailbox->users()->attach($other->id);
        $this->page($other, $conversation)->assertSee('Where is my order?');

        $this->agent->languages = ['nl', 'de'];
        $this->agent->save();
        $this->assertFalse(Translations::forThread($thread->fresh(), $this->agent)['wanted']);
        $this->page($this->agent, $conversation)->assertDontSee('Where is my order?');

        // Not while it's written either.
        $payload = fn () => json_decode(json_encode(['conversation_id' => $conversation->id, 'language' => 'en', 'translations' => [['thread_id' => $thread->id, 'text' => 'Where is', 'html' => false]]]));
        $this->actingAs($this->agent);
        $this->assertSame([], RealtimeConvTranslating::processPayload($payload()));
        $this->actingAs($other);
        $this->assertSame('Where is', RealtimeConvTranslating::processPayload($payload())->translations[0]->content);

        // Asked for from the message's menu: shown.
        $this->assertTrue(Translations::canForce($thread->fresh(), $this->agent));
        Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $conversation])->call('translate', $thread->id);
        $this->assertFalse(Translations::canForce($thread->fresh(), $this->agent));
        $this->page($this->agent, $conversation)->assertSee('Where is my order?');
    }

    /**
     * Not yet detected in the message: the customer's language decides.
     */
    public function testTheCustomersLanguageStandsInForAnUndetectedMessage()
    {
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['translations']]);
        Option::$cache = [];
        LanguageRecognizer::fake([['language' => 'de']]);
        $conversation = $this->receive();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->agent->languages = ['de'];

        $this->assertSame('de', Translations::messageLanguage($thread));
        $this->assertTrue(Translations::readAsWritten($thread, $this->agent, 'en'));
        $this->agent->languages = null;
        $this->assertFalse(Translations::readAsWritten($thread, $this->agent, 'en'));
    }

    /**
     * A customer's language: recognised in their first message when it isn't translated anyway
     * (with the translation when it is), and never changed by a later one.
     */
    public function testTheCustomersLanguageIsDetectedOnce()
    {
        Option::set('aiassistant.mailbox_features_off', [$this->mailbox->id => ['translations']]);
        Option::$cache = [];
        LanguageRecognizer::fake([['language' => 'de']]);
        $conversation = $this->receive();

        $this->assertSame('de', $conversation->customer->fresh()->language);
        LanguageRecognizer::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Waar blijft mijn bestelling?') && in_array('de', $prompt->agent->languages) && in_array('kk', $prompt->agent->languages));
        $this->assertSame(1, \App\Ai\Usage::where('feature', \App\Ai\Usage::FEATURE_LANGUAGE)->count());

        LanguageRecognizer::fake()->preventStrayPrompts();
        $this->receive('Casey Customer <casey@customer.example.org>', 'Hello, any news?');
        LanguageRecognizer::assertPromptedTimes(1);
        $this->assertSame('de', $conversation->customer->fresh()->language);

        // Chinese, Japanese and Korean: by the writing system, no AI.
        $chinese = $this->receive('Wei <wei@customer.example.org>', '我的订单在哪里？请尽快回复我，谢谢。');
        $this->assertSame('zh-Hans', $chinese->customer->language);
        LanguageRecognizer::assertPromptedTimes(1);

        // Translated anyway: from the translation.
        Option::set('aiassistant.mailbox_features_off', []);
        Option::$cache = [];
        $translated = $this->receive('Robin <robin@customer.example.org>');
        $this->assertSame('nl', $translated->customer->language);
        LanguageRecognizer::assertPromptedTimes(1);

        // An agent's choice stays.
        ThreadTranslator::fake(fn () => ['translation' => 'Hello', 'same_language' => false, 'detected_language' => 'fr']);
        Translations::translate($translated->threads()->first(), 'de');
        $this->assertSame('nl', $translated->customer->fresh()->language);
    }

    public function testAgentsEditTheCustomersLanguages()
    {
        $customer = $this->receive()->customer;
        $customer->language = null;
        $customer->save();
        \Session::start();
        $save = fn (array $data) => $this->actingAs($this->agent)->post('/customers/'.$customer->id.'/edit', [
            '_token' => csrf_token(), 'first_name' => 'Casey', 'emails' => ['casey@customer.example.org'],
        ] + $data);

        $this->actingAs($this->agent)->get('/customers/'.$customer->id.'/edit')
            ->assertSee('Detect Automatically')->assertSee('No Translation Needed')->assertSee('name="languages[]"', false);

        $save(['language' => 'fr', 'languages' => ['en', 'de']])->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertSame('fr', $customer->language);
        $this->assertSame(['en', 'de'], $customer->languages);

        $save(['language' => 'xx', 'languages' => ['yy']])->assertSessionHasErrors(['language', 'languages.0']);
        $this->assertSame('fr', $customer->fresh()->language);

        $save(['language' => ''])->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertNull($customer->language);
        $this->assertNull($customer->languages);
    }

    /**
     * The sidebar: the customer's language, set there (a select), and those they read besides;
     * printed, as text.
     */
    public function testTheSidebarShowsTheCustomersLanguages()
    {
        $conversation = $this->receive();
        $this->page($this->agent, $conversation)->assertSee('<li class="customer-language" x-data>', false)
            ->assertSee('<option value="nl" selected>', false);

        $conversation->customer->languages = ['en', 'de'];
        $conversation->customer->save();
        $this->page($this->agent, $conversation)->assertSee('· also reads English, German');
        \Cache::store('array')->flush();
        $this->actingAs($this->agent)->get(route('conversations.view', ['id' => $conversation->id, 'print' => 1]))
            ->assertSee('Dutch · also reads English, German')->assertDontSee('customer-language__select', false);

        $conversation->customer->language = null;
        $conversation->customer->save();
        $this->page($this->agent, $conversation)->assertSee('Reads English, German')->assertSee('Detect Automatically');

        // Printed, none: no line.
        $conversation->customer->languages = null;
        $conversation->customer->save();
        \Cache::store('array')->flush();
        $this->actingAs($this->agent)->get(route('conversations.view', ['id' => $conversation->id, 'print' => 1]))
            ->assertDontSee('<li class="customer-language"', false);
    }

    /**
     * The auto reply in the customer's language, where the mailbox has it.
     */
    public function testTheAutoReplyIsInTheCustomersLanguage()
    {
        $conversation = $this->receive();
        foreach (['de', 'fr'] as $language) {
            $version = new MailboxAutoReply();
            $version->mailbox_id = $this->mailbox->id;
            $version->language = $language;
            $version->enabled = true;
            $version->subject = 'Re';
            $version->message = 'Thanks';
            $version->save();
        }
        LanguageRecognizer::fake()->preventStrayPrompts();
        $conversation->customer->language = 'fr';
        $conversation->customer->save();

        AutoReplies::forget();
        $this->assertSame('fr', AutoReplies::language($conversation->fresh(), $this->mailbox));
        LanguageRecognizer::assertNeverPrompted();
    }

    public function testTheMigrationKeepsTheLanguages()
    {
        require_once base_path('database/migrations/2026_11_09_010101_customer_and_user_languages.php');

        // A user's AI language: one they read, unless it's their own.
        $this->assertSame('["nl"]', \CustomerAndUserLanguages::userLanguages('nl', 'en'));
        $this->assertSame('["nl"]', \CustomerAndUserLanguages::userLanguages('nl', null));
        $this->assertNull(\CustomerAndUserLanguages::userLanguages('zh-Hans', 'zh-CN'));
        $this->assertNull(\CustomerAndUserLanguages::userLanguages('xx', 'en'));

        // A chat's language: an agent's choice first, then the newest conversation's.
        $chat = fn ($customer, $data) => \DB::table('conversations')->where('id', $this->receive($customer)->id)->update(['ai_assistant' => json_encode($data)]);
        $chat('Ana <ana@customer.example.org>', ['language' => 'ja', 'language_by' => 'user']);
        $chat('Ana <ana@customer.example.org>', ['language' => 'de', 'language_by' => 'detected']);
        $chat('Ben <ben@customer.example.org>', ['language' => 'fr', 'language_by' => 'detected']);
        $chat('Ben <ben@customer.example.org>', ['language' => 'it', 'language_by' => 'detected']);
        $chat('Cy <cy@customer.example.org>', ['language' => '', 'language_by' => 'user']);
        \DB::table('customers')->update(['language' => null]);
        $kept = Customer::create('robin@customer.example.org');
        $kept->language = 'sv';
        $kept->save();
        $chat('Robin <robin@customer.example.org>', ['language' => 'ja', 'language_by' => 'user']);

        \CustomerAndUserLanguages::customerLanguages();

        $language = fn ($email) => \App\Email::where('email', $email)->first()->customer->language;
        $this->assertSame('ja', $language('ana@customer.example.org'));
        $this->assertSame('it', $language('ben@customer.example.org'));
        $this->assertNull($language('cy@customer.example.org'));
        $this->assertSame('sv', $language('robin@customer.example.org'));
    }
}
