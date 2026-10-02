<?php

namespace Tests\Feature;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Sending replies to customers: a reply must end up sent (and in the
 * outgoing log), or visibly failed.
 */
class ReplySendingTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function conversationWithReply()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
        ]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Our answer</p>',
        ]);

        return [$conversation, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first()];
    }

    /**
     * A failed SendReplyToCustomer job in failed_jobs, as the queue worker
     * leaves it when sending fails for good.
     */
    protected function failedSendJob(Conversation $conversation, Thread $reply)
    {
        $replies = $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]);
        \App\Jobs\SendReplyToCustomer::dispatch($conversation, $replies, $conversation->customer)
            ->onConnection('database')
            ->onQueue('emails');
        $job = \DB::table('jobs')->where('queue', 'emails')->orderBy('id', 'desc')->first();
        \DB::table('jobs')->where('id', $job->id)->delete();

        $reply->send_status = SendLog::STATUS_SEND_ERROR;
        $reply->updateSendStatusData(['msg' => '550 Mailbox unavailable']);
        $reply->save();

        return \DB::table('failed_jobs')->insertGetId([
            'connection' => 'database',
            'queue'      => 'emails',
            'payload'    => $job->payload,
            'exception'  => 'Symfony\Component\Mailer\Exception\TransportException: 550 Mailbox unavailable',
            'failed_at'  => now(),
        ]);
    }

    /**
     * A reply in a closed conversation, its SendReplyToCustomer job waiting
     * in the database queue (as in production) to be run by runQueue().
     */
    protected function queuedReplyInClosedConversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
        ]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();

        config(['queue.default' => 'database']);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Our answer</p>',
        ]);
        \DB::table('jobs')->where('queue', 'emails')->where('payload', 'not like', '%SendReplyToCustomer%')->delete();
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertNotNull($reply->getQueuedJobId());

        $conversation = $conversation->fresh();
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        return [$conversation, $reply];
    }

    /**
     * Move a conversation's threads 20 minutes back, past the undo delay.
     */
    protected function backdate(Conversation $conversation)
    {
        $conversation->threads()->update(['created_at' => \DB::raw('created_at - INTERVAL 20 MINUTE')]);
    }

    protected function runQueue()
    {
        \DB::table('jobs')->where('queue', 'emails')->update(['available_at' => time() - 1]);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true]);
    }

    /**
     * Make every mail transport throw.
     */
    protected function failSending(\Throwable $exception)
    {
        $transport = new class($exception) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected $exception;

            public function __construct($exception)
            {
                parent::__construct();
                $this->exception = $exception;
            }

            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw $this->exception;
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
        \MailHelper::$last_mail_config_hash = '';
        $this->app->forgetInstance('mail.manager');
        $this->app->extend('mail.manager', function ($manager) use ($transport) {
            return static::captureAllMailDrivers($manager, $transport);
        });
    }

    public function testRejectedReplyReopensConversation()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 User unknown".', 550));

        $this->runQueue();

        $this->assertSame(SendLog::STATUS_SEND_INTERMEDIATE_ERROR, (int) $reply->fresh()->send_status, 'The reply shows the error.');
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status, 'The conversation is reopened.');
        $this->assertSame([SendLog::STATUS_SEND_ERROR], SendLog::where('thread_id', $reply->id)->pluck('status')->all());
        $this->assertSame(1, \DB::table('jobs')->where('queue', 'emails')->count(), 'Sending is retried later.');
    }

    public function testPermanentFailureReopensConversation()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Expected response code "250/251/252" but got code "554", with message "554 5.5.1 Error: no valid recipients".', 554));

        $this->runQueue();

        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertContains(SendLog::STATUS_SEND_ERROR, SendLog::where('thread_id', $reply->id)->pluck('status')->all());
        $this->assertNotNull($reply->fresh()->getFailedJobId(), 'The job can be retried.');
    }

    public function testErrorThatIsNotAnExceptionIsRetriedLater()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        $this->failSending(new \TypeError('Something in a module went wrong'));

        $this->runQueue();

        $this->assertSame([SendLog::STATUS_SEND_ERROR], SendLog::where('thread_id', $reply->id)->pluck('status')->all(), 'The failed attempt is in the outgoing log.');
        $this->assertGreaterThan(time() + 200, \DB::table('jobs')->where('queue', 'emails')->value('available_at'), 'Retried after a delay.');
    }

    public function testGivingUpReopensConversation()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        // The worker gives up on a job that ran out of attempts (as after
        // being killed by timeouts) without running it.
        \DB::table('jobs')->where('queue', 'emails')->update(['attempts' => 168]);

        $this->runQueue();

        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertSame(['casey@customer.example.org'], SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_SEND_ERROR)->pluck('email')->all());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testUndoCancelsTheQueuedReply()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        \Session::start();

        $this->actingAs($this->agent)->get('/conversation/undo-reply/'.$reply->id.'/'.csrf_token());

        $this->assertSame(Thread::STATE_DRAFT, (int) $reply->fresh()->state);
        $this->assertSame(0, \DB::table('jobs')->where('queue', 'emails')->count(), 'The job is cancelled, so sending the draft again sends it once.');
    }

    public function testCheckOutgoingListsRepliesNotInTheLog()
    {
        [$sent_conversation, $sent] = $this->conversationWithReply();
        [$conversation, $waiting] = $this->queuedReplyInClosedConversation();
        $this->backdate($sent_conversation);
        $this->backdate($conversation);

        $this->artisan('tallport:check-outgoing')
            ->expectsOutput('Replies not sent: 1')
            ->expectsOutputToContain('thread '.$waiting->id."\tconversation ".$conversation->id."\t")
            ->assertExitCode(0);

        $this->artisan('tallport:check-outgoing')->expectsOutputToContain('job waiting in queue')->assertExitCode(0);

        \DB::table('jobs')->where('queue', 'emails')->delete();
        $this->artisan('tallport:check-outgoing')->expectsOutputToContain('no job')->assertExitCode(0);
    }

    public function testSentReplyIsLoggedBeforeSavingToImapSentFolder()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        \DB::table('jobs')->where('queue', 'emails')->delete();
        $replies = $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]);
        // The worker is killed while saving to the IMAP Sent folder.
        $job = new class($conversation, $replies, $conversation->customer) extends \App\Jobs\SendReplyToCustomer {
            public $attempt = 1;

            public function attempts()
            {
                return $this->attempt;
            }

            public function saveToImapSentFolder($mailbox)
            {
                throw new \Error('Worker killed');
            }
        };

        try {
            $job->handle();
            $this->fail('The job was not interrupted.');
        } catch (\Error $e) {
            $this->assertSame('Worker killed', $e->getMessage());
        }

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertSame([SendLog::STATUS_ACCEPTED], SendLog::where('thread_id', $reply->id)->pluck('status')->all(), 'Logged before the IMAP folder.');

        // The job runs again (attempt 2): the reply isn't sent twice.
        $job = new class($conversation, $replies, $conversation->customer) extends \App\Jobs\SendReplyToCustomer {
            public function attempts()
            {
                return 2;
            }
        };
        $job->handle();
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testJobsAreFoundWhereverTheReplyIsInTheirThreads()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        \DB::table('jobs')->where('queue', 'emails')->delete();
        // As the listener leaves it after dropping newer threads: keys from 1.
        $customer_thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $threads = new \Illuminate\Database\Eloquent\Collection([1 => $reply, 2 => $customer_thread]);
        \App\Jobs\SendReplyToCustomer::dispatch($conversation, $threads, $conversation->customer)->onQueue('emails');
        $this->assertStringContainsString('{i:1;i:'.$reply->id.';', \DB::table('jobs')->where('queue', 'emails')->value('payload'));

        $this->assertNotNull($reply->getQueuedJobId());
        $this->assertNull($customer_thread->getQueuedJobId(), 'Only the job for the reply.');

        $job = \DB::table('jobs')->where('queue', 'emails')->first();
        \DB::table('jobs')->where('id', $job->id)->delete();
        \DB::table('failed_jobs')->insert(['connection' => 'database', 'queue' => 'emails', 'payload' => $job->payload, 'exception' => 'Error', 'failed_at' => now()]);

        $this->assertNull($reply->getQueuedJobId());
        $this->assertNotNull($reply->getFailedJobId());
    }

    public function testCheckOutgoingFixMarksUnsentReplyAndRetrySendsIt()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        // The job is gone without having run.
        \DB::table('jobs')->where('queue', 'emails')->delete();
        $this->backdate($conversation);

        $this->artisan('tallport:check-outgoing', ['--fix' => true])
            ->expectsOutputToContain('thread '.$reply->id."\t")
            ->assertExitCode(0);

        $reply = $reply->fresh();
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->send_status, 'Shown as not sent.');
        $this->assertTrue($reply->canRetrySend(), 'With a Retry button.');
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status, 'The conversation is reopened.');
        $this->assertSame(['casey@customer.example.org'], SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_SEND_ERROR)->pluck('email')->all());

        // Already marked: listed, but not marked again.
        $this->artisan('tallport:check-outgoing', ['--fix' => true])
            ->expectsOutputToContain('send_status '.SendLog::STATUS_SEND_ERROR."\tno job")
            ->assertExitCode(0);
        $this->assertSame(1, SendLog::where('thread_id', $reply->id)->count());

        // Retry queues the reply again (there is no failed job).
        $this->assertSame('success', $this->postAjax($this->agent, '/conversation/ajax', ['action' => 'retry_send', 'thread_id' => $reply->id])->json()['status']);
        $this->runQueue();

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $reply->fresh()->send_status);
        $this->artisan('tallport:check-outgoing')->expectsOutput('Replies not sent: 0')->assertExitCode(0);
    }

    public function testCheckOutgoingFixMarksReplyWhoseJobFailedUnnoticed()
    {
        [$conversation, $reply] = $this->queuedReplyInClosedConversation();
        // Failed before 1.17.11: in failed_jobs, but the reply shows nothing.
        $job = \DB::table('jobs')->where('queue', 'emails')->first();
        \DB::table('jobs')->where('id', $job->id)->delete();
        \DB::table('failed_jobs')->insert(['connection' => 'database', 'queue' => 'emails', 'payload' => $job->payload, 'exception' => 'ErrorException', 'failed_at' => now()]);
        $this->backdate($conversation);

        $this->artisan('tallport:check-outgoing', ['--fix' => true])
            ->expectsOutputToContain('job failed: marked not sent, conversation reopened')
            ->assertExitCode(0);

        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        $this->assertNotNull($reply->fresh()->getFailedJobId(), 'Retry retries the failed job.');
    }

    public function testCheckOutgoingFixLeavesOtherRepliesAlone()
    {
        [$conversation, $waiting] = $this->queuedReplyInClosedConversation();
        $this->backdate($conversation);
        // Just sent: its job may not have run yet.
        [$recent_conversation, $recent] = $this->queuedReplyInClosedConversation();
        \DB::table('jobs')->where('queue', 'emails')->where('payload', 'like', '%;i:'.$recent->id.';%')->delete();
        // A phone conversation is not emailed.
        [$phone_conversation, $phone] = $this->queuedReplyInClosedConversation();
        \DB::table('jobs')->where('queue', 'emails')->where('payload', 'like', '%;i:'.$phone->id.';%')->delete();
        \DB::table('conversations')->where('id', $phone_conversation->id)->update(['type' => Conversation::TYPE_PHONE]);
        $this->backdate($phone_conversation);

        $this->artisan('tallport:check-outgoing', ['--fix' => true])
            ->expectsOutput('Replies not sent: 1')
            ->expectsOutputToContain('thread '.$waiting->id."\t")
            ->assertExitCode(0);

        foreach ([$waiting, $recent, $phone] as $reply) {
            $this->assertNull($reply->fresh()->send_status);
        }
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
    }

    public function testCheckOutgoingIsScheduled()
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(function ($event) {
            return str_contains($event->command, 'tallport:check-outgoing');
        });

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events->first()->expression);

        // The command line as the scheduler runs it.
        $command = preg_replace("/^.*artisan'?\\s+/", '', $events->first()->command);
        $this->assertStringContainsString('--fix', $command);
        $this->artisan($command)->expectsOutput('Replies not sent: 0')->assertExitCode(0);
    }

    public function testQueueWorkerRestartIsQueuedOnce()
    {
        // FeatureTestCase queued one already (as Laravel 13 writes it).
        \Helper::queueWorkerRestart();

        $this->assertSame(1, \DB::table('jobs')->where('payload', 'like', '%RestartQueueWorker%')->count());
    }

    public function testRetryAfterFailureSendsTheReply()
    {
        [$conversation, $reply] = $this->conversationWithReply();
        $this->failedSendJob($conversation, $reply);
        $this->captured_mail->flush();
        SendLog::where('thread_id', $reply->id)->delete();

        $response = $this->postAjax($this->agent, '/conversation/ajax', ['action' => 'retry_send', 'thread_id' => $reply->id])->json();
        $this->assertSame('success', $response['status']);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true]);

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'), 'The retried reply is sent.');
        $this->assertSame([SendLog::STATUS_ACCEPTED], SendLog::where('thread_id', $reply->id)->pluck('status')->all());
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $reply->fresh()->send_status);
    }
}
