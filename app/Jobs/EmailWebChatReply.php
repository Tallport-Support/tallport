<?php

namespace App\Jobs;

use App\Conversation;
use App\Thread;
use App\WebChat\Visitor;
use App\WebChat\WebChat;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A website chat reply the visitor hasn't seen: by email too, to their confirmed or signed-in
 * address, as an ordinary reply (App\Jobs\SendReplyToCustomer), so answering it continues the
 * conversation by email.
 */
class EmailWebChatReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $thread_id;

    public function __construct($thread_id)
    {
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->thread_id = $thread_id;
    }

    public function handle()
    {
        $thread = \App\Misc\ChatDelivery::findReply($this->thread_id);
        $conversation = $thread ? $thread->conversation : null;
        if (!$conversation || $conversation->channel != WebChat::CHANNEL) {
            return;
        }
        // A later reply's own email carries this one too.
        if ($conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->where('id', '>', $thread->id)->exists()) {
            return;
        }
        // Seen, or they've been on the website since.
        $around = Visitor::where('conversation_id', $conversation->id)->where(function ($query) use ($thread) {
            $query->where('read_thread_id', '>=', $thread->id)->orWhere('last_seen_at', '>=', now()->subSeconds(WebChat::EMAIL_AFTER));
        })->exists();
        $email = WebChat::email($conversation->customer);
        if ($around || !$email) {
            return;
        }
        if (!$conversation->customer_email) {
            $conversation->customer_email = $email;
            $conversation->save();
        }
        $replies = $conversation->getThreads(null, null, [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
            ->filter(fn ($reply) => $reply->id <= $thread->id);
        SendReplyToCustomer::dispatch($conversation, $replies, $conversation->customer)->onQueue('emails');
    }
}
