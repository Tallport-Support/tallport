<?php

namespace App\Console\Commands;

use App\Misc\CustomerPhotos;
use Illuminate\Console\Command;

/**
 * Removes customers' photos looked up online older than
 * CustomerPhotos::REFRESH_DAYS (all of them without a service): a customer
 * whose conversation is opened gets a fresh one, the others none.
 */
class CleanCustomerPhotos extends Command
{
    protected $signature = 'tallport:clean-customer-photos';

    protected $description = 'Remove customer photos looked up online older than '.CustomerPhotos::REFRESH_DAYS.' days';

    public function handle()
    {
        $this->info('Removed: '.CustomerPhotos::removeOld());
    }
}
