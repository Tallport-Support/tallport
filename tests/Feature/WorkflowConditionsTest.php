<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use App\Workflow;
use App\Workflows\Conditions;
use App\Workflows\Runner;
use Tests\FeatureTestCase;

/**
 * Each condition a workflow can check, with its operators, on a
 * conversation as it is (time passing) and right after an event.
 */
class WorkflowConditionsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        // The Workflow user an earlier test made was rolled back.
        (new \ReflectionProperty(Runner::class, 'robot'))->setValue(null, null);

        $this->agent = $this->createUser(['first_name' => 'Robin', 'last_name' => 'Reply']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function workflow(array $conditions, array $actions, array $attributes = [])
    {
        $workflow = new Workflow();
        $workflow->mailbox_id = $this->mailbox->id;
        $workflow->name = $attributes['name'] ?? 'Test';
        $workflow->type = Workflow::TYPE_AUTOMATIC;
        $workflow->active = true;
        $workflow->complete = true;
        $workflow->max_executions = 5;
        $workflow->apply_to_prev = true;
        $workflow->sort_order = $attributes['sort_order'] ?? 1;
        $workflow->conditions = json_encode($conditions);
        $workflow->actions = json_encode($actions);
        $workflow->save();

        return $workflow;
    }

    protected function conversation(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email,
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * One condition row on the conversation, as one run checks it: the last
     * thread it remembers is forgotten afterwards.
     */
    protected function holds($type, $operator, $value, Conversation $conversation, $trigger = null)
    {
        try {
            return Conditions::checkRow(['type' => $type, 'operator' => $operator, 'value' => $value], $conversation, new Workflow(), $trigger);
        } finally {
            (new \ReflectionProperty(Runner::class, 'last_threads'))->setValue(null, []);
        }
    }

    public function testPeople()
    {
        $conversation = $this->conversation();

        $this->assertTrue($this->holds('customer_name', 'equal', 'casey customer', $conversation));
        $this->assertFalse($this->holds('customer_name', 'starts', 'Robin', $conversation));
        $this->assertTrue($this->holds('customer_email', 'equal', 'casey@customer.example.org', $conversation));

        // Without a customer: the conversation's address.
        $conversation->customer_id = null;
        $conversation->unsetRelation('customer');
        $this->assertFalse($this->holds('customer_name', 'contains', 'Casey', $conversation));
        $this->assertTrue($this->holds('customer_email', 'ends', '@customer.example.org', $conversation));
    }

    public function testUserAction()
    {
        $colleague = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox->users()->attach($colleague->id);
        $conversation = $this->conversation();
        $this->assertFalse($this->holds('user_action', 'replied', '-1', $conversation), 'The customer wrote last.');

        $this->workflow([[['type' => 'user_action', 'operator' => 'noted', 'value' => (string) $this->agent->id]]], [
            [['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]],
        ]);
        $conversation->createUserThread($colleague, '<p>A colleague noted</p>', ['type' => Thread::TYPE_NOTE]);
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->fresh()->status, 'Another user.');
        $this->assertTrue($this->holds('user_action', 'noted', '-1', $conversation));
        $this->assertFalse($this->holds('user_action', 'replied', '-1', $conversation));
        $conversation->createUserThread($this->agent, '<p>I replied</p>');
        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->fresh()->status, 'A reply, not a note.');
        $conversation->createUserThread($this->agent, '<p>I noted</p>', ['type' => Thread::TYPE_NOTE]);
        $this->assertSame(Conversation::STATUS_PENDING, $conversation->fresh()->status);
    }

    public function testConversationFields()
    {
        $conversation = $this->conversation(['cc' => 'boss@customer.example.org']);

        $this->assertTrue($this->holds('type', 'equal', (string) Conversation::TYPE_EMAIL, $conversation));
        $this->assertFalse($this->holds('status', 'not_equal', (string) Conversation::STATUS_ACTIVE, $conversation));
        $this->assertTrue($this->holds('state', 'equal', (string) Conversation::STATE_PUBLISHED, $conversation));
        $this->assertTrue($this->holds('user', 'equal', '-1', $conversation), 'Unassigned.');
        $this->assertTrue($this->holds('user', 'not_equal', (string) $this->agent->id, $conversation));
        $conversation->user_id = $this->agent->id;
        $this->assertTrue($this->holds('user', 'equal', (string) $this->agent->id, $conversation));
        $this->assertFalse($this->holds('user', 'equal', '-1', $conversation));

        // The last message's recipients.
        $this->assertTrue($this->holds('to', 'equal', $this->mailbox->email, $conversation));
        $this->assertTrue($this->holds('cc', 'contains', 'boss@', $conversation));
        $this->assertFalse($this->holds('cc', 'contains', 'casey@', $conversation));
        $this->assertFalse($this->holds('to', 'contains', '@', new Conversation()), 'No messages.');
    }

    public function testBody()
    {
        $conversation = $this->conversation(['body' => "Hello,\n\nMy order 4711 is late."]);
        $conversation->createUserThread($this->agent, '<p>Internal: VIP customer</p>', ['type' => Thread::TYPE_NOTE]);
        $conversation = $conversation->fresh();

        $this->assertTrue($this->holds('body', 'customer', 'ORDER 4711', $conversation));
        $this->assertFalse($this->holds('body', 'customer', 'VIP', $conversation));
        $this->assertTrue($this->holds('body', 'note', 'vip', $conversation));
        $this->assertTrue($this->holds('body', 'regex', '/order \d+/', $conversation));
        $this->assertFalse($this->holds('body', 'regex', '/invoice \d+/', $conversation));

        // After an event: only the new thread, when it's of the kind.
        $this->assertTrue($this->holds('body', 'note', 'VIP', $conversation, 'conversation.note_added'));
        $this->assertFalse($this->holds('body', 'customer', 'order', $conversation, 'conversation.note_added'));

        // A customer's reply.
        $this->workflow([[['type' => 'body', 'operator' => 'customer', 'value' => 'refund']]], [
            [['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]],
        ]);
        $message_id = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->value('message_id');
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Re: Question',
            'in_reply_to' => $message_id, 'body' => 'I want a refund.',
        ]));
        $this->assertSame(2, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count());
        $this->assertSame(Conversation::STATUS_PENDING, $conversation->fresh()->status);
    }

    public function testHeaders()
    {
        $conversation = $this->conversation(['headers' => ['X-Mailer' => 'Shop 3000']]);

        $this->assertTrue($this->holds('headers', 'contains', 'x-mailer: shop', $conversation));
        $this->assertFalse($this->holds('headers', 'not_contains', 'Shop 3000', $conversation));
        $this->assertTrue($this->holds('headers', 'not_contains', 'X-Spam', $conversation));
        $this->assertTrue($this->holds('headers', 'regex', '/^X-Mailer: Shop \d+/m', $conversation));
        $this->assertTrue($this->holds('headers', 'contains', 'Shop', $conversation, 'conversation.customer_replied'));

        // A note last: no customer message to look at.
        $conversation->createUserThread($this->agent, '<p>Note</p>', ['type' => Thread::TYPE_NOTE]);
        $this->assertFalse($this->holds('headers', 'contains', 'Shop', $conversation->fresh(), 'conversation.note_added'));
        $this->assertFalse($this->holds('headers', 'not_contains', 'X-Spam', $conversation->fresh(), 'conversation.note_added'));
    }

    public function testFlags()
    {
        $conversation = $this->conversation();
        $customer_thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $this->assertTrue($this->holds('attachment', 'no', null, $conversation));
        $conversation->has_attachments = true;
        $this->assertTrue($this->holds('attachment', 'yes', null, $conversation));
        $this->assertFalse($this->holds('attachment', 'no', null, $conversation));

        $this->assertTrue($this->holds('bounce', 'no', null, $conversation));
        $this->assertFalse($this->holds('bounce', 'yes', null, $conversation));
        $customer_thread->send_status_data = json_encode(['is_bounce' => true]);
        $customer_thread->save();
        $this->assertTrue($this->holds('bounce', 'yes', null, $conversation));
        $this->assertFalse($this->holds('bounce', 'yes', null, new Conversation()), 'No customer message.');

        $this->assertTrue($this->holds('imported', 'no', null, $conversation));
        $customer_thread->imported = true;
        $customer_thread->save();
        $this->assertTrue($this->holds('imported', 'yes', null, $conversation));
        $without_threads = new Conversation();
        $without_threads->imported = true;
        $this->assertTrue($this->holds('imported', 'yes', null, $without_threads), 'The conversation, without threads.');

        $this->assertTrue($this->holds('customer_viewed', 'no', null, $conversation));
        $conversation->createUserThread($this->agent, '<p>Answer</p>');
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertFalse($this->holds('customer_viewed', 'yes', null, $conversation));
        $reply->opened_at = now();
        $reply->save();
        $this->assertTrue($this->holds('customer_viewed', 'yes', null, $conversation));
        $this->assertFalse($this->holds('customer_viewed', 'no', null, $conversation));
    }

    public function testNewReplyOrMoved()
    {
        $conversation = $this->conversation();

        $this->assertTrue($this->holds('new_or_reply', 'reply', null, $conversation, 'conversation.user_replied'));
        $this->assertTrue($this->holds('new_or_reply', 'reply', null, $conversation, 'conversation.customer_replied'));
        $this->assertFalse($this->holds('new_or_reply', 'reply', null, $conversation, 'conversation.created_by_customer'));
        $this->assertTrue($this->holds('new_or_reply', 'moved', null, $conversation, 'conversation.moved'));
        $this->assertFalse($this->holds('new_or_reply', 'other', null, $conversation, 'conversation.moved'));

        // Moved here from another mailbox: only workflows about moving run.
        $this->workflow([[['type' => 'new_or_reply', 'operator' => 'moved']]], [[['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]]]);
        $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'order']]], [[['type' => 'assign', 'value' => (string) $this->agent->id]]], ['name' => 'Orders', 'sort_order' => 2]);
        $other = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $this->receiveEmail($other, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $other->email, 'subject' => 'My order']));
        $moved = Conversation::where('mailbox_id', $other->id)->first();
        $moved->moveToMailbox($this->mailbox, $this->agent);

        $moved->refresh();
        $this->assertSame(Conversation::STATUS_PENDING, $moved->status);
        $this->assertNull($moved->user_id, 'Its subject was there before.');
    }

    public function testDates()
    {
        $conversation = $this->conversation();
        $hour = ['number' => '1', 'metric' => 'h'];

        $this->assertTrue($this->holds('created', 'in_last', $hour, $conversation));
        $this->assertFalse($this->holds('created', 'not_in_last', $hour, $conversation));
        $this->assertTrue($this->holds('customer_reply', 'in_last', $hour, $conversation));
        $this->assertFalse($this->holds('user_reply', 'in_last', $hour, $conversation), 'No user reply.');
        $this->assertFalse($this->holds('created', 'in_last', ['number' => '0', 'metric' => 'h'], $conversation), 'No span.');
        $this->assertFalse($this->holds('created', 'in_last', ['number' => '1', 'metric' => 'y'], $conversation), 'No such unit.');

        $conversation->createUserThread($this->agent, '<p>Answer</p>');
        Conversation::where('id', $conversation->id)->update(['last_reply_from' => Conversation::PERSON_USER, 'last_reply_at' => now()->subHours(3)]);
        $conversation = $conversation->fresh();
        $this->assertTrue($this->holds('user_reply', 'not_in_last', $hour, $conversation));
        $this->assertFalse($this->holds('waiting', 'longer', $hour, $conversation), 'The team replied last.');

        // The customer wrote after the reply: the reply's own time.
        $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
        $this->assertTrue($this->holds('user_reply', 'in_last', $hour, $conversation));
        $this->assertTrue($this->holds('waiting', 'longer', $hour, $conversation));
        $this->assertFalse($this->holds('waiting', 'not_longer', $hour, $conversation));
        $conversation->status = Conversation::STATUS_CLOSED;
        $this->assertFalse($this->holds('waiting', 'longer', $hour, $conversation), 'Closed: not waiting.');
    }

    public function testCheck()
    {
        $conversation = $this->conversation(['subject' => 'Invoice']);
        $workflow = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'invoice']]], []);

        $this->assertTrue(Conditions::check($workflow, $conversation));
        $conversation->state = Conversation::STATE_DRAFT;
        $this->assertFalse(Conditions::check($workflow, $conversation), 'Never on drafts.');
        $conversation->state = Conversation::STATE_PUBLISHED;
        $workflow->conditions = '[]';
        $this->assertFalse(Conditions::check($workflow, $conversation), 'No conditions: never.');

        $this->assertNull(Conditions::item('no_such_condition'));
        $this->assertSame(['conversation.status_changed'], array_slice(Conditions::item('status')['triggers'], -1));
    }

    public function testModuleConditions()
    {
        $conversation = $this->conversation();
        \Eventy::addFilter('workflow.check_condition', function ($result, $type, $operator, $value, $conversation) {
            return $type == 'vip' ? $value == 'gold' : $result;
        }, 20, 6);

        $this->assertTrue($this->holds('vip', 'equal', 'gold', $conversation));
        $this->assertFalse($this->holds('vip', 'equal', 'silver', $conversation));
        $this->assertFalse($this->holds('other', 'equal', 'gold', $conversation));
    }

    public function testComparisons()
    {
        $this->assertTrue(Conditions::compareText('Invoice', 'equal', 'INVOICE'));
        $this->assertFalse(Conditions::compareText('Invoice', 'equal', 'Invoice 2'));
        $this->assertTrue(Conditions::compareText('Invoice', 'not_equal', 'Receipt'));
        $this->assertTrue(Conditions::compareText('Invoice', 'not_contains', 'receipt'));
        $this->assertTrue(Conditions::compareText('Invoice', 'not_contains', ''));
        $this->assertFalse(Conditions::compareText('Invoice', 'not_contains', 'VOICE'));
        $this->assertTrue(Conditions::compareText('Invoice', 'starts', 'inv'));
        $this->assertFalse(Conditions::compareText('Invoice', 'starts', ''));
        $this->assertTrue(Conditions::compareText('Invoice', 'ends', 'ICE'));
        $this->assertFalse(Conditions::compareText('Invoice', 'unknown', 'Invoice'));
        $this->assertFalse(Conditions::compareText(null, 'contains', 'x'));
        $this->assertFalse(Conditions::compareText('Invoice', 'contains', ['Invoice']));
        $this->assertFalse(Conditions::compareList([], 'contains', 'x'));
        $this->assertTrue(Conditions::compareList([], 'not_contains', 'x'));
    }
}
