<?php

namespace App\Misc;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use Illuminate\Support\Carbon;

/**
 * Common conversation lookup and incoming message storage for chat channels.
 * Channels resolve identities and prepare content before calling these methods.
 */
class ChatConversations
{
    public static function unavailableMessage()
    {
        return __('This chat is no longer available on the messaging service. You cannot reply or reopen it here. You can still add notes.');
    }

    public static function markUnavailable($conversation)
    {
        $conversation = $conversation ? $conversation->fresh() : null;
        if (!$conversation || !$conversation->hasChannel() || $conversation->isChatUnavailable()) {
            return;
        }
        $conversation->setMeta('chat_unavailable', true);
        if (!$conversation->isSpam()) {
            $conversation->setStatus(Conversation::STATUS_CLOSED);
        }
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();
        $thread = $conversation->threads()->orderBy('id', 'desc')->first();
        if ($thread) {
            \DB::afterCommit(fn () => Conversation::refreshConversations($conversation, $thread));
        }
    }

    /**
     * All chat channels use the mailbox's conversation policy.
     */
    public static function latest(Mailbox $mailbox, Customer $customer, $channel)
    {
        $conversation = Conversation::where('mailbox_id', $mailbox->id)
            ->where('customer_id', $customer->id)
            ->where('channel', $channel)
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')->first();

        return self::canContinue($conversation, $mailbox) ? $conversation : null;
    }

    public static function reopenDays(Mailbox $mailbox)
    {
        return (int) $mailbox->getMeta('chat_reopen_days', 30);
    }

    /**
     * Also applies when a channel resolves a conversation by room instead of customer.
     */
    public static function canContinue($conversation, Mailbox $mailbox)
    {
        if (!$conversation || $conversation->isChatUnavailable() || $conversation->chatShouldStartNew($mailbox)) {
            return false;
        }
        $last = $conversation->last_reply_at ?: $conversation->created_at;

        return !$last || Carbon::parse($last)->gte(now()->subDays(self::reopenDays($mailbox)));
    }

    /**
     * Append to the selected conversation, or create one with the channel's
     * subject and source metadata. Existing model methods handle attachments,
     * reopening, counters and events. The caller owns any transaction.
     *
     * @return array|false Conversation and thread, or false if creation failed.
     */
    public static function receive($conversation, Customer $customer, array $conversation_data, array $message)
    {
        $message['type'] = Thread::TYPE_CUSTOMER;
        $message['customer_id'] = $customer->id;

        if ($conversation) {
            return [
                'conversation' => $conversation,
                'thread' => Thread::createExtended($message, $conversation, $customer),
            ];
        }

        $conversation_data['type'] = Conversation::TYPE_EMAIL;

        return Conversation::create($conversation_data, [$message], $customer);
    }
    public static function receiveMailboxReply($conversation, Customer $customer, array $conversation_data, array $message, $sender)
    {
        $message['type'] = Thread::TYPE_MESSAGE;
        $message['external_chat_sender'] = $sender;
        $message['after_commit'] = true;
        if ($conversation) {
            return ['conversation' => $conversation, 'thread' => Thread::createExtended($message, $conversation, $customer)];
        }
        $conversation_data['type'] = Conversation::TYPE_EMAIL;

        return Conversation::create($conversation_data, [$message], $customer);
    }

    public static function updatePending(Thread $thread, $body)
    {
        if ($thread->body !== $body) {
            $thread->body = $body;
            $thread->save();
        }
    }

    public static function resolvePending(Thread $thread, $body, array $attachments = [])
    {
        $conversation = $thread->conversation;
        foreach ($attachments as $attachment) {
            $file = \App\Attachment::create($attachment['file_name'], $attachment['mime_type'], null,
            base64_decode($attachment['data']), null, false, $thread->id);
            if ($file) {
                $thread->has_attachments = true;
                $conversation->has_attachments = true;
            }
        }
        $thread->body = $body;
        $thread->setMeta('chat_pending', false);
        $thread->save();
        if ((int) $conversation->threads()->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])->max('id') === (int) $thread->id) {
            $conversation->setPreview($body);
        }
        $conversation->save();
        \DB::afterCommit(function () use ($conversation, $thread) {
            if ($thread->isCustomerMessage()) {
                if ($thread->first) {
                    event(new \App\Events\CustomerCreatedConversation($conversation, $thread));
                    \Eventy::action('conversation.created_by_customer', $conversation, $thread, $conversation->customer);
                } else {
                    event(new \App\Events\CustomerReplied($conversation, $thread));
                    \Eventy::action('conversation.customer_replied', $conversation, $thread, $conversation->customer);
                }
            }
            Conversation::refreshConversations($conversation, $thread);
        });
    }
}
