<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Livewire\NewConversation;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * A new conversation (App\Livewire\NewConversation): emails, phone
 * conversations and their drafts.
 */
class NewConversationTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function form()
    {
        $conversation = new Conversation();
        $conversation->mailbox = $this->mailbox;

        return Livewire::actingAs($this->agent)->test(NewConversation::class, ['conversation' => $conversation, 'mailbox' => $this->mailbox]);
    }

    public function testSendsAnEmail()
    {
        $this->actingAs($this->agent)->get('/mailbox/'.$this->mailbox->id.'/new-ticket')->assertOk()->assertSee('new-conversation', false);

        $this->form()->set('to', 'casey@customer.example.org')->set('subject', 'Your order')->call('send')
            ->assertToasted('Please enter a message', 'danger');

        $this->form()->set('to', "casey@customer.example.org\npat@customer.example.org")->assertSee('Send emails separately to each recipient')
            ->set('to', 'casey@customer.example.org')->set('subject', 'Your order')->set('body', '<p>It ships today.</p>')
            ->call('send')->assertRedirect();

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame('Your order', $conversation->subject);
        $this->assertSame(Conversation::STATE_PUBLISHED, $conversation->state);
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testDraftGetsANumberAndCanBeDiscarded()
    {
        $form = $this->form()->set('to', 'casey@customer.example.org')->set('subject', 'Draft order')->set('body', '<p>Draft</p>')
            ->call('saveDraft')->assertDispatched('composer-draft-saved');
        $conversation = Conversation::find($form->get('conversation_id'));
        $this->assertSame(Conversation::STATE_DRAFT, $conversation->state);
        $this->assertSame($conversation->number, $form->get('number'));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));

        // Saved again: the same draft.
        $form->set('body', '<p>Draft, longer</p>')->call('saveDraft');
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());

        $form->call('discard')->assertRedirect();
        $this->assertNull(Conversation::find($conversation->id));
    }

    public function testPhoneConversationWithANewCustomer()
    {
        $existing = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin', 'last_name' => 'Buyer']);

        $form = $this->form()->set('type', Conversation::TYPE_PHONE)->assertSee('Customer Name')
            ->set('name_query', 'Robin')->assertSee('Robin Buyer');
        $form->call('chooseCustomer', $existing->id)->assertSet('customer_id', $existing->id)->assertSet('to_email', 'robin@customer.example.org');

        $this->form()->set('type', Conversation::TYPE_PHONE)->set('name_query', 'Kim Caller')->set('phone', '+31 20 555 0199')
            ->set('subject', 'Called about a refund')->set('body', '<p>Wants a refund.</p>')->call('send')->assertRedirect();

        $customer = Customer::where('first_name', 'Kim')->where('last_name', 'Caller')->first();
        $this->assertNotNull($customer);
        $conversation = Conversation::where('customer_id', $customer->id)->first();
        $this->assertSame(Conversation::TYPE_PHONE, $conversation->type);
        $this->assertSame(Thread::TYPE_NOTE, $conversation->threads()->first()->type);
        $this->assertCount(0, $this->sentEmails());
    }

    /**
     * From a forwarded message (Create a New Conversation from it): its sender
     * as the recipient.
     */
    public function testFromAForwardedMessage()
    {
        $thread = new Thread();
        $thread->body = '<p>From: Pat Partner &lt;pat@partner.example.org&gt;</p>';
        $thread->to = json_encode(['pat@partner.example.org']);
        $conversation = new Conversation();
        $conversation->mailbox = $this->mailbox;
        $conversation->subject = 'Re: Partnership';

        Livewire::actingAs($this->agent)->test(NewConversation::class, ['conversation' => $conversation, 'mailbox' => $this->mailbox, 'thread' => $thread])
            ->assertSet('to', 'pat@partner.example.org')->assertSet('subject', 'Re: Partnership')->assertSet('body', $thread->body)
            ->call('send', Conversation::STATUS_CLOSED)->assertRedirect();

        $sent = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame(Conversation::STATUS_CLOSED, $sent->status);
        $this->assertCount(1, $this->sentEmailsTo('pat@partner.example.org'));
    }

    /**
     * The copies of the message's files (ConversationsController::create()) go with it.
     */
    public function testFromAMessageWithFiles()
    {
        $file = \App\Attachment::create('terms.pdf', 'application/pdf', null, '%PDF-1.4', null, false);
        $conversation = new Conversation();
        $conversation->mailbox = $this->mailbox;

        Livewire::actingAs($this->agent)->test(NewConversation::class, ['conversation' => $conversation, 'mailbox' => $this->mailbox, 'attachments' => [$file]])
            ->assertSet('attachments.0.name', 'terms.pdf')
            ->set('to', 'pat@partner.example.org')->set('subject', 'Terms')->set('body', '<p>Attached.</p>')->call('send')->assertRedirect();

        $sent = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame(['terms.pdf'], $sent->threads()->first()->attachments->pluck('file_name')->all());
    }

    public function testFilesAndSavedReplies()
    {
        [$upload, $saved, $removed] = array_map(fn ($attachment) => [
            'id' => encrypt($attachment->id), 'name' => $attachment->file_name, 'size' => 4, 'url' => $attachment->url(),
        ], [
            \App\Attachment::create('photo.jpg', 'image/jpeg', null, 'jpeg', null, false),
            \App\Attachment::create('manual.pdf', 'application/pdf', null, '%PDF-1.4', null, false),
            \App\Attachment::create('wrong.txt', 'text/plain', null, 'oops', null, false),
        ]);

        $form = $this->form()->dispatch('composer-attach', attachments: [$upload, $removed, ['name' => 'no id']])
            ->dispatch('composer-saved-reply', id: 3, attachments: [$saved])->assertSet('saved_reply_id', 3)
            ->call('removeAttachment', $removed['id']);
        $this->assertSame(['photo.jpg', 'manual.pdf'], array_column($form->get('attachments'), 'name'));

        $form->set('to', 'casey@customer.example.org')->set('subject', 'Manual')->set('body', '<p>Attached.</p>')->call('send')->assertRedirect();
        $thread = Conversation::where('mailbox_id', $this->mailbox->id)->first()->threads()->first();
        $this->assertEqualsCanonicalizing(['photo.jpg', 'manual.pdf'], $thread->attachments->pluck('file_name')->all());
    }

    /**
     * Nothing written: no draft. A draft or a message that fails says why, and the form stays.
     */
    public function testEmptyDraftsAndErrors()
    {
        $this->form()->set('subject', 'Only a subject')->call('saveDraft')->assertNotDispatched('composer-draft-saved');
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());

        $this->form()->set('to', 'not an address')->set('subject', 'Order')->set('body', '<p>Hi</p>')->call('send')
            ->assertToasted('Incorrect recipients', 'danger')->assertNoRedirect();

        // Someone else's message.
        $other = $this->createMailbox();
        $this->receiveEmail($other, $this->makeEmail(['from' => 'pat@customer.example.org', 'to' => $other->email]));
        $other_thread = Thread::whereHas('conversation', fn ($query) => $query->where('mailbox_id', $other->id))->first();
        $this->form()->set('thread_id', $other_thread->id)->set('to', 'casey@customer.example.org')->call('saveDraft')
            ->assertToasted('Incorrect thread', 'danger')->assertNotDispatched('composer-draft-saved');
        $this->form()->set('thread_id', $other_thread->id)->call('discard')->assertToasted('Not enough permissions', 'danger')->assertNoRedirect();
        $this->assertNotNull(Thread::find($other_thread->id));
        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testChoosingAPhoneCustomer()
    {
        $customer = $this->createCustomer('robin@customer.example.org', ['first_name' => 'Robin', 'last_name' => 'Buyer']);
        $customer->setPhones([['value' => '+31 20 555 0100', 'type' => \App\Customer::PHONE_TYPE_WORK]]);
        $customer->save();

        $form = $this->form()->set('type', Conversation::TYPE_PHONE)->call('chooseCustomer', $customer->id)
            ->assertSet('phone', '+31 20 555 0100')->assertSet('name_query', 'Robin Buyer');

        // A phone typed already stays; no such customer: nothing changes.
        $this->form()->set('type', Conversation::TYPE_PHONE)->set('phone', '+31 6 1234 5678')->call('chooseCustomer', $customer->id)
            ->assertSet('phone', '+31 6 1234 5678');
        $form->call('chooseCustomer', 999999)->assertSet('customer_id', $customer->id);

        // Typed again: a new customer.
        $form->set('name_query', 'Robin Other')->assertSet('customer_id', null)->assertSet('name', 'Robin Other');
    }

    public function testOnlyForThoseWhoSeeTheMailbox()
    {
        $conversation = new Conversation();
        $conversation->mailbox = $this->mailbox;

        Livewire::actingAs($this->createUser())->test(NewConversation::class, ['conversation' => $conversation, 'mailbox' => $this->mailbox])
            ->assertForbidden();
    }
}
