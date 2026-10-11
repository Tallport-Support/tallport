<?php

namespace App\Misc;

use App\Conversation;
use App\Thread;

/**
 * Reply eligibility and delivery state in Tallport. Channels own their send
 * attempts, delivery receipts, retries and protocol-specific status data.
 */
class ChatDelivery
{
    public static function plainText(Thread $thread)
    {
        $text = HtmlToText::convert($thread->body);
        foreach ($thread->all_attachments as $attachment) {
            $url = $attachment->url();
            $text = str_replace([' ('.$url.')', $url], '', $text);
        }

        return trim($text);
    }

    /**
     * The channel checks its own delivery record before sending this reply.
     */
    public static function findReply($thread_id)
    {
        $thread = Thread::find($thread_id);
        if (!$thread || $thread->state != Thread::STATE_PUBLISHED || $thread->type != Thread::TYPE_MESSAGE) {
            return null;
        }
        if ($thread->conversation->isChatUnavailable()) {
            if (!$thread->isSendStatusSuccess()) {
                self::recordStatus($thread, \App\SendLog::STATUS_SEND_ERROR, ['msg' => ChatConversations::unavailableMessage()]);
            }
            return null;
        }

        return $thread;
    }

    /**
     * Merge status details without losing a channel's partial-send progress.
     */
    public static function recordStatus(Thread $thread, $status, array $data = [])
    {
        $thread->send_status = $status;
        if ($data) {
            $thread->updateSendStatusData($data);
        }
        $thread->save();
    }

    /**
     * Reopen after a delivery error without restoring spam or unpublished
     * conversations.
     */
    public static function reopenConversation($conversation)
    {
        $conversation = $conversation ? $conversation->fresh() : null;
        if (!$conversation || $conversation->isChatUnavailable() || $conversation->isActive() || $conversation->isSpam()
            || $conversation->state != Conversation::STATE_PUBLISHED
        ) {
            return;
        }

        $conversation->setStatus(Conversation::STATUS_ACTIVE);
        $conversation->save();
        $conversation->mailbox->updateFoldersCounters();
    }
}
