<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Customers' local time is shown (App\Misc\SenderTime): a module showing
 * the same is switched off.
 */
class SenderTime extends Migration
{
    public function up()
    {
        try {
            if (\App\Module::isActive('sendertimezone')) {
                \App\Module::setActive('sendertimezone', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
    }
}
