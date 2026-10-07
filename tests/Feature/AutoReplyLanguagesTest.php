<?php

namespace Tests\Feature;

use App\Ai\Agents\LanguageRecognizer;
use App\AutoReply\AutoReplies;
use App\MailboxAutoReply;
use App\Option;
use App\Telegram\Telegram;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Auto replies in the customer's language: the versions on the Auto Reply
 * page, choosing one (from the characters, or with the AI faked), and the
 * Telegram /start auto reply.
 */
class AutoReplyLanguagesTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox();
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = '<p>We will answer soon.</p>';
        $this->mailbox->save();
        Option::$cache = [];
        AutoReplies::forget();
    }

    protected function version($language, $subject, $enabled = true)
    {
        $version = new MailboxAutoReply();
        $version->mailbox_id = $this->mailbox->id;
        $version->language = $language;
        $version->enabled = $enabled;
        $version->subject = $subject;
        $version->message = '<p>'.$subject.'</p>';
        $version->save();

        return $version;
    }

    protected function autoReplySubjectFor($body, $subject = 'Help', $email = null)
    {
        AutoReplies::forget();
        $email = $email ?: 'customer'.uniqid().'@customer.example.org';
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $email, 'to' => $this->mailbox->email, 'subject' => $subject, 'body' => $body]));
        $emails = $this->sentEmailsTo($email);

        return count($emails) ? $emails[0]->getSubject() : null;
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function form(array $versions = [], array $extra = [])
    {
        return array_merge([
            'auto_reply_enabled' => 1,
            'auto_reply_subject' => 'We got your message',
            'auto_reply_message' => '<p>We will answer soon.</p>',
            'versions'           => $versions,
        ], $extra);
    }

    // Choosing the version.

    public function testCjkVersionsAreChosenFromTheCharacters()
    {
        $this->version('ja', 'お問い合わせありがとうございます');
        $this->version('ko', '문의해 주셔서 감사합니다');
        $this->version('zh-Hans', '感谢您的来信');
        $this->version('zh-Hant', '感謝您的來信');
        LanguageRecognizer::fake()->preventStrayPrompts();

        $this->assertSame('お問い合わせありがとうございます', $this->autoReplySubjectFor('アプリが起動しません。どうすればいいですか？'));
        $this->assertSame('문의해 주셔서 감사합니다', $this->autoReplySubjectFor('앱이 실행되지 않습니다. 도와주세요.'));
        $this->assertSame('感谢您的来信', $this->autoReplySubjectFor('你好，我的软件无法连接，请帮忙检查一下。'));
        $this->assertSame('感謝您的來信', $this->autoReplySubjectFor('你好，我的軟體無法連線，請幫忙檢查一下。'));
        $this->assertSame('We got your message', $this->autoReplySubjectFor('My app does not start, please help.'));
        LanguageRecognizer::assertNeverPrompted();
    }

    public function testTheOtherChineseAndDisabledVersions()
    {
        $this->version('zh-Hans', '感谢您的来信');
        $this->version('ja', 'Japanese', false);

        $this->assertSame('感谢您的来信', $this->autoReplySubjectFor('你好，我的軟體無法連線，請幫忙檢查一下。'));
        $this->assertSame('We got your message', $this->autoReplySubjectFor('アプリが起動しません。どうすればいいですか？'));
    }

    public function testOtherLanguagesAreRecognisedByTheAi()
    {
        $this->version('de', 'Danke für Ihre Nachricht');
        $this->version('ja', 'お問い合わせありがとうございます');

        // Without the AI Assistant: the default.
        $this->assertSame('We got your message', $this->autoReplySubjectFor('Meine App startet nicht, bitte helfen Sie mir.'));

        Option::set('aiassistant.api_key', encrypt('sk-test'));
        LanguageRecognizer::fake([['language' => 'de'], ['language' => 'other']]);
        $this->assertSame('Danke für Ihre Nachricht', $this->autoReplySubjectFor('Meine App startet nicht, bitte helfen Sie mir.'));
        LanguageRecognizer::assertPrompted(function ($prompt) {
            return $prompt->agent->languages == ['de'] && str_contains($prompt->prompt, 'Meine App startet nicht');
        });
        $this->assertSame('We got your message', $this->autoReplySubjectFor('My app does not start, please help.'));

        // A failing AI: the default.
        LanguageRecognizer::fake(function () {
            throw new \RuntimeException('Provider down');
        });
        $this->assertSame('We got your message', $this->autoReplySubjectFor('Meine App startet nicht, bitte helfen Sie mir.'));
    }

    public function testCustomersLanguageIsRememberedForAFewHours()
    {
        $this->version('de', 'Danke für Ihre Nachricht');
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        LanguageRecognizer::fake([['language' => 'de'], ['language' => 'other']]);
        $customer = 'rapid@customer.example.org';
        $subjects = function () use ($customer) {
            return array_map(function ($email) {
                return $email->getSubject();
            }, $this->sentEmailsTo($customer));
        };

        $this->autoReplySubjectFor('Meine App startet nicht, bitte helfen Sie mir.', 'Hilfe', $customer);
        // A second email in a row (another subject: a new conversation).
        $this->autoReplySubjectFor('See screenshot', 'Screenshot', $customer);
        LanguageRecognizer::assertPromptedTimes(1);
        $this->assertSame(['Danke für Ihre Nachricht', 'Danke für Ihre Nachricht'], $subjects());

        // Hours later: recognised again.
        $this->travel(AutoReplies::REMEMBER_HOURS + 1)->hours();
        $this->autoReplySubjectFor('My app does not start, please help.', 'Help again', $customer);
        LanguageRecognizer::assertPromptedTimes(2);
        $this->assertSame('We got your message', $subjects()[2]);
    }

    public function testFailedRecognitionIsNotRemembered()
    {
        $this->version('de', 'Danke für Ihre Nachricht');
        Option::set('aiassistant.api_key', encrypt('sk-test'));
        $customer = 'rapid@customer.example.org';
        LanguageRecognizer::fake(function () {
            throw new \RuntimeException('Provider down');
        });
        $this->assertSame('We got your message', $this->autoReplySubjectFor('Meine App startet nicht, bitte helfen Sie mir.', 'Hilfe', $customer));

        LanguageRecognizer::fake([['language' => 'de']]);
        $this->autoReplySubjectFor('Meine App startet immer noch nicht.', 'Hilfe 2', $customer);
        $this->assertSame('Danke für Ihre Nachricht', $this->sentEmailsTo($customer)[1]->getSubject());
    }

    public function testQuotedTextIsLeftOut()
    {
        $this->version('ja', 'お問い合わせありがとうございます');

        $body = "Hello, the app does not start on my phone. Can you help me please?\n\n> アプリが起動しません。どうすればいいですか？アプリが起動しません。";
        $this->assertSame('We got your message', $this->autoReplySubjectFor($body));
    }

    // The Auto Reply page.

    public function testPageAddsSavesAndRemovesLanguages()
    {
        $this->actingAs($this->admin)->get('/mailbox/settings/'.$this->mailbox->id.'/auto-reply')
            ->assertStatus(200)->assertSee('Add Language')->assertSee('日本語');

        $this->postForm($this->admin, '/mailbox/settings/'.$this->mailbox->id.'/auto-reply', $this->form([], ['add_language' => 1, 'add_language_code' => 'ja']))
            ->assertSessionHas('auto_reply_language', 'ja');
        $version = MailboxAutoReply::where('mailbox_id', $this->mailbox->id)->where('language', 'ja')->first();
        $this->assertFalse($version->enabled);
        $this->actingAs($this->admin)->get('/mailbox/settings/'.$this->mailbox->id.'/auto-reply')->assertSee('auto_reply_ja_message', false);

        // An enabled version needs a subject and message.
        $this->postForm($this->admin, '/mailbox/settings/'.$this->mailbox->id.'/auto-reply', $this->form(['ja' => ['enabled' => 1, 'subject' => '', 'message' => '']]))
            ->assertSessionHasErrors(['versions.ja.subject', 'versions.ja.message'])
            ->assertSessionHas('auto_reply_language', 'ja');

        $this->postForm($this->admin, '/mailbox/settings/'.$this->mailbox->id.'/auto-reply', $this->form(['ja' => ['enabled' => 1, 'subject' => 'ありがとう', 'message' => '<p>すぐに返信します。</p><script>alert(1)</script>']]))
            ->assertSessionHasNoErrors();
        $version = $version->fresh();
        $this->assertTrue($version->enabled);
        $this->assertSame('ありがとう', $version->subject);
        $this->assertStringNotContainsString('script', $version->message);

        $this->postForm($this->admin, '/mailbox/settings/'.$this->mailbox->id.'/auto-reply', $this->form(['ja' => ['enabled' => 1, 'subject' => 'ありがとう', 'message' => '<p>x</p>']], ['remove_language' => 'ja']));
        $this->assertSame(0, MailboxAutoReply::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testLanguagesAreNamedInTheViewersLanguage()
    {
        $this->version('ko', '문의해 주셔서 감사합니다');
        $this->version('zh-Hant', '感謝您的來信');

        $this->actingAs($this->admin)->get('/mailbox/settings/'.$this->mailbox->id.'/auto-reply')
            ->assertSee('aria-selected="false">Korean', false)
            ->assertSee('aria-selected="false">Chinese (Traditional)', false)
            ->assertSee('Japanese (日本語)');

        $dutch = $this->createAdmin(['locale' => 'nl']);
        $this->actingAs($dutch)->withSession(['user_locale' => 'nl'])->get('/mailbox/settings/'.$this->mailbox->id.'/auto-reply')
            ->assertSee('aria-selected="false">Koreaans', false)
            ->assertSee('Japans (日本語)');

        // Korean can be edited.
        $this->postForm($this->admin, '/mailbox/settings/'.$this->mailbox->id.'/auto-reply', $this->form(['ko' => ['enabled' => 1, 'subject' => '감사합니다', 'message' => '<p>곧 답변드리겠습니다.</p>']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('감사합니다', AutoReplies::versions($this->mailbox)['ko']->subject);
    }

    // Telegram.

    public function testTelegramAutoReplyInTheAppsLanguage()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Telegram::saveSettings($this->mailbox, [
            'enabled' => true, 'token' => '1:T', 'ignore_start' => true,
            'auto_reply' => 'Welcome!', 'auto_replies' => ['ja' => 'ようこそ！', 'zh-Hans' => '欢迎！', 'de' => ''],
        ]);
        $sent = function ($language_code) {
            $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => Telegram::webhookSecret($this->mailbox)])
                ->postJson('/telegram/webhook/'.$this->mailbox->id, ['update_id' => rand(1, 1000000), 'message' => [
                    'message_id' => 1, 'text' => '/start', 'chat' => ['id' => 5, 'type' => 'private'],
                    'from' => ['id' => 5, 'first_name' => 'C', 'language_code' => $language_code],
                ]]);

            return collect(Http::recorded())->last()[0]['text'];
        };

        $this->assertSame('ようこそ！', $sent('ja'));
        $this->assertSame('欢迎！', $sent('zh-hant'));
        // Empty version: the default.
        $this->assertSame('Welcome!', $sent('de'));
        $this->assertSame('Welcome!', $sent('fr'));
    }

    public function testTelegramPageAddsLanguages()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        Telegram::saveSettings($this->mailbox, ['enabled' => true, 'token' => '1:T', 'auto_reply' => 'Welcome!']);

        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', [
            'enabled' => 1, 'token' => '***', 'auto_reply' => 'Welcome!', 'add_language' => 1, 'add_language_code' => 'ja',
        ])->assertSessionHas('telegram_language', 'ja');
        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', [
            'enabled' => 1, 'token' => '***', 'auto_reply' => 'Welcome!', 'auto_replies' => ['ja' => 'ようこそ！'],
        ]);
        $this->assertSame(['ja' => 'ようこそ！'], Telegram::settings($this->mailbox->fresh())['auto_replies']);
        $this->actingAs($this->admin)->get('/mailbox/'.$this->mailbox->id.'/telegram')->assertSee('ようこそ！');

        $this->postForm($this->admin, '/mailbox/'.$this->mailbox->id.'/telegram', [
            'enabled' => 1, 'token' => '***', 'auto_reply' => 'Welcome!', 'auto_replies' => ['ja' => 'ようこそ！'], 'remove_language' => 'ja',
        ]);
        $this->assertSame([], Telegram::settings($this->mailbox->fresh())['auto_replies']);
    }

    public function testLanguageTags()
    {
        $languages = ['zh-Hans', 'zh-Hant', 'pt-BR', 'no', 'en'];
        $this->assertSame('zh-Hant', AutoReplies::fromLanguageTag('zh-TW', $languages));
        $this->assertSame('zh-Hans', AutoReplies::fromLanguageTag('zh', $languages));
        $this->assertSame('pt-BR', AutoReplies::fromLanguageTag('pt', $languages));
        $this->assertSame('no', AutoReplies::fromLanguageTag('nb', $languages));
        $this->assertSame('en', AutoReplies::fromLanguageTag('en-GB', $languages));
        $this->assertNull(AutoReplies::fromLanguageTag('fr', $languages));
        $this->assertNull(AutoReplies::fromLanguageTag('', $languages));
    }

    // Diagnostics and the update.

    public function testCommandShowsTheChoice()
    {
        $this->version('ja', 'お問い合わせありがとうございます');
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'x@customer.example.org', 'to' => $this->mailbox->email, 'body' => 'アプリが起動しません。どうすればいいですか？']));
        $conversation = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $this->artisan('tallport:auto-reply-language', ['conversation' => $conversation->id])
            ->expectsOutputToContain('From the characters: ja')
            ->assertExitCode(0);
        $this->artisan('tallport:auto-reply-language', ['--text' => '你好，我的軟體無法連線'])
            ->expectsOutputToContain('From the characters: zh-Hant')
            ->assertExitCode(0);
        $this->artisan('tallport:auto-reply-language')->assertExitCode(1);
    }

    public function testEarlierVersionsAreCopied()
    {
        if (!class_exists('CreateMailboxAutoRepliesTable')) {
            require base_path('database/migrations/2026_10_06_010101_create_mailbox_auto_replies_table.php');
        }

        \CreateMailboxAutoRepliesTable::copyVersions([
            (object) ['mailbox_id' => $this->mailbox->id, 'language' => 'zh', 'enabled' => 1, 'subject' => '谢谢', 'message' => '<p>谢谢</p>'],
            (object) ['mailbox_id' => $this->mailbox->id, 'language' => 'ko', 'enabled' => 0, 'subject' => '감사', 'message' => '<p>감사</p>'],
        ]);

        $versions = AutoReplies::versions($this->mailbox);
        $this->assertEquals(['ko', 'zh-Hans', 'zh-Hant'], $versions->keys()->sort()->values()->all());
        $this->assertSame('谢谢', $versions['zh-Hant']->subject);
        $this->assertFalse($versions['ko']->enabled);
    }

    public function testInstallerListsOptionalExtensions()
    {
        $html = view('vendor.installer.requirements', [
            'requirements'   => ['requirements' => ['php' => ['ctype' => true]]],
            'phpSupportInfo' => ['supported' => true, 'minimum' => '8.5.0', 'current' => PHP_VERSION],
            'optional'       => ['intl' => ['enabled' => false, 'purpose' => config('installer.optional.intl')]],
        ])->render();

        $this->assertStringContainsString('(8.5.0+)', $html);
        $this->assertStringContainsString('Simplified and Traditional Chinese auto replies', $html);
    }

    public function testRecognisingATextsLanguage()
    {
        LanguageRecognizer::fake()->preventStrayPrompts();

        $this->assertNull(AutoReplies::recognize('12345 !!!', ['ja', 'de']), 'No letters: nothing to go by.');
        $this->assertSame('ja', AutoReplies::recognize('アプリが起動しません。', ['ja', 'de']));
        $this->assertNull(AutoReplies::recognize('你好，我的软件无法连接。', ['ja', 'de']), 'Chinese, without a Chinese version.');
        $this->assertNull(AutoReplies::recognize('Meine App startet nicht.', ['ja']), 'Only Chinese, Japanese or Korean versions: no AI.');
        LanguageRecognizer::assertNeverPrompted();
    }

    public function testMoreLanguageTags()
    {
        $languages = ['el-polyton', 'ms-Arab', 'en'];

        $this->assertSame('el-polyton', AutoReplies::fromLanguageTag('el', $languages));
        $this->assertSame('ms-Arab', AutoReplies::fromLanguageTag('ms-MY', $languages));
    }

    /**
     * The text a language is told from: the subject, also when the message is empty.
     */
    public function testAnEmptyMessage()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'お問い合わせ', 'body' => '']));
        $conversation = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();
        \App\Thread::where('conversation_id', $conversation->id)->update(['body' => '']);

        $this->assertSame('お問い合わせ', trim(AutoReplies::text($conversation)));
    }

    /**
     * Choices are remembered for the request, but not without end.
     */
    public function testChoicesRememberedForARequestAreLimited()
    {
        $this->version('ja', 'お問い合わせありがとうございます');
        $chosen = new \ReflectionProperty(AutoReplies::class, 'chosen');
        $chosen->setValue(null, array_fill(1000, 101, null));
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Help', 'body' => 'アプリが起動しません。']));
        $conversation = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $this->assertSame([$conversation->id => 'ja'], $chosen->getValue());
    }
}
