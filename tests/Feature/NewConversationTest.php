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

        $form = $this->form()->call('switchType', Conversation::TYPE_PHONE)->assertSee('Customer Name')
            ->set('name_query', 'Robin')->assertSee('Robin Buyer');
        $form->call('chooseCustomer', $existing->id)->assertSet('customer_id', $existing->id)->assertSet('to_email', 'robin@customer.example.org');

        $this->form()->call('switchType', Conversation::TYPE_PHONE)->set('name_query', 'Kim Caller')->set('phone', '+31 20 555 0199')
            ->set('subject', 'Called about a refund')->set('body', '<p>Wants a refund.</p>')->call('send')->assertRedirect();

        $customer = Customer::where('first_name', 'Kim')->where('last_name', 'Caller')->first();
        $this->assertNotNull($customer);
        $conversation = Conversation::where('customer_id', $customer->id)->first();
        $this->assertSame(Conversation::TYPE_PHONE, $conversation->type);
        $this->assertSame(Thread::TYPE_NOTE, $conversation->threads()->first()->type);
        $this->assertCount(0, $this->sentEmails());
    }
}
