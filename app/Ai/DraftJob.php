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
}
