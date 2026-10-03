<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Customer photos from Gravatar are built in (App\Misc\Gravatar, a setting):
 * on where a module did it, which is switched off.
 */
class CustomerGravatar extends Migration
{
    public function up()
    {
        try {
            if (\App\Module::isActive('customerdataenrichment')) {
                \Option::set(\App\Misc\Gravatar::OPTION, true);
                \App\Module::setActive('customerdataenrichment', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
    }
}
