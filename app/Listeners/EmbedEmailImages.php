<?php

namespace App\Listeners;

/**
 * Images in an email go inline, never linked (App\Misc\EmailImages).
 */
class EmbedEmailImages
{
    public function handle($event)
    {
        \App\Misc\EmailImages::embed($event->message);
    }
}
