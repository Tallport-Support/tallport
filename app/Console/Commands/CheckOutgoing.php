<?php

namespace App\Console\Commands;

use App\Conversation;
use App\SendLog;
use App\Thread;
use Illuminate\Console\Command;

class CheckOutgoing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tallport:check-outgoing {--days=30 : How far back to look}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List replies to customers that are not in the outgoing emails log, and why (IDs and statuses only)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $threads = Thread::select('threads.*')
            ->join('conversations', 'conversations.id', '=', 'threads.conversation_id')
            ->where('threads.type', Thread::TYPE_MESSAGE)
            ->where('threads.state', Thread::STATE_PUBLISHED)
            ->where('threads.imported', false)
            ->where('conversations.type', Conversation::TYPE_EMAIL)
            ->where('threads.created_at', '>=', now()->subDays((int) $this->option('days')))
            // Replies are sent after the undo delay.
            ->where('threads.created_at', '<', now()->subMinutes(15))
            ->whereNotExists(function ($query) {
                $query->select(\DB::raw(1))
                    ->from('send_logs')
                    ->whereColumn('send_logs.thread_id', 'threads.id')
                    ->where('send_logs.mail_type', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER);
            })
            ->orderBy('threads.id')
            ->get();

        $this->line('Replies without an outgoing log entry: '.count($threads));
        foreach ($threads as $thread) {
            $this->line(implode("\t", [
                'thread '.$thread->id,
                'conversation '.$thread->conversation_id,
                $thread->created_at,
                'send_status '.($thread->send_status ?? 'none'),
                $this->reason($thread),
            ]));
        }

        return 0;
    }

    protected function reason(Thread $thread)
    {
        $pattern = '%"displayName":"App\\\\\\\\Jobs\\\\\\\\SendReplyToCustomer"%{i:0;i:'.$thread->id.';%';
        if (\App\Job::where('payload', 'like', $pattern)->exists()) {
            return 'job waiting in queue';
        }
        if ($thread->getFailedJobId()) {
            return 'job failed';
        }

        return 'no job';
    }
}
