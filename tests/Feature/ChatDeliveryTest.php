<?php

namespace Tests\Feature;

use App\Conversation;
use App\Jobs\SendReplyToCustomer;
use App\Misc\ChatDelivery;
use App\SendLog;
use App\Telegram\Telegram;
use App\Thread;
use Tests\FeatureTestCase;

class ChatDeliveryTest extends FeatureTestCase
{
    protected function conversation($channel = Telegram::CHANNEL)
    {
        return Conversation::create([
            'type' => Conversation::TYPE_EMAIL,
            'subject' => 'Help',
            'mailbox_id' => $this->createMailbox()->id,
            'source_type' => Conversation::SOURCE_TYPE_API,
            'channel' => $channel,
        ], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Help']], $this->createCustomer())['conversation'];
    }

    public function testOnlyPublishedAgentRepliesAreEligibleForDelivery()
    {
        $conversation = $this->conversation();
        $data = ['source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB];
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Reply', $data);
        $note = Thread::create($conversation, Thread::TYPE_NOTE, 'Internal note', $data);
        $draft = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Draft', $data);
        $draft->state = Thread::STATE_DRAFT;
        $draft->save();

        $this->assertSame($reply->id, ChatDelivery::findReply($reply->id)->id);
        $this->assertNull(ChatDelivery::findReply($note->id));
        $this->assertNull(ChatDelivery::findReply($draft->id));
        $this->assertNull(ChatDelivery::findReply($conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->value('id')));
        $this->assertNull(ChatDelivery::findReply(0));
    }

    public function testStatusUpdatesPreservePartialDeliveryProgress()
    {
        $reply = Thread::create($this->conversation(), Thread::TYPE_MESSAGE, 'Reply', [
            'source_via' => Thread::PERSON_USER,
            'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);
        $reply->updateSendStatusData(['telegram_sent' => ['text0'], 'telegram_messages' => [71]]);
        $reply->save();

        ChatDelivery::recordStatus($reply, SendLog::STATUS_SEND_INTERMEDIATE_ERROR, ['msg' => 'Try again']);

        $reply->refresh();
        $this->assertSame(SendLog::STATUS_SEND_INTERMEDIATE_ERROR, (int) $reply->send_status);
        $this->assertSame(['telegram_sent' => ['text0'], 'telegram_messages' => [71], 'msg' => 'Try again'], $reply->getSendStatusData());
    }

    /**
     * @dataProvider conversationStates
     */
    public function testReopeningUsesCurrentStateAndPreservesSpamAndUnpublishedConversations($status, $state, $expected)
    {
        $conversation = $this->conversation();
        $current = $conversation->fresh();
        $current->status = $status;
        $current->state = $state;
        $current->save();

        // The email job's existing entry point delegates to the shared operation.
        SendReplyToCustomer::reopenConversation($conversation);

        $this->assertSame($expected, (int) $current->fresh()->status);
        $this->assertSame($state, (int) $current->fresh()->state);
    }

    public static function conversationStates()
    {
        return [
            'closed' => [Conversation::STATUS_CLOSED, Conversation::STATE_PUBLISHED, Conversation::STATUS_ACTIVE],
            'active' => [Conversation::STATUS_ACTIVE, Conversation::STATE_PUBLISHED, Conversation::STATUS_ACTIVE],
            'spam' => [Conversation::STATUS_SPAM, Conversation::STATE_PUBLISHED, Conversation::STATUS_SPAM],
            'deleted' => [Conversation::STATUS_CLOSED, Conversation::STATE_DELETED, Conversation::STATUS_CLOSED],
            'draft' => [Conversation::STATUS_CLOSED, Conversation::STATE_DRAFT, Conversation::STATUS_CLOSED],
        ];
    }

    /**
     * @dataProvider failedChatReplies
     */
    public function testChannelDeliveryFailuresApplyTheSameReopeningPolicy($channel, $status, $state, $expected)
    {
        $conversation = $this->conversation($channel === 'telegram' ? Telegram::CHANNEL : config('nostr.channel'));
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Reply', [
            'source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB,
        ]);
        $conversation->status = $status;
        $conversation->state = $state;
        $conversation->save();
        $count = $conversation->threads()->count();
        \Illuminate\Support\Facades\Event::fake([\App\Events\ConversationStatusChanged::class]);

        if ($channel === 'telegram') {
            (new \App\Jobs\SendReplyToTelegram($reply->id))->withFakeQueueInteractions()->handle();
        } else {
            (new \App\Nostr\OutgoingMessageSender())->sendThread($conversation, $reply);
        }

        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertSame($expected, (int) $conversation->fresh()->status);
        $this->assertSame($state, (int) $conversation->fresh()->state);
        $this->assertSame($count, $conversation->threads()->count());
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\ConversationStatusChanged::class);
    }

    public static function failedChatReplies()
    {
        $cases = [];
        foreach (['telegram', 'nostr'] as $channel) {
            foreach (self::conversationStates() as $name => $state) {
                $cases[$channel.' '.$name] = array_merge([$channel], $state);
            }
        }

        return $cases;
    }
}
