<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns spatie/laravel-activitylog 4 expects (its published migrations).
 */
class AddEventAndBatchUuidColumnsToActivityLogsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $table_name = config('activitylog.table_name');

        Schema::table($table_name, function (Blueprint $table) use ($table_name) {
            if (!Schema::hasColumn($table_name, 'event')) {
                $table->string('event')->nullable()->after('subject_type');
            }
            if (!Schema::hasColumn($table_name, 'batch_uuid')) {
                $table->uuid('batch_uuid')->nullable()->after('properties');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropColumn(['event', 'batch_uuid']);
        });
    }
}
