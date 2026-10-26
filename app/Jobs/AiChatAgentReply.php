<?php

namespace App\Jobs;

use App\Ai\ChatAgent;
use App\Conversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The AI agent's next step in a chat it has (App\Ai\ChatAgent::reply()).
 */
class AiChatAgentReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $conversation_id;

    public $tries = 1;

    public $timeout = 300;

    public function __construct($conversation_id)
    {
        $this->onConnection(\Helper::queueConnection('ai'));
        $this->onQueue('ai');
        $this->conversation_id = $conversation_id;
    }

    public function handle()
    {
        $conversation = Conversation::find($this->conversation_id);
        if ($conversation) {
            ChatAgent::reply($conversation);
        }
    }
}
