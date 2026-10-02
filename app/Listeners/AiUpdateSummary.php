<?php

namespace App\Listeners;

use App\Ai\Settings;
use App\Jobs\AiSummarizeConversation;

/**
 * A new message or note: update the conversation's AI summary (in the
 * mailbox's language; other languages when someone reads it in one).
 */
class AiUpdateSummary
{
    public function handle($event)
    {
        $conversation = $event->conversation ?? null;
        if ($conversation) {
            AiSummarizeConversation::request($conversation, Settings::language($conversation->mailbox));
        }
    }
}
