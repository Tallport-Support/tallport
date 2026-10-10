<?php

namespace App\Jobs;

use App\Matrix\Outgoing;
use App\Misc\ChatDelivery;
use App\SendLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendReplyToMatrix implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $thread_id;
    public $timeout = 300;
    public $tries = 5;
    public $backoff = [30, 120, 300, 900];

    public function __construct($thread_id)
    {
        $this->thread_id = $thread_id;
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->onQueue('emails');
    }

    public function handle()
    {
        $thread = ChatDelivery::findReply($this->thread_id);
        if (!$thread || $thread->send_status == SendLog::STATUS_ACCEPTED || $thread->getMeta('chat_external_sender')) {
            return;
        }
        try {
            (new Outgoing())->send($thread);
        } catch (\Throwable $e) {
            \App\Misc\ChatLog::failure('matrix', $thread->conversation->mailbox_id, 'send', $e);
            ChatDelivery::recordStatus($thread, SendLog::STATUS_SEND_ERROR, ['msg' => __('Matrix reply failed. Check the connection and verification, then retry.')]);
            ChatDelivery::reopenConversation($thread->conversation);
            if ($e instanceof \App\Matrix\MatrixException && $e->retry_after > 0 && $this->job) {
                $this->release($e->retry_after);
                return;
            }
            throw new \App\Matrix\MatrixException('Matrix reply could not be delivered.');
        }
    }
}
