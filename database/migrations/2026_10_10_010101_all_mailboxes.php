<?php

use Illuminate\Database\Migrations\Migration;

/**
 * All Mailboxes is built in (App\Misc\AllMailboxes): a module adding the
 * same view is switched off. Nothing is stored.
 */
class AllMailboxes extends Migration
{
    public function up()
    {
        try {
            if (\App\Module::isActive('globalmailbox')) {
                \App\Module::setActive('globalmailbox', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
    }
}
