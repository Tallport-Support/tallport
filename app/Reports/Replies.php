<?php

namespace App\Reports;

use App\Conversation;
use App\Thread;
use App\User;

/**
 * Keeps report_replies up to date: agents' replies to customers and how
 * long the customer waited for each. A conversation that changes is done
 * again when the request, command or job is done; tallport:report-replies
 * does the rest (new conversations, existing ones after installing).
 */
class Replies
{
    const TABLE = 'report_replies';

    /**
     * The last conversation done by tallport:report-replies.
     */
    const CURSOR_OPTION = 'report_replies_cursor';

    protected static $pending = [];

    public static function listen()
    {
        Thread::saved(function ($thread) {
            if ($thread->wasRecentlyCreated || $thread->wasChanged(['state', 'type', 'created_at', 'conversation_id', 'created_by_user_id'])) {
                self::touch($thread->conversation_id);
                if ($thread->wasChanged('conversation_id')) {
                    self::touch($thread->getOriginal('conversation_id'));
                }
            }
        });
        Thread::deleted(function ($thread) {
            self::touch($thread->conversation_id);
        });
        Conversation::deleted(function ($conversation) {
            try {
                \DB::table(self::TABLE)->where('conversation_id', $conversation->id)->delete();
            } catch (\Throwable $e) {
                // Before the migration.
            }
        });
    }

    /**
     * Do a conversation again when the request, command or job is done.
     */
    public static function touch($conversation_id)
    {
        if (!$conversation_id || isset(self::$pending[$conversation_id])) {
            return;
        }
        self::$pending[$conversation_id] = true;
        \Illuminate\Support\defer(function () {
            self::flush();
        }, 'tallport-report-replies')->always();
    }

    public static function flush()
    {
        $ids = array_keys(self::$pending);
        self::$pending = [];
        try {
            self::update($ids);
        } catch (\Throwable $e) {
            \Helper::logException($e, '[report replies]');
        }
    }

    /**
     * Replace the replies of conversations.
     */
    public static function update($conversation_ids)
    {
        $conversation_ids = array_values(array_unique(array_filter((array) $conversation_ids)));
        if (!$conversation_ids) {
            return;
        }
        $threads = Thread::whereIn('conversation_id', $conversation_ids)
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'type', 'created_by_user_id', 'created_at'])
            ->groupBy('conversation_id');
        $robots = User::where('type', User::TYPE_ROBOT)->pluck('id')->all();

        $rows = [];
        foreach ($threads as $conversation_threads) {
            $rows = array_merge($rows, self::conversationReplies($conversation_threads, $robots));
        }
        \DB::transaction(function () use ($conversation_ids, $rows) {
            \DB::table(self::TABLE)->whereIn('conversation_id', $conversation_ids)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                \DB::table(self::TABLE)->insert($chunk);
            }
        });
    }

    /**
     * A conversation's replies: each answers the customer's messages since
     * the previous reply, and the wait is counted from the first of them.
     */
    public static function conversationReplies($threads, $robots = [])
    {
        $rows = [];
        $waiting_since = null;
        $answered = false;
        foreach ($threads as $thread) {
            if ($thread->type == Thread::TYPE_CUSTOMER) {
                $waiting_since = $waiting_since ?: $thread->created_at;
                continue;
            }
            if (!$thread->created_by_user_id || in_array($thread->created_by_user_id, $robots)) {
                continue;
            }
            $response_time = $waiting_since ? max(0, $thread->created_at->getTimestamp() - $waiting_since->getTimestamp()) : null;
            $rows[] = [
                'thread_id'       => $thread->id,
                'conversation_id' => $thread->conversation_id,
                'user_id'         => $thread->created_by_user_id,
                'replied_at'      => $thread->created_at,
                'response_time'   => $response_time,
                'first'           => $response_time !== null && !$answered,
            ];
            $answered = $answered || $response_time !== null;
            $waiting_since = null;
        }

        return $rows;
    }

    /**
     * Do the conversations after the cursor (existing ones after installing,
     * then new ones), for up to $seconds. Returns the number done.
     */
    public static function updateNext($seconds = 50, $chunk = 200)
    {
        $started = microtime(true);
        $count = 0;
        do {
            $cursor = (int) \Option::get(self::CURSOR_OPTION, 0);
            $ids = Conversation::where('id', '>', $cursor)->orderBy('id')->limit($chunk)->pluck('id')->all();
            if ($ids) {
                self::update($ids);
                \Option::set(self::CURSOR_OPTION, end($ids));
                $count += count($ids);
            }
        } while ($ids && microtime(true) - $started < $seconds);

        return $count;
    }
}
