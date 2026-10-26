<?php

namespace App\Listeners;

use App\Ai\ChatAgent;

/**
 * The AI agent (App\Ai\ChatAgent): takes a new conversation (before notifications), follows the
 * customer's replies in one it has, and lets go when a person replies.
 */
class AiChatAgent
{
    public function handle($event)
    {
        $conversation = $event->conversation ?? null;
        if (!$conversation) {
            return;
        }
        switch (get_class($event)) {
            case 'App\Events\CustomerCreatedConversation':
                if (ChatAgent::takes($conversation, $event->thread ?? null)) {
                    ChatAgent::take($conversation);
                }
                break;
            case 'App\Events\CustomerReplied':
                ChatAgent::customerReplied($conversation, $event->thread ?? null);
                break;
            case 'App\Events\UserReplied':
                ChatAgent::personReplied($conversation, $event->thread);
                break;
        }
    }
}
