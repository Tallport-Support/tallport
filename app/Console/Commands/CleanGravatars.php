<?php

namespace App\Console\Commands;

use App\Misc\Gravatar;
use Illuminate\Console\Command;

/**
 * Removes customers' Gravatar photos older than Gravatar::REFRESH_DAYS: a
 * customer whose conversation is opened gets a fresh one, the others none.
 */
class CleanGravatars extends Command
{
    protected $signature = 'tallport:clean-gravatars';

    protected $description = 'Remove customer photos from Gravatar older than '.Gravatar::REFRESH_DAYS.' days';

    public function handle()
    {
        $this->info('Removed: '.Gravatar::removeOld());
    }
}
