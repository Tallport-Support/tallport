<?php

namespace Tests\Feature;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Incoming email beyond the normal loop: bounces, replies to notifications
 * that must be refused, agents forwarding a customer's email in (@fwd),
 * one email to several mailboxes, and auto-responders.
 */
class IncomingEmailEdgeCasesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['email' => 'agent@example.org']);
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveFromCustomer(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Customer email, agent reply (UI), customer answer: the agent gets a
     * notification, returned here.
     */
    protected function notificationForAgent()
    {
        $conversation = $this->receiveFromCustomer(['message_id' => 'first@customer.example.org']);
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Answer</p>',
        ]);
        $reply_id = $this->sentEmailsTo('casey@customer.example.org')[0]->getId();
        $this->receiveFromCustomer(['message_id' => 'second@customer.example.org', 'in_reply_to' => $reply_id, 'body' => 'Follow-up question']);
        $notification = $this->sentEmailsTo($this->agent->email)[0];
        $this->captured_mail->flush();

        return [$conversation, $notification];
    }

    protected function countThreads(Conversation $conversation)
    {
        return $conversation->threads()->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])->count();
    }

    // Bounces.

    public function testBounceMarksReplyAsUndelivered()
    {
        $conversation = $this->receiveFromCustomer();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Answer</p>',
        ]);
        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $reply_id = $this->sentEmailsTo('casey@customer.example.org')[0]->getId();
        $this->captured_mail->flush();

        $boundary = 'bounce1';
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'       => 'Mail Delivery System <MAILER-DAEMON@mx.customer.example.org>',
            'to'         => $this->mailbox->email,
            'subject'    => 'Undelivered Mail Returned to Sender',
            'message_id' => 'bounce@mx.customer.example.org',
            'headers'    => ['Content-Type' => 'multipart/report; report-type=delivery-status; boundary="'.$boundary.'"'],
            'body'       => "--$boundary\nContent-Type: text/plain\n\nThe mail system could not deliver your message.\n"
                ."--$boundary\nContent-Type: message/delivery-status\n\nFinal-Recipient: rfc822; casey@customer.example.org\nAction: failed\nStatus: 5.1.1\n"
                ."--$boundary\nContent-Type: text/rfc822-headers\n\nMessage-ID: <$reply_id>\nTo: casey@customer.example.org\nSubject: Re: Question about my order\n"
                ."--$boundary--",
        ]));

        $reply->refresh();
        $this->assertEquals(SendLog::STATUS_DELIVERY_ERROR, $reply->send_status);
        $this->assertTrue(SendLog::where('thread_id', $reply->id)->where('status', SendLog::STATUS_DELIVERY_ERROR)->exists());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'No auto reply or anything to the bounce.');
    }

    // Replies to notifications that must not be accepted.

    public function testNotificationReplyWithForgedHashIsDropped()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $threads_before = $this->countThreads($conversation);
        $forged = preg_replace('/-([a-z0-9]{16})@/', '-0000000000000000@', $notification->getId());

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: '.$notification->getSubject(),
            'in_reply_to' => $forged, 'body' => 'Forged reply',
        ]));

        $this->assertSame($threads_before, $this->countThreads($conversation));
        $this->assertSame(0, Thread::where('body', 'like', '%Forged reply%')->count());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testNotificationReplyFromWrongAddressIsRefused()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $threads_before = $this->countThreads($conversation);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'impostor@elsewhere.example.org', 'to' => $this->mailbox->email, 'subject' => 'Re: '.$notification->getSubject(),
            'in_reply_to' => $notification->getId(), 'body' => 'Reply from the wrong address',
        ]));

        $this->assertSame($threads_before, $this->countThreads($conversation));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'Nothing is sent to the customer.');
        $this->assertCount(1, $this->sentEmailsTo('impostor@elsewhere.example.org'), 'The sender is told the reply was not accepted.');
    }

    public function testNotificationReplyFromAgentWithoutAccessIsRefused()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $this->mailbox->users()->detach($this->agent->id);
        \Cache::flush();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: '.$notification->getSubject(),
            'in_reply_to' => $notification->getId(), 'body' => 'Reply after losing access',
        ]));

        $this->assertSame(0, Thread::where('body', 'like', '%losing access%')->count());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testAutoReplyToNotificationIsIgnored()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $threads_before = $this->countThreads($conversation);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Out of office',
            'in_reply_to' => $notification->getId(), 'body' => 'I am on holiday.',
            'headers' => ['Auto-Submitted' => 'auto-replied'],
        ]));

        $this->assertSame($threads_before, $this->countThreads($conversation));
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    /**
     * An agent who answered a notification by email and then emails again in
     * that same thread (replying to their own email) adds a note.
     */
    public function testAgentFollowingUpOnOwnEmailedReplyAddsNote()
    {
        [$conversation, $notification] = $this->notificationForAgent();
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: '.$notification->getSubject(),
            'message_id' => 'agent-answer@agent.example.org', 'in_reply_to' => $notification->getId(),
            'body' => 'First emailed answer.',
        ]));
        $this->captured_mail->flush();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: '.$notification->getSubject(),
            'in_reply_to' => 'agent-answer@agent.example.org', 'body' => 'Second emailed answer.',
        ]));

        $this->assertNoteFromAgent($conversation, 'Second emailed answer.');
    }

    /**
     * An agent answering by email in the thread of their own UI reply (e.g.
     * they were copied on it) adds a note; the customer stays the same.
     */
    public function testAgentAnsweringOwnUiReplyByEmailAddsNote()
    {
        $conversation = $this->receiveFromCustomer();
        $this->postAjax($this->agent, '/conversation/ajax', [
            'action' => 'send_reply', 'mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>First answer</p>',
        ]);
        $reply_id = $this->sentEmailsTo('casey@customer.example.org')[0]->getId();
        $this->captured_mail->flush();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: Question about my order',
            'in_reply_to' => $reply_id, 'body' => 'One more thing from the agent.',
        ]));

        $this->assertNoteFromAgent($conversation, 'One more thing from the agent.');
    }

    public function testAgentWhoIsTheConversationsCustomerRepliesAsCustomer()
    {
        $conversation = $this->receiveFromCustomer(['from' => $this->agent->email, 'message_id' => 'own@agent.example.org']);
        $this->assertSame($this->agent->email, $conversation->customer_email);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $this->agent->email, 'to' => $this->mailbox->email, 'subject' => 'Re: Question about my order',
            'in_reply_to' => 'own@agent.example.org', 'body' => 'Adding to my own question.',
        ]));

        $thread = Thread::where('body', 'like', '%Adding to my own question.%')->first();
        $this->assertEquals($conversation->id, $thread->conversation_id);
        $this->assertEquals(Thread::TYPE_CUSTOMER, $thread->type);
    }

    public function testEmailFromUserWithoutAccessStaysCustomerMessage()
    {
        $outsider = $this->createUser(['email' => 'outsider@example.org']);
        $conversation = $this->receiveFromCustomer(['message_id' => 'first@customer.example.org']);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => $outsider->email, 'to' => $this->mailbox->email, 'subject' => 'Re: Question about my order',
            'in_reply_to' => 'first@customer.example.org', 'body' => 'Outsider chiming in.',
        ]));

        $thread = Thread::where('body', 'like', '%Outsider chiming in.%')->first();
        $this->assertEquals(Thread::TYPE_CUSTOMER, $thread->type);
        $this->assertNull($thread->created_by_user_id);
    }

    protected function assertNoteFromAgent(Conversation $conversation, $body)
    {
        $thread = Thread::where('body', 'like', '%'.$body.'%')->first();
        $this->assertNotNull($thread, 'The email was dropped.');
        $this->assertEquals($conversation->id, $thread->conversation_id);
        $this->assertEquals(Thread::TYPE_NOTE, $thread->type);
        $this->assertEquals($this->agent->id, $thread->created_by_user_id);

        $customer_id = $conversation->customer_id;
        $conversation->refresh();
        $this->assertSame('casey@customer.example.org', $conversation->customer_email, 'The customer did not change.');
        $this->assertEquals($customer_id, $conversation->customer_id);
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'Nothing is sent to the customer.');

        $this->actingAs($this->agent)->get('/conversation/'.$conversation->id.'?folder_id='.$conversation->folder_id)->assertStatus(200)->assertSee($body);
    }

    // Agents forwarding customer email in.

    public function testAgentForwardsCustomerEmailWithFwdCommand()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'    => $this->agent->email,
            'to'      => $this->mailbox->email,
            'subject' => 'Fwd: Broken zipper',
            'body'    => "@fwd\n\n---------- Forwarded message ---------\nFrom: Robin Buyer <robin@customer.example.org>\nDate: Mon, 28 Sep 2026 10:00\nSubject: Broken zipper\nTo: {$this->agent->email}\n\nThe zipper on my jacket broke.",
        ]));

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('robin@customer.example.org', $conversation->customer_email, 'The conversation is with the original sender.');
        $this->assertSame('Broken zipper', $conversation->subject);
        $thread = $conversation->threads()->first();
        $this->assertEquals(Thread::TYPE_CUSTOMER, $thread->type);
        $this->assertStringNotContainsString('@fwd', $thread->body);
    }

    // Several mailboxes.

    public function testEmailToTwoMailboxesStartsConversationInEach()
    {
        $incoming = ['in_protocol' => 1, 'in_server' => 'imap.example.org', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p'];
        $this->mailbox->fill($incoming)->save();
        $sales = $this->createMailbox([$this->agent], ['name' => 'Sales'] + $incoming);

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'       => 'casey@customer.example.org',
            'to'         => $this->mailbox->email,
            'cc'         => $sales->email,
            'subject'    => 'Question for support and sales',
            'message_id' => 'both@customer.example.org',
        ]), [$this->mailbox, $sales]);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(1, Conversation::where('mailbox_id', $sales->id)->count());
        $this->assertSame('Question for support and sales', Conversation::where('mailbox_id', $sales->id)->value('subject'));
    }

    // Auto-responders.

    public function testNoAutoReplyToAutoResponder()
    {
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = 'Thanks!';
        $this->mailbox->save();

        $this->receiveFromCustomer([
            'subject' => 'Automatic reply: Out of office',
            'headers' => ['Auto-Submitted' => 'auto-replied'],
        ]);

        $this->assertNotNull(Conversation::where('mailbox_id', $this->mailbox->id)->first(), 'The email is still saved.');
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'No auto-reply loop.');
    }

    public function testCustomerNameFromEncodedHeader()
    {
        $conversation = $this->receiveFromCustomer([
            'from'    => '=?UTF-8?B?'.base64_encode('Zoë Müller').'?= <zoe@customer.example.org>',
            'subject' => '=?UTF-8?Q?Gr=C3=BC=C3=9Fe?=',
        ]);

        $this->assertSame('Grüße', $conversation->subject);
        $customer = $conversation->customer;
        $this->assertSame('Zoë', $customer->first_name);
        $this->assertSame('Müller', $customer->last_name);
    }
}
