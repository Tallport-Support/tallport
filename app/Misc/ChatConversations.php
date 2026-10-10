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
        if (!$conversation || $conversation->chatShouldStartNew($mailbox)) {
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
}
