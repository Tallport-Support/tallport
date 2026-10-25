<?php

namespace Tests\Feature;

use App\Ai\Agents\MessageTranslator;
use App\AutoReply\ChannelMessages;
use App\Mailbox;
use App\MailboxAutoReply;
use App\Option;
use App\WebChat\WebChat;
use Tests\FeatureTestCase;

/**
 * A mailbox's greeting and auto reply, written once for all its channels, with a channel's own (or
 * none) on the same page (App\AutoReply\ChannelMessages, AutomaticMessagesController).
 */
class AutomaticMessagesTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([], ['name' => 'Forma']);
        WebChat::saveSettings($this->mailbox, ['enabled' => true, 'widget' => 'forma']);
        Option::$cache = [];
    }

    protected function postForm($uri, array $data, array $headers = [])
    {
        \Session::start();

        return $this->actingAs($this->admin)->post($uri, array_merge(['_token' => csrf_token()], $data), $headers);
    }

    protected function version($language, $enabled)
    {
        $version = MailboxAutoReply::firstOrNew(['mailbox_id' => $this->mailbox->id, 'language' => $language]);
        $version->enabled = $enabled;
        $version->subject = 'Danke';
        $version->message = '<p>Danke.</p>';
        $version->save();
    }

    protected function url($page)
    {
        return route('mailboxes.'.$page, ['id' => $this->mailbox->getSlug()]);
    }

    /**
     * Its rows under Automatic Messages, with their values; each page lists the channels the
     * mailbox has (that can greet, for the greeting).
     */
    public function testPagesForTheChannelsTheMailboxHas()
    {
        $this->actingAs($this->admin)->get($this->url('general'))->assertOk()
            ->assertSeeInOrder(['Channels', 'Nostr', 'Automatic Messages', 'Greeting', 'Tallport’s', 'Auto Reply', 'Off', 'AI']);

        $this->actingAs($this->admin)->get($this->url('auto_reply'))->assertOk()
            ->assertSeeInOrder(['Mailbox Auto Reply', 'Send an Automatic Reply', 'Default', 'For email.', 'id="auto-reply-email"', 'id="auto-reply-webchat"'], false)
            ->assertDontSee('id="auto-reply-telegram"', false)->assertDontSee('Translate from Default');
        $this->actingAs($this->admin)->get($this->url('greeting'))->assertOk()
            ->assertSeeInOrder(['Mailbox Greeting', 'Built-in Greeting', 'Your Own Greeting', 'No Greeting', 'id="greeting-webchat"', 'Shown when a visitor opens the chat.'], false)
            ->assertDontSee('id="greeting-email"', false);

        WebChat::saveSettings($this->mailbox, ['enabled' => false]);
        $this->actingAs($this->admin)->get($this->url('greeting'))->assertOk()
            ->assertSee('None of this mailbox’s channels can greet. Website Chat, Telegram and Matrix can, once they’re set up.');
    }

    /**
     * The mailbox's greeting (Built-in, its own in languages, or none) and a channel's own: added
     * languages start from Default and open; removed ones go; its own needs a text.
     */
    public function testGreetingPage()
    {
        $form = fn (array $mailbox, array $webchat = ['mode' => 'mailbox']) => ['messages' => ['mailbox' => $mailbox, 'webchat' => $webchat]];

        $this->postForm($this->url('greeting.save'), $form(['mode' => 'own', 'texts' => ['default' => 'Hi there.']]) + ['add_language' => 'mailbox:nl'])
            ->assertSessionHas('automatic_messages_open', 'mailbox:nl');
        $this->assertSame(['mode' => 'own', 'texts' => ['' => 'Hi there.', 'nl' => 'Hi there.']], ChannelMessages::settings($this->mailbox->fresh())['greeting']);
        $this->actingAs($this->admin)->get($this->url('greeting'))->assertSee('id="greeting--nl" data-group="mailbox" data-language="nl"', false);

        $this->postForm($this->url('greeting.save'), $form(['mode' => 'own', 'texts' => ['default' => 'Hi there.', 'nl' => 'Hallo daar.']]))->assertSessionHasNoErrors();
        $this->assertSame('Hallo daar.', ChannelMessages::greeting($this->mailbox->fresh(), 'webchat', 'nl-BE'));

        // The website chat's own needs its Default.
        $this->postForm($this->url('greeting.save'), $form(['mode' => 'own', 'texts' => ['default' => 'Hi there.', 'nl' => 'Hallo daar.']], ['mode' => 'own', 'texts' => ['default' => ' ']]))
            ->assertSessionHasErrors('messages.webchat.texts.default');
        $this->postForm($this->url('greeting.save'), $form(['mode' => 'own', 'texts' => ['default' => 'Hi there.', 'nl' => 'Hallo daar.']], ['mode' => 'own', 'texts' => ['default' => 'Welcome to the shop.']]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Welcome to the shop.', ChannelMessages::greeting($this->mailbox->fresh(), 'webchat', 'nl'));
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $this->mailbox->id]))->assertSeeInOrder(['Automatic Messages', 'Greeting', 'Its Own']);

        $this->postForm($this->url('greeting.save'), $form(['mode' => 'off', 'texts' => ['default' => 'Hi there.', 'nl' => 'Hallo daar.']]) + ['remove_language' => 'mailbox:nl']);
        $settings = ChannelMessages::settings($this->mailbox->fresh());
        $this->assertSame(['mode' => 'off', 'texts' => ['' => 'Hi there.']], $settings['greeting']);
        $this->assertSame('mailbox', $settings['channels']['webchat']['greeting']['mode']);
        $this->assertSame('', ChannelMessages::greeting($this->mailbox->fresh(), 'webchat'));
    }

    /**
     * The mailbox's auto reply (its switch for all channels) and a channel's own: Email's with a
     * subject, a chat's plain text. While it's off, the mailbox's versions stay as they were.
     */
    public function testAutoReplyPage()
    {
        $mailbox_texts = ['default' => ['subject' => 'Thanks', 'message' => '<p>We got it.</p>']];
        $this->postForm($this->url('auto_reply.save'), ['auto_reply_enabled' => 1, 'messages' => ['mailbox' => ['texts' => $mailbox_texts]], 'add_language' => 'mailbox:de'])
            ->assertSessionHas('automatic_messages_open', 'mailbox:de');
        $version = MailboxAutoReply::where('mailbox_id', $this->mailbox->id)->where('language', 'de')->firstOrFail();
        $this->assertSame(['Thanks', '<p>We got it.</p>'], [$version->subject, $version->message]);

        $own = [
            'email'   => ['mode' => 'own', 'texts' => ['default' => ['subject' => 'Re: {%subject%}', 'message' => '<p>Your email arrived.</p>']]],
            'webchat' => ['mode' => 'own', 'texts' => ['default' => ' ']],
        ];
        $this->postForm($this->url('auto_reply.save'), ['auto_reply_enabled' => 1, 'messages' => ['mailbox' => ['texts' => $mailbox_texts]] + $own])
            ->assertSessionHasErrors('messages.webchat.texts.default');
        $own['webchat']['texts']['default'] = "Thanks!\nWe reply within the hour.";
        $this->postForm($this->url('auto_reply.save'), ['auto_reply_enabled' => 1, 'messages' => ['mailbox' => ['texts' => $mailbox_texts]] + $own])->assertSessionHasNoErrors();
        $this->assertSame(0, MailboxAutoReply::where('mailbox_id', $this->mailbox->id)->count(), 'Not sent: removed.');
        $channels = ChannelMessages::settings($this->mailbox->fresh())['channels'];
        $this->assertSame(['' => ['subject' => 'Re: {%subject%}', 'message' => '<p>Your email arrived.</p>', 'message_markdown' => null]], $channels['email']['auto_reply']['texts']);
        $this->assertSame(['' => "Thanks!\nWe reply within the hour."], $channels['webchat']['auto_reply']['texts']);

        // Off: the channels' choices saved, the mailbox's texts as they were.
        $this->postForm($this->url('auto_reply.save'), ['messages' => ['email' => ['mode' => 'off'], 'webchat' => ['mode' => 'mailbox']]])->assertSessionHasNoErrors();
        $mailbox = $this->mailbox->fresh();
        $this->assertFalse((bool) $mailbox->auto_reply_enabled);
        $this->assertSame(['Thanks', '<p>We got it.</p>'], [$mailbox->auto_reply_subject, $mailbox->auto_reply_message]);
        $this->assertSame(['off', 'mailbox'], [ChannelMessages::mode($mailbox, 'email', 'auto_reply'), ChannelMessages::mode($mailbox, 'webchat', 'auto_reply')]);
        // Its versions' first lines still show: which are up to date, for a notice prepared before it goes on.
        $this->actingAs($this->admin)->get($this->url('auto_reply'))->assertSee('Off for now: the mailbox’s auto reply is off.')
            ->assertSee('<span>Default</span><span class="f-form-row__value">We got it.</span>', false);
    }

    /**
     * Email's own auto reply: its subject and message, in place of the mailbox's; none for None.
     */
    public function testEmailsOwnAutoReply()
    {
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'Thanks';
        $this->mailbox->auto_reply_message = '<p>We got it.</p>';
        $this->mailbox->save();
        $own = fn ($mode, $texts = []) => ChannelMessages::saveSettings($this->mailbox, ['greeting' => ['mode' => 'tallport', 'texts' => []], 'channels' => ['email' => ['auto_reply' => ['mode' => $mode, 'texts' => $texts]]]]);

        $own('own', ['' => ['subject' => 'Your email: {%subject%}', 'message' => '<p>Your email arrived.</p>']]);
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Order']));
        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame('Your email: Order', $email->getSubject());
        $this->assertStringContainsString('Your email arrived.', $email->getBody());

        $own('off');
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'dana@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Order']));
        $this->assertSame([], $this->sentEmailsTo('dana@customer.example.org'));
    }

    /**
     * Translate from Default, with the AI Assistant: a group's default text (an email's with its
     * subject) in other languages, for the page to fill in.
     */
    public function testTranslateFromDefault()
    {
        $url = route('mailboxes.automatic_messages.translate', ['id' => $this->mailbox->getSlug()]);
        $data = ['message' => "Thanks, {%customer.firstName%}.\n\nWe reply **soon**.", 'subject' => 'Thanks', 'format' => 'markdown', 'languages' => ['de']];
        $this->postAjax($this->admin, $url, $data)->assertStatus(422)->assertJson(['status' => 'error', 'msg' => 'The AI Assistant isn’t available for this mailbox.']);

        Option::set('aiassistant.api_key', encrypt('sk-test'));
        MessageTranslator::fake([['message' => "Danke, {%customer.firstName%}.\n\nWir antworten **bald**.", 'subject' => 'Danke']]);
        $this->postAjax($this->admin, $url, $data)->assertOk()
            ->assertExactJson(['status' => 'success', 'translations' => ['de' => ['message' => "Danke, {%customer.firstName%}.\n\nWir antworten **bald**.", 'subject' => 'Danke']]]);
        MessageTranslator::assertPrompted(fn ($prompt) => $prompt->agent->language == 'de' && $prompt->agent->format == 'markdown' && str_contains($prompt->prompt, 'We reply **soon**.'));

        $this->postAjax($this->admin, $url, ['languages' => ['tlh']] + $data)->assertSessionHasErrors('languages.0');
        $this->version('de', true);
        $this->actingAs($this->admin)->get($this->url('auto_reply'))->assertSee('Translate from Default');
    }

    /**
     * Each channel as before: the website chat's greeting becomes the mailbox's; Telegram's Start
     * text its own greeting; Nostr's auto reply its own (the switch on, Email's none, when the
     * mailbox's was off); Matrix none of either. Versions that were off go.
     */
    public function testMigrationKeepsEachChannelAsItWas()
    {
        WebChat::saveSettings($this->mailbox, ['greeting_mode' => 'custom', 'greeting' => 'Welcome!', 'greetings' => ['nl' => 'Welkom!']]);
        \App\Telegram\Telegram::saveSettings($this->mailbox, ['enabled' => true, 'token' => '1:T', 'auto_reply' => 'Hi from the bot.', 'auto_replies' => ['de' => 'Hallo vom Bot.']]);
        $nostr = \App\Nostr\NostrMailbox::forMailbox($this->mailbox->id);
        $nostr->enabled = true;
        $nostr->auto_reply_enabled = true;
        $nostr->auto_reply_text = ' We will answer soon. ';
        $nostr->save();
        \App\Matrix\MatrixMailbox::create(['mailbox_id' => $this->mailbox->id, 'active_mailbox_id' => $this->mailbox->id, 'homeserver' => 'https://matrix.example.org',
            'user_id' => '@support:example.org', 'user_hash' => hash('sha256', '@support:example.org'), 'device_id' => 'TALLPORT', 'status' => 'ready', 'credentials' => []]);
        $this->version('de', true);
        $this->version('fr', false);

        require_once database_path('migrations/2026_12_01_010101_mailbox_auto_reply_for_all_channels.php');
        (new \MailboxAutoReplyForAllChannels())->up();

        $mailbox = Mailbox::find($this->mailbox->id);
        $settings = ChannelMessages::settings($mailbox);
        $this->assertSame(['mode' => 'own', 'texts' => ['' => 'Welcome!', 'nl' => 'Welkom!']], $settings['greeting']);
        $this->assertSame(['mode' => 'own', 'texts' => ['' => 'Hi from the bot.', 'de' => 'Hallo vom Bot.']], $settings['channels']['telegram']['greeting']);
        $this->assertSame(['mode' => 'own', 'texts' => ['' => 'We will answer soon.']], $settings['channels']['nostr']['auto_reply']);
        $this->assertTrue((bool) $mailbox->auto_reply_enabled);
        foreach (['email' => 'auto_reply', 'webchat' => 'auto_reply', 'telegram' => 'auto_reply', 'matrix' => 'auto_reply'] as $key => $kind) {
            $this->assertSame('off', ChannelMessages::mode($mailbox, $key, $kind), $key);
        }
        $this->assertSame('off', ChannelMessages::mode($mailbox, 'matrix', 'greeting'));
        $this->assertSame(['de'], MailboxAutoReply::where('mailbox_id', $mailbox->id)->pluck('language')->all());
    }
}
