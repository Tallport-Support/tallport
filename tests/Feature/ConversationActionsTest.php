<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * What agents do to conversations from the UI, through the ajax endpoint:
 * assign, change status, delete and restore, star, follow, rename, move,
 * merge, bulk actions, drafts, and editing or deleting threads.
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

        $response = $this->ajax($this->agent, [
            'action'          => 'conversation_change_user',
            'conversation_id' => $conversation->id,
            'user_id'         => $this->agent->id,
        ]);
        $this->assertSuccess($response);
        $this->assertSame('Assignee updated', $response['msg']);
        $this->assertEquals($this->agent->id, $conversation->fresh()->user_id);
        $this->assertEquals(Folder::TYPE_ASSIGNED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_USER_CHANGED, $this->lastLineItem($conversation)->action_type);

        $again = $this->ajax($this->agent, [
            'action'          => 'conversation_change_user',
            'conversation_id' => $conversation->id,
            'user_id'         => $this->agent->id,
        ]);
        $this->assertSame('Assignee already set', $again['msg']);

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_user',
            'conversation_id' => $conversation->id,
            'user_id'         => -1,
        ]));
        $this->assertNull($conversation->fresh()->user_id);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation));
    }

    public function testCannotAssignToUserWithoutMailboxAccess()
    {
        $conversation = $this->receiveConversation();
        $outsider = $this->createUser();

        $response = $this->ajax($this->agent, [
            'action'          => 'conversation_change_user',
            'conversation_id' => $conversation->id,
            'user_id'         => $outsider->id,
        ]);

        $this->assertSame('Not enough permissions', $response['msg']);
        $this->assertNull($conversation->fresh()->user_id);
    }

    public function testAssigneeIsNotifiedByEmail()
    {
        $colleague = $this->createUser(['first_name' => 'Colleague']);
        $this->mailbox->users()->attach($colleague->id);
        $this->mailbox->syncPersonalFolders([$this->agent->id, $colleague->id]);
        $conversation = $this->receiveConversation(['subject' => 'Needs a specialist']);

        $this->ajax($this->agent, [
            'action'          => 'conversation_change_user',
            'conversation_id' => $conversation->id,
            'user_id'         => $colleague->id,
        ]);

        $notifications = $this->sentEmailsTo($colleague->email);
        $this->assertCount(1, $notifications);
        $this->assertSame('user.notification', $notifications[0]->getHeaders()->get('X-FreeScout-Mail-Type')->getFieldBody());
        $this->assertStringContainsString('Needs a specialist', $notifications[0]->getSubject());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    // Status.

    public function testCloseAndReopen()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_CLOSED,
        ]));
        $closed = $conversation->fresh();
        $this->assertEquals(Conversation::STATUS_CLOSED, $closed->status);
        $this->assertEquals($this->agent->id, $closed->closed_by_user_id);
        $this->assertNotNull($closed->closed_at);
        $this->assertEquals(Folder::TYPE_CLOSED, $this->folderType($conversation));
        $line_item = $this->lastLineItem($conversation);
        $this->assertEquals(Thread::ACTION_TYPE_STATUS_CHANGED, $line_item->action_type);
        $this->assertEquals(Conversation::STATUS_CLOSED, $line_item->status);

        $again = $this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_CLOSED,
        ]);
        $this->assertSame('Status already set', $again['msg']);

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_ACTIVE,
        ]));
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation));
    }

    public function testInvalidStatusIsRejected()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => 99,
        ]);

        $this->assertSame('Incorrect status', $response['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    public function testSpamAndNotSpam()
    {
        $conversation = $this->receiveConversation();
        $this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_PENDING,
        ]);

        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_SPAM,
        ]));
        $this->assertEquals(Folder::TYPE_SPAM, $this->folderType($conversation));

        // "Not spam" restores the status it had before.
        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => 'not_spam',
        ]));
        $this->assertEquals(Conversation::STATUS_PENDING, $conversation->fresh()->status);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation));
    }

    public function testUserWithoutAccessCannotChangeStatus()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax($this->createUser(), [
            'action'          => 'conversation_change_status',
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_CLOSED,
        ]);

        $this->assertSame('Not enough permissions', $response['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
    }

    // Deleting.

    public function testDeletingNeedsPermission()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax($this->agent, ['action' => 'delete_conversation', 'conversation_id' => $conversation->id]);

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
        $this->assertSuccess($this->ajax($deleter, ['action' => 'delete_conversation', 'conversation_id' => $conversation->id]));
        $this->assertEquals(Conversation::STATE_DELETED, $conversation->fresh()->state);
        $this->assertEquals(Folder::TYPE_DELETED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_DELETED_TICKET, $this->lastLineItem($conversation)->action_type);

        $this->assertSuccess($this->ajax($deleter, ['action' => 'restore_conversation', 'conversation_id' => $conversation->id]));
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->fresh()->state);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation));
        $this->assertEquals(Thread::ACTION_TYPE_RESTORE_TICKET, $this->lastLineItem($conversation)->action_type);

        $this->assertSuccess($this->ajax($deleter, ['action' => 'delete_conversation_forever', 'conversation_id' => $conversation->id]));
        $this->assertNull(Conversation::find($conversation->id));
        $this->assertSame(0, Thread::whereIn('id', $thread_ids)->count());
    }

    public function testEmptyDeletedFolder()
    {
        $admin = $this->createAdmin();
        $deleted = $this->receiveConversation(['subject' => 'To be deleted']);
        $kept = $this->receiveConversation(['subject' => 'To be kept', 'from' => 'other@customer.example.org']);
        $this->ajax($admin, ['action' => 'delete_conversation', 'conversation_id' => $deleted->id]);
        $deleted_folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_DELETED)->first();
        $unassigned_folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        $refused = $this->ajax($admin, ['action' => 'empty_folder', 'folder_id' => $unassigned_folder->id]);
        $this->assertSame('Folder not found', $refused['msg'], 'Only Deleted and Spam can be emptied.');

        $this->assertSuccess($this->ajax($admin, ['action' => 'empty_folder', 'folder_id' => $deleted_folder->id]));
        $this->assertNull(Conversation::find($deleted->id));
        $this->assertNotNull(Conversation::find($kept->id));
    }

    // Personal: star and follow.

    public function testStarAndUnstar()
    {
        $conversation = $this->receiveConversation();
        $starred = Folder::where('mailbox_id', $this->mailbox->id)
            ->where('type', Folder::TYPE_STARRED)->where('user_id', $this->agent->id)->first();

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'star_conversation', 'conversation_id' => $conversation->id, 'sub_action' => 'star']));
        $this->assertTrue(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $starred->id])->exists());
        $this->assertEquals(Folder::TYPE_UNASSIGNED, $this->folderType($conversation), 'Starring does not move the conversation.');

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'star_conversation', 'conversation_id' => $conversation->id, 'sub_action' => 'unstar']));
        $this->assertFalse(\DB::table('conversation_folder')->where(['conversation_id' => $conversation->id, 'folder_id' => $starred->id])->exists());
    }

    public function testFollowAndUnfollow()
    {
        $conversation = $this->receiveConversation();

        $follow = $this->ajax($this->agent, ['action' => 'follow', 'conversation_id' => $conversation->id]);
        $this->assertSame('Following', $follow['msg_success']);
        $this->assertTrue(\DB::table('followers')->where(['conversation_id' => $conversation->id, 'user_id' => $this->agent->id])->exists());

        $unfollow = $this->ajax($this->agent, ['action' => 'unfollow', 'conversation_id' => $conversation->id]);
        $this->assertSame('Unfollowed', $unfollow['msg_success']);
        $this->assertFalse(\DB::table('followers')->where(['conversation_id' => $conversation->id, 'user_id' => $this->agent->id])->exists());
    }

    // Subject.

    public function testUpdateSubject()
    {
        $conversation = $this->receiveConversation(['subject' => 'Old subject']);

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'update_subject', 'conversation_id' => $conversation->id, 'value' => '  New subject ']));
        $this->assertSame('New subject', $conversation->fresh()->subject);

        $empty = $this->ajax($this->agent, ['action' => 'update_subject', 'conversation_id' => $conversation->id, 'value' => '   ']);
        $this->assertSame('error', $empty['status']);
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
        $this->ajax($this->agent, ['action' => 'conversation_change_user', 'conversation_id' => $conversation->id, 'user_id' => $colleague->id]);

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

    // Bulk actions from the folder list.

    public function testBulkStatusAndAssign()
    {
        $first = $this->receiveConversation(['subject' => 'First']);
        $second = $this->receiveConversation(['subject' => 'Second', 'from' => 'other@customer.example.org']);
        $ids = [$first->id, $second->id];

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'bulk_conversation_change_status', 'conversation_id' => $ids, 'status' => Conversation::STATUS_CLOSED]));
        $this->assertEquals([Conversation::STATUS_CLOSED, Conversation::STATUS_CLOSED], Conversation::whereIn('id', $ids)->pluck('status')->all());

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'bulk_conversation_change_user', 'conversation_id' => $ids, 'user_id' => $this->agent->id]));
        $this->assertEquals([$this->agent->id, $this->agent->id], Conversation::whereIn('id', $ids)->pluck('user_id')->all());
    }

    public function testBulkActionsSkipInaccessibleConversations()
    {
        $other_mailbox = $this->createMailbox([], ['name' => 'Private']);
        $mine = $this->receiveConversation();
        $not_mine = $this->receiveConversation([], $other_mailbox);

        $this->ajax($this->agent, ['action' => 'bulk_conversation_change_status', 'conversation_id' => [$mine->id, $not_mine->id], 'status' => Conversation::STATUS_CLOSED]);

        $this->assertEquals(Conversation::STATUS_CLOSED, $mine->fresh()->status);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $not_mine->fresh()->status);
    }

    // Drafts.

    public function testReplyDraftCanBeLoadedSentOrDiscarded()
    {
        $conversation = $this->receiveConversation();

        $saved = $this->ajax($this->agent, [
            'action'          => 'save_draft',
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

        $loaded = $this->ajax($this->agent, ['action' => 'load_draft', 'thread_id' => $draft->id]);
        $this->assertSuccess($loaded);
        $this->assertStringContainsString('Draft answer', $loaded['data']['body']);

        // Sending publishes the draft instead of adding a second reply.
        $this->assertSuccess($this->ajax($this->agent, [
            'action'          => 'send_reply',
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
        $saved = $this->ajax($this->agent, [
            'action'          => 'save_draft',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'status'          => Conversation::STATUS_ACTIVE,
            'body'            => '<p>Draft answer</p>',
        ]);

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'discard_draft', 'thread_id' => $saved['thread_id']]));

        $this->assertNull(Thread::find($saved['thread_id']));
        $this->assertNotNull(Conversation::find($conversation->id), 'Discarding a reply draft keeps the conversation.');
    }

    public function testNewConversationDraftIsDiscardedCompletely()
    {
        $saved = $this->ajax($this->agent, [
            'action'     => 'save_draft',
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

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'discard_draft', 'thread_id' => $saved['thread_id']]));

        $this->assertNull(Conversation::find($conversation->id));
    }

    // Threads.

    public function testEditCustomerMessageKeepsOriginal()
    {
        $admin = $this->createAdmin();
        $conversation = $this->receiveConversation(['body' => 'My phone number is 555-1234.']);
        $thread = $conversation->threads()->first();

        $response = $this->ajax($admin, ['action' => 'save_edit_thread', 'thread_id' => $thread->id, 'body' => '<p>My phone number is [removed].</p>']);

        $this->assertSuccess($response);
        $thread->refresh();
        $this->assertStringContainsString('[removed]', $thread->body);
        $this->assertStringContainsString('555-1234', $thread->body_original);
        $this->assertEquals($admin->id, $thread->edited_by_user_id);
    }

    public function testEditingOwnNoteNeedsPermission()
    {
        $conversation = $this->receiveConversation();
        $this->ajax($this->agent, [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
            'body'   => '<p>My note</p>', 'is_note' => 1,
        ]);
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();

        $refused = $this->ajax($this->agent, ['action' => 'save_edit_thread', 'thread_id' => $note->id, 'body' => '<p>Edited</p>']);
        $this->assertSame('Not enough permissions', $refused['msg']);

        $this->agent->permissions = [User::PERM_EDIT_CONVERSATIONS => true];
        $this->agent->save();
        $this->assertSuccess($this->ajax($this->agent, ['action' => 'save_edit_thread', 'thread_id' => $note->id, 'body' => '<p>Edited</p>']));
        $this->assertStringContainsString('Edited', $note->fresh()->body);
    }

    public function testOnlyOwnNotesCanBeDeleted()
    {
        $conversation = $this->receiveConversation();
        $this->ajax($this->agent, [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id,
            'body'   => '<p>My note</p>', 'is_note' => 1,
        ]);
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $customer_thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();

        $this->assertSame('Thread not found', $this->ajax($this->agent, ['action' => 'delete_thread', 'thread_id' => $customer_thread->id])['msg']);
        $this->assertSame('Not enough permissions', $this->ajax($this->createAdmin(), ['action' => 'delete_thread', 'thread_id' => $note->id])['msg']);

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'delete_thread', 'thread_id' => $note->id]));
        $this->assertNull(Thread::find($note->id));
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

    // Lists and panels loaded by the frontend.

    public function testFolderPagination()
    {
        $this->receiveConversation(['subject' => 'Listed conversation']);
        $folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        $response = $this->ajax($this->agent, ['action' => 'conversations_pagination', 'folder_id' => $folder->id, 'page' => 1]);

        $this->assertSame('', $response['msg']);
        $this->assertStringContainsString('Listed conversation', $response['html']);
    }

    public function testFolderPaginationRefusesInaccessibleFolder()
    {
        $folder = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        $response = $this->ajax($this->createUser(), ['action' => 'conversations_pagination', 'folder_id' => $folder->id, 'page' => 1]);

        $this->assertSame('Not enough permissions', $response['msg']);
    }

    public function testCustomerSidebar()
    {
        $conversation = $this->receiveConversation();

        $response = $this->ajax($this->agent, [
            'action'          => 'load_customer_info',
            'customer_email'  => 'casey@customer.example.org',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
        ]);

        $this->assertSuccess($response);
        $this->assertStringContainsString('Casey Customer', $response['html']);
    }

    public function testMergeSearch()
    {
        $current = $this->receiveConversation(['subject' => 'Current']);
        $other = $this->receiveConversation(['subject' => 'Find me', 'from' => 'other@customer.example.org']);

        $found = $this->ajax($this->agent, ['action' => 'merge_search', 'number' => $other->id, 'cur_conv_id' => $current->id]);
        $this->assertSuccess($found);
        $this->assertStringContainsString('Find me', $found['html']);

        $self = $this->ajax($this->agent, ['action' => 'merge_search', 'number' => $current->id, 'cur_conv_id' => $current->id]);
        $this->assertSame('Conversation not found', $self['msg']);
    }

    public function testDialogs()
    {
        $other_mailbox = $this->createMailbox([$this->agent], ['name' => 'Sales department']);
        $conversation = $this->receiveConversation();
        $this->ajax($this->agent, ['action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi</p>']);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $base = '/conversation/ajax-html/';

        $this->actingAs($this->agent)->get($base.'move_conv?conversation_id='.$conversation->id)
            ->assertStatus(200)->assertSee('Sales department');
        $this->actingAs($this->agent)->get($base.'send_log?thread_id='.$reply->id)
            ->assertStatus(200)->assertSee('casey@customer.example.org');
        $this->actingAs($this->agent)->get($base.'change_customer?conversation_id='.$conversation->id)->assertStatus(200);
        $this->actingAs($this->agent)->get($base.'merge_conv?conversation_id='.$conversation->id)->assertStatus(200);

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
