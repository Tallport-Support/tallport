<?php

namespace App\Misc;

use App\Conversation;
use App\Thread;
use App\User;
use Carbon\Carbon;

/**
 * A customer's local time, from the UTC offset in the Date header of their
 * emails: when they sent one, and what time it is for them now.
 */
class SenderTime
{
    /**
     * When a customer's email was sent, with its offset ("+02:00"); or null.
     */
    public static function sentAt(Thread $thread)
    {
        if ($thread->type != Thread::TYPE_CUSTOMER || !$thread->headers) {
            return null;
        }
        $date = \App\Incoming\HeaderText::value($thread->headers, 'Date');
        if (!$date) {
            return null;
        }
        try {
            return Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $date));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The offset of the customer's latest email in the conversation, or null.
     */
    public static function offset(Conversation $conversation)
    {
        $thread = $conversation->threads()
            ->where('type', Thread::TYPE_CUSTOMER)
            ->whereNotNull('headers')
            ->orderBy('created_at', 'desc')
            ->first();
        $sent_at = $thread ? self::sentAt($thread) : null;

        return $sent_at ? $sent_at->getOffsetString() : null;
    }

    /**
     * A time as the customer sees it (in the user's time format).
     */
    public static function format(Carbon $date, $offset)
    {
        return User::dateFormat($date->copy()->setTimezone($offset), 'H:i', null, true, false);
    }
}
