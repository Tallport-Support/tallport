<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Jobs\SendReplyToCustomer;
use App\SendLog;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * The SendReplyToCustomer job (and the ReplyToCustomer email it sends):
 * threading headers, conversation history, recipients, From aliases,
 * attachments, retries, the cases where nothing is sent, and saving the
 * sent reply to the mailbox's IMAP Sent folder.
 */
class SendReplyToCustomerJobTest extends FeatureTestCase
{
    /**
     * An Outlook conversation index from 2023 (tests/Messages/webklex/multipart_without_body.eml).
     */
    const OLD_THREAD_INDEX = 'AdlT8uVmpHPvImbCRM6E9LODIvAcQA==';

    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Shop Support']);
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

    protected function reply(Conversation $conversation, $body, array $data = [])
    {
        $response = $this->postAjax($this->agent, '/conversation/ajax', array_merge([
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => $body,
        ], $data))->json();
        $this->assertSame('success', $response['status'], json_encode($response));

        return $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
    }

    /**
     * The conversation's customer messages and replies, as the listener
     * hands them to the job.
     */
    protected function threadsOf(Conversation $conversation)
    {
        return $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE]);
    }

    /**
     * A conversation with one reply that isn't sent yet (queued, as in
     * production), for running the job directly.
     */
    protected function conversationWithQueuedReply()
    {
        $conversation = $this->receiveConversation();
        config(['queue.default' => 'database']);
        $reply = $this->reply($conversation, '<p>Our answer</p>');
        config(['queue.default' => 'sync']);
        \DB::table('jobs')->where('queue', 'emails')->delete();
        $this->captured_mail->flush();

        return [$conversation->fresh(), $reply];
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

    protected function assertLoggedNotSent($reason)
    {
        \Log::shouldHaveReceived('warning')->withArgs(function ($message) use ($reason) {
            return str_starts_with($message, '[SendReplyToCustomer] Reply ') && str_ends_with($message, ' not sent: '.$reason.'.');
        })->once();
    }

    // Threading.

    public function testReplyExtendsOutlookThreadIndex()
    {
        $conversation = $this->receiveConversation(['headers' => [
            'Thread-Index' => self::OLD_THREAD_INDEX,
            'Thread-Topic' => 'Question about my order',
        ]]);

        $this->reply($conversation, '<p>Our answer</p>');

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $thread_index = base64_decode($email->getHeaders()->get('Thread-Index')->getFieldBody());
        $this->assertSame(27, strlen($thread_index), 'A 5-byte child block is appended.');
        $this->assertSame(base64_decode(self::OLD_THREAD_INDEX), substr($thread_index, 0, 22));
        $this->assertSame('Question about my order', $email->getHeaders()->get('Thread-Topic')->getFieldBody());
    }

    public function testThreadIndexThatIsNotAConversationIndexIsCopied()
    {
        $this->assertSame('not-an-index', SendReplyToCustomer::extendThreadIndex('not-an-index'));
        $this->assertSame(base64_encode('too short'), SendReplyToCustomer::extendThreadIndex(base64_encode('too short')));
    }

    public function testThreadIndexChildBlockHoldsTheTimeSinceTheMessage()
    {
        // A conversation index made just now: the child block's delta is tiny (code 0).
        $now_bits = ((int) round((microtime(true) + 11644473600) * 10000000) >> 16) & 0xFFFFFFFFFF;
        $header = "\x01".pack('NC', $now_bits >> 8, $now_bits & 0xFF).str_repeat("\xAB", 16);

        $child = substr(base64_decode(SendReplyToCustomer::extendThreadIndex(base64_encode($header))), 22);

        $this->assertSame(5, strlen($child));
        $this->assertSame(0, ord($child[0]) & 0x80, 'Code bit 0: delta counted in ~1.7 ms units.');
        $this->assertSame(0, ord($child[0]), 'A delta of less than a second leaves the high bits empty.');
        $this->assertSame(0, ord($child[4]) & 0x0F, 'Sequence 0.');

        // Three years ago: too long for the short units (code 1).
        $child = substr(base64_decode(SendReplyToCustomer::extendThreadIndex(self::OLD_THREAD_INDEX)), 22);
        $this->assertSame(0x80, ord($child[0]) & 0x80);
    }

    public function testReferencesListsEarlierMessagesOldestFirst()
    {
        $conversation = $this->receiveConversation(['message_id' => 'first@customer.example.org']);
        $first_reply = $this->reply($conversation, '<p>First answer</p>');
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'        => 'casey@customer.example.org',
            'to'          => $this->mailbox->email,
            'subject'     => 'Re: Question about my order',
            'message_id'  => 'second@customer.example.org',
            'in_reply_to' => $first_reply->getMessageId(),
        ]));
        $this->captured_mail->flush();

        $this->reply($conversation, '<p>Second answer</p>');

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame('<second@customer.example.org>', $email->getHeaders()->get('In-Reply-To')->getFieldBody());
        $this->assertSame(
            '<first@customer.example.org> <'.$first_reply->getMessageId().'> <second@customer.example.org>',
            $email->getHeaders()->get('References')->getFieldBody()
        );
        $this->assertSame('Re: Question about my order', $email->getSubject());
    }

    /**
     * Some mail servers reject headers over 4096 characters: References is
     * kept to about 1500, with the first and the last message.
     */
    public function testLongReferencesKeepTheFirstAndTheLastMessage()
    {
        $padding = str_repeat('x', 180);
        $conversation = $this->receiveConversation(['message_id' => 'm0-'.$padding.'@customer.example.org']);
        for ($i = 1; $i <= 10; $i++) {
            $this->receiveEmail($this->mailbox, $this->makeEmail([
                'from'        => 'casey@customer.example.org',
                'to'          => $this->mailbox->email,
                'subject'     => 'Re: Question about my order',
                'message_id'  => 'm'.$i.'-'.$padding.'@customer.example.org',
                'in_reply_to' => 'm'.($i - 1).'-'.$padding.'@customer.example.org',
            ]));
        }
        $this->assertSame(11, $conversation->threads()->count());

        $this->reply($conversation, '<p>Our answer</p>');

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame('<m10-'.$padding.'@customer.example.org>', $email->getHeaders()->get('In-Reply-To')->getFieldBody());
        preg_match_all('/<m(\d+)-/', $email->getHeaders()->get('References')->getFieldBody(), $m);
        $this->assertSame(['0', '4', '5', '6', '7', '8', '9', '10'], $m[1], 'The oldest, then the most recent that fit.');
    }

    public function testThreadIndexFromTheFutureCountsAsNoTimePassed()
    {
        $future_bits = ((int) round((microtime(true) + 3600 + 11644473600) * 10000000) >> 16) & 0xFFFFFFFFFF;
        $header = "\x01".pack('NC', $future_bits >> 8, $future_bits & 0xFF).str_repeat("\xAB", 16);

        $child = substr(base64_decode(SendReplyToCustomer::extendThreadIndex(base64_encode($header))), 22);

        $this->assertSame("\x00\x00\x00\x00", substr($child, 0, 4));
    }

    // Conversation history.

    public function testLastHistoryQuotesOnlyThePreviousMessage()
    {
        config(['app.email_conv_history' => 'last']);
        $conversation = $this->receiveConversation(['body' => 'The very first question']);
        $first_reply = $this->reply($conversation, '<p>First answer</p>');
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'        => 'casey@customer.example.org',
            'to'          => $this->mailbox->email,
            'subject'     => 'Re: Question about my order',
            'in_reply_to' => $first_reply->getMessageId(),
            'body'        => 'A follow-up question',
        ]));
        $this->captured_mail->flush();

        $this->reply($conversation, '<p>Second answer</p>');

        $body = $this->sentEmailsTo('casey@customer.example.org')[0]->getBody();
        $this->assertStringContainsString('Second answer', $body);
        $this->assertStringContainsString('A follow-up question', $body);
        $this->assertStringNotContainsString('First answer', $body);
        $this->assertStringNotContainsString('The very first question', $body);
    }

    public function testFullHistoryQuotesEveryMessage()
    {
        config(['app.email_conv_history' => 'full']);
        $conversation = $this->receiveConversation(['body' => 'The very first question']);
        $first_reply = $this->reply($conversation, '<p>First answer</p>');
        $this->captured_mail->flush();

        $this->reply($conversation, '<p>Second answer</p>');

        $body = $this->sentEmailsTo('casey@customer.example.org')[0]->getBody();
        $this->assertStringContainsString('First answer', $body);
        $this->assertStringContainsString('The very first question', $body);
    }

    /**
     * A conversation moved to another mailbox: earlier replies quoted in a
     * reply keep the signature of the mailbox they were written in.
     */
    public function testQuotedReplyKeepsTheSignatureOfItsMailbox()
    {
        config(['app.email_conv_history' => 'full']);
        $this->mailbox->signature = 'Regards, the Shop';
        $this->mailbox->save();
        $warehouse = $this->createMailbox([$this->agent], ['name' => 'Warehouse']);
        $warehouse->signature = 'Regards, the Warehouse';
        $warehouse->save();
        $conversation = $this->receiveConversation();
        $this->reply($conversation, '<p>First answer</p>');
        $conversation->fresh()->moveToMailbox($warehouse, $this->agent);
        $this->captured_mail->flush();

        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $warehouse->id, 'conversation_id' => $conversation->id, 'body' => '<p>Second answer</p>',
        ])->json();
        $this->assertSame('success', $response['status'], json_encode($response));

        $body = $this->sentEmailsTo('casey@customer.example.org')[0]->getBody();
        $this->assertMatchesRegularExpression('/Second answer.*Regards, the Warehouse.*First answer.*Regards, the Shop/s', $body);
    }

    /**
     * Forwarding includes the original conversation up to the forward, with
     * the signatures the replies were sent with.
     */
    public function testForwardIncludesTheConversationUpToTheForward()
    {
        $this->mailbox->signature = 'Regards, the Shop';
        $this->mailbox->save();
        $warehouse = $this->createMailbox([$this->agent], ['name' => 'Warehouse']);
        $warehouse->signature = 'Regards, the Warehouse';
        $warehouse->save();
        $conversation = $this->receiveConversation(['body' => 'The package arrived damaged.']);
        $this->reply($conversation, '<p>Sorry to hear that.</p>');
        $conversation->fresh()->moveToMailbox($warehouse, $this->agent);

        config(['queue.default' => 'database']);
        $response = $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $warehouse->id, 'conversation_id' => $conversation->id,
            'subtype' => Thread::SUBTYPE_FORWARD, 'to_email' => ['claims@partner.example.org'], 'body' => '<p>Please check this shipment.</p>',
        ])->json();
        $this->assertSame('success', $response['status'], json_encode($response));
        // Answered in the original conversation before the forward is sent.
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $warehouse->id, 'conversation_id' => $conversation->id, 'body' => '<p>We are checking with the warehouse.</p>',
        ]);
        $later_reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
        $later_reply->created_at = $later_reply->created_at->addMinute();
        $later_reply->save();
        \DB::table('jobs')->where('queue', 'emails')->where('payload', 'like', '%claims@partner%')->update(['available_at' => time() - 1]);
        $this->captured_mail->flush();
        $forward_job = \DB::table('jobs')->where('queue', 'emails')->get()->first(function ($job) {
            $command = unserialize(json_decode($job->payload)->data->command);

            return $command instanceof SendReplyToCustomer && $command->conversation->customer_email == 'claims@partner.example.org';
        });
        \DB::table('jobs')->where('queue', 'emails')->where('id', '!=', $forward_job->id)->delete();
        \DB::table('jobs')->where('id', $forward_job->id)->update(['available_at' => time() - 1]);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'emails', '--once' => true]);

        $body = $this->sentEmailsTo('claims@partner.example.org')[0]->getBody();
        $this->assertMatchesRegularExpression('/Please check this shipment.*Sorry to hear that.*Regards, the Shop.*The package arrived damaged./s', $body);
        $this->assertStringNotContainsString('We are checking with the warehouse.', $body, 'Replies after the forward are left out.');
    }

    // Recipients.

    public function testMailboxAutoBccIsAdded()
    {
        $this->mailbox->auto_bcc = 'archive@shop.example.org';
        $this->mailbox->save();
        $conversation = $this->receiveConversation();

        $this->reply($conversation, '<p>Our answer</p>', ['bcc' => ['boss@shop.example.org']]);

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['boss@shop.example.org', 'archive@shop.example.org'], array_keys($email->getBcc()));
    }

    public function testBccAddressAlsoInCcIsSentOnce()
    {
        $conversation = $this->receiveConversation();

        $this->reply($conversation, '<p>Our answer</p>', [
            'cc'  => ['partner@customer.example.org'],
            'bcc' => ['partner@customer.example.org', 'casey@customer.example.org'],
        ]);

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['partner@customer.example.org'], array_keys($email->getCc()));
        $this->assertSame([], array_keys($email->getBcc() ?: []), 'The customer and Cc addresses are not Bcc\'d.');
    }

    public function testReplyWithSeveralAddressesInToIsSentToAllOfThem()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $reply->to = json_encode(['casey@customer.example.org', 'partner@customer.example.org']);
        $reply->save();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['casey@customer.example.org', 'partner@customer.example.org'], array_keys($email->getTo()));
        $this->assertEqualsCanonicalizing(
            ['casey@customer.example.org', 'partner@customer.example.org'],
            SendLog::where('thread_id', $reply->id)->pluck('email')->all()
        );
    }

    public function testReplyToAnotherCustomerOfTheConversation()
    {
        $partner = $this->createCustomer('partner@customer.example.org', ['first_name' => 'Pat', 'last_name' => 'Partner']);
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $reply->to = json_encode(['partner@customer.example.org']);
        $reply->save();
        \Queue::fake();

        // The listener picks the customer the reply is addressed to.
        event(new \App\Events\UserReplied($conversation, $reply));

        \Queue::assertPushed(SendReplyToCustomer::class, function ($job) use ($partner) {
            return $job->customer->id == $partner->id;
        });

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $partner))->handle();

        $email = $this->sentEmailsTo('partner@customer.example.org')[0];
        $this->assertSame(['partner@customer.example.org' => 'Pat Partner'], $email->getTo());
        $this->assertSame($partner->id, SendLog::where('thread_id', $reply->id)->value('customer_id'));
    }

    public function testPhoneConversationIsSentToTheCustomersEmail()
    {
        $customer = $this->createCustomer('caller@customer.example.org', ['first_name' => 'Cal', 'last_name' => 'Caller']);
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $conversation->type = Conversation::TYPE_PHONE;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = '';
        $conversation->save();
        $reply->to = null;
        $reply->save();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $customer))->handle();

        $this->assertCount(1, $this->sentEmailsTo('caller@customer.example.org'));
    }

    // From.

    public function testReplyFromAliasUsesTheAliasName()
    {
        $this->mailbox->aliases = 'sales@shop.example.org (Sales Team)';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->save();
        $conversation = $this->receiveConversation();

        $this->reply($conversation, '<p>Our answer</p>', ['from_alias' => 'sales@shop.example.org']);

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['sales@shop.example.org' => 'Sales Team'], $email->getFrom());
    }

    public function testReplyFromAliasUsesTheAgentNameWhenTheMailboxSendsAsTheAgent()
    {
        $this->mailbox->aliases = 'sales@shop.example.org (Sales Team)';
        $this->mailbox->aliases_reply = true;
        $this->mailbox->from_name = \App\Mailbox::FROM_NAME_USER;
        $this->mailbox->save();
        $conversation = $this->receiveConversation();

        $this->reply($conversation, '<p>Our answer</p>', ['from_alias' => 'sales@shop.example.org']);

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['sales@shop.example.org' => 'Alex Agent'], $email->getFrom());
    }

    public function testFromThatIsNotAnAliasIsIgnored()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $reply->from = 'someone-else@elsewhere.example.org';
        $reply->save();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame([$this->mailbox->email => 'Shop Support'], $email->getFrom());
    }

    // Attachments.

    public function testReplyAttachmentIsAttached()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        \App\Attachment::create('invoice.pdf', 'application/pdf', null, '%PDF-1.4 invoice', null, false, $reply->id);
        $reply->has_attachments = true;
        $reply->save();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $attachments = $this->sentEmailsTo('casey@customer.example.org')[0]->message->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('invoice.pdf', $attachments[0]->getFilename());
        $this->assertSame('%PDF-1.4 invoice', $attachments[0]->getBody());
    }

    public function testMissingAttachmentFileIsLoggedAndTheReplySentWithoutIt()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $attachment = \App\Attachment::create('invoice.pdf', 'application/pdf', null, '%PDF-1.4 invoice', null, false, $reply->id);
        $reply->has_attachments = true;
        $reply->save();
        \App\Attachment::getDisk()->delete($attachment->getStorageFilePath());
        \Log::spy();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertCount(0, $email->message->getAttachments());
        \Log::shouldHaveReceived('error')->withArgs(function ($message) use ($reply) {
            return str_starts_with($message, '[ReplyToCustomer] Thread: '.$reply->id.'. Attachment file not find on disk: ');
        })->once();
    }

    public function testAttachmentsOnOtherStorageAreAttachedByModules()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        \App\Attachment::create('invoice.pdf', 'application/pdf', null, '%PDF-1.4 invoice', null, false, $reply->id);
        $reply->has_attachments = true;
        $reply->save();
        config(['filesystems.default' => 's3']);
        $attached = [];
        \Eventy::addFilter('email.reply_to_customer.attach', function ($result, $message, $attachment, $thread_id) use (&$attached) {
            $attached[] = [$attachment->file_name, $thread_id];
            $message->attachData('from module', $attachment->file_name);

            return true;
        }, 20, 4);

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $this->assertSame([['invoice.pdf', $reply->id]], $attached);
        $attachments = $this->sentEmailsTo('casey@customer.example.org')[0]->message->getAttachments();
        $this->assertSame('from module', $attachments[0]->getBody());
    }

    // Not sent.

    public function testNothingIsSentWithoutConversation()
    {
        (new SendReplyToCustomer(null, collect(), null))->handle();

        $this->assertCount(0, $this->sentEmails());
    }

    public function testNothingIsSentWhenTheMailboxIsGone()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $conversation->mailbox_id = 999999;
        $conversation->unsetRelation('mailbox');
        \Log::spy();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $this->assertCount(0, $this->sentEmails());
        $this->assertLoggedNotSent('the mailbox no longer exists');
    }

    public function testNothingIsSentWhenTheReplyIsGone()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        \Log::spy();

        (new SendReplyToCustomer($conversation, collect(), $conversation->customer))->handle();

        $this->assertCount(0, $this->sentEmails());
        $this->assertLoggedNotSent('the reply no longer exists');
    }

    public function testPhoneConversationWithoutCustomerEmailIsNotSent()
    {
        $customer = $this->createCustomer('caller@customer.example.org');
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $conversation->type = Conversation::TYPE_PHONE;
        $conversation->customer_id = $customer->id;
        $conversation->customer_email = '';
        $conversation->save();
        $customer->emails()->delete();
        $reply->to = null;
        $reply->save();
        \Log::spy();

        (new SendReplyToCustomer($conversation->fresh(), $this->threadsOf($conversation), $customer))->handle();

        $this->assertCount(0, $this->sentEmails());
        $this->assertLoggedNotSent('the customer has no email address');
    }

    /**
     * The customer may have been deleted since the reply was queued: the
     * job looks the recipient up by email address.
     */
    public function testRecipientIsLookedUpWhenNoCustomerIsGiven()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), null))->handle();

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    // Retries.

    public function testThirdAttemptShowsTheErrorAndRetriesInAnHour()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $conversation->setStatus(Conversation::STATUS_CLOSED);
        $conversation->save();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection could not be established'));
        $job = (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->withFakeQueueInteractions();
        $job->job->attempts = 3;

        try {
            $job->handle();
            $this->fail('The job did not throw.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            $this->assertSame('Connection could not be established', $e->getMessage());
        }

        $job->assertReleased(3600);
        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_SEND_INTERMEDIATE_ERROR, $reply->send_status);
        $this->assertSame('Connection could not be established', $reply->getSendStatusData()['msg']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->fresh()->status, 'Reopened.');
        $this->assertSame(0, SendLog::where('thread_id', $reply->id)->count(), 'Only the first attempt is logged.');
    }

    public function testSecondAttemptWaitsAnHourWithoutShowingTheError()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection could not be established'));
        $job = (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->withFakeQueueInteractions();
        $job->job->attempts = 2;

        try {
            $job->handle();
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
        }

        $job->assertReleased(3600);
        $this->assertNull($reply->fresh()->send_status);
    }

    public function testGivingUpWithoutAReplyIsOnlyLogged()
    {
        [$conversation, $reply] = $this->conversationWithQueuedReply();

        (new SendReplyToCustomer($conversation, collect(), $conversation->customer))->failed(new \Exception('Gave up'));

        $this->assertNull($reply->fresh()->send_status);
        $this->assertSame(0, SendLog::count());
        $this->assertSame(1, \App\ActivityLog::where('description', \App\ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_TO_CUSTOMER)->count());
    }

    // IMAP Sent folder.

    /**
     * A mailbox that saves sent replies to "Sent" on the given IMAP server.
     */
    protected function useImapSentFolder($port)
    {
        $this->mailbox->fill([
            'in_protocol' => 1, 'in_server' => '127.0.0.1', 'in_port' => $port, 'in_username' => 'u', 'in_password' => 'p',
            'in_encryption' => \App\Mailbox::IN_ENCRYPTION_NONE, 'imap_sent_folder' => 'Sent',
        ])->save();
    }

    /**
     * An IMAP server with INBOX and Sent that accepts APPEND. It prints its
     * port, then "APPEND <folder> <base64 message>" for each message appended.
     * $append and $list: how it answers those (OK, NO or BAD).
     */
    protected function startImapServer($append = 'OK', $list = 'OK')
    {
        $code = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
$client = @stream_socket_accept($server, 30);
if (!$client) { exit(1); }
fwrite($client, "* OK [CAPABILITY IMAP4rev1] ready\r\n");
while (($line = fgets($client)) !== false) {
    [$tag, $command] = array_pad(explode(' ', trim($line), 3), 2, '');
    $command = strtoupper($command);
    if ($command == 'APPEND' && preg_match('/^\S+ APPEND "?([^" ]+)"? .*\{(\d+)\}\r\n$/', $line, $m)) {
        fwrite($client, "+ Ready\r\n");
        $message = '';
        while (strlen($message) < (int) $m[2]) {
            $message .= fread($client, (int) $m[2] - strlen($message));
        }
        fgets($client);
        echo 'APPEND '.$m[1].' '.base64_encode($message)."\n";
        fwrite($client, "$tag {$argv[1]} APPEND completed\r\n");
        continue;
    }
    switch ($command) {
        case 'LIST':
            $response = $argv[2] == 'OK'
                ? "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n* LIST (\\HasNoChildren \\Sent) \"/\" \"Sent\"\r\n$tag OK LIST completed\r\n"
                : "$tag {$argv[2]} LIST failed\r\n";
            break;
        case 'LOGOUT':
            fwrite($client, "* BYE\r\n$tag OK LOGOUT completed\r\n");
            break 2;
        default:
            $response = "$tag OK $command completed\r\n";
    }
    fwrite($client, $response);
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, $append, $list], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        return [$process, $pipes, (int) fgets($pipes[1])];
    }

    public function testSentReplyIsSavedToTheImapSentFolder()
    {
        [$process, $pipes, $port] = $this->startImapServer();
        try {
            $this->useImapSentFolder($port);
            [$conversation, $reply] = $this->conversationWithQueuedReply();

            (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();
            gc_collect_cycles();
        } finally {
            proc_terminate($process);
            $output = stream_get_contents($pipes[1]);
            proc_close($process);
        }

        $this->assertMatchesRegularExpression('/^APPEND Sent (\S+)$/m', $output);
        preg_match('/^APPEND Sent (\S+)$/m', $output, $m);
        $saved = base64_decode($m[1]);
        $this->assertStringContainsString('Message-ID: <'.$reply->getMessageId().'>', $saved);
        $this->assertStringContainsString('Our answer', quoted_printable_decode($saved));
        $this->assertSame('', \MailHelper::$smtp_mime_message, 'The saved message is forgotten.');
    }

    /**
     * Run the job for a queued reply against the IMAP server; returns what
     * was logged as errors.
     */
    protected function sendWithImapServer($append, $list)
    {
        [$process, $pipes, $port] = $this->startImapServer($append, $list);
        $errors = [];
        \Log::listen(function ($message) use (&$errors) {
            if ($message->level == 'error') {
                $errors[] = $message->message;
            }
        });
        try {
            $this->useImapSentFolder($port);
            [$conversation, $reply] = $this->conversationWithQueuedReply();

            (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();
            gc_collect_cycles();
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));

        return $errors;
    }

    public function testRejectedSaveToTheImapSentFolderIsLogged()
    {
        $errors = $this->sendWithImapServer('NO', 'OK');

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('[Shop Support » Connection Settings » Fetching Emails » IMAP Folder To Save Outgoing Replies] '
            .'Could not save outgoing reply to the IMAP folder: ', $errors[0]);
    }

    public function testFolderListErrorIsLogged()
    {
        $errors = $this->sendWithImapServer('OK', 'BAD');

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('[Shop Support » Connection Settings » Fetching Emails » IMAP Folder To Save Outgoing Replies] '
            .'Could not save outgoing reply to the IMAP folder, IMAP folder not found: Sent - ', $errors[0]);
    }

    public function testMissingImapSentFolderIsLogged()
    {
        $server = proc_open([PHP_BINARY, base_path('tests/Support/fake-imap-server.php')], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        \Log::spy();
        try {
            $this->useImapSentFolder($port);
            [$conversation, $reply] = $this->conversationWithQueuedReply();

            (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();
            gc_collect_cycles();
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return $message == '[Shop Support » Connection Settings » Fetching Emails » IMAP Folder To Save Outgoing Replies] '
                .'Could not save outgoing reply to the IMAP folder (check folder name and make sure IMAP folder does not have spaces - folders with spaces do not work): Sent';
        })->once();
    }

    public function testUnreachableImapServerIsLoggedAndTheReplyStillSent()
    {
        $this->useImapSentFolder(1);
        [$conversation, $reply] = $this->conversationWithQueuedReply();
        \Log::spy();

        (new SendReplyToCustomer($conversation, $this->threadsOf($conversation), $conversation->customer))->handle();

        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $reply->fresh()->send_status);
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_starts_with($message, '[Shop Support » Connection Settings » Fetching Emails » IMAP Folder To Save Outgoing Replies] '
                .'Could not save outgoing reply to the IMAP folder: Sent - ');
        })->once();
    }
}
