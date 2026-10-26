<?php

namespace App\Listeners;

use App\Ai\ChatAgent;

/**
 * The AI agent (App\Ai\ChatAgent): takes a new chat (before notifications),
 * follows the customer's replies in a chat it has, and lets go when a person replies.
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
                if (ChatAgent::takes($conversation)) {
                    ChatAgent::take($conversation);
                }
                break;
            case 'App\Events\CustomerReplied':
                ChatAgent::customerReplied($conversation);
                break;
            case 'App\Events\UserReplied':
                ChatAgent::personReplied($conversation, $event->thread);
                break;
        }
    }
}
