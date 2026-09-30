<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\SendLog;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * The core help desk loop: a customer emails the mailbox, an agent replies
 * from the UI, the customer gets the reply by email and answers it, and the
 * answer lands in the same conversation.
 */
class EmailConversationLoopTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    protected function receiveCustomerEmail(array $options = [])
    {
        $raw = $this->makeEmail(array_merge([
            'from' => 'Casey Customer <casey@customer.example.org>',
            'to'   => $this->mailbox->email,
        ], $options));

        return $this->receiveEmail($this->mailbox, $raw);
    }

    public function testCustomerEmailStartsConversation()
    {
        $this->receiveCustomerEmail([
            'subject'    => 'Question about my order',
            'message_id' => 'first@customer.example.org',
            'body'       => "Hello,\n\nWhere is my order?",
        ]);

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertNotNull($conversation, 'No conversation was created.');
        $this->assertSame('Question about my order', $conversation->subject);
        $this->assertSame('casey@customer.example.org', $conversation->customer_email);
        $this->assertEquals(Conversation::TYPE_EMAIL, $conversation->type);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->status);
        $this->assertEquals(Conversation::STATE_PUBLISHED, $conversation->state);
        $this->assertNull($conversation->user_id, 'A new conversation should be unassigned.');

        $customer = Customer::find($conversation->customer_id);
        $this->assertSame('Casey', $customer->first_name);
        $this->assertSame('Customer', $customer->last_name);

        $threads = $conversation->threads()->get();
        $this->assertCount(1, $threads);
        $thread = $threads->first();
        $this->assertEquals(Thread::TYPE_CUSTOMER, $thread->type);
        $this->assertSame('first@customer.example.org', $thread->message_id);
        $this->assertStringContainsString('Where is my order?', $thread->body);
    }

    protected function replyAsAgent(Conversation $conversation, $body, array $data = [])
    {
        return $this->postAjax($this->agent, '/conversation/ajax', array_merge([
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => $body,
        ], $data));
    }

    public function testAgentReplyIsEmailedToCustomer()
    {
        $this->receiveCustomerEmail([
            'subject'    => 'Question about my order',
            'message_id' => 'first@customer.example.org',
        ]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $response = $this->replyAsAgent($conversation, '<p>Your order ships tomorrow.</p>');

        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertNotNull($reply, 'The reply was not saved.');
        $this->assertEquals($this->agent->id, $reply->created_by_user_id);
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $reply->send_status);

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(1, $emails, 'The customer should get exactly one email.');
        $email = $emails[0];

        $this->assertSame([$this->mailbox->email], array_keys($email->getFrom()));
        $this->assertSame('Re: Question about my order', $email->getSubject());
        $this->assertStringContainsString('Your order ships tomorrow.', $email->getBody());

        // Threading: the reply has FreeScout's own Message-ID and points back
        // at the customer's email, so mail clients group them.
        $domain = explode('@', $this->mailbox->email)[1];
        $expected_message_id = 'FS_reply-'.$reply->id.'-'.\MailHelper::getMessageIdHash($reply->id).'@'.$domain;
        $this->assertSame($expected_message_id, $email->getId());
        $this->assertSame('<first@customer.example.org>', $email->getHeaders()->get('In-Reply-To')->getFieldBody());
        $this->assertStringContainsString('<first@customer.example.org>', $email->getHeaders()->get('References')->getFieldBody());
        $this->assertSame('customer.message', $email->getHeaders()->get('X-FreeScout-Mail-Type')->getFieldBody());

        $log = SendLog::where('thread_id', $reply->id)->first();
        $this->assertNotNull($log, 'The send was not logged.');
        $this->assertSame($expected_message_id, $log->message_id);
        $this->assertSame('casey@customer.example.org', $log->email);
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $log->status);
    }

    public function testCustomerAnswerToReplyJoinsConversation()
    {
        $this->receiveCustomerEmail([
            'subject'    => 'Question about my order',
            'message_id' => 'first@customer.example.org',
        ]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->replyAsAgent($conversation, '<p>Your order ships tomorrow.</p>');
        $reply_email = $this->sentEmailsTo('casey@customer.example.org')[0];

        // The customer answers above the quoted reply, like a mail client does.
        $this->receiveCustomerEmail([
            'subject'     => 'Re: Question about my order',
            'message_id'  => 'second@customer.example.org',
            'in_reply_to' => $reply_email->getId(),
            'references'  => ['first@customer.example.org', $reply_email->getId()],
            'html'        => true,
            'body'        => '<div>Great, thank you!</div>'
                .'<blockquote>'.$reply_email->getBody().'</blockquote>',
        ]);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count(), 'The answer started a new conversation.');

        $answer = Thread::where('message_id', 'second@customer.example.org')->first();
        $this->assertNotNull($answer, 'The answer was not saved.');
        $this->assertEquals($conversation->id, $answer->conversation_id);
        $this->assertEquals(Thread::TYPE_CUSTOMER, $answer->type);
        $this->assertStringContainsString('Great, thank you!', $answer->body);
        $this->assertStringNotContainsString('Your order ships tomorrow.', $answer->body, 'The quoted reply was not separated from the answer.');

        $conversation->refresh();
        $this->assertEquals(Conversation::STATUS_ACTIVE, $conversation->status);
        $this->assertSame(3, $conversation->threads()->count());
    }

    /**
     * Mail clients differ in which threading headers they send; each one on
     * its own must be enough to find the conversation.
     */
    public function testAnswerIsMatchedByInReplyToOrReferencesAlone()
    {
        $this->receiveCustomerEmail(['message_id' => 'first@customer.example.org']);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->replyAsAgent($conversation, '<p>Your order ships tomorrow.</p>');
        $reply_id = $this->sentEmailsTo('casey@customer.example.org')[0]->getId();

        $this->receiveCustomerEmail([
            'message_id'  => 'by-in-reply-to@customer.example.org',
            'in_reply_to' => $reply_id,
            'body'        => 'Answer with In-Reply-To only',
        ]);
        $this->receiveCustomerEmail([
            'message_id' => 'by-references@customer.example.org',
            'references' => [$reply_id],
            'body'       => 'Answer with References only',
        ]);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertEquals($conversation->id, Thread::where('message_id', 'by-in-reply-to@customer.example.org')->value('conversation_id'));
        $this->assertEquals($conversation->id, Thread::where('message_id', 'by-references@customer.example.org')->value('conversation_id'));
    }

    public function testEmailReplyingToUnknownMessageStartsNewConversation()
    {
        $this->receiveCustomerEmail([
            'subject'     => 'Re: Something from elsewhere',
            'message_id'  => 'stray@customer.example.org',
            'in_reply_to' => 'never-seen@elsewhere.example.org',
        ]);

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Re: Something from elsewhere', $conversation->subject);
        $this->assertSame(1, $conversation->threads()->count());
    }

    public function testNoteIsNotEmailed()
    {
        $this->receiveCustomerEmail();
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $response = $this->replyAsAgent($conversation, '<p>Internal: check the warehouse.</p>', ['is_note' => 1]);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $note = $conversation->threads()->where('type', Thread::TYPE_NOTE)->first();
        $this->assertNotNull($note, 'The note was not saved.');
        $this->assertStringContainsString('check the warehouse', $note->body);
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testUserWithoutMailboxAccessCannotReply()
    {
        $this->receiveCustomerEmail();
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $outsider = $this->createUser();

        $response = $this->postAjax($outsider, '/conversation/ajax', [
            'action'          => 'send_reply',
            'mailbox_id'      => $this->mailbox->id,
            'conversation_id' => $conversation->id,
            'body'            => '<p>Hi!</p>',
        ]);

        $this->assertSame('Not enough permissions', $response->json()['msg']);
        $this->assertSame(1, $conversation->threads()->count());
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'));
    }

    /**
     * Customer emails, agent replies from the UI, customer answers.
     *
     * @return Conversation
     */
    protected function startConversationWithAnswer()
    {
        $this->receiveCustomerEmail([
            'subject'    => 'Question about my order',
            'message_id' => 'first@customer.example.org',
        ]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->replyAsAgent($conversation, '<p>Your order ships tomorrow.</p>');
        $reply_email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->receiveCustomerEmail([
            'subject'     => 'Re: Question about my order',
            'message_id'  => 'second@customer.example.org',
            'in_reply_to' => $reply_email->getId(),
            'body'        => 'Can you send it by express instead?',
        ]);

        return $conversation;
    }

    public function testAgentIsNotifiedWhenCustomerAnswers()
    {
        $conversation = $this->startConversationWithAnswer();

        $notifications = $this->sentEmailsTo($this->agent->email);
        $this->assertCount(1, $notifications, 'The agent should get one notification.');
        $notification = $notifications[0];

        $this->assertSame('user.notification', $notification->getHeaders()->get('X-FreeScout-Mail-Type')->getFieldBody());
        $this->assertSame('[#'.$conversation->number.'] Question about my order', $notification->getSubject());
        $this->assertStringContainsString('Can you send it by express instead?', $notification->getBody());

        // Answering the notification by email must be recognised as the
        // agent's reply, so its Message-ID encodes the thread and the user.
        $answer = Thread::where('message_id', 'second@customer.example.org')->first();
        $domain = explode('@', $this->mailbox->email)[1];
        $this->assertSame(
            'FS_notify-'.$answer->id.'-'.$this->agent->id.'-'.\MailHelper::getMessageIdHash($answer->id).'@'.$domain,
            $notification->getId()
        );
    }

    public function testNewConversationDoesNotNotifyAgentByDefault()
    {
        $this->receiveCustomerEmail();

        $this->assertCount(0, $this->sentEmailsTo($this->agent->email));
    }

    public function testAgentAnsweringNotificationByEmailRepliesToCustomer()
    {
        $conversation = $this->startConversationWithAnswer();
        $notification = $this->sentEmailsTo($this->agent->email)[0];
        $this->captured_mail->flush();

        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from'        => $this->agent->email,
            'to'          => $this->mailbox->email,
            'subject'     => 'Re: '.$notification->getSubject(),
            'message_id'  => 'agent-answer@agent.example.org',
            'in_reply_to' => $notification->getId(),
            'body'        => 'Yes, express shipping is on its way.',
        ]));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->orderBy('id', 'desc')->first();
        $this->assertStringContainsString('express shipping is on its way', $reply->body);
        $this->assertEquals($this->agent->id, $reply->created_by_user_id);
        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(1, $emails, 'The agent\'s emailed answer should be sent to the customer.');
        $this->assertStringContainsString('express shipping is on its way', $emails[0]->getBody());
    }

    public function testAutoReplyIsSentForNewConversation()
    {
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = 'We will answer within a day.';
        $this->mailbox->save();

        $this->receiveCustomerEmail(['message_id' => 'first@customer.example.org']);

        $emails = $this->sentEmailsTo('casey@customer.example.org');
        $this->assertCount(1, $emails);
        $this->assertSame('We got your message', $emails[0]->getSubject());
        $this->assertStringContainsString('We will answer within a day.', $emails[0]->getBody());
        $this->assertSame('<first@customer.example.org>', $emails[0]->getHeaders()->get('In-Reply-To')->getFieldBody());

        $thread = Thread::where('message_id', 'first@customer.example.org')->first();
        $domain = explode('@', $this->mailbox->email)[1];
        $this->assertSame('FS_autoreply-'.$thread->id.'-'.\MailHelper::getMessageIdHash($thread->id).'@'.$domain, $emails[0]->getId());
    }

    public function testAutoReplyIsNotSentForAnswers()
    {
        $this->mailbox->auto_reply_enabled = true;
        $this->mailbox->auto_reply_subject = 'We got your message';
        $this->mailbox->auto_reply_message = 'We will answer within a day.';
        $this->mailbox->save();

        $this->startConversationWithAnswer();

        $subjects = array_map(function ($email) {
            return $email->getSubject();
        }, $this->sentEmailsTo('casey@customer.example.org'));
        $this->assertSame(['We got your message', 'Re: Question about my order'], $subjects);
    }

    public function testAgentCanOpenConversation()
    {
        $this->receiveCustomerEmail([
            'subject' => 'Question about my order',
            'body'    => 'Where is my order?',
        ]);
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();

        $response = $this->actingAs($this->agent)->get('/conversation/'.$conversation->id.'?folder_id='.$conversation->folder_id);

        $response->assertStatus(200);
        $response->assertSee('Question about my order');
        $response->assertSee('Where is my order?');
    }

    public function testFetchingTheSameEmailAgainDoesNothing()
    {
        // The exact same email: a rebuilt one could get a different Date
        // header, which counts as a new email reusing the Message-ID.
        $options = ['message_id' => 'once@customer.example.org', 'date' => 'Wed, 30 Sep 2026 10:00:00 +0000'];
        $this->receiveCustomerEmail($options);
        $this->receiveCustomerEmail($options);

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $this->assertSame(1, Thread::where('message_id', 'once@customer.example.org')->count());
    }
}
