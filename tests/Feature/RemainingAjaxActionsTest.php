<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Ajax actions not covered by the feature-specific tests: smaller
 * conversation actions and dialogs, customer lists, user photos and
 * invitations, mail server checks, system update checks and the module
 * actions that don't need freescout.net.
 */
class RemainingAjaxActionsTest extends FeatureTestCase
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

    protected function receiveConversation(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function conversationAjax($user, array $data)
    {
        return $this->postAjax($user, '/conversation/ajax', $data)->json();
    }

    // Conversations.

    public function testBulkDelete()
    {
        $first = $this->receiveConversation(['subject' => 'First']);
        $second = $this->receiveConversation(['subject' => 'Second', 'from' => 'other@customer.example.org']);

        $this->assertSame('Not enough permissions', $this->conversationAjax($this->agent, ['action' => 'bulk_delete_conversation', 'conversation_id' => [$first->id]])['msg']);

        $this->assertSame('success', $this->conversationAjax($this->admin, ['action' => 'bulk_delete_conversation', 'conversation_id' => [$first->id, $second->id]])['status']);
        $this->assertEquals([Conversation::STATE_DELETED, Conversation::STATE_DELETED], Conversation::whereIn('id', [$first->id, $second->id])->pluck('state')->all());

        // Deleting what's already deleted removes it for good.
        $this->conversationAjax($this->admin, ['action' => 'bulk_delete_conversation', 'conversation_id' => [$first->id]]);
        $this->assertNull(Conversation::find($first->id));
    }

    public function testLoadAttachmentsForForwarding()
    {
        Storage::fake('local_app');
        $boundary = 'b1';
        $conversation = $this->receiveConversation([
            'headers' => ['Content-Type' => 'multipart/mixed; boundary="'.$boundary.'"'],
            'body'    => "--$boundary\nContent-Type: text/plain\n\nSee attached.\n--$boundary\nContent-Type: text/plain; name=\"notes.txt\"\nContent-Disposition: attachment; filename=\"notes.txt\"\nContent-Transfer-Encoding: base64\n\n".base64_encode('Some notes')."\n--$boundary--",
        ]);

        $response = $this->conversationAjax($this->agent, ['action' => 'load_attachments', 'conversation_id' => $conversation->id]);

        $this->assertSame('success', $response['status']);
        $this->assertSame(['notes.txt'], array_column($response['data']['attachments'], 'name'));
    }

    public function testEditThreadDialog()
    {
        $conversation = $this->receiveConversation(['body' => 'Original text']);
        $thread = $conversation->threads()->first();

        $response = $this->conversationAjax($this->admin, ['action' => 'load_edit_thread', 'thread_id' => $thread->id]);

        $this->assertSame('success', $response['status']);
        $this->assertStringContainsString('Original text', $response['html']);
        $this->assertSame('Not enough permissions', $this->conversationAjax($this->createUser(), ['action' => 'load_edit_thread', 'thread_id' => $thread->id])['msg']);
    }

    public function testRetrySendWithoutFailedJob()
    {
        $conversation = $this->receiveConversation();
        $this->conversationAjax($this->agent, ['action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi</p>']);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();

        $response = $this->conversationAjax($this->agent, ['action' => 'retry_send', 'thread_id' => $reply->id]);

        $this->assertSame('error', $response['status'], 'Nothing failed, so nothing to retry.');
        $this->assertSame('Not enough permissions', $this->conversationAjax($this->createUser(), ['action' => 'retry_send', 'thread_id' => $reply->id])['msg']);
    }

    public function testChatsLoadMore()
    {
        $response = $this->conversationAjax($this->agent, ['action' => 'chats_load_more', 'mailbox_id' => $this->mailbox->id, 'offset' => 0]);

        $this->assertSame('success', $response['status']);
        $this->assertSame('Action not authorized', $this->conversationAjax($this->createUser(), ['action' => 'chats_load_more', 'mailbox_id' => $this->mailbox->id, 'offset' => 0])['msg']);
    }

    public function testMoreDialogs()
    {
        $conversation = $this->receiveConversation();
        $this->conversationAjax($this->agent, ['action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Visible reply</p>']);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $base = '/conversation/ajax-html/';

        $this->actingAs($this->agent)->get($base.'show_original?thread_id='.$reply->id)->assertStatus(200)->assertSee('Visible reply');

        $outsider = $this->createUser();
        $this->actingAs($outsider)->get($base.'show_original?thread_id='.$reply->id)->assertStatus(403);
    }

    // Customers.

    public function testCustomersPaginationAndSearchVariants()
    {
        $this->createCustomer('robin@customer.example.org', [
            'first_name' => 'Robin',
            'last_name'  => 'Buyer',
            'phones'     => [['value' => '+31 20 555 0101', 'type' => \App\Customer::PHONE_TYPE_WORK]],
        ]);

        $page = $this->postAjax($this->agent, '/customers/ajax', ['action' => 'customers_pagination', 'q' => 'Robin', 'page' => 1])->json();
        $this->assertSame('success', $page['status']);
        $this->assertStringContainsString('Robin', $page['html']);

        $by_name = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=name&show_fields=name&q=Robin')->json();
        $this->assertSame(['Robin Buyer'], array_column($by_name['results'], 'text'));

        $by_email = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=email&show_fields=email&q=robin@')->json();
        $this->assertSame(['robin@customer.example.org'], array_column($by_email['results'], 'text'));

        $by_phone = $this->actingAs($this->agent)->get('/customers/ajax-search?search_by=phone&show_fields=phone&q=555')->json();
        $this->assertStringContainsString('555 0101', $by_phone['results'][0]['text']);
    }

    // Users.

    public function testDeletePhoto()
    {
        Storage::fake('local');
        Storage::put('users/photo.jpg', 'image');
        $this->agent->photo_url = 'photo.jpg';
        $this->agent->save();

        $this->assertSame('Not enough permissions', $this->postAjax($this->createUser(), '/users/ajax', ['action' => 'delete_photo', 'user_id' => $this->agent->id])->json()['msg']);

        $this->assertSame('success', $this->postAjax($this->agent, '/users/ajax', ['action' => 'delete_photo', 'user_id' => $this->agent->id])->json()['status']);
        $this->assertSame('', (string)$this->agent->fresh()->photo_url);
        Storage::assertMissing('users/photo.jpg');
    }

    public function testResendInvite()
    {
        $invited = $this->createUser(['email' => 'invited@example.org']);
        $invited->invite_state = User::INVITE_STATE_SENT;
        $invited->save();

        $this->assertSame('Not enough permissions', $this->postAjax($this->agent, '/users/ajax', ['action' => 'send_invite', 'user_id' => $invited->id])->json()['msg']);

        $this->assertSame('success', $this->postAjax($this->admin, '/users/ajax', ['action' => 'send_invite', 'user_id' => $invited->id])->json()['status']);
        $this->assertCount(1, $this->sentEmailsTo('invited@example.org'));
    }

    public function testInviteNotResentToActiveUser()
    {
        $this->assertSame('User already accepted invitation', $this->postAjax($this->admin, '/users/ajax', ['action' => 'send_invite', 'user_id' => $this->agent->id])->json()['msg']);
    }

    // Mail server checks (refused locally, no real server involved).

    public function testConnectionChecksReportUnreachableServer()
    {
        config(['app.remote_host_white_list' => '127.0.0.1']);
        $this->mailbox->fill(['in_protocol' => 1, 'in_server' => '127.0.0.1', 'in_port' => 1, 'in_username' => 'u', 'in_password' => 'p'])->save();

        $fetch = $this->postAjax($this->admin, '/mailbox/ajax', ['action' => 'fetch_test', 'mailbox_id' => $this->mailbox->id])->json();
        $this->assertSame('error', $fetch['status']);
        $this->assertNotEmpty($fetch['msg']);

        $folders = $this->postAjax($this->admin, '/mailbox/ajax', ['action' => 'imap_folders', 'mailbox_id' => $this->mailbox->id])->json();
        $this->assertSame('error', $folders['status']);

        $this->assertSame('Not enough permissions', $this->postAjax($this->agent, '/mailbox/ajax', ['action' => 'fetch_test', 'mailbox_id' => $this->mailbox->id])->json()['msg']);
    }

    // System and modules.

    public function testCheckForUpdatesWithoutNetwork()
    {
        $response = $this->postAjax($this->admin, '/system/ajax', ['action' => 'check_updates'])->json();

        $this->assertSame('error', $response['status']);
        $this->assertStringStartsWith('Error occurred', $response['msg']);
    }

    public function testUpdateRunsTheUpdater()
    {
        \Updater::shouldReceive('update')->once()->andReturn(true);

        $response = $this->postAjax($this->admin, '/system/ajax', ['action' => 'update'])->json();

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertSame(403, $this->postAjax($this->agent, '/system/ajax', ['action' => 'update'])->status());
    }

    /**
     * APP_DISABLE_UPDATING must stop updates, not only hide the button (S3).
     */
    public function testUpdateRefusedWhenUpdatingIsDisabled()
    {
        config(['app.disable_updating' => true]);
        \Updater::shouldReceive('update')->never();

        $response = $this->postAjax($this->admin, '/system/ajax', ['action' => 'update'])->json();

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('disabled', $response['msg']);

        // Checking for updates answers plainly instead of an error (S4).
        $check = $this->postAjax($this->admin, '/system/ajax', ['action' => 'check_updates'])->json();
        $this->assertSame('success', $check['status'], json_encode($check));
        $this->assertStringContainsString('disabled', $check['msg_success']);
    }

    /**
     * Deleting a module deactivates it before removing its files (S5).
     */
    public function testDeleteModuleDeactivatesIt()
    {
        $dir = base_path('Modules/TallportTestFixture');
        $this->assertDirectoryDoesNotExist($dir, 'Leftover fixture module from an earlier run.');
        @mkdir(base_path('Modules'));
        mkdir($dir);
        file_put_contents($dir.'/module.json', json_encode([
            'name' => 'TallportTestFixture', 'alias' => 'tallporttestfixture', 'description' => 'Test fixture',
            'version' => '1.0.0', 'active' => 0, 'order' => 0, 'providers' => [], 'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ]));

        try {
            \App\Module::setActive('tallporttestfixture', true);
            \Module::clearCache();
            \App\Module::$modules = null;

            $response = $this->postAjax($this->admin, '/modules/ajax', ['action' => 'delete', 'alias' => 'tallporttestfixture'])->json();
        } finally {
            if (is_dir($dir)) {
                exec('rm -rf '.escapeshellarg($dir));
            }
        }

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFalse(\App\Module::isActive('tallporttestfixture'), 'Deleted modules must not stay active.');
    }

    public function testModuleActionsWithoutModule()
    {
        $ajax = function ($data) {
            return $this->postAjax($this->admin, '/modules/ajax', $data)->json();
        };

        $delete_missing = $ajax(['action' => 'delete', 'alias' => 'nosuchmodule']);
        $this->assertSame('Module not found: nosuchmodule', $delete_missing['msg']);
        $this->assertSame('error', $delete_missing['status'], 'Deleting a missing module is not a success (S5).');

        $this->assertSame('success', $ajax(['action' => 'deactivate', 'alias' => 'nosuchmodule'])['status']);
        $this->assertCommandCalled('tallport:clear-cache');
    }
}
