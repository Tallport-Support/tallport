<?php

namespace Tests\Feature;

use App\KbArticle;
use App\Mailbox;
use App\MailboxAutoReply;
use App\Misc\Markdown;
use App\Option;
use App\SavedReply;
use App\Workflow;
use Tests\FeatureTestCase;

/**
 * Saved replies, signatures, auto replies, knowledge base articles, workflows' texts and
 * branding are written in Markdown and stored as written; the HTML next to it is rendered
 * from it. Texts written in rich text before stay so until rewritten.
 */
class MarkdownTextsTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->admin], ['name' => 'Support']);
    }

    protected function postForm($uri, array $data, array $headers = [])
    {
        \Session::start();

        return $this->actingAs($this->admin)->post($uri, array_merge(['_token' => csrf_token()], $data), $headers);
    }

    public function testEditorNamesTheFieldByItsFormat()
    {
        $this->assertSame(Markdown::fromInput(['text_markdown' => '**Hi**'], 'text'), ["<p><strong>Hi</strong></p>", '**Hi**']);
        $this->assertSame(Markdown::fromInput(['text' => '<b>Hi</b>'], 'text'), ['<b>Hi</b>', null]);
        $this->assertNull(Markdown::fromInput([], 'text'));
        $this->assertTrue(Markdown::isMarkdown(null, '<p>&nbsp;</p>'));
        $this->assertFalse(Markdown::isMarkdown(null, '<p>Hi</p>'));
        $this->assertTrue(Markdown::isMarkdown('', '<p>Hi</p>'));
    }

    public function testSavedReplies()
    {
        $this->actingAs($this->admin)->get(route('mailboxes.saved_replies.create', ['id' => $this->mailbox->id]))
            ->assertSee('name="text_markdown"', false)->assertSee('data-fruit-mode="markdown"', false);

        $this->postForm(route('mailboxes.saved_replies.save', ['id' => $this->mailbox->id]), [
            'name' => 'Thanks', 'text_markdown' => "Hi {%customer.firstName%},\n**thanks** & <i>bye</i>",
        ])->assertSessionHasNoErrors();
        $saved_reply = SavedReply::where('name', 'Thanks')->first();
        $this->assertSame("Hi {%customer.firstName%},\n**thanks** & <i>bye</i>", $saved_reply->text_markdown);
        $this->assertSame("<p>Hi {%customer.firstName%},<br />\n<strong>thanks</strong> &amp; bye</p>", $saved_reply->text);

        // Inserted as written, the variables filled in; the HTML for a rich text reply.
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'Ann & Co <ann@example.com>', 'to' => $this->mailbox->email, 'subject' => 'Order']));
        $conversation = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $conversation->customer->first_name = 'Ann & Co';
        $conversation->customer->save();
        $response = $this->postAjax($this->admin, route('saved_replies.ajax'), [
            'action' => 'get', 'saved_reply_id' => $saved_reply->id, 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
        ])->json();
        $this->assertSame("Hi Ann & Co,\n**thanks** & <i>bye</i>", $response['markdown']);
        $this->assertStringContainsString('Hi Ann &amp; Co,', $response['text']);

        // Written in rich text before: edited and inserted as such.
        $saved_reply->text_markdown = null;
        $saved_reply->text = '<p style="color:red">Red</p>';
        $saved_reply->save();
        $this->actingAs($this->admin)->get(route('mailboxes.saved_replies.edit', ['id' => $this->mailbox->id, 'saved_reply_id' => $saved_reply->id]))
            ->assertSee('name="text"', false)->assertDontSee('name="text_markdown"', false);
        $this->assertNull($this->postAjax($this->admin, route('saved_replies.ajax'), [
            'action' => 'get', 'saved_reply_id' => $saved_reply->id, 'mailbox_id' => $this->mailbox->id,
        ])->json('markdown'));
    }

    public function testSignature()
    {
        // Someone who may change the signature only.
        $this->admin = $this->createUser();
        $this->mailbox->users()->attach($this->admin->id, ['access' => json_encode([Mailbox::ACCESS_PERM_SIGNATURE])]);
        // A new mailbox's signature is Markdown.
        $this->assertSame(Mailbox::DEFAULT_SIGNATURE, Markdown::toHtml(Mailbox::DEFAULT_SIGNATURE_MARKDOWN));
        $this->actingAs($this->admin)->get(route('mailboxes.update', ['id' => $this->mailbox->id]))->assertSee('name="signature_markdown"', false);

        $this->postForm(route('mailboxes.update.save', ['id' => $this->mailbox->id]), [
            'signature_markdown' => "Best,\n[{%mailbox.name%}](https://example.com/{%mailbox.name%})",
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $mailbox = $this->mailbox->fresh();
        $this->assertSame("Best,\n[{%mailbox.name%}](https://example.com/{%mailbox.name%})", $mailbox->signature_markdown);
        $this->assertStringContainsString('href="https://example.com/{%mailbox.name%}"', $mailbox->signature);

        // Rich text, as before.
        $this->postForm(route('mailboxes.update.save', ['id' => $this->mailbox->id]), [
            'signature' => '<p><b>Old</b></p>',
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertNull($this->mailbox->fresh()->signature_markdown);
        $this->assertStringContainsString('<b>Old</b>', $this->mailbox->fresh()->signature);
    }

    public function testAutoReplies()
    {
        $url = route('mailboxes.auto_reply.save', ['id' => $this->mailbox->id]);
        // An empty message is refused on its own field.
        $this->postForm($url, [
            'auto_reply_enabled' => 1, 'auto_reply_subject' => 'Thanks', 'auto_reply_message_markdown' => " \n",
        ], ['X-Requested-With' => 'XMLHttpRequest'])->assertStatus(422)->assertJsonValidationErrors('auto_reply_message_markdown');

        $this->postForm($url, [
            'auto_reply_enabled' => 1, 'auto_reply_subject' => 'Thanks', 'auto_reply_message_markdown' => "We got it.\n\n- Mon to Fri",
            'versions' => ['de' => ['enabled' => 1, 'subject' => 'Danke', 'message_markdown' => '**Danke**']],
        ])->assertSessionHasNoErrors();
        $mailbox = $this->mailbox->fresh();
        $this->assertSame("We got it.\n\n- Mon to Fri", $mailbox->auto_reply_message_markdown);
        $this->assertSame("<p>We got it.</p>\n<ul>\n<li>Mon to Fri</li>\n</ul>", $mailbox->auto_reply_message);
        $version = MailboxAutoReply::where('mailbox_id', $mailbox->id)->where('language', 'de')->first();
        $this->assertSame('**Danke**', $version->message_markdown);
        $this->assertSame('<p><strong>Danke</strong></p>', $version->message);
        // Saved with its button, not as it changes: no auto reply goes out half-written.
        $this->actingAs($this->admin)->get(route('mailboxes.auto_reply', ['id' => $this->mailbox->id]))
            ->assertSee('name="auto_reply_message_markdown"', false)->assertSee('name="versions[de][message_markdown]"', false)
            ->assertDontSee('Tallport.autoSave', false)->assertSee('form="page-form"', false);
    }

    public function testKnowledgeBase()
    {
        \Session::start();
        $this->actingAs($this->admin)->post(route('kb.save'), [
            '_token' => csrf_token(), 'title' => 'Refunds', 'mailbox_id' => '', 'body_markdown' => "Refunds take **5 days**.\n\n<script>x</script>",
        ]);
        $article = KbArticle::where('title', 'Refunds')->first();
        $this->assertSame("<p>Refunds take <strong>5 days</strong>.</p>", $article->body);
        // The AI reads the Markdown as written.
        $this->assertSame("# Refunds\n\nRefunds take **5 days**.\n\n<script>x</script>", $article->documentContent());
        $this->postAjax($this->admin, route('kb.ajax'), ['action' => 'get', 'article_id' => $article->id])
            ->assertJson(['markdown' => "Refunds take **5 days**.\n\n<script>x</script>"]);
        $this->actingAs($this->admin)->get(route('kb.edit', ['id' => $article->id]))->assertSee('name="body_markdown"', false);
    }

    public function testWorkflowTexts()
    {
        \Session::start();
        $this->actingAs($this->admin)->post(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            '_token' => csrf_token(), 'name' => 'Note', 'type' => Workflow::TYPE_MANUAL,
            'actions' => json_encode([[['type' => 'note', 'value' => ['body_markdown' => 'Check **{%customer.email%}**']]]]),
        ])->assertSessionHasNoErrors();
        $value = Workflow::where('name', 'Note')->first()->getActions()[0][0]['value'];
        $this->assertSame('Check **{%customer.email%}**', $value['body_markdown']);
        $this->assertSame('<p>Check <strong>{%customer.email%}</strong></p>', $value['body']);
    }

    public function testBranding()
    {
        $this->postForm(route('settings.save', ['section' => 'branding']), [
            'settings' => ['branding.accent' => 'blue', 'branding.email_footer_markdown' => '*Example Inc.*', 'branding.footer' => '<p>Old</p>'],
        ]);
        Option::$cache = [];
        $this->assertSame('*Example Inc.*', Option::get('branding.email_footer_markdown'));
        $this->assertSame('<p><em>Example Inc.</em></p>', Option::get('branding.email_footer'));
        $this->assertSame('<p>Old</p>', Option::get('branding.footer'));
        $this->assertNull(Option::get('branding.footer_markdown', null));
    }
}
