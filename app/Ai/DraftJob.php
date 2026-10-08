<?php

namespace App\Ai;

use Illuminate\Database\Eloquent\Model;

/**
 * A reply draft asked for by a user (AiDraftsController::store()): how it went, and what
 * counts against the user's drafts per day.
 */
class DraftJob extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_DELETED = 'deleted';

    protected $table = 'aiassistant_draft_jobs';

    protected $casts = [
        'result'       => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Drafts a user asked for today.
     */
    public static function countToday($user)
    {
        return self::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
    }

    /**
     * Remove drafts for deleted conversations, keeping only today's quota rows.
     */
    public static function forgetConversations($conversation_ids)
    {
        $today = now()->startOfDay();
        self::whereIn('conversation_id', $conversation_ids)->where(function ($query) use ($today) {
            $query->where('created_at', '<', $today)->orWhereNull('created_at');
        })->delete();

        self::whereIn('conversation_id', $conversation_ids)->update([
            'conversation_id' => null,
            'status'          => self::STATUS_DELETED,
            'locale'          => null,
            'document_limit'  => 0,
            'result'          => null,
            'error_type'      => null,
            'error_message'   => null,
            'error_detail'    => null,
            'started_at'      => null,
            'completed_at'    => null,
        ]);
    }
}
