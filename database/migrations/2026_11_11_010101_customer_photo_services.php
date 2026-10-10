<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Customer photos from a service of choice (App\Misc\CustomerPhotos): the
 * Gravatar switch (customer_gravatar) becomes customer_photos. An existing
 * installation keeps what it had; a new one gets photos from Gravatar (unset).
 */
class CustomerPhotoServices extends Migration
{
    public function up()
    {
        if (\Option::get('customer_photos', null) === null && DB::table('users')->exists()) {
            \Option::set('customer_photos', \Option::get('customer_gravatar', false) ? 'gravatar' : 'none');
        }
        \Option::remove('customer_gravatar');
    }

    public function down()
    {
    }
}
