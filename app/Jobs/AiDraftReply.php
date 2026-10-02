<?php

namespace App\Jobs;

use App\Ai\DraftJob;
use App\Ai\Drafts;
use App\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Make a reply draft a user asked for.
 */
class AiDraftReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $draft_job_id;

    public $language;

    public $tries = 1;

    public $timeout = 240;

    public function __construct($draft_job_id, $language)
    {
        $this->draft_job_id = $draft_job_id;
        $this->language = $language;
    }

    public function handle()
    {
        $draft_job = DraftJob::find($this->draft_job_id);
        if (!$draft_job || $draft_job->status != DraftJob::STATUS_PENDING) {
            return;
        }
        $draft_job->status = DraftJob::STATUS_RUNNING;
        $draft_job->started_at = now();
        $draft_job->save();

        try {
            $draft_job->result = Drafts::draft(Conversation::findOrFail($draft_job->conversation_id), $this->language);
            $draft_job->status = DraftJob::STATUS_COMPLETED;
        } catch (\Throwable $e) {
            $draft_job->status = DraftJob::STATUS_FAILED;
            $draft_job->error_type = get_class($e);
            $draft_job->error_message = __('Could not draft a reply.');
            $draft_job->error_detail = mb_substr($e->getMessage(), 0, 2000);
            \Helper::logException($e, '[AI Assistant] Draft for conversation #'.$draft_job->conversation_id.':');
        }
        $draft_job->completed_at = now();
        $draft_job->save();
    }

    public function failed(\Throwable $e)
    {
        DraftJob::where('id', $this->draft_job_id)->whereIn('status', [DraftJob::STATUS_PENDING, DraftJob::STATUS_RUNNING])->update([
            'status'        => DraftJob::STATUS_FAILED,
            'error_type'    => get_class($e),
            'error_message' => __('Could not draft a reply.'),
            'error_detail'  => mb_substr($e->getMessage(), 0, 2000),
            'completed_at'  => now(),
        ]);
    }
}
