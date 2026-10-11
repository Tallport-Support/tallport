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

    public function testUnavailableChatShowsAnExplanationAndAllowsOnlyANoteComposer()
    {
        $this->conversation->channel = \App\Matrix\Matrix::CHANNEL;
        $this->conversation->save();
        \App\Misc\ChatConversations::markUnavailable($this->conversation);
        $this->conversation->refresh();
        $this->composer()->call('open', 'reply')
            ->assertSee(\App\Misc\ChatConversations::unavailableMessage())->assertDontSee('btn-reply-submit', false)
            ->call('switchToNote')->assertSet('mode', 'note')->assertSee('btn-reply-submit', false)
            ->assertDontSee('Add Note &amp; Active', false);
        $actions = \App\Misc\ConversationActionButtons::getActions($this->conversation, $this->agent, $this->mailbox);
        $this->assertArrayNotHasKey('reply', $actions);
        \Livewire\Livewire::actingAs($this->agent)->test(\App\Livewire\ConversationToolbar::class, ['conversation' => $this->conversation])
            ->assertDontSee('changeStatus('.Conversation::STATUS_ACTIVE.')', false)
            ->assertDontSee('changeStatus('.Conversation::STATUS_PENDING.')', false);
    }

    /**
     * The empty field says who it's for, by mode; all of them go along for a switch in place.
     */
    public function testTheFieldsPlaceholder()
    {
        $placeholders = 'data-placeholders="'.e(json_encode(['reply' => 'Reply to Casey Customer', 'note' => 'Note for your team', 'forward' => 'Add a message'])).'"';
        $this->composer()->call('open', 'reply')->assertSee('placeholder="Reply to Casey Customer"', false)->assertSee($placeholders, false);
        $this->composer()->call('open', 'note')->assertSee('placeholder="Note for your team"', false);
        $this->composer()->call('open', 'forward')->assertSee('placeholder="Add a message"', false);
    }

    public function testSendsAReplyWithCc()
    {
        $this->composer()->call('open', 'reply')->call('send')->assertToasted('Please enter a message', 'danger');

        $this->composer()->call('open', 'reply')->set('cc', "pat@customer.example.org\n")
            ->set('body', '<p>A new zipper is on its way.</p>')->call('send', Conversation::STATUS_PENDING)->assertRedirect();

        // Recipient fields suggest customers.
        $this->composer()->call('open', 'reply')->set('recipient_query', 'casey')
            ->assertSee('<option value="casey@customer.example.org">Casey Customer</option>', false);

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

    /**
     * A forward takes copies of the conversation's files, which the draft keeps.
     */
    public function testForwardTakesTheFilesAlong()
    {
        $thread = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        \App\Attachment::create('invoice.pdf', 'application/pdf', null, '%PDF-1.4', null, false, $thread->id);
        $thread->has_attachments = true;
        $thread->save();
        $this->conversation->has_attachments = true;
        $this->conversation->save();

        $composer = $this->composer()->call('open', 'forward')->assertSet('conv_history', 'full');

        $this->assertSame(['invoice.pdf'], array_column($composer->get('attachments'), 'name'));
        $draft = Thread::find($composer->get('thread_id'));
        $this->assertSame(Thread::STATE_DRAFT, $draft->state);
        $this->assertTrue($draft->isForward());
        $this->assertSame(['invoice.pdf'], $draft->attachments->pluck('file_name')->all());
        $this->assertSame(1, $thread->attachments()->count(), 'Copies: the original stays.');

        // Continued later: a forward again, with its files and address.
        $composer->set('to_email', 'repairs@vendor.example.org')->set('body', '<p>Please fix</p>')->call('saveDraft');
        $this->composer()->call('editDraft', $draft->id)->assertSet('mode', 'forward')->assertSet('to_email', 'repairs@vendor.example.org')
            ->assertSet('attachments.0.name', 'invoice.pdf')->assertSet('attachments.0.embed', false);
    }

    public function testADraftToContinueFromTheAddress()
    {
        // Not a draft: not found.
        $message = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->composer()->call('editDraft', $message->id)->assertToasted('Thread not found', 'danger')->assertSet('mode', '');

        $composer = $this->composer()->call('open', 'reply')->set('cc', 'pat@customer.example.org')->set('body', '<p>Draft</p>')->call('saveDraft');
        $draft_id = $composer->get('thread_id');

        Livewire::withQueryParams(['show_draft' => $draft_id])->actingAs($this->agent)
            ->test(ConversationComposer::class, ['conversation' => $this->conversation])
            ->assertSet('mode', 'reply')->assertSet('thread_id', $draft_id)->assertSet('cc', 'pat@customer.example.org')->assertSet('body', '<p>Draft</p>');
    }

    /**
     * A draft another user's conversation holds, or one already sent: the
     * composer says so and keeps what was written.
     */
    public function testDraftAndSendErrors()
    {
        $other_mailbox = $this->createMailbox();
        $this->receiveEmail($other_mailbox, $this->makeEmail(['from' => 'pat@customer.example.org', 'to' => $other_mailbox->email]));
        $other_thread = Thread::whereHas('conversation', fn ($query) => $query->where('mailbox_id', $other_mailbox->id))->first();

        $this->composer()->call('open', 'reply')->set('thread_id', $other_thread->id)->set('body', '<p>Draft</p>')
            ->call('saveDraft')->assertToasted('Incorrect thread', 'danger')->assertNotDispatched('composer-draft-saved');

        $this->composer()->dispatch('composer-discard-draft', thread_id: $other_thread->id)->assertToasted('Not enough permissions', 'danger');
        $this->assertNotNull(Thread::find($other_thread->id));

        $sent = $this->conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $this->composer()->call('open', 'reply')->set('thread_id', $sent->id)->set('body', '<p>Answer</p>')
            ->call('send')->assertReturned(false)->assertToasted('Message has been already sent. Please discard this draft.', 'danger')
            ->assertNoRedirect()->assertSet('body', '<p>Answer</p>');
        $this->assertSame(0, $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());
    }

    public function testNotesAreNotSavedAsDrafts()
    {
        $this->composer()->call('saveDraft')->assertSet('thread_id', null);
        $this->composer()->call('open', 'note')->set('body', '<p>Internal</p>')->call('saveDraft')->assertSet('thread_id', null)->assertNotDispatched('composer-draft-saved');

        $this->assertSame(0, Thread::where('state', Thread::STATE_DRAFT)->count());
    }

    /**
     * A note kept in the browser comes back into a closed composer.
     */
    public function testANoteKeptInTheBrowser()
    {
        $this->composer()->call('openNote', '<p>Unsent note</p>')->assertSet('mode', 'note')->assertSet('body', '<p>Unsent note</p>')
            ->assertDispatched('fruit-editor-set', target: 'body', html: '<p>Unsent note</p>');

        $this->composer()->call('open', 'reply')->set('body', '<p>Reply</p>')->call('openNote', '<p>Unsent note</p>')
            ->assertSet('mode', 'reply')->assertSet('body', '<p>Reply</p>');
    }

    /**
     * Uploaded files and a saved reply's files go with the message; a removed one doesn't.
     */
    public function testAttachments()
    {
        $upload = \App\Attachment::create('photo.jpg', 'image/jpeg', null, 'jpeg', null, false);
        $saved = \App\Attachment::create('manual.pdf', 'application/pdf', null, '%PDF-1.4', null, false);
        $removed = \App\Attachment::create('wrong.txt', 'text/plain', null, 'oops', null, false);
        [$upload, $saved, $removed] = array_map(fn ($attachment) => [
            'id' => encrypt($attachment->id), 'name' => $attachment->file_name, 'size' => 4, 'url' => $attachment->url(),
        ], [$upload, $saved, $removed]);

        $composer = $this->composer()->call('open', 'reply')
            ->dispatch('composer-attach', attachments: [$upload, $removed, ['name' => 'no id']])
            ->dispatch('composer-saved-reply', id: 7, attachments: [$saved])
            ->assertSet('saved_reply_id', 7);
        $this->assertSame(['photo.jpg', 'wrong.txt', 'manual.pdf'], array_column($composer->get('attachments'), 'name'));

        $composer->call('removeAttachment', $removed['id']);
        $this->assertSame(['photo.jpg', 'manual.pdf'], array_column($composer->get('attachments'), 'name'));
        $composer->set('body', '<p>See the files.</p>')->call('send')->assertRedirect();

        $reply = $this->conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertEqualsCanonicalizing(['photo.jpg', 'manual.pdf'], $reply->attachments->pluck('file_name')->all());
    }

    /**
     * An AI draft opens a reply with it, and its translation goes along.
     */
    public function testAnAiDraft()
    {
        $this->composer()->call('open', 'note')
            ->dispatch('composer-ai-draft', html: '<p>Your zipper ships today.</p>', translation: 'Je rits wordt vandaag verzonden.', language: 'nl')
            ->assertSet('mode', 'reply')->assertSet('body', '<p>Your zipper ships today.</p>')
            ->assertSet('ai_draft_translation', 'Je rits wordt vandaag verzonden.')->assertSet('ai_draft_translation_language', 'nl')
            ->assertDispatched('fruit-editor-set', target: 'body', html: '<p>Your zipper ships today.</p>');

        // Into the reply being written.
        $this->composer()->call('open', 'reply')->set('cc', 'pat@customer.example.org')
            ->dispatch('composer-ai-draft', html: '<p>Draft</p>')->assertSet('cc', 'pat@customer.example.org')->assertSet('body', '<p>Draft</p>');
    }

    /**
     * The customer's addresses to reply to: the conversation's first; the one chosen gets the reply.
     */
    public function testChoosingTheCustomersAddress()
    {
        $customer = $this->conversation->customer;
        $customer->addEmail('casey@work.example.org');
        $composer = Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $this->conversation, 'toCustomers' => [
            ['email' => 'casey@work.example.org', 'customer' => $customer],
            ['email' => 'casey@customer.example.org', 'customer' => $customer],
        ]]);

        $composer->assertSet('to_customers', [
            'casey@work.example.org'     => 'Casey Customer <casey@work.example.org>',
            'casey@customer.example.org' => 'Casey Customer <casey@customer.example.org>',
        ])->call('open', 'reply')->assertSet('to', 'casey@customer.example.org');

        $composer->set('to', 'casey@work.example.org')->set('body', '<p>To work</p>')->call('send')->assertRedirect();
        $this->assertCount(1, $this->sentEmailsTo('casey@work.example.org'));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    /**
     * The assignee a reply leaves, as the mailbox says.
     */
    public function testTheAssigneeAsTheMailboxSays()
    {
        $colleague = $this->createUser();
        $this->mailbox->users()->attach($colleague->id);
        $this->conversation->user_id = $colleague->id;
        $this->conversation->save();
        $user_id = function ($ticket_assignee) {
            $this->mailbox->ticket_assignee = $ticket_assignee;
            $this->mailbox->save();

            return $this->composer()->call('open', 'reply')->get('user_id');
        };

        $this->assertSame(-1, $user_id(\App\Mailbox::TICKET_ASSIGNEE_ANYONE));
        $this->assertSame($this->agent->id, $user_id(\App\Mailbox::TICKET_ASSIGNEE_REPLYING));
        $this->assertSame($colleague->id, $user_id(\App\Mailbox::TICKET_ASSIGNEE_REPLYING_UNASSIGNED));
        $this->assertSame($colleague->id, $user_id(\App\Mailbox::TICKET_ASSIGNEE_KEEP_CURRENT));
    }
}
