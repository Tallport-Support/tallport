<?php

namespace App\Inbox;

use Illuminate\Support\Facades\Cache;

/**
 * The local inbox's change log (inbox_changes): every change to what a conversation's list row
 * shows, from wherever it happens (observers and the few query-builder updates). A change to who
 * may see what isn't logged: the sync's scope (App\Inbox\Sync::scope()) changes, and the browser
 * starts over. Browsers sync from it (App\Inbox\Sync), told by a hint on the mailbox's realtime
 * channel (App\Events\RealtimeInboxChanged), with no data in it.
 */
class Changes
{
    const KEEP_DAYS = 30;

    /**
     * Logged this request already: once each.
     */
    protected static $logged = [];

    /**
     * Conversations whose list row changed (IDs, or models), in a mailbox.
     */
    public static function conversations($conversations, $mailbox_id = null)
    {
        $rows = [];
        $list = $conversations instanceof \Traversable || is_array($conversations) ? $conversations : [$conversations];
        // IDs alone: their mailboxes, for the hint.
        $ids = [];
        foreach ($list as $conversation) {
            if (!is_object($conversation) && !$mailbox_id) {
                $ids[] = (int) $conversation;
            }
        }
        $mailboxes = $ids ? \App\Conversation::whereIn('id', array_diff($ids, array_keys(self::$logged)))->pluck('mailbox_id', 'id')->all() : [];
        foreach ($list as $conversation) {
            $id = is_object($conversation) ? $conversation->id : (int) $conversation;
            $mailbox = is_object($conversation) ? $conversation->mailbox_id : ($mailbox_id ?: ($mailboxes[$id] ?? null));
            if (!$id || isset(self::$logged[$id])) {
                continue;
            }
            self::$logged[$id] = true;
            $rows[] = ['conversation_id' => $id, 'mailbox_id' => $mailbox, 'created_at' => now()];
        }
        self::write($rows);
    }

    /**
     * The hint alone (a conversation moved away from this mailbox).
     */
    public static function hint($mailbox_id)
    {
        if ($mailbox_id && Cache::add('inbox_changed_hint_'.$mailbox_id, true, 1)) {
            \App\Events\RealtimeInboxChanged::dispatchSelf($mailbox_id);
        }
    }

    public static function latest()
    {
        return (int) \DB::table('inbox_changes')->max('id');
    }

    /**
     * The oldest change kept: a cursor before it can't catch up.
     */
    public static function oldest()
    {
        return (int) \DB::table('inbox_changes')->min('id');
    }

    public static function prune()
    {
        return \DB::table('inbox_changes')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /**
     * Logged once per request (a test, a queue job: a new one).
     */
    public static function forget()
    {
        self::$logged = [];
    }

    protected static function write(array $rows)
    {
        if (!$rows) {
            return;
        }
        try {
            \DB::table('inbox_changes')->insert($rows);
        } catch (\Throwable $e) {
            // Never in the way of the change itself; the browser's poll and resync catch up.
            \Helper::logException($e, '[Inbox] ');

            return;
        }
        // Open tabs hear of it within seconds: one hint per mailbox a second at most.
        foreach (array_unique(array_filter(array_column($rows, 'mailbox_id'))) as $mailbox_id) {
            self::hint($mailbox_id);
        }
    }
}
