<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Smaller features around conversations: undoing a sent reply, cloning a
 * conversation from a thread, in-app (web) notifications,
 * and the general file upload used by the editors.
 */
class ConversationExtrasTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveConversation(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    protected function reply(Conversation $conversation, $user = null)
    {
        $this->postAjax($user ?: $this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Oops, wrong answer</p>',
        ]);

        return $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
    }

    // Undo.

    public function testUndoTurnsSentReplyBackIntoDraft()
    {
        $conversation = $this->receiveConversation();
        $reply = $this->reply($conversation);
        \Session::start();

        $this->assertSame($reply->id, session('flash_undo_floating')['thread_id']);
        $toast_response = $this->actingAs($this->agent)->get($conversation->url())->assertOk()
            ->assertSee('action="'.route('conversations.undo.submit', ['thread_id' => $reply->id]).'"', false)
            ->assertDontSee('undo-reply/'.$reply->id.'/'.csrf_token(), false);
        $this->assertSame(1, preg_match('/&quot;duration&quot;:(\d+)/', $toast_response->getContent(), $duration));
        $this->assertGreaterThan(0, (int) $duration[1]);
        $this->assertLessThanOrEqual(Conversation::UNDO_TIMOUT * 1000, (int) $duration[1]);

        $this->actingAs($this->agent)->get(route('conversations.undo', ['thread_id' => $reply->id, 'token' => csrf_token()]))->assertStatus(405);
        $this->actingAs($this->agent)->get(route('conversations.undo', ['thread_id' => $reply->id, 'token' => csrf_token()]).'?_token='.csrf_token())->assertStatus(405);
        $this->assertSame(Thread::STATE_PUBLISHED, (int) $reply->fresh()->state);

        $response = $this->actingAs($this->agent)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => csrf_token()]);

        $response->assertRedirect();
        $this->assertEquals(Thread::STATE_DRAFT, $reply->fresh()->state);
        $this->assertNull(session('flash_error_floating'));
    }

    public function testUndoNeedsValidTokenAndRecentReply()
    {
        $conversation = $this->receiveConversation();
        $reply = $this->reply($conversation);

        \Session::start();
        $this->actingAs($this->agent)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => 'wrong-token'])->assertStatus(419);
        $this->assertEquals(Thread::STATE_PUBLISHED, $reply->fresh()->state);

        \DB::table('threads')->where('id', $reply->id)->update(['created_at' => now()->subMinutes(10)]);
        $this->actingAs($this->agent)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => csrf_token()]);
        $this->assertEquals(Thread::STATE_PUBLISHED, $reply->fresh()->state, 'Too late to undo.');
    }

    public function testOthersCannotUndoYourReply()
    {
        $conversation = $this->receiveConversation();
        $reply = $this->reply($conversation);
        $colleague = $this->createUser();
        $this->mailbox->users()->attach($colleague->id);

        $response = $this->actingAs($colleague)->post(route('conversations.undo.submit', ['thread_id' => $reply->id]), ['_token' => csrf_token()]);

        $response->assertRedirect();
        $this->assertSame('Sending can not be undone', session('flash_error_floating'));
        $this->assertEquals(Thread::STATE_PUBLISHED, $reply->fresh()->state);
    }

    // Clone.

    public function testCloneConversationFromThread()
    {
        $conversation = $this->receiveConversation(['body' => 'Two questions in one email.']);
        $thread = $conversation->threads()->first();
        \Session::start();

        $this->actingAs($this->agent)->get($conversation->url())->assertOk()
            ->assertSee('action="'.route('conversations.clone_conversation.submit', [
                'mailbox_id' => $this->mailbox->id,
                'from_thread_id' => $thread->id,
            ]).'"', false);

        $this->actingAs($this->agent)->get(route('conversations.clone_conversation', ['mailbox_id' => $this->mailbox->id, 'from_thread_id' => $thread->id, 'token' => csrf_token()]))->assertStatus(405);
        $this->actingAs($this->agent)->get(route('conversations.clone_conversation', ['mailbox_id' => $this->mailbox->id, 'from_thread_id' => $thread->id, 'token' => csrf_token()]).'?_token='.csrf_token())->assertStatus(405);
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());

        $response = $this->actingAs($this->agent)->post(route('conversations.clone_conversation.submit', ['mailbox_id' => $this->mailbox->id, 'from_thread_id' => $thread->id]), ['_token' => csrf_token()]);

        $response->assertRedirect();
        $clone = Conversation::where('mailbox_id', $this->mailbox->id)->where('id', '!=', $conversation->id)->first();
        $this->assertNotNull($clone, 'No clone was created.');
        $this->assertSame('Question about my order', $clone->subject);
        $this->assertEquals($conversation->customer_id, $clone->customer_id);
        $this->assertStringContainsString('Two questions in one email.', $clone->threads()->first()->body);
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count(), 'The original keeps its thread.');
    }

    public function testCloneNeedsValidToken()
    {
        $conversation = $this->receiveConversation();

        \Session::start();
        $this->actingAs($this->agent)->post(route('conversations.clone_conversation.submit', ['mailbox_id' => $this->mailbox->id, 'from_thread_id' => $conversation->threads()->first()->id]), ['_token' => 'wrong-token'])->assertStatus(419);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    public function testUserOutsideMailboxCannotCloneConversation()
    {
        $conversation = $this->receiveConversation();
        $outsider = $this->createUser();

        $this->actingAs($outsider)->post(route('conversations.clone_conversation.submit', [
            'mailbox_id' => $this->mailbox->id,
            'from_thread_id' => $conversation->threads()->first()->id,
        ]), ['_token' => csrf_token()])->assertForbidden();

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
    }

    // Web notifications.

    public function testWebNotificationsListAndMarkRead()
    {
        // The customer answering the agent's reply notifies the agent.
        $conversation = $this->receiveConversation(['message_id' => 'first@customer.example.org']);
        $this->reply($conversation);
        $reply_id = $this->sentEmailsTo('casey@customer.example.org')[0]->getId();
        $this->receiveConversation(['in_reply_to' => $reply_id, 'body' => 'Follow-up']);
        $this->assertSame(1, $this->agent->unreadNotifications()->count());

        $list = $this->postAjax($this->agent, '/users/ajax', ['action' => 'web_notifications'])->json();
        $this->assertSame('success', $list['status']);
        $this->assertStringContainsString('Casey Customer replied to conversation #'.$conversation->number, $list['html']);
        $this->assertStringContainsString('Follow-up', $list['html']);

        $this->assertSame('success', $this->postAjax($this->agent, '/users/ajax', ['action' => 'mark_notifications_as_read'])->json()['status']);
        $this->assertSame(0, $this->agent->unreadNotifications()->count());
    }

    // Uploads.

    public function testUploadStoresFileAndRenamesExecutables()
    {
        Storage::fake('local');
        $file = function ($name, $contents) {
            $path = tempnam(sys_get_temp_dir(), 'upload');
            file_put_contents($path, $contents);

            return new UploadedFile($path, $name, null, null, true);
        };
        $upload = function ($name, $contents) use ($file) {
            return $this->actingAs($this->agent)->post('/uploads/upload', [
                'file' => $file($name, $contents),
            ], ['X-Requested-With' => 'XMLHttpRequest'])->json();
        };

        $image = $upload('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $this->assertSame('success', $image['status'], json_encode($image));
        $this->assertMatchesRegularExpression('#/uploads/[A-Za-z0-9]{25}\.png$#', $image['url']);

        $script = $upload('shell.php', '<?php echo "pwned";');
        $this->assertSame('success', $script['status']);
        $this->assertStringEndsWith('.php_', $script['url'], 'Executables are renamed so they cannot run.');
        $this->assertCount(2, Storage::disk('local')->files('uploads'));
    }

    public function testUploadNeedsLogin()
    {
        $this->post('/uploads/upload', [])->assertRedirect('/login');
    }
}
