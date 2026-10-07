<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\Jobs\SendAutoReply;
use App\Jobs\SendEmailReplyError;
use App\Jobs\SendNotificationToUsers;
use App\SendLog;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * Emails to agents (SendNotificationToUsers) and auto replies to customers
 * (the SendAutoReply listener and job): who gets them, how much history,
 * loop protection, and what happens when sending fails.
 */
class NotificationAndAutoReplyJobsTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Shop Support']);
    }

    protected function receiveCustomerEmail(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * A conversation of three customer messages, newest last.
     */
    protected function conversationWithThreeMessages()
    {
        $conversation = $this->receiveCustomerEmail(['message_id' => 'first@customer.example.org', 'body' => 'Message one']);
        foreach (['second' => 'Message two', 'third' => 'Message three'] as $id => $body) {
            $this->receiveCustomerEmail(['message_id' => $id.'@customer.example.org', 'in_reply_to' => 'first@customer.example.org', 'body' => $body]);
        }
        $this->captured_mail->flush();

        return $conversation->fresh();
    }

    protected function notify(array $users, Conversation $conversation)
    {
        $job = (new SendNotificationToUsers(collect($users), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();
        $job->handle();

        return $job;
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

    protected function enableAutoReply()
    {
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = 'We will answer within a day.';
        $this->mailbox->save();
    }

    protected function autoRepliesTo($email)
    {
        return array_values(array_filter($this->sentEmailsTo($email), function ($email) {
            return $email->getSubject() == 'We got your message';
        }));
    }

    // Notifications to agents.

    public function testNotificationIncludesTheWholeConversationByDefault()
    {
        $conversation = $this->conversationWithThreeMessages();

        $this->notify([$this->agent], $conversation);

        $body = $this->sentEmailsTo($this->agent->email)[0]->getBody();
        $this->assertMatchesRegularExpression('/Message three.*Message two.*Message one/s', $body);
        $this->assertSame([SendLog::STATUS_ACCEPTED], SendLog::where('user_id', $this->agent->id)->pluck('status')->all());
    }

    public function testNotificationHistoryCanBeLimitedToTheLastMessage()
    {
        $conversation = $this->conversationWithThreeMessages();
        config(['app.email_user_history' => 'last']);

        $this->notify([$this->agent], $conversation);

        $body = $this->sentEmailsTo($this->agent->email)[0]->getBody();
        $this->assertStringContainsString('Message three', $body);
        $this->assertStringContainsString('Message two', $body);
        $this->assertStringNotContainsString('Message one', $body);
    }

    public function testNotificationHistoryCanBeLeftOut()
    {
        $conversation = $this->conversationWithThreeMessages();
        config(['app.email_user_history' => 'none']);

        $this->notify([$this->agent], $conversation);

        $body = $this->sentEmailsTo($this->agent->email)[0]->getBody();
        $this->assertStringContainsString('Message three', $body);
        $this->assertStringNotContainsString('Message two', $body);
    }

    public function testNotificationIsOnlySentToActiveUsersWithAccess()
    {
        $conversation = $this->conversationWithThreeMessages();
        $outsider = $this->createUser();
        $deleted = $this->createUser(['status' => User::STATUS_DELETED]);
        $this->mailbox->users()->attach($deleted->id);

        $this->notify([$outsider, $deleted, $this->agent], $conversation);

        $this->assertCount(0, $this->sentEmailsTo($outsider->email));
        $this->assertCount(0, $this->sentEmailsTo($deleted->email));
        $this->assertCount(1, $this->sentEmailsTo($this->agent->email));
    }

    public function testNothingIsSentForAnUndoneThread()
    {
        $conversation = $this->conversationWithThreeMessages();
        $thread = $conversation->getThreads()->first();
        $thread->state = Thread::STATE_DRAFT;
        $thread->save();

        (new SendNotificationToUsers(collect([$this->agent]), $conversation, collect([$thread])))->handle();
        (new SendNotificationToUsers(collect([$this->agent]), $conversation, collect()))->handle();

        $this->assertCount(0, $this->sentEmails());
    }

    public function testNothingIsSentForABounceSayingTheSendLimitIsReached()
    {
        $conversation = $this->conversationWithThreeMessages();
        $thread = $conversation->getThreads()->first();
        $thread->body = 'Delivery failed: message limit exceeded';
        $thread->updateSendStatusData(['is_bounce' => true]);
        $thread->save();

        $this->notify([$this->agent], $conversation);

        $this->assertCount(0, $this->sentEmails());
    }

    public function testRetryDoesNotNotifyAgainWhoAlreadyGotIt()
    {
        $conversation = $this->conversationWithThreeMessages();
        $other_agent = $this->createUser();
        $this->mailbox->users()->attach($other_agent->id);
        $this->notify([$this->agent], $conversation);
        $this->captured_mail->flush();

        $job = (new SendNotificationToUsers(collect([$this->agent, $other_agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();
        $job->job->attempts = 2;
        $job->handle();

        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));
        $this->assertCount(1, $this->sentEmailsTo($other_agent->email));
    }

    public function testFailedNotificationIsLoggedAndRetriedInFiveMinutes()
    {
        $conversation = $this->conversationWithThreeMessages();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));
        $job = (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('The job did not throw.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }

        $job->assertReleased(300);
        $log = SendLog::where('user_id', $this->agent->id)->first();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $log->status);
        $this->assertSame('Connection refused', $log->status_message);
        $this->assertSame(SendLog::MAIL_TYPE_USER_NOTIFICATION, (int) $log->mail_type);
        $activity = ActivityLog::where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_USER)->first();
        $this->assertEquals($this->agent->id, $activity->causer_id);
        $this->assertStringStartsWith('Connection refused; File: ', $activity->properties['error']);
    }

    public function testLaterAttemptsAreRetriedHourly()
    {
        $conversation = $this->conversationWithThreeMessages();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));
        $job = (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();
        $job->job->attempts = 2;

        try {
            $job->handle();
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
        }

        $job->assertReleased(3600);
        $job->assertNotFailed();
    }

    /**
     * The mail server took the message but timed out answering: it was
     * probably sent, so it's not sent again and again.
     */
    public function testTimeoutAfterTheDataWasSentGivesUp()
    {
        $conversation = $this->conversationWithThreeMessages();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection to smtp.example.org:587 Timed Out'));
        \MailHelper::$smtp_data_sent = true;
        $job = (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        try {
            $job->handle();
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
        } finally {
            \MailHelper::$smtp_data_sent = false;
        }

        $job->assertFailed();
    }

    public function testFailedNotificationAboutABounceIsNotRetried()
    {
        $conversation = $this->conversationWithThreeMessages();
        $thread = $conversation->getThreads()->first();
        $thread->updateSendStatusData(['is_bounce' => true]);
        $thread->save();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));
        $job = (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailed();
        $job->assertNotReleased();
    }

    public function testGivingUpOnANotificationIsLogged()
    {
        $conversation = $this->conversationWithThreeMessages();

        (new SendNotificationToUsers(collect([$this->agent]), $conversation, $conversation->getThreads()))->failed(new \Exception('Gave up'));

        $activity = ActivityLog::where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_USER)->first();
        $this->assertSame(ActivityLog::NAME_EMAILS_SENDING, $activity->log_name);
        $this->assertStringStartsWith('Gave up; File: ', $activity->properties['error']);
    }

    public function testCustomerReplyToSpamDoesNotNotify()
    {
        $conversation = $this->conversationWithThreeMessages();
        $conversation->status = Conversation::STATUS_SPAM;
        $conversation->save();

        $this->receiveCustomerEmail(['message_id' => 'fourth@customer.example.org', 'in_reply_to' => 'first@customer.example.org', 'body' => 'Message four']);

        $this->assertSame(4, $conversation->threads()->count());
        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));
    }

    public function testImportedThreadDoesNotNotify()
    {
        $conversation = $this->conversationWithThreeMessages();
        $thread = $conversation->getThreads()->first();
        $thread->imported = true;

        event(new \App\Events\CustomerReplied($conversation, $thread));
        \App\Subscription::processEvents();

        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));
    }

    // Auto replies.

    /**
     * A bounce without an Auto-Submitted header (detected by its From).
     */
    public function testAutoReplyIsNotSentToBounces()
    {
        $this->knownBug('F3');

        $this->enableAutoReply();

        $conversation = $this->receiveCustomerEmail(['from' => 'MAILER-DAEMON@mail.customer.example.org', 'subject' => 'Undelivered Mail Returned to Sender']);

        $this->assertNotNull($conversation);
        $this->assertCount(0, $this->sentEmailsTo('MAILER-DAEMON@mail.customer.example.org'));
    }

    public function testAutoReplyIsNotSentToOwnMailboxes()
    {
        $this->enableAutoReply();
        $other_mailbox = $this->createMailbox([], ['name' => 'Billing']);

        $this->receiveCustomerEmail(['from' => $other_mailbox->email]);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertCount(0, $this->sentEmailsTo($other_mailbox->email));
    }

    /**
     * Loop protection: after two auto replies within the check period, a
     * customer who keeps sending the same subject gets no more.
     */
    public function testAutoRepliesStopForRepeatedSubjects()
    {
        $this->enableAutoReply();
        $this->receiveCustomerEmail(['subject' => 'Out of office', 'message_id' => 'one@customer.example.org']);
        $this->receiveCustomerEmail(['subject' => 'Something else', 'message_id' => 'two@customer.example.org']);
        $this->assertCount(2, $this->autoRepliesTo('casey@customer.example.org'));

        $this->receiveCustomerEmail(['subject' => 'Yet another question', 'message_id' => 'three@customer.example.org']);
        $this->assertCount(3, $this->autoRepliesTo('casey@customer.example.org'), 'A new subject is still answered.');

        $this->receiveCustomerEmail(['subject' => 'Out of office', 'message_id' => 'four@customer.example.org']);
        $this->assertCount(3, $this->autoRepliesTo('casey@customer.example.org'), 'A repeated subject is not.');
    }

    public function testAutoRepliesStopAfterTenWithinTheCheckPeriod()
    {
        $this->enableAutoReply();
        $customer = $this->createCustomer('casey@customer.example.org');
        for ($i = 0; $i < 10; $i++) {
            SendLog::log(null, 'm'.$i, 'casey@customer.example.org', SendLog::MAIL_TYPE_AUTO_REPLY, SendLog::STATUS_ACCEPTED, $customer->id);
        }

        $this->receiveCustomerEmail(['subject' => 'Brand new question']);

        $this->assertCount(0, $this->autoRepliesTo('casey@customer.example.org'));
    }

    public function testAutoReplyIsNotSentWhenTurnedOffForTheConversation()
    {
        $conversation = $this->receiveCustomerEmail();
        $conversation->setMeta('ar_off', true);
        $conversation->save();

        (new SendAutoReply($conversation, $conversation->threads()->first(), $this->mailbox, $conversation->customer))->handle();

        $this->assertCount(0, $this->sentEmails());
    }

    public function testAutoReplyNeedsACustomerEmail()
    {
        $conversation = $this->receiveCustomerEmail();
        $conversation->customer_email = '';

        (new SendAutoReply($conversation, $conversation->threads()->first(), $this->mailbox, $conversation->customer))->handle();

        $this->assertCount(0, $this->sentEmails());
    }

    public function testAutoReplyComesFromTheAliasTheCustomerWrote()
    {
        $this->enableAutoReply();
        $this->mailbox->aliases = 'sales@shop.example.org (Sales Team)';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();

        $this->receiveCustomerEmail(['to' => 'Sales <sales@shop.example.org>']);

        $email = $this->autoRepliesTo('casey@customer.example.org')[0];
        $this->assertSame(['sales@shop.example.org' => 'Sales Team'], $email->getFrom());
    }

    public function testAutoReplyFromAliasUsesTheCustomFromName()
    {
        $this->enableAutoReply();
        $this->mailbox->aliases = 'sales@shop.example.org (Sales Team)';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->from_name = \App\Mailbox::FROM_NAME_CUSTOM;
        $this->mailbox->from_name_custom = 'Shop Helpdesk';
        $this->mailbox->save();

        $this->receiveCustomerEmail(['to' => 'sales@shop.example.org']);

        $email = $this->autoRepliesTo('casey@customer.example.org')[0];
        $this->assertSame(['sales@shop.example.org' => 'Shop Helpdesk'], $email->getFrom());
    }

    public function testFailedAutoReplyIsLoggedAndThrown()
    {
        $conversation = $this->receiveCustomerEmail();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));
        $thread = $conversation->threads()->first();

        try {
            (new SendAutoReply($conversation, $thread, $this->mailbox, $conversation->customer))->handle();
            $this->fail('The job did not throw.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }

        $log = SendLog::where('thread_id', $thread->id)->where('mail_type', SendLog::MAIL_TYPE_AUTO_REPLY)->first();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $log->status);
        $this->assertSame('Connection refused', $log->status_message);
        $this->assertEquals($conversation->customer_id, $log->customer_id);
        $activity = ActivityLog::where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER)->first();
        $this->assertEquals($conversation->customer_id, $activity->causer_id);
    }

    public function testGivingUpOnAnAutoReplyIsLogged()
    {
        $conversation = $this->receiveCustomerEmail();

        (new SendAutoReply($conversation, $conversation->threads()->first(), $this->mailbox, $conversation->customer))->failed(new \Exception('Gave up'));

        $activity = ActivityLog::where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER)->first();
        $this->assertEquals($conversation->customer_id, $activity->causer_id);
        $this->assertStringStartsWith('Gave up; File: ', $activity->properties['error']);
    }

    // Replies to agents whose emailed answer couldn't be used.

    public function testReplyErrorEmailIsSentAndLogged()
    {
        (new SendEmailReplyError($this->agent->email, $this->agent, $this->mailbox, 'The conversation you replied to could not be found.'))->handle();

        $email = $this->sentEmailsTo($this->agent->email)[0];
        $this->assertStringContainsString('The conversation you replied to could not be found.', $email->getBody());
        $log = SendLog::where('mail_type', SendLog::MAIL_TYPE_WRONG_USER_EMAIL_MESSAGE)->first();
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $log->status);
        $this->assertEquals($this->agent->id, $log->user_id);
    }

    public function testFailedReplyErrorEmailIsLoggedAndThrown()
    {
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));

        try {
            (new SendEmailReplyError($this->agent->email, $this->agent, $this->mailbox, 'Not found.'))->handle();
            $this->fail('The job did not throw.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
        }

        $log = SendLog::where('mail_type', SendLog::MAIL_TYPE_WRONG_USER_EMAIL_MESSAGE)->first();
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, $log->status);
        $this->assertSame('Connection refused', $log->status_message);
        $this->assertSame(1, ActivityLog::where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_WRONG_EMAIL)->count());
    }
}
