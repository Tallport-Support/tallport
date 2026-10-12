<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Activity log properties are JSON, and the channel logs look inside them (properties->status). On
 * PostgreSQL that needs a JSON column, not text; MySQL, MariaDB and SQLite read text as JSON.
 */
class ActivityLogPropertiesJson extends Migration
{
    public function up()
    {
        if (DB::getDriverName() == 'pgsql') {
            DB::statement('ALTER TABLE activity_logs ALTER COLUMN properties TYPE json USING properties::json');
        }
    }

    public function down()
    {
        if (DB::getDriverName() == 'pgsql') {
            DB::statement('ALTER TABLE activity_logs ALTER COLUMN properties TYPE text USING properties::text');
        }
    }
}
