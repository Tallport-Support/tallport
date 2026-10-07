<?php

namespace Tests\Feature;

use App\Conversation;
use App\Jobs\SendNotificationToUsers;
use App\Mailbox;
use App\Thread;
use App\Workflow;
use App\Workflows\Actions;
use App\Workflows\Runner;
use Tests\FeatureTestCase;

/**
 * What workflow actions do to a conversation, and how the runner goes
 * through workflows: in order, until one stops or deletes, within its time.
 */
class WorkflowActionsTest extends FeatureTestCase
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
        $workflow->mailbox_id = array_key_exists('mailbox_id', $attributes) ? $attributes['mailbox_id'] : $this->mailbox->id;
        $workflow->name = $attributes['name'] ?? 'Test';
        $workflow->type = $attributes['type'] ?? Workflow::TYPE_AUTOMATIC;
        $workflow->active = true;
        $workflow->complete = true;
        $workflow->max_executions = $attributes['max_executions'] ?? 1;
        $workflow->apply_to_prev = true;
        $workflow->sort_order = $attributes['sort_order'] ?? 1;
        $workflow->conditions = json_encode($conditions);
        $workflow->actions = json_encode($actions);
        $workflow->save();

        return $workflow;
    }

    protected function manual(array $actions, array $attributes = [])
    {
        return $this->workflow([], $actions, array_merge(['type' => Workflow::TYPE_MANUAL], $attributes));
    }

    protected function conversation($subject = 'Question', array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => $subject,
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function perform($type, $value, Conversation $conversation, $runner = null, array $extra = [])
    {
        return Actions::perform(array_merge(['type' => $type, 'value' => $value], $extra), $conversation, new Workflow(), Runner::robot(), $runner);
    }

    public function testNotification()
    {
        \Queue::fake([SendNotificationToUsers::class]);
        $colleague = $this->createUser(['first_name' => 'Alex']);
        $outsider = $this->createUser(['first_name' => 'Outsider']);
        $this->mailbox->users()->attach($colleague->id);
        $conversation = $this->conversation();

        $this->assertFalse($this->perform('notification', ['assignee', 'last_user'], $conversation), 'Unassigned, no replies.');
        $this->assertFalse($this->perform('notification', [(string) $outsider->id], $conversation), 'No access to the mailbox.');
        \Queue::assertNotPushed(SendNotificationToUsers::class);

        $conversation->user_id = $this->agent->id;
        $conversation->save();
        $conversation->createUserThread($colleague, '<p>Answer</p>');
        $this->assertTrue($this->perform('notification', ['assignee', 'last_user', (string) $outsider->id], $conversation));

        \Queue::assertPushedOn('emails', SendNotificationToUsers::class, function ($job) use ($conversation, $colleague) {
            return $job->conversation->id == $conversation->id
                && $job->users->pluck('id')->sort()->values()->all() == collect([$this->agent->id, $colleague->id])->sort()->values()->all()
                && $job->threads->count() == $conversation->threads()->count();
        });
    }

    public function testReplyAndNote()
    {
        $conversation = $this->conversation('Question', ['cc' => 'boss@customer.example.org']);

        $this->assertFalse($this->perform('reply', json_encode(['body' => '<p><br></p>']), $conversation), 'Empty.');
        $this->assertTrue($this->perform('reply', json_encode([
            'body' => '<p>Hello {%customer.firstName%}</p>', 'cc' => 'team@customer.example.org', 'sender_name' => (string) Actions::SENDER_MAILBOX,
        ]), $conversation));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertStringContainsString('Hello Casey', $reply->body);
        $this->assertEqualsCanonicalizing(['team@customer.example.org', 'boss@customer.example.org'], $reply->getCcArray(), "The conversation's Cc too.");
        $this->assertSame(Actions::SENDER_MAILBOX, (int) $reply->getMeta(Actions::META_SENDER));
        $this->assertNull($reply->getMeta(Thread::META_CONVERSATION_HISTORY), 'A reply has the usual history.');

        // An image is something to say.
        $this->assertTrue($this->perform('note', ['body' => '<img src="https://example.org/a.png">'], $conversation));
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_NOTE)->count());

        // A channel's conversation: notes only.
        $conversation->channel = \App\Telegram\Telegram::CHANNEL;
        $this->assertFalse($this->perform('reply', ['body' => '<p>Hi</p>'], $conversation));
        $this->assertFalse($this->perform('email_customer', ['body' => '<p>Hi</p>'], $conversation));
        $this->assertTrue($this->perform('note', ['body' => '<p>Hi</p>'], $conversation));
    }

    /**
     * The sender name an email action chose, under the mailbox's "From name:
     * the user" setting (the mailbox.get_mail_from_name filter).
     */
    public function testSenderName()
    {
        $this->mailbox->from_name = Mailbox::FROM_NAME_USER;
        $this->mailbox->save();
        $conversation = $this->conversation();
        $robot = Runner::robot();
        $name = function ($sender) use ($conversation, $robot) {
            $thread = new Thread();
            $thread->setMeta(Actions::META_SENDER, $sender);

            return $this->mailbox->getMailFrom($robot, $conversation->fresh(), $thread)['name'];
        };

        $this->assertSame('Workflow', $name(Actions::SENDER_WORKFLOW));
        $this->assertSame('Workflow', $this->mailbox->getMailFrom($robot, $conversation)['name'], 'Not a workflow\'s email.');
        $this->assertSame('Support', $name(Actions::SENDER_MAILBOX));
        $this->assertSame('Support', $name(Actions::SENDER_ASSIGNEE_OR_MAILBOX), 'Unassigned.');
        $conversation->changeUser($this->agent->id, $this->agent, false);
        $this->assertSame('Robin Reply', $name(Actions::SENDER_ASSIGNEE_OR_MAILBOX));

        // The mailbox's own name setting wins.
        $this->mailbox->from_name = Mailbox::FROM_NAME_MAILBOX;
        $this->assertSame('Support', $name(Actions::SENDER_ASSIGNEE_OR_MAILBOX));
    }

    /**
     * The sender name reaches the email: the From of a workflow's reply.
     */
    public function testSenderNameInSentEmail()
    {
        $this->knownBug('R4');

        $this->mailbox->from_name = Mailbox::FROM_NAME_USER;
        $this->mailbox->save();
        $conversation = $this->conversation();

        $this->perform('reply', ['body' => '<p>Reply</p>', 'sender_name' => (string) Actions::SENDER_MAILBOX], $conversation);

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertSame('Support', end($emails)->getFrom()[$this->mailbox->email]);
    }

    /**
     * An email action whose body is only markup and spaces does nothing,
     * as the editor treats it (incomplete) and as forward does.
     */
    public function testBlankEmailBodyIsNotSent()
    {
        $this->knownBug('W2');

        $conversation = $this->conversation();

        $this->assertFalse($this->perform('reply', ['body' => '<p> </p>'], $conversation));
        $this->assertSame(0, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());
    }

    public function testForward()
    {
        $conversation = $this->conversation('Broken zipper');

        $this->assertFalse($this->perform('forward', ['body' => '<p>FYI</p>'], $conversation), 'No address.');
        $this->assertFalse($this->perform('forward', ['to' => 'repairs@example.org', 'body' => ' '], $conversation), 'Nothing to say.');
        $this->assertTrue($this->perform('forward', [
            'to' => 'repairs@example.org', 'cc' => 'boss@example.org', 'body' => '<p>For {%customer.firstName%}</p>',
            'conv_history' => 'full', 'sender_name' => (string) Actions::SENDER_MAILBOX,
        ], $conversation));

        $forwarded = Conversation::where('customer_email', 'repairs@example.org')->first();
        $this->assertSame('Fwd: Broken zipper', $forwarded->subject);
        $this->assertSame(Runner::robot()->id, $forwarded->created_by_user_id);
        $this->assertContains('boss@example.org', $forwarded->getCcArray());
        $thread = $forwarded->threads()->first();
        $this->assertStringContainsString('For Casey', $thread->body);
        $this->assertSame('full', $thread->getMeta(Thread::META_CONVERSATION_HISTORY));
        $this->assertSame(Actions::SENDER_MAILBOX, (int) $thread->getMeta(Actions::META_SENDER));
        $this->assertNotEmpty($this->sentEmailsTo('repairs@example.org'));

        // Never what a workflow forwarded.
        $this->assertFalse($this->perform('forward', ['to' => 'elsewhere@example.org', 'body' => '<p>FYI</p>'], $forwarded));
    }

    public function testStatusAssignAndAutoReply()
    {
        $colleague = $this->createUser(['first_name' => 'Alex']);
        $conversation = $this->conversation();

        $this->assertTrue($this->perform('no_autoreply', null, $conversation));
        $this->assertFalse($this->perform('no_autoreply', null, $conversation), 'Already off.');

        $this->assertFalse($this->perform('status', (string) Conversation::STATUS_ACTIVE, $conversation), 'Already.');
        $this->assertFalse($this->perform('status', '99', $conversation), 'No such status.');

        $this->assertFalse($this->perform('assign', '-1', $conversation), 'Already unassigned.');
        $this->assertFalse($this->perform('assign', (string) Workflow::ASSIGNEE_CURRENT, $conversation), 'Nobody runs it.');
        $this->assertFalse($this->perform('assign', '999999', $conversation), 'No such user.');
        $this->assertTrue($this->perform('assign', (string) Workflow::ASSIGNEE_CURRENT, $conversation, $colleague));
        $this->assertSame($colleague->id, $conversation->fresh()->user_id);
        $this->assertFalse($this->perform('assign', (string) $colleague->id, $conversation->fresh()), 'Already theirs.');
        $this->assertTrue($this->perform('assign', '-1', $conversation->fresh()));
        $this->assertNull($conversation->fresh()->user_id);

        // Only if available: as a module says.
        \Eventy::addFilter('user.is_user_available', function ($available, $user) use ($colleague) {
            return $user->id == $colleague->id ? false : $available;
        }, 20, 2);
        $this->assertFalse($this->perform('assign', (string) $colleague->id, $conversation->fresh(), null, ['only_if_available' => 1]));
        $this->assertTrue($this->perform('assign', (string) $this->agent->id, $conversation->fresh(), null, ['only_if_available' => 1]));
        $this->assertSame($this->agent->id, $conversation->fresh()->user_id);
    }

    public function testMoveAndDelete()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->conversation();

        $this->assertFalse($this->perform('move', (string) $this->mailbox->id, $conversation), 'Already there.');
        $this->assertFalse($this->perform('move', '999999', $conversation));
        $this->assertTrue($this->perform('move', (string) $sales->id, $conversation));
        $this->assertSame($sales->id, $conversation->fresh()->mailbox_id);

        $this->assertTrue($this->perform('delete', null, $conversation->fresh()));
        $this->assertSame(Conversation::STATE_DELETED, $conversation->fresh()->state);
        $this->assertFalse($this->perform('delete', null, $conversation->fresh()), 'Already deleted.');

        $this->assertSame('deleted', $this->perform('delete_forever', null, $conversation->fresh()));
        $this->assertNull(Conversation::find($conversation->id));
        $this->assertSame(0, Thread::where('conversation_id', $conversation->id)->count());
    }

    public function testModuleActions()
    {
        $conversation = $this->conversation();
        $done = [];
        \Eventy::addFilter('workflow.perform_action', function ($performed, $type, $operator, $value, $conversation) use (&$done) {
            if ($type != 'tag') {
                return $performed;
            }
            $done[] = [$operator, $value, $conversation->id];

            return true;
        }, 20, 5);

        $this->assertTrue($this->perform('tag', 'vip', $conversation, null, ['operator' => 'add']));
        $this->assertFalse($this->perform('unknown', 'x', $conversation));
        $this->assertSame([['add', 'vip', $conversation->id]], $done);
        $this->assertNull(Actions::item('unknown'));
        $this->assertSame([], Actions::email('not json'));
    }

    public function testDeletingStopsLaterWorkflows()
    {
        $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'spam']]], [
            [['type' => 'delete_forever']],
            [['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]],
        ], ['sort_order' => 1]);
        $later = $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'spam']]], [
            [['type' => 'note', 'value' => json_encode(['body' => '<p>Seen</p>'])]],
        ], ['sort_order' => 2, 'name' => 'Later']);

        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Cheap spam']));

        $this->assertSame(0, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(0, $later->conversationsCount());
    }

    public function testManualRunsOnlyInItsMailbox()
    {
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $workflow = $this->manual([[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]);
        $here = $this->conversation();
        $this->receiveEmail($sales, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $sales->email]));
        $there = Conversation::where('mailbox_id', $sales->id)->first();

        $this->assertSame(1, Runner::runManual($workflow, [$here, $there], $this->agent));

        $this->assertSame(Conversation::STATUS_CLOSED, $here->fresh()->status);
        $this->assertSame(Conversation::STATUS_ACTIVE, $there->fresh()->status);
        $line = $here->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_MANUAL)->first();
        $this->assertSame('Robin Reply ran the Test workflow', $line->getActionText('', false, true, null, $this->agent->getFullName()));
        $this->assertSame('Robin Reply ran the Test workflow for conversation #'.$here->number, $line->getActionText($here->number, false, true, null, $this->agent->getFullName()));

        // Nothing done: no line.
        $this->assertSame(1, Runner::runManual($workflow, [$here->fresh()], $this->agent));
        $this->assertSame(1, $here->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_MANUAL)->count());

        // A workflow deleted since.
        $workflow->delete();
        $this->assertSame('Robin Reply ran the Deleted workflow', $line->fresh()->getActionText('', false, true, null, $this->agent->getFullName()));
    }

    public function testAutomaticLineWithConversationNumber()
    {
        $this->workflow([[['type' => 'subject', 'operator' => 'contains', 'value' => 'invoice']]], [[['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]]]);
        $conversation = $this->conversation('Invoice');

        $line = $conversation->threads()->where('action_type', Thread::ACTION_TYPE_WORKFLOW_AUTOMATIC)->first();
        $this->assertSame('Workflow Test was triggered for conversation #'.$conversation->number, $line->getActionText($conversation->number, false, true));
    }

    public function testEventErrorsAreLogged()
    {
        $this->workflow([[['type' => 'broken', 'operator' => 'equal', 'value' => 'x']]], [[['type' => 'status', 'value' => '3']]]);
        \Eventy::addFilter('workflows.conditions_config', function ($config) {
            $config['conversation']['items']['broken'] = ['title' => 'Broken', 'triggers' => ['conversation.created_by_customer']];

            return $config;
        }, 20, 2);
        \Eventy::addFilter('workflow.check_condition', function ($result, $type) {
            if ($type == 'broken') {
                throw new \RuntimeException('Module failed');
            }

            return $result;
        }, 20, 2);
        \Log::spy();

        $conversation = $this->conversation();

        $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->status, 'The email still came in.');
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_starts_with($message, '[Workflows] ') && str_contains($message, 'Module failed');
        })->once();

        // Events without a conversation: nothing to do.
        \Eventy::action('conversation.status_changed', null, $this->agent);
    }

    public function testTimeBasedRuns()
    {
        $hours = ['number' => '2', 'metric' => 'h'];
        $closing = $this->workflow([[['type' => 'created', 'operator' => 'not_in_last', 'value' => $hours]]], [
            [['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]],
            [['type' => 'stop']],
        ], ['name' => 'Close', 'sort_order' => 1]);
        $noting = $this->workflow([[['type' => 'created', 'operator' => 'not_in_last', 'value' => $hours]]], [
            [['type' => 'note', 'value' => json_encode(['body' => '<p>Old</p>'])]],
        ], ['name' => 'Note', 'sort_order' => 2]);
        $old = $this->conversation('Old');
        $new = $this->conversation('New');
        Conversation::where('id', $old->id)->update(['created_at' => now()->subHours(3)]);

        $this->assertSame(1, Runner::processDue());

        $this->assertSame(Conversation::STATUS_CLOSED, $old->fresh()->status);
        $this->assertSame(Conversation::STATUS_ACTIVE, $new->fresh()->status);
        $this->assertSame(0, $noting->conversationsCount(), 'The first one stopped it.');
        $this->assertSame(1, $closing->conversationsCount());
    }

    public function testTimeLimit()
    {
        $hours = ['number' => '2', 'metric' => 'h'];
        $first = $this->workflow([[['type' => 'created', 'operator' => 'not_in_last', 'value' => $hours]]], [
            [['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]],
        ], ['name' => 'First', 'sort_order' => 1]);
        $second = $this->workflow([[['type' => 'created', 'operator' => 'not_in_last', 'value' => $hours]]], [
            [['type' => 'note', 'value' => json_encode(['body' => '<p>Old</p>'])]],
        ], ['name' => 'Second', 'sort_order' => 2]);
        $old = $this->conversation('Old');
        Conversation::where('id', $old->id)->update(['created_at' => now()->subHours(3)]);

        $this->assertSame(1, Runner::processDue(0), 'Out of time after the first.');
        $this->assertSame(1, $first->conversationsCount());
        $this->assertSame(0, $second->conversationsCount());
        $this->assertSame(1, Runner::processDue(), 'The next run.');
        $this->assertSame(1, $second->conversationsCount());
    }

    /**
     * Conditions every conversation must meet narrow the conversations
     * looked at; the outcome is the same as checking each one.
     */
    public function testTimeBasedCandidates()
    {
        $colleague = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox->users()->attach($colleague->id);
        $hours = ['number' => '2', 'metric' => 'h'];
        $mine = $this->conversation('Mine');
        $theirs = $this->conversation('Theirs');
        $unassigned = $this->conversation('Unassigned');
        $pending = $this->conversation('Pending');
        Conversation::whereIn('id', [$mine->id, $theirs->id, $unassigned->id, $pending->id])->update(['created_at' => now()->subHours(3)]);
        Conversation::where('id', $mine->id)->update(['user_id' => $this->agent->id]);
        Conversation::where('id', $theirs->id)->update(['user_id' => $colleague->id]);
        Conversation::where('id', $pending->id)->update(['status' => Conversation::STATUS_PENDING]);
        $note = fn ($name) => [[['type' => 'note', 'value' => json_encode(['body' => '<p>'.$name.'</p>'])]]];
        $old = [['type' => 'created', 'operator' => 'not_in_last', 'value' => $hours]];
        $ran = function (Workflow $workflow) {
            Runner::processWorkflow($workflow);

            return Conversation::whereIn('id', \DB::table(Workflow::CONVERSATIONS_TABLE)->where('workflow_id', $workflow->id)->pluck('conversation_id'))
                ->orderBy('id')->pluck('subject')->all();
        };

        $this->assertSame(['Mine'], $ran($this->workflow([$old, [['type' => 'user', 'operator' => 'equal', 'value' => (string) $this->agent->id]]], $note('a'))));
        $this->assertSame(['Unassigned', 'Pending'], $ran($this->workflow([$old, [['type' => 'user', 'operator' => 'equal', 'value' => '-1']]], $note('b'))));
        $this->assertSame(['Mine', 'Theirs'], $ran($this->workflow([$old, [['type' => 'user', 'operator' => 'not_equal', 'value' => '-1']]], $note('c'))));
        $this->assertSame(['Pending'], $ran($this->workflow([$old, [['type' => 'status', 'operator' => 'equal', 'value' => (string) Conversation::STATUS_PENDING]]], $note('d'))));
        $this->assertSame(['Mine', 'Theirs', 'Unassigned'], $ran($this->workflow([$old, [['type' => 'status', 'operator' => 'not_equal', 'value' => (string) Conversation::STATUS_PENDING]]], $note('e'))));
        $this->assertSame([], $ran($this->workflow([[['type' => 'created', 'operator' => 'in_last', 'value' => $hours]]], $note('f'))));

        // Either condition: not narrowed by one of them.
        $this->assertSame(['Mine', 'Pending'], $ran($this->workflow([$old, [
            ['type' => 'user', 'operator' => 'equal', 'value' => (string) $this->agent->id],
            ['type' => 'status', 'operator' => 'equal', 'value' => (string) Conversation::STATUS_PENDING],
        ],], $note('g'))));
    }

    /**
     * "Assigned to User is not X" holds for unassigned conversations when
     * checked one by one, so time passing should find them too.
     */
    public function testTimeBasedNotAssignedToUserIncludesUnassigned()
    {
        $this->knownBug('W1');

        $mine = $this->conversation('Mine');
        $unassigned = $this->conversation('Unassigned');
        Conversation::whereIn('id', [$mine->id, $unassigned->id])->update(['created_at' => now()->subHours(3)]);
        Conversation::where('id', $mine->id)->update(['user_id' => $this->agent->id]);
        $workflow = $this->workflow([
            [['type' => 'created', 'operator' => 'not_in_last', 'value' => ['number' => '2', 'metric' => 'h']]],
            [['type' => 'user', 'operator' => 'not_equal', 'value' => (string) $this->agent->id]],
        ], [[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]);
        $this->assertTrue(\App\Workflows\Conditions::check($workflow, $unassigned->fresh()));

        Runner::processWorkflow($workflow);

        $this->assertSame(Conversation::STATUS_CLOSED, $unassigned->fresh()->status);
        $this->assertSame(Conversation::STATUS_ACTIVE, $mine->fresh()->status);
    }
}
