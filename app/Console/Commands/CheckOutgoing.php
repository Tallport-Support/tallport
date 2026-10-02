<?php

namespace App\Console\Commands;

use App\Conversation;
use App\Jobs\SendReplyToCustomer;
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
    protected $signature = 'tallport:check-outgoing
        {--days=30 : How far back to look}
        {--fix : Mark replies that no job will send as not sent, and reopen their conversations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List replies to customers that have not been sent, and why (IDs and statuses only)';

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
            ->where('threads.created_at', '>=', now()->subDays((int) $this->option('days')))
            // Replies are sent after the undo delay.
            ->where('threads.created_at', '<', now()->subMinutes(15))
            ->where(function ($query) {
                $query->where(function ($query) {
                    $query->where('conversations.type', Conversation::TYPE_EMAIL)
                        ->whereNotExists(function ($query) {
                            $query->select(\DB::raw(1))
                                ->from('send_logs')
                                ->whereColumn('send_logs.thread_id', 'threads.id')
                                ->where('send_logs.mail_type', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER)
                                ->whereNotIn('send_logs.status', SendLog::$status_errors);
                        });
                })
                // Telegram and Nostr replies: accepted by Telegram, or a relay.
                ->orWhere(function ($query) {
                    $query->where('conversations.type', Conversation::TYPE_CHAT)
                        ->whereIn('conversations.channel', [\App\Telegram\Telegram::CHANNEL, \App\Nostr\Nostr::channel()])
                        ->where(function ($query) {
                            $query->whereNull('threads.send_status')
                                ->orWhere('threads.send_status', '!=', SendLog::STATUS_ACCEPTED);
                        });
                });
            })
            ->orderBy('threads.id')
            ->get();

        $this->line('Replies not sent: '.count($threads));
        foreach ($threads as $thread) {
            $reason = $this->reason($thread);
            $fixed = false;
            // A failed job without a send status failed before Tallport 1.17.11.
            if ($this->option('fix') && $reason != 'job waiting in queue' && !$thread->send_status) {
                $this->markNotSent($thread);
                $fixed = true;
            }
            $this->line(implode("\t", [
                'thread '.$thread->id,
                'conversation '.$thread->conversation_id,
                $thread->created_at,
                'via '.(Thread::$source_types[$thread->source_type] ?? 'unknown'),
                'send_status '.($thread->send_status ?: 'none'),
                $reason.($fixed ? ': marked not sent, conversation reopened' : ''),
            ]));
        }

        return 0;
    }

    protected function reason(Thread $thread)
    {
        if ($thread->getQueuedJobId()) {
            return 'job waiting in queue';
        }
        if ($thread->getFailedJobId()) {
            return 'job failed';
        }

        return 'no job';
    }

    /**
     * Nothing will send this reply (without Retry): show it as not sent and
     * put the conversation back in the agents' active list.
     */
    protected function markNotSent(Thread $thread)
    {
        $conversation = $thread->conversation;
        $message = 'Not sent: no job is sending this reply (found by tallport:check-outgoing).';
        if (!$conversation->isChat()) {
            $email = $thread->getToArray()[0] ?? $conversation->customer_email;
            SendLog::log($thread->id, null, $email, SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_SEND_ERROR, $conversation->customer_id, null, $message);
        } else {
            $thread->updateSendStatusData(['msg' => $message]);
        }

        $thread->send_status = SendLog::STATUS_SEND_ERROR;
        $thread->save();
        SendReplyToCustomer::reopenConversation($conversation);

        \Log::warning('[tallport:check-outgoing] Reply '.$thread->id.' in conversation '.$conversation->id.' was not sent; marked as not sent and reopened.');
    }
}
