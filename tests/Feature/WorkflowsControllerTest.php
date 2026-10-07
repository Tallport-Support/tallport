<?php

namespace Tests\Feature;

use App\Conversation;
use App\Http\Controllers\WorkflowsController;
use App\User;
use App\Workflow;
use Tests\FeatureTestCase;

/**
 * Workflows pages (WorkflowsController): what a workflow must have to be
 * saved, and the ajax actions' refusals and redirects.
 */
class WorkflowsControllerTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function workflow($name, $type, array $actions, $mailbox_id)
    {
        $workflow = new Workflow();
        $workflow->mailbox_id = $mailbox_id;
        $workflow->name = $name;
        $workflow->type = $type;
        $workflow->active = true;
        $workflow->complete = true;
        $workflow->max_executions = 1;
        $workflow->sort_order = 1;
        $workflow->conditions = json_encode($type == Workflow::TYPE_AUTOMATIC ? [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]] : []);
        $workflow->actions = json_encode($actions);
        $workflow->save();

        return $workflow;
    }

    protected function conversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Question']));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function errors($type, array $conditions, array $actions)
    {
        return WorkflowsController::validateWorkflow($type, $conditions, $actions, $this->mailbox);
    }

    public function testConditionsMustBeComplete()
    {
        $status = [[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]];

        $this->assertSame(['conditions' => 'Add a condition.'], $this->errors(Workflow::TYPE_AUTOMATIC, [], $status));
        $this->assertSame([], $this->errors(Workflow::TYPE_MANUAL, [], $status), 'Manual workflows have no conditions.');
        $this->assertSame(
            ['conditions' => 'Complete or remove the incomplete conditions.'],
            $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'no_such_condition', 'operator' => 'contains', 'value' => 'x']]], $status)
        );
        $this->assertSame(
            ['conditions' => 'Enter a time for the date conditions.'],
            $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'waiting', 'operator' => 'longer', 'value' => ['number' => '', 'metric' => 'h']]]], $status)
        );
        $this->assertSame([], $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'waiting', 'operator' => 'longer', 'value' => ['number' => '2', 'metric' => 'h']]]], $status));
        $this->assertSame(
            ['conditions' => 'Not a valid regex pattern: /[/'],
            $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'subject', 'operator' => 'regex', 'value' => '/[/']]], $status)
        );

        // Modules can refuse a condition.
        $refuse = function ($refused, $row) {
            return $row['type'] == 'subject' && $row['value'] == 'refused' ? true : $refused;
        };
        \Eventy::addFilter('workflow.validate_condition', $refuse, 20, 2);
        $this->assertSame(
            ['conditions' => 'Complete or remove the incomplete conditions.'],
            $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'subject', 'operator' => 'contains', 'value' => 'refused']]], $status)
        );
        \Eventy::removeFilter('workflow.validate_condition', $refuse, 20);
    }

    public function testActionsMustBeComplete()
    {
        $this->assertSame(['actions' => 'Add an action.'], $this->errors(Workflow::TYPE_MANUAL, [], []));
        $this->assertSame(
            ['actions' => 'Complete or remove the incomplete actions.'],
            $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'no_such_action', 'value' => '1']]])
        );
        $this->assertSame(
            ['actions' => 'Enter the address to forward to.'],
            $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'forward', 'value' => json_encode(['body' => '<p>FYI</p>', 'to' => 'not an address'])]]])
        );
        $this->assertSame([], $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'forward', 'value' => json_encode(['body' => '<p>FYI</p>', 'to' => 'boss@example.org'])]]]));
        $this->assertSame(
            ['actions' => 'Complete or remove the incomplete actions.'],
            $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'assign', 'value' => '999999']]]),
            'No such user.'
        );
        $this->assertSame(
            ['actions' => 'Only manual workflows can assign to the user running them.'],
            $this->errors(Workflow::TYPE_AUTOMATIC, [[['type' => 'subject', 'operator' => 'contains', 'value' => 'x']]], [[['type' => 'assign', 'value' => (string) Workflow::ASSIGNEE_CURRENT]]])
        );
        $this->assertSame([], $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'assign', 'value' => (string) Workflow::ASSIGNEE_CURRENT]]]));
        $this->assertSame(
            ['actions' => 'Complete or remove the incomplete actions.'],
            $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'status', 'value' => '99']]]),
            'Not one of the choices.'
        );
        $this->assertSame(
            ['actions' => 'Complete or remove the incomplete actions.'],
            $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'notification', 'value' => []]]]),
            'Nobody to notify.'
        );
        $this->assertSame([], $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'notification', 'value' => ['assignee']]]]));
    }

    /**
     * Modules add actions (workflows.actions_config) and can refuse one
     * (workflow.validate_action).
     */
    public function testModuleActions()
    {
        $add = function ($config) {
            $config['dummy']['items']['tag'] = ['title' => 'Add a Tag', 'values_type' => 'text'];

            return $config;
        };
        $refuse = function ($refused, $row) {
            return $row['type'] == 'tag' && $row['value'] == 'forbidden' ? true : $refused;
        };
        \Eventy::addFilter('workflows.actions_config', $add, 20, 1);
        \Eventy::addFilter('workflow.validate_action', $refuse, 20, 2);

        try {
            $this->assertSame([], $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'tag', 'value' => 'vip']]]));
            $this->assertSame(
                ['actions' => 'Complete or remove the incomplete actions.'],
                $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'tag', 'value' => '  ']]]),
                'A text action needs its text.'
            );
            $this->assertSame(
                ['actions' => 'Complete or remove the incomplete actions.'],
                $this->errors(Workflow::TYPE_MANUAL, [], [[['type' => 'tag', 'value' => 'forbidden']]])
            );
        } finally {
            \Eventy::removeFilter('workflows.actions_config', $add, 20);
            \Eventy::removeFilter('workflow.validate_action', $refuse, 20);
        }
    }

    public function testNamesAreUniquePerMailbox()
    {
        $this->workflow('Close', Workflow::TYPE_MANUAL, [[['type' => 'status', 'value' => '3']]], $this->mailbox->id);
        \Session::start();

        $response = $this->actingAs($this->admin)->post(route('mailboxes.workflows.save', ['mailbox_id' => $this->mailbox->id]), [
            '_token' => csrf_token(), 'name' => 'Close', 'type' => Workflow::TYPE_MANUAL,
            'actions' => json_encode([[['type' => 'status', 'value' => '3']]]),
        ]);

        $response->assertSessionHasErrors(['name' => 'A workflow with this name already exists.']);
        $this->assertSame(1, Workflow::where('name', 'Close')->count());

        // The same name for all mailboxes is another workflow.
        $this->post(route('workflows.save'), [
            '_token' => csrf_token(), 'name' => 'Close', 'type' => Workflow::TYPE_MANUAL,
            'actions' => json_encode([[['type' => 'status', 'value' => '3']]]),
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Workflow::where('name', 'Close')->count());
    }

    public function testAjaxRefusals()
    {
        $global = $this->workflow('Everywhere', Workflow::TYPE_MANUAL, [[['type' => 'status', 'value' => '3']]], null);

        // Sorting all mailboxes' workflows: admins.
        $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'sort', 'workflows' => [$global->id]])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
        $this->postAjax($this->admin, route('workflows.ajax'), ['action' => 'sort', 'mailbox_id' => 999999, 'workflows' => [$global->id]])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);

        // Deleting a global workflow: admins, even for users who may edit workflows.
        $this->agent->permissions = [User::PERM_EDIT_WORKFLOWS => true];
        $this->agent->save();
        $this->postAjax($this->agent->fresh(), route('workflows.ajax'), ['action' => 'delete', 'workflow_id' => $global->id])
            ->assertJson(['status' => 'error', 'msg' => 'Not enough permissions']);
        $this->assertNotNull(Workflow::find($global->id));

        $this->postAjax($this->admin, route('workflows.ajax'), ['action' => 'nonsense'])
            ->assertJson(['status' => 'error', 'msg' => 'Unknown action']);
    }

    public function testRunThatDeletesTheConversationRedirectsToTheMailbox()
    {
        $global = $this->workflow('Purge', Workflow::TYPE_MANUAL, [[['type' => 'delete_forever', 'value' => '']]], null);
        $conversation = $this->conversation();

        $response = $this->postAjax($this->agent, route('workflows.ajax'), [
            'action' => 'run', 'workflow_id' => $global->id, 'conversation_id' => $conversation->id, 'mailbox_id' => $this->mailbox->id,
        ]);

        $response->assertJson(['status' => 'success', 'redirect_url' => route('mailboxes.view', ['id' => $this->mailbox->id])]);
        $this->assertNull(Conversation::find($conversation->id));

        // A mailbox's workflow: to its mailbox.
        $own = $this->workflow('Purge here', Workflow::TYPE_MANUAL, [[['type' => 'delete_forever', 'value' => '']]], $this->mailbox->id);
        $conversation = $this->conversation();
        $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'run', 'workflow_id' => $own->id, 'conversation_id' => $conversation->id])
            ->assertJson(['status' => 'success', 'redirect_url' => route('mailboxes.view', ['id' => $this->mailbox->id])]);
    }

    public function testRunThatKeepsTheConversationStays()
    {
        $global = $this->workflow('Close', Workflow::TYPE_MANUAL, [[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]], null);
        $conversation = $this->conversation();

        $response = $this->postAjax($this->agent, route('workflows.ajax'), ['action' => 'run', 'workflow_id' => $global->id, 'conversation_id' => $conversation->id]);

        $response->assertJson(['status' => 'success']);
        $this->assertArrayNotHasKey('redirect_url', $response->json());
    }
}
