<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether a user has read a conversation: unread while someone else (the customer, or a
 * teammate's reply or note) has added to it since the user last opened it. The user's own
 * messages and what happens to it (status, assignment) don't count.
 */
class ConversationRead extends Model
{
    /**
     * Threads up to this one were there before reads were kept: read for everyone.
     */
    const OPTION_READ_UP_TO = 'conversation_reads_up_to';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['user_id', 'conversation_id', 'thread_id'];

    /**
     * Read up to its last thread (opened, or Mark as Read).
     */
    public static function markRead($conversation_ids, $user)
    {
        $last_threads = Thread::whereIn('conversation_id', (array) $conversation_ids)
            ->groupBy('conversation_id')->selectRaw('conversation_id, max(id) as thread_id')->pluck('thread_id', 'conversation_id');
        $rows = [];
        foreach ($last_threads as $conversation_id => $thread_id) {
            $rows[] = ['user_id' => $user->id, 'conversation_id' => $conversation_id, 'thread_id' => $thread_id];
        }
        if ($rows) {
            self::upsert($rows, ['user_id', 'conversation_id'], ['thread_id']);
        }
    }

    /**
     * Unread until opened again (Mark as Unread).
     */
    public static function markUnread($conversation_ids, $user)
    {
        $rows = [];
        foreach (array_unique(array_map('intval', (array) $conversation_ids)) as $conversation_id) {
            $rows[] = ['user_id' => $user->id, 'conversation_id' => $conversation_id, 'thread_id' => null];
        }
        if ($rows) {
            self::upsert($rows, ['user_id', 'conversation_id'], ['thread_id']);
        }
    }

    /**
     * The IDs of the conversations the user hasn't read, of the ones given (a page of a list).
     */
    public static function unreadIds($conversations, $user)
    {
        $ids = [];
        foreach ($conversations as $conversation) {
            $ids[] = $conversation->id;
        }
        if (!$ids) {
            return [];
        }
        $reads = self::where('user_id', $user->id)->whereIn('conversation_id', $ids)->get()->keyBy('conversation_id');
        // The latest message or note by someone else.
        $latest = Thread::whereIn('conversation_id', $ids)
            ->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->where('state', Thread::STATE_PUBLISHED)
            ->where(function ($query) use ($user) {
                $query->whereNull('created_by_user_id')->orWhere('created_by_user_id', '!=', $user->id);
            })
            ->groupBy('conversation_id')->selectRaw('conversation_id, max(id) as thread_id')->pluck('thread_id', 'conversation_id');
        $read_up_to = (int) \Option::get(self::OPTION_READ_UP_TO, 0);

        $unread = [];
        foreach ($ids as $id) {
            $read = $reads->get($id);
            if ($read && $read->thread_id === null) {
                $unread[] = $id;
            } elseif (($latest[$id] ?? 0) > ($read ? $read->thread_id : $read_up_to)) {
                $unread[] = $id;
            }
        }

        return $unread;
    }
}
