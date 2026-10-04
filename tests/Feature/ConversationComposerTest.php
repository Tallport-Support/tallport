<?php

namespace Tests\Feature;

use App\Conversation;
use App\Livewire\ConversationComposer;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The open conversation's composer (App\Livewire\ConversationComposer):
 * replies, notes, forwards and drafts, sent through the conversation ajax
 * actions' code.
 */
class ConversationComposerTest extends FeatureTestCase
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

    public function testModesAndTheSendButton()
    {
        $composer = $this->composer()->assertDontSee('btn-reply-submit', false);

        $composer->dispatch('composer-open', mode: 'reply')->assertSet('mode', 'reply')
            ->assertSee('btn-reply-submit', false)->assertSee('Send &amp; Active', false)->assertDontSee('Forward &amp; Pending', false)->assertDontSee('Add Note &amp; Close', false);
        // An open composer stays as it is.
        $composer->dispatch('composer-open', mode: 'note')->assertSet('mode', 'reply');

        $this->composer()->call('open', 'note')->assertSee('Add Note &amp; Close', false)->assertDontSee('name="cc"', false);
        $this->composer()->call('open', 'forward')->assertSee('name="to_email"', false)->assertSee('Forward &amp; Pending', false)->assertDontSee('Send &amp; Active', false);

        $this->actingAs($this->agent)->get($this->conversation->url())->assertStatus(200)->assertSee('conversation-composer', false);
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket')->assertSee('Send &amp; Close', false);
    }

    public function testSendsAReplyWithCc()
    {
        $this->composer()->call('open', 'reply')->call('send')->assertToasted('Please enter a message', 'danger');

        $this->composer()->call('open', 'reply')->set('show_cc', true)->set('cc', "pat@customer.example.org\n")
            ->set('body', '<p>A new zipper is on its way.</p>')->call('send', Conversation::STATUS_PENDING)->assertRedirect();

        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('A new zipper', $reply->body);
        $this->assertSame(['pat@customer.example.org'], $reply->getCcArray());
        $this->assertSame(Conversation::STATUS_PENDING, $this->conversation->fresh()->status);
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testAddsANoteAndForwards()
    {
        $this->composer()->call('open', 'note')->set('body', '<p>Warranty covers it.</p>')->call('send')
            ->assertDispatched('composer-note-forget')->assertRedirect();
        $this->assertSame(1, $this->conversation->threads()->where('type', Thread::TYPE_NOTE)->count());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));

        $this->composer()->call('open', 'forward')->set('to_email', 'noreply@vendor.example.org')
            ->assertSee('looks like an address that does not read replies', false)
            ->set('body', '<p>Please check this.</p>')->call('send')->assertRedirect();
        $this->assertCount(1, $this->sentEmailsTo('noreply@vendor.example.org'));
    }

    public function testDraftsAreSavedEditedAndDiscarded()
    {
        $composer = $this->composer()->call('open', 'reply')->call('saveDraft')->assertSet('thread_id', null);

        $composer->set('body', '<p>Draft answer</p>')->call('saveDraft')->assertDispatched('composer-draft-saved');
        $draft = Thread::find($composer->get('thread_id'));
        $this->assertSame(Thread::STATE_DRAFT, $draft->state);
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'Drafts are not sent.');

        $this->composer()->dispatch('composer-edit-draft', thread_id: $draft->id)->assertSet('mode', 'reply')->assertSet('thread_id', $draft->id)
            ->assertDispatched('fruit-editor-set')
            ->dispatch('composer-discard-draft', thread_id: $draft->id)->assertSet('mode', '');
        $this->assertNull(Thread::find($draft->id));

        Livewire::actingAs($this->createUser())->test(ConversationComposer::class, ['conversation' => $this->conversation])->assertForbidden();
    }
}
