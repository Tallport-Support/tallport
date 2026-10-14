<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Livewire\ConversationComposer;
use App\Livewire\ConversationInspector;
use App\Livewire\ConversationList;
use App\Livewire\ConversationSubject;
use App\Misc\ConversationActions;
use App\Thread;
use App\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * What agents do to conversations from the UI (App\Misc\ConversationActions,
 * the Livewire components and the ajax endpoint): assign, change status,
 * delete and restore, star, follow, rename, move, merge, bulk actions,
 * drafts, and editing or deleting threads.
 */
class ConversationActionsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveConversation(array $options = [], $mailbox = null)
    {
        $mailbox = $mailbox ?: $this->mailbox;
        $this->receiveEmail($mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $mailbox->email,
        ], $options)));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function ajax($user, array $data)
    {
        return $this->postAjax($user, '/conversation/ajax', $data)->json();
    }

    /**
     * The mailbox's Unassigned list (App\Livewire\ConversationList), for the bulk actions.
     */
    protected function folderList()
    {
        $folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        return Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder]);
    }

    protected function folderType(Conversation $conversation)
    {
        return Folder::find($conversation->fresh()->folder_id)->type;
    }

    protected function lastLineItem(Conversation $conversation)
    {
        return $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->orderBy('id', 'desc')->first();
    }

    protected function assertSuccess(array $response)
    {
        $this->assertSame('success', $response['status'], json_encode($response));
    }

    // Assigning.

    public function testAssignAndUnassign()
    {
        $conversation = $this->receiveConversation();

        $response = ConversationActions::changeUser($conversation->fresh(), $this->agent->id, $this->agent, request());
        $this->assertSuccess($response);
        $this->assertSame('Assignee updated', $response['msg']);
        $this->assertEquals($this->agent->id, $conversation->fresh()->user_id);
        $this->assertEquals(Folder::TYPE_ASSIGNED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_USER_CHANGED, $this->lastLineItem($conversation)->action_type);

        $again = ConversationActions::changeUser($conversation->fresh(), $this->agent->id, $this->agent, request());
        $this->assertSame('Assignee already set', $again['msg']);

        $this->assertSuccess(ConversationActions::changeUser($conversation->fresh(), -1, $this->agent, request()));
        $this->assertNull($conversation->fresh()->user_id);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation));
    }

    public function testCannotAssignToUserWithoutMailboxAccess()
    {
        $conversation = $this->receiveConversation();
        $outsider = $this->createUser();

        $response = ConversationActions::changeUser($conversation->fresh(), $outsider->id, $this->agent, request());

        $this->assertSame('Not enough permissions', $response['msg']);
        $this->assertNull($conversation->fresh()->user_id);
    }

    public function testAssigneeIsNotifiedByEmail()
    {
        $colleague = $this->createUser(['first_name' => 'Colleague']);
        $this->mailbox->users()->attach($colleague->id);
        $this->mailbox->syncPersonalFolders([$this->agent->id, $colleague->id]);
        $conversation = $this->receiveConversation(['subject' => 'Needs a specialist']);

        ConversationActions::changeUser($conversation->fresh(), $colleague->id, $this->agent, request());
        // At the end of the request (App\Http\Middleware\TerminateHandler).
        \App\Subscription::processEvents();

        $notifications = $this->sentEmailsTo($colleague->email);
        $this->assertCount(1, $notifications);
        $this->assertSame('user.notification', $notifications[0]->getHeaders()->get('X-Tallport-Mail-Type')->getFieldBody());
        $this->assertStringContainsString('Needs a specialist', $notifications[0]->getSubject());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    // Status.

    public function testCloseAndReopen()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess(ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_CLOSED, $this->agent, request()));
        $closed = $conversation->fresh();
        $this->assertEquals(Conversation::STATUS_CLOSED, $closed->status);
        $this->assertEquals($this->agent->id, $closed->closed_by_user_id);
        $this->assertNotNull($closed->closed_at);
        $this->assertEquals(Folder::TYPE_CLOSED, $this->folderType($conversation));
        $line_item = $this->lastLineItem($conversation);
        $this->assertEquals(Thread::ACTION_TYPE_STATUS_CHANGED, $line_item->action_type);
        $this->assertEquals(Conversation::STATUS_CLOSED, $line_item->status);

        $again = ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_CLOSED, $this->agent, request());
        $this->assertSame('Status already set', $again['msg']);

        $this->assertSuccess(ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_ACTIVE, $this->agent, request()));
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
        // Closing made the agent responsible (Conversation::takeOwnership()): reopened, it's theirs.
        $this->assertEquals($this->agent->id, $conversation->fresh()->user_id);
        $this->assertEquals(Folder::TYPE_ASSIGNED, $this->folderType($conversation));
    }

    public function testInvalidStatusIsRejected()
    {
        $conversation = $this->receiveConversation();

        $response = ConversationActions::changeStatus($conversation->fresh(), 99, $this->agent, request());

        $this->assertSame('Incorrect status', $response['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    public function testSpamAndNotSpam()
    {
        $conversation = $this->receiveConversation();
        ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_PENDING, $this->agent, request());

        $this->assertSuccess(ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_SPAM, $this->agent, request()));
        $this->assertEquals(Folder::TYPE_SPAM, $this->folderType($conversation));

        // "Not spam" restores the status it had before.
        $this->assertSuccess(ConversationActions::changeStatus($conversation->fresh(), 'not_spam', $this->agent, request()));
        $this->assertEquals(Conversation::STATUS_PENDING, $conversation->fresh()->status);
        $this->assertEquals($this->agent->id, $conversation->fresh()->user_id, 'Marking it spam made the agent responsible.');
        $this->assertEquals(Folder::TYPE_ASSIGNED, $this->folderType($conversation));
    }

    public function testUserWithoutAccessCannotChangeStatus()
    {
        $conversation = $this->receiveConversation();

        $response = ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_CLOSED, $this->createUser(), request());

        $this->assertSame('Not enough permissions', $response['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    // Deleting.

    public function testDeletingNeedsPermission()
    {
        $conversation = $this->receiveConversation();

        $response = ConversationActions::delete($conversation->fresh(), $this->agent);

        $this->assertSame('Not enough permissions', $response['msg']);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->fresh()->state);
    }

    public function testDeleteRestoreAndDeleteForever()
    {
        $deleter = $this->createUser(['permissions' => [User::PERM_DELETE_CONVERSATIONS => true]]);
        $this->mailbox->users()->attach($deleter->id);
        $conversation = $this->receiveConversation();
        $thread_ids = $conversation->threads()->pluck('id')->all();

        // Deleting moves it to the Deleted folder.
        $this->assertSuccess(ConversationActions::delete($conversation->fresh(), $deleter));
        $this->assertEquals(Conversation::STATE_DELETED, $conversation->fresh()->state);
        $this->assertEquals(Folder::TYPE_DELETED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_DELETED_TICKET, $this->lastLineItem($conversation)->action_type);

        $this->assertSuccess(ConversationActions::restore($conversation->fresh(), $deleter));
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->fresh()->state);
        $this->assertEquals($deleter->id, $conversation->fresh()->user_id, 'Deleting made the deleter responsible.');
        $this->assertEquals(Folder::TYPE_ASSIGNED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_RESTORE_TICKET, $this->lastLineItem($conversation)->action_type);

        // Deleting forever only works from the Deleted folder.
        $this->assertSuccess(ConversationActions::delete($conversation->fresh(), $deleter));
        $this->assertSuccess(ConversationActions::delete($conversation->fresh(), $deleter, true));
        $this->assertNull($conversation->fresh());
        $this->assertSame(0, Thread::whereIn('id', $thread_ids)->count());
    }

    /**
     * "Delete forever" is offered in the Deleted folder only; a conversation
     * elsewhere must be deleted (moved to Deleted) first (C2).
     */
    public function testDeleteForeverOnlyFromDeletedFolder()
    {
        $admin = $this->createAdmin();
        $conversation = $this->receiveConversation();

        $response = ConversationActions::delete($conversation->fresh(), $admin, true);

        $this->assertSame(['msg' => 'Only deleted conversations can be deleted forever.'], $response);
        $this->assertNotNull($conversation->fresh());
    }

    public function testEmptyDeletedFolder()
    {
        $admin = $this->createAdmin();
        $deleted = $this->receiveConversation(['subject' => 'To be deleted']);
        $kept = $this->receiveConversation(['subject' => 'To be kept', 'from' => 'other@customer.example.org']);
        ConversationActions::delete($deleted->fresh(), $admin);
        $deleted_folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_DELETED)->first();
        $unassigned_folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        $refused = $this->ajax($admin, ['action' => 'empty_folder', 'folder_id' => $unassigned_folder->id]);
        $this->assertSame('Folder not found', $refused['msg'], 'Only Deleted and Spam can be emptied.');

        $this->assertSuccess($this->ajax($admin, ['action' => 'empty_folder', 'folder_id' => $deleted_folder->id]));
        $this->assertNull($deleted->fresh());
        $this->assertNotNull($kept->fresh());
    }

    // Personal: star and follow.

    public function testStarAndUnstar()
    {
        $conversation = $this->receiveConversation();
        $starred = Folder::where('mailbox_id', $this->mailbox->id)
            ->where('type', Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();

        $heading = Livewire::actingAs($this->agent)->test(ConversationSubject::class, ['conversation' => $conversation]);
        $heading->call('star');
        $this->assertTrue(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $starred->id])->exists());
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation), 'Starring does not move the conversation.');

        $heading->call('star');
        $this->assertFalse(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $starred->id])->exists());
    }

    public function testFollowAndUnfollow()
    {
        $conversation = $this->receiveConversation();

        $follow = ConversationActions::follow($conversation->fresh(), $this->agent, true);
        $this->assertSame('Following', $follow['msg_success']);
        $this->assertTrue(\DB::table('followers')->where(['conversation_id' => $conversation->id, 'user_id' => $this->agent->id])->exists());

        $unfollow = ConversationActions::follow($conversation->fresh(), $this->agent, false);
        $this->assertSame('Unfollowed', $unfollow['msg_success']);
        $this->assertFalse(\DB::table('followers')->where(['conversation_id' => $conversation->id, 'user_id' => $this->agent->id])->exists());
    }

    // Subject.

    public function testUpdateSubject()
    {
        $conversation = $this->receiveConversation(['subject' => 'Old subject']);

        $this->assertSuccess(ConversationActions::changeSubject($conversation->fresh(), '  New subject ', $this->agent));
        $this->assertSame('New subject', $conversation->fresh()->subject);

        $empty = ConversationActions::changeSubject($conversation->fresh(), '   ', $this->agent);
        $this->assertSame([], $empty, 'Nothing to do.');
        $this->assertSame('New subject', $conversation->fresh()->subject);
    }

    // Moving and merging.

    public function testMoveToAnotherMailbox()
    {
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_move',
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $other_mailbox->id,
        ]));

        $moved = $conversation->fresh();
        $this->assertEquals($other_mailbox->id, $moved->mailbox_id);
        $this->assertEquals($other_mailbox->id, Folder::find($moved->folder_id)->mailbox_id);
        $line_item = $this->lastLineItem($conversation);
        $this->assertEquals(Thread::ACTION_TYPE_MOVED_FROM_MAILBOX, $line_item->action_type);
        $this->assertEquals($this->mailbox->id, $line_item->action_data);
    }

    public function testMoveUnassignsUserWithoutAccessToTargetMailbox()
    {
        $colleague = $this->createUser();
        $this->mailbox->users()->attach($colleague->id);
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales']);
        $conversation = $this->receiveConversation();
        ConversationActions::changeUser($conversation->fresh(), $colleague->id, $this->agent, request());

        $this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => $conversation->id, 'mailbox_id' => $other_mailbox->id]);

        $this->assertNull($conversation->fresh()->user_id);
    }

    public function testMerge()
    {
        $survivor = $this->receiveConversation(['subject' => 'Order 1', 'message_id' => 'one@customer.example.org']);
        $absorbed = $this->receiveConversation(['subject' => 'Order 1 again', 'message_id' => 'two@customer.example.org']);

        $this->assertSuccess($this->ajax($this->agent, [
            'action'                => 'conversation_merge',
            'conversation_id'       => $survivor->id,
            'merge_conversation_id' => [$absorbed->id],
        ]));

        $this->assertEquals($survivor->id, Thread::where('message_id', 'two@customer.example.org')->value('conversation_id'));
        $this->assertEquals(Thread::ACTION_TYPE_MERGED, $this->lastLineItem($survivor)->action_type);
        $absorbed = $absorbed->fresh();
        $this->assertEquals(Conversation::STATE_DELETED, $absorbed->state);
        $this->assertSame(0, $absorbed->threads()->where('type', '!=', Thread::TYPE_LINEITEM)->count());
    }

    /**
     * Merging into itself is refused, and every failed merge is reported,
     * not just the last one (C5).
     */
    public function testMergeProblemsAreReported()
    {
        $survivor = $this->receiveConversation(['subject' => 'Survivor']);
        $mergeable = $this->receiveConversation(['subject' => 'Mergeable', 'from' => 'b@customer.example.org']);
        $private = $this->receiveConversation([], $this->createMailbox([], ['name' => 'Private']));

        $self = $this->ajax($this->agent, ['action' => 'conversation_merge', 'conversation_id' => $survivor->id, 'merge_conversation_id' => [$survivor->id]]);
        $this->assertSame('error', $self['status']);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $survivor->fresh()->state);

        $mixed = $this->ajax($this->agent, ['action' => 'conversation_merge', 'conversation_id' => $survivor->id, 'merge_conversation_id' => [$private->id, $mergeable->id]]);
        $this->assertSame('success', $mixed['status'], 'One of them was merged.');
        $this->assertStringContainsString('#'.$private->number, $mixed['msg'], 'The failure is reported although it wasn\'t the last.');
        $this->assertEquals(Conversation::STATE_DELETED, $mergeable->fresh()->state);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $private->fresh()->state);
    }

    public function testRestoreOnlyDeletedConversations()
    {
        $admin = $this->createAdmin();
        $conversation = $this->receiveConversation();
        $threads = $conversation->threads()->count();

        $response = ConversationActions::restore($conversation->fresh(), $admin);

        $this->assertSame(['msg' => 'Only deleted conversations can be restored.'], $response, 'Nothing to restore (C8).');
        $this->assertSame($threads, $conversation->threads()->count(), 'No "restored" line item.');
    }

    public function testBulkActionsSkipUnchangedConversations()
    {
        $conversation = $this->receiveConversation();
        $threads = $conversation->threads()->count();

        $this->folderList()->set('selected', [$conversation->id])->call('changeStatus', Conversation::STATUS_ACTIVE);
        $this->folderList()->set('selected', [$conversation->id])->call('assign', -1);

        $this->assertSame($threads, $conversation->threads()->count(), 'No line items for changes that didn\'t happen (C9).');
    }

    public function testEditingMissingThread()
    {
        $this->assertSame('Thread not found', ConversationActions::editThread(Thread::find(999999), '<p>x</p>', $this->createAdmin())['msg']);
    }

    // Bulk actions from the folder list.

    public function testBulkStatusAndAssign()
    {
        $first = $this->receiveConversation(['subject' => 'First']);
        $second = $this->receiveConversation(['subject' => 'Second', 'from' => 'other@customer.example.org']);
        $ids = [$first->id, $second->id];

        $this->folderList()->set('selected', $ids)->call('changeStatus', Conversation::STATUS_CLOSED)->assertRedirect();
        $this->assertEquals([Conversation::STATUS_CLOSED, Conversation::STATUS_CLOSED], Conversation::whereIn('id', $ids)->pluck('status')->all());

        $this->folderList()->set('selected', $ids)->call('assign', $this->agent->id)->assertRedirect();
        $this->assertEquals([$this->agent->id, $this->agent->id], Conversation::whereIn('id', $ids)->pluck('user_id')->all());
    }

    public function testBulkActionsSkipInaccessibleConversations()
    {
        $other_mailbox = $this->createMailbox([], ['name' => 'Private']);
        $mine = $this->receiveConversation();
        $not_mine = $this->receiveConversation([], $other_mailbox);

        $this->folderList()->set('selected', [$mine->id, $not_mine->id])->call('changeStatus', Conversation::STATUS_CLOSED);

        $this->assertEquals(Conversation::STATUS_CLOSED, $mine->fresh()->status);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $not_mine->fresh()->status);
    }

    // Drafts.

    public function testReplyDraftCanBeLoadedSentOrDiscarded()
    {
        $conversation = $this->receiveConversation();

        $saved = $this->saveDraft($this->agent, [
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_ACTIVE,
            'body'            => '<p>Draft answer</p>',
        ]);
        $this->assertSuccess($saved);
        $draft = Thread::find($saved['thread_id']);
        $this->assertEquals(Thread::STATE_DRAFT, $draft->state);
        $drafts_folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_DRAFTS)->first();
        $this->assertTrue(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $drafts_folder->id])->exists());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'Drafts are not sent.');

        Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $conversation])
            ->call('editDraft', $draft->id)->assertSet('thread_id', $draft->id)->assertSet('body', '<p>Draft answer</p>');

        // Sending publishes the draft instead of adding a second reply.
        $this->assertSuccess($this->sendReply($this->agent, [
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'thread_id'       => $draft->id,
            'body'            => '<p>Final answer</p>',
        ]));
        $this->assertEquals(Thread::STATE_PUBLISHED, $draft->fresh()->state);
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->count());
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertFalse(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $drafts_folder->id])->exists());
    }

    public function testDiscardReplyDraft()
    {
        $conversation = $this->receiveConversation();
        $saved = $this->saveDraft($this->agent, [
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_ACTIVE,
            'body'            => '<p>Draft answer</p>',
        ]);

        $this->assertSuccess($this->discardDraft($this->agent, ['thread_id' => $saved['thread_id']]));

        $this->assertNull(Thread::find($saved['thread_id']));
        $this->assertNotNull($conversation->fresh(), 'Discarding a reply draft keeps the conversation.');
    }

    public function testNewConversationDraftIsDiscardedCompletely()
    {
        $saved = $this->saveDraft($this->agent, [
            'mailbox_id' => $this->mailbox->id,
            'is_create'  => 1,
            'status'     => Conversation::STATUS_ACTIVE,
            'to'         => ['new.customer@customer.example.org'],
            'subject'    => 'Draft subject',
            'body'       => '<p>Not finished</p>',
        ]);
        $this->assertSuccess($saved);
        $conversation = Conversation::find($saved['conversation_id']);
        $this->assertEquals(Conversation::STATE_DRAFT, $conversation->state);
        $this->assertEquals(Folder::TYPE_DRAFTS, $this->folderType($conversation));

        $this->assertSuccess($this->discardDraft($this->agent, ['thread_id' => $saved['thread_id']]));

        $this->assertNull($conversation->fresh());
    }

    /**
     * A new-conversation draft has no customer yet (the recipients are kept
     * on the draft); sending it sets the customer and emails them.
     */
    public function testSendingNewConversationDraftSetsCustomer()
    {
        $saved = $this->saveDraft($this->agent, [
            'mailbox_id' => $this->mailbox->id,
            'is_create'  => 1,
            'status'     => Conversation::STATUS_ACTIVE,
            'to'         => ['new.customer@customer.example.org'],
            'subject'    => 'Draft subject',
            'body'       => '<p>Almost done</p>',
        ]);

        $this->assertSuccess($this->sendReply($this->agent, [
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $saved['conversation_id'],
            'thread_id'       => $saved['thread_id'],
            'is_create'       => 1,
            'to'              => ['new.customer@customer.example.org'],
            'subject'         => 'Draft subject',
            'body'            => '<p>Done now</p>',
        ]));

        $conversation = Conversation::find($saved['conversation_id']);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->state);
        $this->assertSame('new.customer@customer.example.org', $conversation->customer_email);
        $this->assertEquals(\App\Customer::getByEmail('new.customer@customer.example.org')->id, $conversation->customer_id);
        $this->assertCount(1, $this->sentEmailsTo('new.customer@customer.example.org'));
    }

    // Threads.

    /**
     * Only one's own notes can be edited, also by admins: not what customers wrote, not what
     * was sent, not others' notes. An edited note keeps its original.
     */
    public function testOnlyOwnNotesCanBeEdited()
    {
        $admin = $this->createAdmin();
        $this->mailbox->users()->attach($admin->id);
        $conversation = $this->receiveConversation(['body' => 'My phone number is 555-1234.']);
        $this->sendReply($admin, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Sent answer</p>']);
        $this->sendReply($admin, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Admin note</p>', 'is_note' => 1]);
        $this->agent->permissions = [User::PERM_EDIT_CONVERSATIONS => true];
        $this->agent->save();
        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Agent note</p>', 'is_note' => 1]);
        $threads = $conversation->threads()->get();
        $customer = $threads->firstWhere('type', Thread::TYPE_CUSTOMER);
        $reply = $threads->firstWhere('type', Thread::TYPE_MESSAGE);
        $admin_note = $threads->first(fn ($thread) => $thread->type == Thread::TYPE_NOTE && $thread->created_by_user_id == $admin->id);
        $agent_note = $threads->first(fn ($thread) => $thread->type == Thread::TYPE_NOTE && $thread->created_by_user_id == $this->agent->id);

        foreach ([$customer, $reply, $agent_note] as $thread) {
            $this->assertSame('Not enough permissions', ConversationActions::editThread($thread->fresh(), '<p>Changed</p>', $admin)['msg']);
        }
        $this->assertSame('Not enough permissions', ConversationActions::editThread($reply->fresh(), '<p>Changed</p>', $this->agent)['msg']);

        $this->assertSuccess(ConversationActions::editThread($admin_note->fresh(), '<p>Admin note, edited</p>', $admin));
        $admin_note->refresh();
        $this->assertStringContainsString('edited', $admin_note->body);
        $this->assertStringContainsString('Admin note', $admin_note->body_original);
        $this->assertEquals($admin->id, $admin_note->edited_by_user_id);
    }

    public function testEditingOwnNoteNeedsPermission()
    {
        $conversation = $this->receiveConversation();
        $this->sendReply($this->agent, [
            'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
            'body'   => '<p>My note</p>', 'is_note' => 1,
        ]);
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();

        $refused = ConversationActions::editThread($note->fresh(), '<p>Edited</p>', $this->agent);
        $this->assertSame('Not enough permissions', $refused['msg']);

        $this->agent->permissions = [User::PERM_EDIT_CONVERSATIONS => true];
        $this->agent->save();
        $this->assertSuccess(ConversationActions::editThread($note->fresh(), '<p>Edited</p>', $this->agent));
        $this->assertStringContainsString('Edited', $note->fresh()->body);
    }

    public function testOnlyOwnNotesCanBeDeleted()
    {
        $conversation = $this->receiveConversation();
        $this->sendReply($this->agent, [
            'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
            'body'   => '<p>My note</p>', 'is_note' => 1,
        ]);
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $customer_thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $this->assertSame('Thread not found', ConversationActions::deleteNote($customer_thread->fresh(), $this->agent)['msg']);
        $this->assertSame('Not enough permissions', ConversationActions::deleteNote($note->fresh(), $this->createAdmin())['msg']);

        $this->assertSuccess(ConversationActions::deleteNote($note->fresh(), $this->agent));
        $this->assertNull($note->fresh());
    }

    // Customer.

    public function testChangeCustomer()
    {
        $conversation = $this->receiveConversation();
        $other = $this->createCustomer('real.customer@customer.example.org');

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_customer',
            'conversation_id' => $conversation->id,
            'customer_email'  => 'real.customer@customer.example.org',
        ]));

        $conversation->refresh();
        $this->assertEquals($other->id, $conversation->customer_id);
        $this->assertSame('real.customer@customer.example.org', $conversation->customer_email);
        $this->assertEquals(Thread::ACTION_TYPE_CUSTOMER_CHANGED, $this->lastLineItem($conversation)->action_type);
    }

    /**
     * A missing conversation gets an error message, not a 500 (C3, C4).
     */
    public function testMissingConversationIsReported()
    {
        $this->assertSame('Conversation not found', ConversationActions::changeStatus(Conversation::find(999999), 'not_spam', $this->agent, request())['msg']);
        $this->assertSame('Conversation not found', $this->ajax($this->agent, [
            'action' => 'conversation_change_customer', 'conversation_id' => 999999, 'customer_email' => 'nobody-known@customer.example.org',
        ])['msg']);
    }

    /**
     * Unknown addresses are refused with a message (C4); the change customer
     * dialog creates the customer first (see testChangeCustomer).
     */
    public function testChangeCustomerToUnknownEmail()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax($this->agent, [
            'action'          => 'conversation_change_customer',
            'conversation_id' => $conversation->id,
            'customer_email'  => 'nobody-known@customer.example.org',
        ]);

        $this->assertSame('Customer not found', $response['msg']);
        $this->assertSame('casey@customer.example.org', $conversation->fresh()->customer_email);
    }

    // Lists and panels loaded by the frontend.

    public function testFolderPagination()
    {
        $this->receiveConversation(['subject' => 'Listed conversation']);
        $folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        Livewire::actingAs($this->agent)->test(ConversationList::class, ['folder' => $folder])
            ->call('gotoPage', 1)->assertSee('Listed conversation');
    }

    public function testFolderPaginationRefusesInaccessibleFolder()
    {
        $folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        Livewire::actingAs($this->createUser())->test(ConversationList::class, ['folder' => $folder])->assertForbidden();
    }

    public function testCustomerSidebar()
    {
        $conversation = $this->receiveConversation();

        Livewire::actingAs($this->agent)->test(ConversationInspector::class, ['conversation' => $conversation])->assertSee('Casey Customer');
    }

    public function testMergeSearch()
    {
        $current = $this->receiveConversation(['subject' => 'Current']);
        $other = $this->receiveConversation(['subject' => 'Find me', 'from' => 'other@customer.example.org']);

        $found = $this->ajax($this->agent, ['action' => 'merge_search', 'number' => $other->id, 'cur_conv_id' => $current->id]);
        $this->assertSuccess($found);
        $this->assertStringContainsString('Find me', $found['html']);
        $this->assertSame($other->id, $found['conversation']['id']);

        $self = $this->ajax($this->agent, ['action' => 'merge_search', 'number' => $current->id, 'cur_conv_id' => $current->id]);
        $this->assertSame('Conversation not found', $self['msg']);
    }

    public function testDialogs()
    {
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales department']);
        $earlier = $this->receiveConversation(['subject' => 'An earlier question', 'message_id' => 'earlier@customer.example.org']);
        $conversation = $this->receiveConversation();
        $this->sendReply($this->agent, ['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi</p>']);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $base = '/conversation/ajax-html/';

        $this->actingAs($this->agent)->get($base.'move_conv?conversation_id='.$conversation->id)
            ->assertStatus(200)->assertSee('Sales department');
        $this->actingAs($this->agent)->get($base.'send_log?thread_id='.$reply->id)
            ->assertStatus(200)->assertSee('casey@customer.example.org');
        $this->actingAs($this->agent)->get($base.'change_customer?conversation_id='.$conversation->id)->assertStatus(200);
        // Merge: the customer's earlier conversation listed once (its id, for the search to
        // take it out while found), Merge in the dialog's footer.
        $this->actingAs($this->agent)->get($base.'merge_conv?conversation_id='.$conversation->id)->assertStatus(200)
            ->assertSee('An earlier question')
            ->assertSee("JSON.parse('[\\u0022".$earlier->id."\\u0022]')", false)
            ->assertSeeInOrder(['f-dialog__footer', 'btn-merge-conv'], false);

        $outsider = $this->createUser();
        $this->actingAs($outsider)->get($base.'move_conv?conversation_id='.$conversation->id)->assertStatus(403);
        $this->actingAs($outsider)->get($base.'send_log?thread_id='.$reply->id)->assertStatus(403);
        $this->actingAs($this->agent)->get($base.'no_such_dialog')->assertStatus(404);
    }

    public function testUnknownAction()
    {
        $this->assertSame('Unknown action', $this->ajax($this->agent, ['action' => 'no_such_action'])['msg']);
    }
}
