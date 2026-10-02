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
        $this->assertSame(1, \DB::table('jobs')->where('queue', 'emails')->count());

        $conversation = $conversation->fresh();
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();

        return [$conversation, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first()];
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
        \DB::table('threads')->whereIn('id', [$sent->id, $waiting->id])->update(['created_at' => now()->subMinutes(20)]);

        $this->artisan('tallport:check-outgoing')
            ->expectsOutput('Replies without an outgoing log entry: 1')
            ->expectsOutputToContain('thread '.$waiting->id."\tconversation ".$conversation->id."\t")
            ->assertExitCode(0);

        $this->artisan('tallport:check-outgoing')->expectsOutputToContain('job waiting in queue')->assertExitCode(0);

        \DB::table('jobs')->where('queue', 'emails')->delete();
        $this->artisan('tallport:check-outgoing')->expectsOutputToContain('no job')->assertExitCode(0);
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
