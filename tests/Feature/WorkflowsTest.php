<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use App\User;
use App\Workflow;
use App\Workflows\Conditions;
use App\Workflows\Runner;
use Tests\FeatureTestCase;

/**
 * Workflows: automatic ones when something happens and as time passes,
 * manual ones when a user runs them.
 */
class WorkflowsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Robin', 'last_name' => 'Reply']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Support']);
    }

    protected function workflow(array $conditions, $actions, array $attributes = [])
    {
        $workflow = new Workflow();
        $workflow->mailbox_id = array_key_exists('mailbox_id', $attributes) ? $attributes['mailbox_id'] : $this->mailbox->id;
        $workflow->name = $attributes['name'] ?? 'Test';
        $workflow->type = $attributes['type'] ?? Workflow::TYPE_AUTOMATIC;
        $workflow->active = true;
        $workflow->complete = true;
        $workflow->max_executions = $attributes['max_executions'] ?? 1;
        $workflow->apply_to_prev = $attributes['apply_to_prev'] ?? false;
        $workflow->sort_order = $attributes['sort_order'] ?? 1;
        $workflow->conditions = json_encode($conditions);
        $workflow->actions = is_string($actions) ? $actions : json_encode($actions);
        $workflow->save();

        return $workflow;
    }

    protected function conversation($subject = 'Question', $from = 'casey@customer.example.org')
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $from, 'to' => $this->mailbox->email, 'subject' => $subject]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    public function testNewConversation()
    {
        $workflow = $this->workflow(
            [[['type' => 'subject', 'operator' => 'contains', 'value' => 'invoice']], [['type' => 'new_or_reply', 'operator' => 'new']]],
            [
                [['type' => 'assign', 'value' => (string) $this->agent->id]],
                [['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]],
                [['type' => 'note', 'value' => json_encode(['body' => '<p>For {%customer.email%}</p>'])]],
            ]
        );

        $conversation = $this->conversation('Invoice 12');
        $other = $this->conversation('Question');

        $this->assertSame($this->agent->id, $conversation->user_id);
        $this->assertSame(Conversation::STATUS_PENDING, $conversation->status);
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $this->assertStringContainsString('For casey@customer.example.org', $note->body);
        $this->assertSame($workflow->id, (int) $note->getMeta('workflow_id'));
        $this->assertSame(Runner::robot()->id, $note->created_by_user_id);
        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC)->first();
        $this->assertStringContainsString('Workflow Test was triggered', strip_tags($line->getActionText('', true, false, null, '', $this->agent)));
        $this->assertNull($other->user_id, 'Not invoices.');
        $this->assertSame(1, $workflow->conversationsCount());

        // Once.
        $conversation->changeStatus(Conversation::STATUS_ACTIVE, $this->agent);
        $this->assertSame(1, $conversation->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC)->count());

        $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
            ->assertSee('Triggered by the <strong>Test</strong> workflow', false);
    }

    public function testEmailActions()
    {
        $this->workflow(
            [[['type' => 'customer_email', 'operator' => 'ends', 'value' => '@customer.example.org']]],
            [
                [['type' => 'email_customer', 'value' => json_encode([
                    'subject' => 'About #{%conversation.number%}', 'body' => '<p>Thanks!</p>', 'no_signature' => '1', 'cc' => 'boss@customer.example.org',
                ])]],
                [['type' => 'no_autoreply']],
            ]
        );

        $conversation = $this->conversation('Question');

        $thread = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('Thanks!', $thread->body);
        $this->assertSame(['boss@customer.example.org'], $thread->getCcArray());
        $this->assertSame('none', $thread->getMeta(Thread::META_CONVERSATION_HISTORY));
        $this->assertTrue(!empty($conversation->fresh()->meta['ar_off']));
        $email = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertNotEmpty($email);
        $this->assertSame('About #'.$conversation->number, end($email)->getSubject());
    }

    public function testTimePasses()
    {
        $workflow = $this->workflow(
            [[['type' => 'waiting', 'operator' => 'longer', 'value' => ['number' => '2', 'metric' => 'h']]]],
            [[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]
        );
        $old = $this->conversation('Old');
        $new = $this->conversation('New');
        Conversation::where('id', $old->id)->update(['last_reply_at' => now()->subHours(3)]);

        $this->artisan('tallport:workflows')->assertExitCode(0);

        $this->assertSame(Conversation::STATUS_CLOSED, $old->fresh()->status);
        $this->assertSame(Conversation::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame(0, Runner::processDue(), 'Once.');

        // Not by events: no condition about them.
        $this->assertFalse(Conditions::check($workflow, $new->fresh(), 'conversation.customer_replied'));
    }

    public function testOrderAndStop()
    {
        $global = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'spam']]], [
            [['type' => 'status', 'value' => (string) Conversation::STATUS_SPAM]],
            [['type' => 'stop']],
        ], ['mailbox_id' => null, 'name' => 'All mailboxes', 'sort_order' => 5]);
        $local = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'spam']]], [
            [['type' => 'assign', 'value' => (string) $this->agent->id]],
        ], ['sort_order' => 1]);

        $conversation = $this->conversation('Cheap spam');

        $this->assertSame(Conversation::STATUS_SPAM, $conversation->status);
        $this->assertNull($conversation->user_id, 'All mailboxes first; it stopped the rest.');
        $this->assertSame(1, $global->conversationsCount());
        $this->assertSame(0, $local->conversationsCount());
    }

    public function testManualAndStoredShapes()
    {
        // As a module stored them: objects with numeric keys.
        $workflow = $this->workflow([], '{"0":[{"type":"assign","value":"-10"}],"2":{"0":{"type":"status","value":"3"}}}', ['type' => Workflow::TYPE_MANUAL]);
        $conversation = $this->conversation();
        $this->assertCount(2, $workflow->getActions());

        $this->assertSame(1, Runner::runManual($workflow, [$conversation], $this->agent));

        $conversation->refresh();
        $this->assertSame($this->agent->id, $conversation->user_id);
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->status);
        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_MANUAL)->first();
        $this->assertSame($this->agent->id, $line->created_by_user_id);
        $this->assertSame(0, $workflow->conversationsCount(), 'Manual runs are not counted.');
    }

    public function testPages()
    {
        $admin = $this->createAdmin();
        \Session::start();
        $save = function ($url, array $data) use ($admin) {
            return $this->actingAs($admin)->post($url, array_merge(['_token' => csrf_token(), 'type' => Workflow::TYPE_AUTOMATIC, 'active' => 1, 'max_executions' => 1], $data));
        };

        $this->actingAs($admin)->get(route('mailboxes.workflows', ['mailbox_id' => $this->mailbox->id]))->assertOk()->assertSee('New Workflow');
        $this->get(route('mailboxes.workflows.create', ['mailbox_id' => $this->mailbox->id]))->assertOk()->assertSee('workflow-editor', false);
        $this->get(route('workflows'))->assertOk()->assertSee('All Mailboxes');
        $this->get(route('workflows.create'))->assertOk()->assertSee('workflow-editor', false);

        // Incomplete: not saved.
        $save(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            'name' => 'VIP', 'conditions' => json_encode([[['type' => 'subject', 'operator' => 'contains', 'value' => '']]]),
            'actions' => json_encode([[['type' => 'status', 'value' => '3']]]),
        ])->assertSessionHasErrors('conditions');
        $save(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            'name' => 'VIP', 'conditions' => json_encode([[['type' => 'subject', 'operator' => 'contains', 'value' => 'vip']]]),
            'actions' => json_encode([[['type' => 'reply', 'value' => json_encode(['body' => ''])]]]),
        ])->assertSessionHasErrors('actions');

        $save(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            'name' => 'VIP', 'conditions' => json_encode([[['type' => 'subject', 'operator' => 'contains', 'value' => 'vip']]]),
            'actions' => json_encode([[['type' => 'assign', 'value' => (string) $this->agent->id]]]),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $workflow = Workflow::where('name', 'VIP')->first();
        $this->assertSame($this->mailbox->id, $workflow->mailbox_id);
        $this->assertTrue($workflow->active && $workflow->complete);
        $this->get($workflow->url())->assertOk()->assertSee('value="VIP"', false)->assertSee('&quot;vip&quot;', false);
        $this->assertSame($this->agent->id, $this->conversation('VIP order')->user_id);

        $global = $save(route('workflows.save'), [
            'name' => 'Everywhere', 'type' => Workflow::TYPE_MANUAL,
            'actions' => json_encode([[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]),
        ]);
        $everywhere = Workflow::where('name', 'Everywhere')->first();
        $this->assertNull($everywhere->mailbox_id);
        $this->get($everywhere->url())->assertOk()->assertSee('value="Everywhere"', false);

        // Also on existing conversations: in the background.
        $existing = $this->conversation('Old question');
        $save(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            'name' => 'Close old', 'apply_to_prev' => 1, 'conditions' => json_encode([[['type' => 'subject', 'operator' => 'contains', 'value' => 'old']]]),
            'actions' => json_encode([[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]),
        ])->assertSessionHasNoErrors();
        $this->assertSame(Conversation::STATUS_CLOSED, $existing->fresh()->status);

        // Order, menus, run, delete.
        $this->postAjax($admin, route('workflows.ajax'), ['action' => 'sort', 'mailbox_id' => $this->mailbox->id, 'workflows' => [$workflow->id]])->assertJsonPath('status', 'success');
        $conversation = $this->conversation('Question');
        $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$conversation->id)
            ->assertSee('data-workflow-id="'.$everywhere->id.'"', false)->assertDontSee(route('mailboxes.workflows', ['mailbox_id' => $this->mailbox->id]), false);
        $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'run', 'workflow_id' => $workflow->id, 'conversation_id' => $conversation->id])->assertJsonPath('status', 'error');
        $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'run', 'workflow_id' => $everywhere->id, 'conversation_id' => $conversation->id])->assertJsonPath('status', 'success');
        $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'delete', 'workflow_id' => $workflow->id])->assertJsonPath('status', 'error');
        $this->postAjax($admin, route('workflows.ajax'), ['action' => 'delete', 'workflow_id' => $workflow->id])->assertJsonPath('status', 'success');
        $this->assertNull(Workflow::find($workflow->id));

        // Users allowed to manage workflows: their mailboxes'.
        $this->actingAs($this->agent)->get(route('mailboxes.workflows', ['mailbox_id' => $this->mailbox->id]))->assertForbidden();
        $this->agent->permissions = [User::PERM_EDIT_WORKFLOWS => true];
        $this->agent->save();
        $this->actingAs($this->agent->fresh())->get(route('mailboxes.workflows', ['mailbox_id' => $this->mailbox->id]))->assertOk();
        $this->get(route('workflows'))->assertForbidden();
    }

    public function testDeletedMailboxOrUser()
    {
        $colleague = $this->createUser();
        $assigning = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]], [[['type' => 'assign', 'value' => (string) $colleague->id]]]);
        $other = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]], [[['type' => 'status', 'value' => '3']]], ['name' => 'Other']);

        \Eventy::action('user.deleted', $colleague, $this->createAdmin());
        $this->assertFalse($assigning->fresh()->active);
        $this->assertTrue($other->fresh()->active);

        \Eventy::action('mailbox.before_delete', $this->mailbox);
        $this->assertSame(0, Workflow::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testComparisons()
    {
        $this->assertTrue(Conditions::compareList(['a@x.org', 'b@x.org'], 'not_equal', 'c@x.org'));
        $this->assertFalse(Conditions::compareList(['a@x.org', 'b@x.org'], 'not_equal', 'a@x.org'));
        $this->assertTrue(Conditions::compareList(['a@x.org', 'b@y.org'], 'ends', '@y.org'));
        $this->assertTrue(Conditions::compareText('Invoice', 'regex', '/^inv/i'));
        $this->assertFalse(Conditions::compareText('Invoice', 'regex', '/[/'));
        $this->assertFalse(Conditions::compareText('', 'contains', ''));
        $this->assertSame(7200, Conditions::seconds(['number' => '2', 'metric' => 'h']));
        $this->assertNull(Conditions::seconds(['number' => '', 'metric' => 'h']));
        $this->assertSame(User::TYPE_ROBOT, Runner::robot()->type);
    }
}
