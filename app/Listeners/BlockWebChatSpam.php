<?php

namespace App\Listeners;

use App\WebChat\Spam;
use App\WebChat\Visitor;

/**
 * A website chat marked as spam: its visitor is blocked too (App\WebChat\Spam).
 */
class BlockWebChatSpam
{
    public function handle($event)
    {
        $conversation = $event->conversation;
        if ($conversation && $conversation->isSpam() && Visitor::where('conversation_id', $conversation->id)->exists()) {
            Spam::block($conversation);
        }
    }
}
