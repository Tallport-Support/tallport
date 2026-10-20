<?php

namespace App\Listeners;

use App\Thread;
use App\WebChat\WebChat;

/**
 * A website chat answered by email becomes an email conversation: the channel the customer used
 * last decides how it shows and how replies go. Writing in the chat again brings it back
 * (WebChatController::send()).
 */
class FollowWebChatToEmail
{
    public function handle($event)
    {
        $conversation = $event->conversation;
        if ($conversation && $conversation->channel == WebChat::CHANNEL && $event->thread && $event->thread->source_type == Thread::SOURCE_TYPE_EMAIL) {
            $conversation->channel = null;
            $conversation->save();
        }
    }
}
