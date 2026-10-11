<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Folder;
use App\Livewire\ConversationComposer;
use App\Livewire\ConversationList;
use App\Livewire\ConversationThread;
use App\SendLog;
use App\Thread;
use App\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * The conversation ajax actions, dialogs and page components when something
 * is missing or not allowed, and their less common paths: drafts with files,
 * emptying folders, moving by mailbox address, the send log of notifications.
 */
class ConversationAjaxEdgeCasesTest extends FeatureTestCase
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
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function ajax($user, array $data)
    {
        return $this->postAjax($user, '/conversation/ajax', $data)->json();
    }

    protected function assertSuccess(array $response)
    {
        $this->assertSame('success', $response['status'], json_encode($response));
    }

    protected function assertError($message, array $response)
    {
        $this->assertSame('error', $response['status']);
        $this->assertSame($message, $response['msg']);
    }

    protected function replyDraft(Conversation $conversation)
    {
        $response = $this->saveDraft($this->agent, [
            'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Not sent yet</p>',
        ]);
        $this->assertSuccess($response);

        return Thread::find($response['thread_id']);
    }

    protected function attach(Thread $thread, $name = 'notes.txt')
    {
        $attachment = Attachment::create($name, 'text/plain', null, 'Some notes', null, false, $thread->id);
        $thread->has_attachments = true;
        $thread->save();
        $thread->conversation->has_attachments = true;
        $thread->conversation->save();

        return $attachment;
    }

    protected function composer(Conversation $conversation)
    {
        return Livewire::actingAs($this->agent)->test(ConversationComposer::class, ['conversation' => $conversation]);
    }

    protected function folder($type, $mailbox = null)
    {
        return ($mailbox ?: $this->mailbox)->folders()->where('type', $type)->first();
    }

    // Drafts.

    public function testEditDraft()
    {
        $conversation = $this->receiveConversation();
        $draft = $this->replyDraft($conversation);
        $this->attach($draft);

        $composer = $this->composer($conversation)->call('editDraft', $draft->id)
            ->assertSet('thread_id', $draft->id)->assertSet('mode', 'reply')
            ->assertSet('body', '<p>Not sent yet</p>')->assertSet('to', 'casey@customer.example.org');

        $this->assertSame(['notes.txt'], array_column($composer->get('attachments'), 'name'));
        $this->assertSame(Attachment::where('thread_id', $draft->id)->value('id'), decrypt($composer->get('attachments')[0]['id']));
    }

    /**
     * Only the conversation's own drafts are continued.
     */
    public function testEditDraftProblems()
    {
        $conversation = $this->receiveConversation();
        $other_draft = $this->replyDraft($this->receiveConversation(['from' => 'robin@customer.example.org']));

        $this->composer($conversation)->call('editDraft', 999999)->assertToasted('Thread not found', 'danger')->assertSet('thread_id', null);
        $this->composer($conversation)->call('editDraft', $other_draft->id)->assertToasted('Thread not found', 'danger')->assertSet('thread_id', null);
    }

    public function testUploadWithoutAFile()
    {
        $response = $this->postAjax($this->agent, '/conversation/upload', [])->json();

        $this->assertError('Error occurred uploading file', $response);
    }

    public function testUploadAsAnAttachment()
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($path, 'Some notes');

        $response = $this->postAjax($this->agent, '/conversation/upload', [
            'file'   => new UploadedFile($path, 'notes.txt', 'text/plain', null, true),
            'attach' => 1,
        ])->json();

        $this->assertSuccess($response);
        $attachment = Attachment::find(decrypt($response['attachment_id']));
        $this->assertFalse((bool) $attachment->embedded);
        $this->assertEquals($this->agent->id, $attachment->user_id);
    }

    // Customer.

    public function testChangeCustomerToOneTheUserCannotSee()
    {
        config(['app.limit_user_customer_visibility' => true]);
        $conversation = $this->receiveConversation();
        // Robin only wrote to another mailbox.
        $other_mailbox = $this->createMailbox();
        $this->receiveConversation(['from' => 'robin@customer.example.org'], $other_mailbox);

        $response = $this->ajax($this->agent, ['action' => 'conversation_change_customer', 'conversation_id' => $conversation->id, 'customer_email' => 'robin@customer.example.org']);

        $this->assertError('Not enough permissions', $response);
        $this->assertSame('casey@customer.example.org', $conversation->fresh()->customer_email);
    }

    public function testChangeCustomerToOneTheUserJustCreated()
    {
        config(['app.limit_user_customer_visibility' => true]);
        $conversation = $this->receiveConversation();
        $robin = $this->createCustomer('robin@customer.example.org');
        session()->put('user_created_customer', $robin->id);

        $response = $this->ajax($this->agent, ['action' => 'conversation_change_customer', 'conversation_id' => $conversation->id, 'customer_email' => 'robin@customer.example.org']);

        $this->assertSuccess($response);
        $this->assertEquals($robin->id, $conversation->fresh()->customer_id);
        $this->assertNull(session('user_created_customer'));
    }

    // Star, edit.

    /**
     * A row's star (the list's menu) leaves conversations the user can't see alone.
     */
    public function testStarProblems()
    {
        $limited = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        $this->mailbox->users()->attach($limited->id);
        $this->mailbox->syncPersonalFolders([$this->agent->id, $limited->id]);
        $not_theirs = $this->receiveConversation();

        Livewire::actingAs($limited)->test(ConversationList::class, ['folder' => $this->folder(Folder::TYPE_UNASSIGNED)])->call('rowStar', $not_theirs->id, true);

        $this->assertFalse($not_theirs->isStarredByUser($limited->id));
    }

    public function testEditingAMissingThread()
    {
        $conversation = $this->receiveConversation();

        Livewire::actingAs($this->agent)->test(ConversationThread::class, ['conversation' => $conversation])
            ->call('edit', 999999)->assertSet('editing', null);
    }

    // Empty folder.

    public function testEmptyFolderNeedsTheDeletePermission()
    {
        $this->assertError('Not enough permissions', $this->ajax($this->agent, [
            'action' => 'empty_folder', 'mailbox_id' => $this->mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_DELETED)->id,
        ]));
    }

    public function testOnlyTheSpamAndDeletedFoldersOfAccessibleMailboxesCanBeEmptied()
    {
        $deleter = $this->createUser(['permissions' => [User::PERM_DELETE_CONVERSATIONS => true]]);
        $this->mailbox->users()->attach($deleter->id);
        $conversation = $this->receiveConversation();
        $other_mailbox = $this->createMailbox();

        $this->assertError('Folder not found', $this->ajax($deleter, ['action' => 'empty_folder', 'mailbox_id' => $this->mailbox->id, 'folder_id' => 999999]));
        $this->assertError('Folder not found', $this->ajax($deleter, ['action' => 'empty_folder', 'mailbox_id' => $this->mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_UNASSIGNED)->id]));
        $this->assertError('Not enough permissions', $this->ajax($deleter, ['action' => 'empty_folder', 'mailbox_id' => $other_mailbox->id, 'folder_id' => $this->folder(Folder::TYPE_SPAM, $other_mailbox)->id]));
        $this->assertNotNull(Conversation::find($conversation->id));
    }

    public function testEmptyFolderDeletesOnlyAssignedConversationsForUsersWhoSeeOnlyThose()
    {
        $limited = $this->createUser(['permissions' => [User::PERM_DELETE_CONVERSATIONS => true, User::PERM_ONLY_ASSIGNED_TICKETS => true]]);
        $this->mailbox->users()->attach($limited->id);
        $theirs = $this->receiveConversation(['subject' => 'Theirs']);
        $others = $this->receiveConversation(['subject' => 'Others', 'from' => 'robin@customer.example.org']);
        foreach ([$theirs, $others] as $conversation) {
            $conversation->user_id = $conversation->id == $theirs->id ? $limited->id : $this->agent->id;
            $conversation->status = Conversation::STATUS_SPAM;
            $conversation->updateFolder();
            $conversation->save();
        }
        $spam = $this->folder(Folder::TYPE_SPAM);
        $this->assertSame(2, Conversation::where('folder_id', $spam->id)->count());

        $this->assertSuccess($this->ajax($limited, ['action' => 'empty_folder', 'mailbox_id' => $this->mailbox->id, 'folder_id' => $spam->id]));

        $this->assertNull(Conversation::find($theirs->id));
        $this->assertNotNull(Conversation::find($others->id));
    }

    // Move.

    public function testMoveByMailboxAddress()
    {
        $conversation = $this->receiveConversation();
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales']);

        $this->assertSuccess($this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => $conversation->id, 'mailbox_email' => $sales->email]));

        $this->assertEquals($sales->id, $conversation->fresh()->mailbox_id);
    }

    public function testMoveProblems()
    {
        $conversation = $this->receiveConversation();

        $this->assertError('Conversation not found', $this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => 999999, 'mailbox_id' => $this->mailbox->id]));
        $this->assertError('Not enough permissions', $this->ajax($this->createUser(), ['action' => 'conversation_move', 'conversation_id' => $conversation->id, 'mailbox_id' => $this->mailbox->id]));
        $this->assertError('Mailbox not found', $this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => $conversation->id, 'mailbox_email' => 'nowhere@example.org']));
        $this->assertEquals($this->mailbox->id, $conversation->fresh()->mailbox_id);
    }

    public function testMoveNeedsAccessToTheConversationsMailbox()
    {
        $conversation = $this->receiveConversation();
        $admin = $this->createAdmin();
        $sales = $this->createMailbox([], ['name' => 'Sales']);
        // A module (e.g. one restricting admins) takes the mailbox away.
        \Eventy::addFilter('mailbox.user_has_access', function ($value, $mailbox) use ($conversation) {
            return $mailbox->id == $conversation->mailbox_id ? false : $value;
        }, 20, 3);

        $response = $this->ajax($admin, ['action' => 'conversation_move', 'conversation_id' => $conversation->id, 'mailbox_id' => $sales->id]);

        \Eventy::removeAllFilters('mailbox.user_has_access');
        $this->assertError('Not enough permissions', $response);
        $this->assertEquals($this->mailbox->id, $conversation->fresh()->mailbox_id);
    }

    public function testMovingToAMailboxOfOthersGoesBackToTheFolder()
    {
        $sales = $this->createMailbox([], ['name' => 'Sales']);
        $first = $this->receiveConversation(['subject' => 'First']);
        $second = $this->receiveConversation(['subject' => 'Second', 'from' => 'robin@customer.example.org']);
        $unassigned = $this->folder(Folder::TYPE_UNASSIGNED);

        $response = $this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => $first->id, 'mailbox_id' => $sales->id]);
        $this->assertSuccess($response);
        $this->assertSame(route('mailboxes.view.folder', ['id' => $this->mailbox->id, 'folder_id' => $unassigned->id]), $response['redirect_url']);
        $this->assertEquals($sales->id, $first->fresh()->mailbox_id);

        // Working in All Mailboxes: back there.
        $this->createMailbox([$this->agent], ['name' => 'Billing']);
        $this->actingAs($this->agent)->get(route('mailboxes.all', ['folder_id' => -Folder::TYPE_UNASSIGNED]));
        $response = $this->ajax($this->agent, ['action' => 'conversation_move', 'conversation_id' => $second->id, 'mailbox_id' => $sales->id]);
        $this->assertSuccess($response);
        $this->assertSame(route('mailboxes.all', ['folder_id' => -Folder::TYPE_UNASSIGNED]), $response['redirect_url']);
    }

    // Merge.

    public function testMergeProblemsWithTheConversation()
    {
        $conversation = $this->receiveConversation();
        $other = $this->receiveConversation(['from' => 'robin@customer.example.org']);

        $this->assertError('Conversation not found', $this->ajax($this->agent, ['action' => 'conversation_merge', 'conversation_id' => 999999, 'merge_conversation_id' => [$other->id]]));
        $this->assertError('Not enough permissions', $this->ajax($this->createUser(), ['action' => 'conversation_merge', 'conversation_id' => $conversation->id, 'merge_conversation_id' => [$other->id]]));
        $this->assertError('Conversation not found', $this->ajax($this->agent, ['action' => 'conversation_merge', 'conversation_id' => $conversation->id, 'merge_conversation_id' => [999999]]));
        $this->assertNotNull(Conversation::find($other->id));
    }

    public function testMergeSearchDoesNotFindConversationsTheUserCannotSee()
    {
        $hidden = $this->receiveConversation([], $this->createMailbox());

        $this->assertError('Conversation not found', $this->ajax($this->agent, ['action' => 'merge_search', 'number' => $hidden->number]));
    }

    // Dialogs.

    public function testDialogsNeedAnExistingThreadOrConversation()
    {
        $conversation = $this->receiveConversation();
        $base = '/conversation/ajax-html/';
        $this->actingAs($this->agent);

        foreach (['send_log', 'show_original'] as $dialog) {
            $this->get($base.$dialog)->assertNotFound();
            $this->get($base.$dialog.'?thread_id=999999')->assertNotFound();
        }
        foreach (['change_customer', 'move_conv', 'merge_conv'] as $dialog) {
            $this->get($base.$dialog)->assertNotFound();
            $this->get($base.$dialog.'?conversation_id=999999')->assertNotFound();
        }
        $this->get('/thread/999999/original')->assertNotFound();

        $outsider = $this->createUser();
        $this->actingAs($outsider)->get($base.'change_customer?conversation_id='.$conversation->id)->assertForbidden();
        $this->actingAs($outsider)->get($base.'merge_conv?conversation_id='.$conversation->id)->assertForbidden();
    }

    public function testSendLogListsUserNotificationsApart()
    {
        $conversation = $this->receiveConversation();
        $thread = $conversation->threads()->first();
        SendLog::log($thread->id, 'notification-1@example.org', $this->agent->email, SendLog::MAIL_TYPE_USER_NOTIFICATION, SendLog::STATUS_ACCEPTED, null, $this->agent->id);

        $response = $this->actingAs($this->agent)->get('/conversation/ajax-html/send_log?thread_id='.$thread->id);

        $response->assertOk()->assertSee($this->agent->email);
        $this->assertSame([$this->agent->email], array_keys($response->viewData('users_log')));
        $this->assertSame([], $response->viewData('customers_log'));
    }

    public function testShowOriginalOfACustomerMessageWithoutTheEmailShowsTheSavedBody()
    {
        $conversation = $this->receiveConversation(['body' => 'Saved body text']);
        $thread = $conversation->threads()->first();
        // Received before sources were stored.
        \App\Incoming\RawSources::deleteByThreadIds([$thread->id]);

        $response = $this->actingAs($this->agent)->get('/conversation/ajax-html/show_original?thread_id='.$thread->id);

        $response->assertOk()->assertSee('Saved body text')->assertSee('The original email was not stored');
        $this->assertFalse($response->viewData('fetched'));
        $this->assertFalse($response->viewData('raw_kept'));
    }
}
