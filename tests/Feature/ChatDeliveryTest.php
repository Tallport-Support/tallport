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

    /** @dataProvider unavailableChannels */
    public function testUnavailableChatsCannotSendRetryReopenOrContinueButKeepNotes($channel)
    {
        $conversation = $this->conversation($channel === 'nostr' ? config('nostr.channel') : $channel);
        $admin = $this->createAdmin();
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Pending reply', ['source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB]);
        \App\Misc\ChatConversations::markUnavailable($conversation);
        $conversation->refresh();
        $this->assertTrue($conversation->isChatUnavailable());
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->status);
        ChatDelivery::reopenConversation($conversation);
        Conversation::bulkChangeStatus([$conversation->id], Conversation::STATUS_ACTIVE, $admin);
        $conversation->status = Conversation::STATUS_PENDING;
        $conversation->save();
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
        $this->assertFalse(\App\Misc\ChatConversations::canContinue($conversation, $conversation->mailbox));
        $this->assertNull(ChatDelivery::findReply($reply->id));
        $this->assertSame(SendLog::STATUS_SEND_ERROR, (int) $reply->fresh()->send_status);
        $this->assertFalse($reply->fresh()->canRetrySend());
        $message = \App\Misc\ChatConversations::unavailableMessage();
        $this->assertSame(['msg' => $message], \App\Misc\ConversationActions::retrySend($reply->fresh(), $admin));
        $this->postAjax($admin, '/conversation/ajax', ['action' => 'conversation_change_status', 'conversation_id' => $conversation->id, 'status' => Conversation::STATUS_ACTIVE])->assertJson(['msg' => $message]);
        $fields = ['action' => 'send_reply', 'mailbox_id' => $conversation->mailbox_id, 'conversation_id' => $conversation->id, 'body' => 'Blocked reply'];
        $this->postAjax($admin, '/conversation/ajax', $fields)->assertJson(['status' => 'error', 'msg' => $message]);
        $this->assertSame(0, $conversation->threads()->where('body', 'Blocked reply')->count());
        $this->postAjax($admin, '/conversation/ajax', array_merge($fields, ['is_note' => 1, 'body' => 'Internal note', 'status' => Conversation::STATUS_ACTIVE]))->assertJson(['status' => 'success']);
        $this->assertSame(1, $conversation->threads()->where('type', Thread::TYPE_NOTE)->where('body', 'Internal note')->count());
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->threads()->where('type', Thread::TYPE_NOTE)->where('body', 'Internal note')->value('status'));
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
    }

    public static function unavailableChannels()
    {
        return ['telegram' => [Telegram::CHANNEL], 'nostr' => ['nostr'], 'matrix' => [\App\Matrix\Matrix::CHANNEL]];
    }

    public function testUnavailableFlagDoesNotCloseEmailConversations()
    {
        $conversation = $this->conversation(null);
        \App\Misc\ChatConversations::markUnavailable($conversation);
        $this->assertFalse($conversation->fresh()->isChatUnavailable());
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
    }

    public function testClosingAChatDoesNotChangePreviouslySuccessfulDelivery()
    {
        $conversation = $this->conversation();
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Delivered', ['source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB]);
        ChatDelivery::recordStatus($reply, SendLog::STATUS_ACCEPTED);
        \App\Misc\ChatConversations::markUnavailable($conversation);
        $this->assertNull(ChatDelivery::findReply($reply->id));
        (new \App\Listeners\SendReplyToCustomer())->handle(new \App\Events\UserReplied($conversation, $reply->fresh()));
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $reply->fresh()->send_status);
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
