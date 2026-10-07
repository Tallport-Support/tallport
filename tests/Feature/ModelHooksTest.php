<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\CustomerChannel;
use App\SendLog;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * What happens around saving models: observers, the hooks modules listen
 * to, and the small models that record sending and channels.
 */
class ModelHooksTest extends FeatureTestCase
{
    public function testDeletingAnAttachmentTellsModules()
    {
        $attachment = Attachment::create('notes.txt', 'text/plain', null, 'Notes', null);
        $deleted = [];
        \Eventy::addAction('attachment.deleted', function ($attachment) use (&$deleted) {
            $deleted[] = $attachment->id;
        });

        $attachment->delete();

        $this->assertSame([$attachment->id], $deleted);
    }

    public function testUsersWithTheOldRobotAddressesAreRobots()
    {
        $robot = $this->createUser(['email' => 'fsworkflow@example.org']);
        $person = $this->createUser(['email' => 'fs-not-a-robot@example.com']);

        $this->assertEquals(User::TYPE_ROBOT, $robot->fresh()->type);
        $this->assertNotEquals(User::TYPE_ROBOT, $person->fresh()->type);
    }

    public function testAThreadWithoutAConversationChangesNoConversation()
    {
        $thread = new Thread();
        $thread->conversation_id = 999999;
        $thread->type = Thread::TYPE_NOTE;
        $thread->body = 'Lost';
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_WEB;
        $thread->save();

        $this->assertNotNull($thread->fresh());
        $this->assertNull(Conversation::find(999999));
    }

    public function testTheMailDateCanBeTheTimeOfAFetchedMessage()
    {
        config(['app.use_mail_date_on_fetching' => true]);
        $mailbox = $this->createMailbox();
        $date = now()->subDays(3)->startOfMinute();

        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org',
            'to'   => $mailbox->email,
            'date' => $date->format('r'),
        ]));

        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $this->assertSame($date->format('Y-m-d H:i:s'), $conversation->threads()->first()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame($date->format('Y-m-d H:i:s'), $conversation->last_reply_at->format('Y-m-d H:i:s'));
        $this->assertSame($date->format('Y-m-d H:i:s'), \Carbon\Carbon::parse($conversation->last_activity_at)->format('Y-m-d H:i:s'));
    }

    public function testSendLogKeepsTheSmtpQueueIdAndThread()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $thread = Conversation::where('mailbox_id', $mailbox->id)->first()->threads()->first();

        $this->assertTrue(SendLog::log($thread->id, 'msg@example.org', 'casey@customer.example.org', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_ACCEPTED, null, null, null, 'QUEUE123'));

        $log = SendLog::where('message_id', 'msg@example.org')->first();
        $this->assertSame('QUEUE123', $log->smtp_queue_id);
        $this->assertEquals($thread->id, $log->thread->id);
        $this->assertSame(['Accepted for delivery', 'SMTP ID: QUEUE123'], $log->getStatusForPeople());
    }

    public function testACustomerHasOneRecordPerChannelAddress()
    {
        $customer = $this->createCustomer();
        $other = $this->createCustomer();

        $this->assertNotNull(CustomerChannel::create($customer->id, 1, 'chat-1'));
        $this->assertNull(CustomerChannel::create($other->id, 1, 'chat-1'));
        $this->assertSame(1, CustomerChannel::where('channel', 1)->where('channel_id', 'chat-1')->count());
    }
}
