<?php

namespace Tests\Feature;

use App\Conversation;
use App\Mailbox;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * Who may do what (app/Policies): archived mailboxes, users who see only
 * their assigned conversations, deleting, auto replies, and customers
 * limited to the user's mailboxes.
 */
class PoliciesTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([], ['name' => 'Support']);
    }

    protected function agent(array $permissions = [], $mailbox = null)
    {
        $agent = $this->createUser(['permissions' => $permissions]);
        ($mailbox ?: $this->mailbox)->users()->attach($agent->id);

        return $agent->fresh();
    }

    protected function conversation($mailbox = null, $from = 'casey@customer.example.org')
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => $from, 'to' => $mailbox->email, 'subject' => 'Question']));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function archive(Mailbox $mailbox)
    {
        $mailbox->state = Mailbox::STATE_ARCHIVED;
        $mailbox->save();
    }

    public function testArchivedMailboxesAreForAdminsOnly()
    {
        $archived = $this->createMailbox([], ['name' => 'Old']);
        $agent = $this->agent([User::PERM_DELETE_CONVERSATIONS => true], $archived);
        $conversation = $this->conversation($archived);
        $this->archive($archived);

        $this->assertFalse($agent->can('view', $conversation));
        $this->assertFalse($agent->can('viewCached', $conversation));
        $this->assertFalse($agent->can('delete', $conversation));
        $this->assertTrue($this->admin->can('view', $conversation));
        $this->assertTrue($this->admin->can('delete', $conversation));

        $this->actingAs($agent)->get(route('conversations.view', ['id' => $conversation->id]))->assertForbidden();
    }

    public function testDeletingConversations()
    {
        $agent = $this->agent();
        $deleter = $this->agent([User::PERM_DELETE_CONVERSATIONS => true]);
        $outsider = $this->createUser(['permissions' => [User::PERM_DELETE_CONVERSATIONS => true]]);
        $conversation = $this->conversation();

        $this->assertFalse($agent->can('delete', $conversation), 'Needs the permission.');
        $this->assertTrue($deleter->can('delete', $conversation));
        $this->assertFalse($outsider->can('delete', $conversation), 'Needs the mailbox.');

        // Bulk actions ask about no conversation in particular: the permission is enough.
        $this->assertTrue($outsider->can('delete', new Conversation()));
        $this->assertFalse($agent->can('delete', new Conversation()));
    }

    public function testUsersWhoSeeOnlyTheirAssignedConversations()
    {
        $agent = $this->agent([User::PERM_DELETE_CONVERSATIONS => true, User::PERM_ONLY_ASSIGNED_TICKETS => true]);
        $unassigned = $this->conversation();
        $assigned = $this->conversation();
        $assigned->user_id = $agent->id;
        $assigned->save();

        $this->assertFalse($agent->can('view', $unassigned));
        $this->assertFalse($agent->can('viewCached', $unassigned));
        $this->assertFalse($agent->can('delete', $unassigned));
        $this->assertTrue($agent->can('view', $assigned));
        $this->assertTrue($agent->can('delete', $assigned));
    }

    public function testDeletingNotes()
    {
        $agent = $this->agent([User::PERM_ONLY_ASSIGNED_TICKETS => true]);
        $conversation = $this->conversation();
        $note = new Thread();
        $note->conversation_id = $conversation->id;
        $note->type = Thread::TYPE_NOTE;
        $note->state = Thread::STATE_PUBLISHED;
        $note->status = Thread::STATUS_NOCHANGE;
        $note->body = '<p>Note</p>';
        $note->source_via = Thread::PERSON_USER;
        $note->source_type = Thread::SOURCE_TYPE_WEB;
        $note->user_id = $agent->id;
        $note->created_by_user_id = $agent->id;
        $note->save();

        $this->assertFalse($agent->can('delete', $note), 'The conversation is no longer theirs to see.');
        $this->assertFalse($agent->can('edit', $note));
        $this->assertFalse($this->admin->can('delete', $note), 'Only the author deletes a note.');

        $conversation->user_id = $agent->id;
        $conversation->save();
        $this->assertTrue($agent->fresh()->can('delete', $note->fresh()));

        $this->mailbox->users()->detach($agent->id);
        \App\Misc\Helper::$memory_cache = [];
        $this->assertFalse($agent->fresh()->can('delete', $note->fresh()), 'No longer in the mailbox.');
    }

    public function testAutoRepliesNeedTheMailboxPermission()
    {
        $agent = $this->agent();
        $this->assertFalse($agent->can('updateAutoReply', $this->mailbox));

        \DB::table('mailbox_user')->where('mailbox_id', $this->mailbox->id)->where('user_id', $agent->id)
            ->update(['access' => json_encode([Mailbox::ACCESS_PERM_AUTO_REPLIES])]);
        // User::mailboxesSettings() is cached.
        \Cache::flush();
        $this->assertTrue($agent->fresh()->can('updateAutoReply', $this->mailbox));
        $this->actingAs($agent->fresh())->get(route('mailboxes.auto_reply', ['id' => $this->mailbox->id]))->assertOk();

        $this->archive($this->mailbox);
        $this->assertFalse($agent->fresh()->can('updateAutoReply', $this->mailbox->fresh()), 'Not in an archived mailbox.');
    }

    public function testCustomersLimitedToTheUsersMailboxes()
    {
        $agent = $this->agent();
        $other_mailbox = $this->createMailbox([], ['name' => 'Sales']);
        $known = $this->conversation(null, 'known@customer.example.org')->customer;
        $stranger = $this->conversation($other_mailbox, 'stranger@customer.example.org')->customer;

        $this->assertTrue($agent->can('view', $stranger), 'Not limited by default.');

        config(['app.limit_user_customer_visibility' => true]);
        $this->assertTrue($agent->can('view', $known));
        $this->assertFalse($agent->can('view', $stranger));
        $this->assertTrue($this->admin->can('view', $stranger));
    }
}
