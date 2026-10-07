<?php

namespace Tests\Feature;

use App\Ai\DraftJob;
use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Background work at its edges: jobs whose records are gone or that fail,
 * the listener that queues replies to customers, notifications of threads
 * the user can't see any more, and the first login of an invited user.
 */
class BackgroundJobsEdgeCasesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveConversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
            'body' => 'Where is my parcel?',
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    // Jobs.

    public function testDraftJobThatIsNotPendingIsLeftAlone()
    {
        $conversation = $this->receiveConversation();
        $draft_job = new DraftJob();
        $draft_job->conversation_id = $conversation->id;
        $draft_job->user_id = $this->agent->id;
        $draft_job->status = DraftJob::STATUS_COMPLETED;
        $draft_job->save();

        (new \App\Jobs\AiDraftReply($draft_job->id, 'en'))->handle();
        (new \App\Jobs\AiDraftReply(999999, 'en'))->handle();

        $this->assertSame(DraftJob::STATUS_COMPLETED, $draft_job->fresh()->status);
        $this->assertNull($draft_job->fresh()->started_at);
    }

    public function testDraftJobThatGaveUpIsMarkedFailed()
    {
        $conversation = $this->receiveConversation();
        $draft_jobs = [];
        foreach ([DraftJob::STATUS_RUNNING, DraftJob::STATUS_COMPLETED] as $status) {
            $draft_job = new DraftJob();
            $draft_job->conversation_id = $conversation->id;
            $draft_job->user_id = $this->agent->id;
            $draft_job->status = $status;
            $draft_job->save();
            $draft_jobs[$status] = $draft_job;
        }

        foreach ($draft_jobs as $draft_job) {
            (new \App\Jobs\AiDraftReply($draft_job->id, 'en'))->failed(new \RuntimeException('Worker timed out'));
        }

        $failed = $draft_jobs[DraftJob::STATUS_RUNNING]->fresh();
        $this->assertSame(DraftJob::STATUS_FAILED, $failed->status);
        $this->assertSame('RuntimeException', $failed->error_type);
        $this->assertSame('Could not draft a reply.', $failed->error_message);
        $this->assertSame('Worker timed out', $failed->error_detail);
        $this->assertNotNull($failed->completed_at);
        $this->assertSame(DraftJob::STATUS_COMPLETED, $draft_jobs[DraftJob::STATUS_COMPLETED]->fresh()->status, 'A finished draft stays as it is.');
    }

    public function testFailingNostrTaskIsLogged()
    {
        \Log::spy();

        (new \App\Jobs\NostrTask('fetch_profile', []))->handle();

        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_starts_with($message, '[Nostr] fetch_profile failed: ');
        })->once();
    }

    public function testWebhookThatIsGoneIsNotDelivered()
    {
        Http::fake();

        (new \App\Jobs\SendWebhook(999999, 'convo.created', '{}'))->handle();

        Http::assertNothingSent();
    }

    public function testWebhookConnectionErrorIsReported()
    {
        Http::fake(['*' => Http::failedConnection('Could not resolve host: hooks.example.org')]);

        $this->assertSame([0, 'Could not resolve host: hooks.example.org'], \App\Jobs\SendWebhook::deliver('https://hooks.example.org/in', 'convo.created', '{}'));
    }

    public function testFolderCountersAreNotUpdatedTwiceAtOnce()
    {
        $conversation = $this->receiveConversation();
        $folder = $conversation->folder;
        \DB::table('folders')->where('id', $folder->id)->update(['active_count' => 42]);
        $folder->refresh();
        \Cache::put('folder_update_lock_'.$folder->id, true, now()->addMinutes(5));

        (new \App\Jobs\UpdateFolderCounters($folder))->handle();

        $this->assertSame(42, (int) $folder->fresh()->active_count, 'Another job is updating it.');

        \Cache::forget('folder_update_lock_'.$folder->id);
        (new \App\Jobs\UpdateFolderCounters($folder))->handle();
        $this->assertSame(1, (int) $folder->fresh()->active_count);
        $this->assertFalse(\Cache::has('folder_update_lock_'.$folder->id), 'The lock is released.');
    }

    public function testJobsForRecordsThatAreGoneDoNothing()
    {
        Http::fake();
        \Log::spy();

        (new \App\Jobs\AiIndexDocument(999999))->handle();
        (new \App\Jobs\AiTranslateChat(999999, 'de'))->handle();
        (new \App\Jobs\SendReplyToNostr(999999))->handle();

        Http::assertNothingSent();
        \Log::shouldNotHaveReceived('error');
        $this->assertSame(0, \DB::table('polycast_events')->count());
    }

    public function testAlertWithoutTitleIsCalledAlert()
    {
        $admin = $this->createAdmin();

        (new \App\Jobs\SendAlert('The disk is almost full.'))->handle();

        $email = $this->sentEmailsTo($admin->email)[0];
        $this->assertSame('['.config('app.name').'] Alert - '.\Helper::getDomain(), $email->getSubject());
        $this->assertStringContainsString('The disk is almost full.', $email->getBody());
    }

    // Queueing replies to customers.

    public function testImportedReplyIsNotSent()
    {
        $conversation = $this->receiveConversation();
        $thread = $conversation->threads()->first();
        $thread->imported = true;
        $thread->save();
        \Queue::fake();

        event(new \App\Events\UserReplied($conversation, $thread));

        \Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
    }

    public function testEventWithoutThreadSendsNothing()
    {
        $conversation = $this->receiveConversation();
        \Queue::fake();

        (new \App\Listeners\SendReplyToCustomer())->handle((object) ['conversation' => $conversation]);

        \Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
    }

    public function testModuleChannelSendsTheReplyItself()
    {
        $conversation = $this->receiveConversation();
        $conversation->channel = 99;
        $conversation->save();
        \Queue::fake();

        event(new \App\Events\UserReplied($conversation, $conversation->threads()->first()));

        \Queue::assertNotPushed(\App\Jobs\SendReplyToCustomer::class);
        \Queue::assertPushed(\App\Jobs\TriggerAction::class, function ($job) use ($conversation) {
            return $job->action == 'chat_conversation.send_reply' && $job->params[0]->id == $conversation->id;
        });
    }

    public function testSkippingModulesAreNamedByTheirFiles()
    {
        \Eventy::addFilter('test.filter_files', self::class.'@skipSending', 20, 1);
        \Eventy::addFilter('test.filter_files', 'NoSuchClass@method', 20, 1);
        \Eventy::addFilter('test.filter_files', [$this, 'skipSending'], 20, 1);

        $this->assertSame('tests/Feature/BackgroundJobsEdgeCasesTest.php, unknown', \App\Listeners\SendReplyToCustomer::filterFiles('test.filter_files'));
        $this->assertSame('unknown', \App\Listeners\SendReplyToCustomer::filterFiles('test.no_such_filter'));
    }

    public function skipSending($skip)
    {
        return true;
    }

    // Notifications menu.

    public function testNotificationsOfThreadsNoLongerVisibleHideTheirText()
    {
        $conversation = $this->receiveConversation();
        $thread = $conversation->threads()->first();
        $this->agent->notify(new \App\Notifications\WebsiteNotification($conversation, $thread));
        $gone = $this->receiveConversation()->threads()->first();
        $this->agent->notify(new \App\Notifications\WebsiteNotification($gone->conversation, $gone));
        $gone->delete();

        $entries = \App\Notifications\WebsiteNotification::fetchNotificationsData($this->agent->notifications()->get(), $this->agent);
        $this->assertCount(1, $entries, 'A deleted thread is left out.');
        $this->assertSame('Where is my parcel?', trim(strip_tags($entries[0]['last_thread_body'])));

        $this->mailbox->users()->detach($this->agent->id);
        $entries = \App\Notifications\WebsiteNotification::fetchNotificationsData($this->agent->notifications()->get(), $this->agent->fresh());
        $this->assertSame('…', $entries[0]['last_thread_body']);
    }

    // Users.

    public function testInvitedUserIsActivatedByLoggingIn()
    {
        $user = $this->createUser(['email' => 'invited@example.org', 'password' => \Hash::make('secret-password')]);
        $user->invite_state = User::INVITE_STATE_SENT;
        $user->invite_hash = 'abc123';
        $user->save();
        \Session::start();

        $this->post('/login', ['_token' => csrf_token(), 'email' => 'invited@example.org', 'password' => 'secret-password'])->assertRedirect('/home');

        $user->refresh();
        $this->assertSame(User::INVITE_STATE_ACTIVATED, (int) $user->invite_state);
        $this->assertSame('', (string) $user->invite_hash);
    }
}
