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
     * Claim a user's daily draft allowance while holding the user's row lock.
     */
    public static function reserve($user, $conversation)
    {
        return \DB::transaction(function () use ($user, $conversation) {
            $locked_user = \App\User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (self::countToday($locked_user) >= Settings::draftsPerDay($locked_user)) {
                return null;
            }

            $draft_job = new self();
            $draft_job->conversation_id = $conversation->id;
            $draft_job->user_id = $locked_user->id;
            $draft_job->status = self::STATUS_RUNNING;
            $draft_job->started_at = now();
            $draft_job->save();

            return $draft_job;
        });
    }

    /**
     * Finish drafts whose stream ended without running its cleanup (for example, a killed PHP
     * process). The conditional update cannot replace a result saved by a finishing stream.
     */
    public static function failAbandoned($before)
    {
        $failed = 0;
        self::where('status', self::STATUS_RUNNING)->where('started_at', '<', $before)->orderBy('id')->pluck('id')
            ->each(function ($id) use ($before, &$failed) {
                $reference = strtoupper(bin2hex(random_bytes(6)));
                $updated = self::whereKey($id)->where('status', self::STATUS_RUNNING)->where('started_at', '<', $before)->update([
                    'status'        => self::STATUS_FAILED,
                    'error_type'    => 'interrupted',
                    'error_message' => __('Could not draft a reply.'),
                    'error_detail'  => __('ID').': '.$reference,
                    'completed_at'  => now(),
                ]);
                if ($updated) {
                    \Log::error('[AI] ['.$reference.'] Draft #'.$id.' was interrupted.');
                    $failed++;
                }
            });

        return $failed;
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
