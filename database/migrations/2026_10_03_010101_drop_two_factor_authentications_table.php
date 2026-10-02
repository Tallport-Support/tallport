<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The two_factor_authentications table is no longer used: its data has been
 * imported into Tallport's two-factor authentication
 * (2026_10_02_010104_import_existing_two_factor_data).
 */
class DropTwoFactorAuthenticationsTable extends Migration
{
    public function up()
    {
        Schema::dropIfExists('two_factor_authentications');
    }

    public function down()
    {
        // Its data can't be brought back; users keep Tallport's.
    }
}
