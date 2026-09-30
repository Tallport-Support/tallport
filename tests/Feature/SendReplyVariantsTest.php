<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * The less common ways of sending from the reply editor: Cc and Bcc,
 * several recipients (one conversation, or one each), forwarding, phone
 * conversations, and changing status or assignee while replying.
 */
class SendReplyVariantsTest extends FeatureTestCase
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

    protected function send(array $data)
    {
        return $this->postAjax($this->agent, '/conversation/ajax', array_merge([
            'action'     => 'send_reply',
            'mailbox_id' => $this->mailbox->id,
        ], $data))->json();
    }

    protected function assertSuccess(array $response)
    {
        $this->assertSame('success', $response['status'], json_encode($response));
    }

    public function testReplyWithCcAndBcc()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->send([
            'conversation_id' => $conversation->id,
            'body'            => '<p>Looping in accounting.</p>',
            'cc'              => ['accounting@customer.example.org'],
            'bcc'             => ['archive@example.org'],
        ]));

        $email = $this->sentEmailsTo('casey@customer.example.org')[0];
        $this->assertSame(['casey@customer.example.org'], array_keys($email->getTo()));
        $this->assertSame(['accounting@customer.example.org'], array_keys($email->getCc()));
        $this->assertSame(['archive@example.org'], array_keys($email->getBcc()));

        $reply = $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first();
        $this->assertSame(['accounting@customer.example.org'], $reply->getCcArray());
        $this->assertSame(['archive@example.org'], $reply->getBccArray());
    }

    public function testReplyValidation()
    {
        $this->knownBug('C11');

        $conversation = $this->receiveConversation();

        $response = $this->send(['conversation_id' => $conversation->id, 'body' => '']);

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('body field is required', $response['msg']);
        $this->assertSame(1, $conversation->threads()->count());
    }

    public function testReplyAndCloseAndAssignInOneGo()
    {
        $conversation = $this->receiveConversation();

        $this->assertSuccess($this->send([
            'conversation_id' => $conversation->id,
            'body'            => '<p>All done.</p>',
            'status'          => Conversation::STATUS_CLOSED,
            'user_id'         => $this->agent->id,
        ]));

        $conversation->refresh();
        $this->assertEquals(Conversation::STATUS_CLOSED, $conversation->status);
        $this->assertEquals($this->agent->id, $conversation->user_id);
        $this->assertCount(1, $this->sentEmailsTo('casey@customer.example.org'));
    }

    public function testNewConversationToSeveralRecipientsKeepsOneConversation()
    {
        $this->assertSuccess($this->send([
            'conversation_id' => '',
            'is_create'       => 1,
            'to'              => ['first@customer.example.org', 'second@customer.example.org'],
            'subject'         => 'Team update',
            'body'            => '<p>Hello both.</p>',
        ]));

        $this->assertSame(1, Conversation::where('mailbox_id', $this->mailbox->id)->count());
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertSame('first@customer.example.org', $conversation->customer_email);

        // One email to all of them (see C13 in KNOWN_BUGS.md: all end up in To).
        $emails = $this->sentEmailsTo('first@customer.example.org');
        $this->assertCount(1, $emails);
        $this->assertSame($emails, $this->sentEmailsTo('second@customer.example.org'));
    }

    public function testNewConversationSentSeparatelyToEachRecipient()
    {
        $this->assertSuccess($this->send([
            'conversation_id'        => '',
            'is_create'              => 1,
            'multiple_conversations' => 1,
            'to'                     => ['first@customer.example.org', 'second@customer.example.org'],
            'subject'                => 'Survey',
            'body'                   => '<p>Please answer our survey.</p>',
        ]));

        $conversations = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id')->get();
        $this->assertEquals(['first@customer.example.org', 'second@customer.example.org'], $conversations->pluck('customer_email')->all());
        $this->assertNotEquals($conversations[0]->customer_id, $conversations[1]->customer_id);
        $this->assertNotNull(Customer::getByEmail('second@customer.example.org'));

        foreach (['first@customer.example.org', 'second@customer.example.org'] as $recipient) {
            $emails = $this->sentEmailsTo($recipient);
            $this->assertCount(1, $emails, "$recipient should get one email.");
            $this->assertSame([$recipient], array_keys($emails[0]->getTo()), 'Recipients must not see each other.');
            $this->assertEmpty($emails[0]->getCc());
        }
    }

    public function testForward()
    {
        $conversation = $this->receiveConversation(['body' => 'The package arrived damaged.']);

        $response = $this->send([
            'conversation_id' => $conversation->id,
            'subtype'         => Thread::SUBTYPE_FORWARD,
            'to_email'        => ['warehouse@partner.example.org'],
            'body'            => '<p>Please check this shipment.</p>',
        ]);
        $this->assertSuccess($response);

        $emails = $this->sentEmailsTo('warehouse@partner.example.org');
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('Please check this shipment.', $emails[0]->getBody());
        $this->assertStringContainsString('The package arrived damaged.', $emails[0]->getBody(), 'The forwarded message is included.');
        $this->assertCount(0, $this->sentEmailsTo('casey@customer.example.org'), 'The customer is not emailed.');

        $forward = $conversation->threads()->where('subtype', Thread::SUBTYPE_FORWARD)->first();
        $this->assertNotNull($forward, 'The original conversation records the forward.');
    }

    public function testForwardNeedsRecipient()
    {
        $conversation = $this->receiveConversation();

        $response = $this->send([
            'conversation_id' => $conversation->id,
            'subtype'         => Thread::SUBTYPE_FORWARD,
            'to_email'        => [''],
            'body'            => '<p>Please check.</p>',
        ]);

        $this->assertSame('Please specify a recipient.', $response['msg']);
    }

    public function testPhoneConversation()
    {
        $response = $this->send([
            'conversation_id' => '',
            'is_create'       => 1,
            'type'            => Conversation::TYPE_PHONE,
            'name'            => 'Pat Caller',
            'phone'           => '+31 20 123 4567',
            'subject'         => 'Called about delivery',
            'body'            => '<p>Customer called, delivery moved to Friday.</p>',
        ]);
        $this->assertSuccess($response);

        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->assertEquals(Conversation::TYPE_PHONE, $conversation->type);
        $this->assertSame('Called about delivery', $conversation->subject);
        $customer = Customer::find($conversation->customer_id);
        $this->assertSame('Pat', $customer->first_name);
        $this->assertStringContainsString('123', json_encode($customer->getPhones()));
        $this->assertCount(0, $this->sentEmails(), 'Phone conversations are not emailed.');
    }

    public function testPhoneConversationNeedsName()
    {
        $response = $this->send([
            'conversation_id' => '',
            'is_create'       => 1,
            'type'            => Conversation::TYPE_PHONE,
            'subject'         => 'Called',
            'body'            => '<p>Notes</p>',
        ]);

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('name field is required', $response['msg']);
    }
}
