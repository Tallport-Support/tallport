<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Livewire\ConversationThread;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Notes are written in Markdown, replies as the agent prefers (Preferences › Reply Editor).
 * What's written in Markdown is stored as written, with the HTML rendered from it; a draft
 * reopens, and a message is edited, the way it was written.
 */
class MarkdownRepliesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;
    protected $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Broken zipper',
        ]));
        $this->conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
    }

    protected function composer()
    {
        return Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation]);
    }

    public function testThePreference()
    {
        $this->actingAs($this->agent)->get(route('own.preferences'))->assertSee('name="reply_editor"', false);
        \Session::start();
        $this->post(route('users.preferences.save', ['id' => $this->agent->id]), ['_token' => csrf_token(), 'reply_editor' => 'markdown'])->assertRedirect();
        $this->assertTrue($this->agent->fresh()->markdown_replies);
        $this->post(route('users.preferences.save', ['id' => $this->agent->id]), ['_token' => csrf_token(), 'reply_editor' => 'html'])->assertSessionHasErrors('reply_editor');
        $this->post(route('users.preferences.save', ['id' => $this->agent->id]), ['_token' => csrf_token(), 'reply_editor' => 'rich']);
        $this->assertFalse($this->agent->fresh()->markdown_replies);
    }

    public function testNotesAreMarkdown()
    {
        $this->composer()->call('open', 'note')->assertSet('markdown', true)->assertSee('data-fruit-mode="markdown"', false)
            ->set('body', "Ask **Casey**\n- size")->call('send');

        $note = Thread::where('conversation_id', $this->conversation->id)->where('type', Thread::TYPE_NOTE)->first();
        $this->assertSame("Ask **Casey**\n- size", $note->body_markdown);
        $this->assertSame("<p>Ask <strong>Casey</strong></p>\n<ul>\n<li>size</li>\n</ul>", $note->body);

        // Edited in Markdown.
        $this->agent->permissions = [\App\User::PERM_EDIT_CONVERSATIONS => true];
        $this->agent->save();
        Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $this->conversation])
            ->call('edit', $note->id)->assertSet('editing', $note->id)->assertSee('data-fruit-mode="markdown"', false)->assertSee('Ask **Casey**')
            ->call('saveEdit', $note->id, 'Ask *Casey*');
        $note->refresh();
        $this->assertSame('Ask *Casey*', $note->body_markdown);
        $this->assertSame('<p>Ask <em>Casey</em></p>', $note->body);
    }

    public function testRepliesFollowThePreferenceAndDraftsReopenAsWritten()
    {
        // Rich text: as before.
        $this->composer()->call('open', 'reply')->assertSet('markdown', false)->assertDontSee('data-fruit-mode="markdown"', false);

        $this->agent->markdown_replies = true;
        $this->agent->save();
        $composer = $this->composer()->call('open', 'reply')->assertSet('markdown', true)->assertSee('data-fruit-mode="markdown"', false)
            ->set('body', "Hi Casey,\nwe'll send a **new zipper**.")->call('saveDraft');
        $draft = Thread::where('conversation_id', $this->conversation->id)->where('state', Thread::STATE_DRAFT)->first();
        $this->assertSame("Hi Casey,\nwe'll send a **new zipper**.", $draft->body_markdown);
        $this->assertSame("<p>Hi Casey,<br />\nwe'll send a <strong>new zipper</strong>.</p>", $draft->body);

        // A Markdown draft reopens as written, also for someone writing rich text; a rich text one as rich text.
        $this->agent->markdown_replies = false;
        $this->agent->save();
        $this->composer()->call('editDraft', $draft->id)->assertSet('markdown', true)->assertSet('body', "Hi Casey,\nwe'll send a **new zipper**.");
        $draft->body_markdown = null;
        $draft->save();
        $this->agent->markdown_replies = true;
        $this->agent->save();
        $this->composer()->call('editDraft', $draft->id)->assertSet('markdown', false)->assertSet('body', $draft->body);

        // Sent: the email has the HTML.
        $draft->body_markdown = 'Sent **now**';
        $draft->save();
        $this->composer()->call('editDraft', $draft->id)->call('send', null, 'Sent **now**');
        $reply = $draft->fresh();
        $this->assertSame(Thread::STATE_PUBLISHED, $reply->state);
        $this->assertSame('Sent **now**', $reply->body_markdown);
        $this->assertSame('<p>Sent <strong>now</strong></p>', $reply->body);
    }

    /**
     * A channel's reply renders only what the channel takes.
     */
    public function testChannelFormatsLimitTheRenderedReply()
    {
        $limit = fn ($formats) => ['bold'];
        \Eventy::addFilter('channel.formats', $limit, 20, 1);
        try {
            $this->conversation->channel = 99;
            $this->conversation->save();
            $this->agent->markdown_replies = true;
            $this->agent->save();
            $this->composer()->call('open', 'reply')->set('body', "**Yes**, *see* [here](https://example.com)")->call('saveDraft');
            $draft = Thread::where('conversation_id', $this->conversation->id)->where('state', Thread::STATE_DRAFT)->first();
            $this->assertSame('<p><strong>Yes</strong>, see here</p>', $draft->body);
        } finally {
            \Eventy::removeFilter('channel.formats', $limit, 20);
        }
    }
}
