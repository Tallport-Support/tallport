<?php

namespace App\Jobs;

use App\Nostr\Nostr;
use App\Nostr\NostrEvent;
use App\Nostr\OutgoingMessageSender;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Send an agent's reply to the customer's Nostr key, right away (Nostr
 * replies can't be undone). A reply that can't be delivered is shown as not
 * sent, with Retry, and its conversation is reopened.
 */
class SendReplyToNostr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $thread_id;

    public $tries = 1;

    public $timeout = 300;

    public function __construct($thread_id)
    {
        $this->thread_id = $thread_id;
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->onQueue('emails');
    }

    public function handle()
    {
        $thread = Thread::find($this->thread_id);
        if (!$thread || $thread->state != Thread::STATE_PUBLISHED || $thread->type != Thread::TYPE_MESSAGE
            || NostrEvent::sentForThread($thread->id)
        ) {
            return;
        }

        (new OutgoingMessageSender(Nostr::logger()))->sendThread($thread->conversation, $thread);
    }
}
