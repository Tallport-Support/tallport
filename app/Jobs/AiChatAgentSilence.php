<?php

namespace App\Jobs;

use App\Ai\ChatAgent;
use App\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The customer hasn't answered the AI agent's question (App\Ai\ChatAgent::silence()).
 */
class AiChatAgentSilence implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $conversation_id;

    public $thread_id;

    public $tries = 1;

    public function __construct($conversation_id, $thread_id)
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
        $this->conversation_id = $conversation_id;
        $this->thread_id = $thread_id;
    }

    public function handle()
    {
        $conversation = Conversation::find($this->conversation_id);
        if ($conversation) {
            ChatAgent::silence($conversation, $this->thread_id);
        }
    }
}
