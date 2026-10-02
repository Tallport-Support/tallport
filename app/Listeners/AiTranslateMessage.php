<?php

namespace App\Listeners;

use App\Ai\Settings;
use App\Jobs\AiTranslateThread;

/**
 * A customer's message: translate it into the mailbox's language (other
 * languages when someone reads it in one).
 */
class AiTranslateMessage
{
    public function handle($event)
    {
        $thread = $event->thread ?? $event->last_thread ?? null;
        if ($thread && $event->conversation) {
            AiTranslateThread::request($thread, Settings::language($event->conversation->mailbox));
        }
    }
}
