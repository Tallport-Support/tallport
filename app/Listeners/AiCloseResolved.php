<?php

namespace App\Listeners;

use App\Ai\Resolved;

/**
 * A customer's reply: closes the conversation when it says they need nothing more (App\Ai\Resolved).
 */
class AiCloseResolved
{
    public function handle($event)
    {
        if (!empty($event->conversation) && !empty($event->thread)) {
            Resolved::request($event->conversation, $event->thread);
        }
    }
}
