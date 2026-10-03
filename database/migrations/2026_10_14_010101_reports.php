<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reports (App\Reports): agents' replies with how long the customer
 * waited for them, filled by tallport:report-replies. Modules adding
 * reports are switched off.
 */
class Reports extends Migration
{
    public function up()
    {
        Schema::create('report_replies', function (Blueprint $table) {
            $table->unsignedInteger('thread_id')->primary();
            $table->unsignedInteger('conversation_id')->index();
            $table->unsignedInteger('user_id')->nullable();
            $table->dateTime('replied_at')->index();
            // Seconds since the customer's first message not answered before;
            // null when the reply didn't answer a customer.
            $table->unsignedInteger('response_time')->nullable();
            // The conversation's first reply to the customer.
            $table->boolean('first')->default(false);
        });

        if (Schema::hasColumn('conversations', 'rpt_ready')) {
            // Its index goes with it.
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropColumn('rpt_ready');
            });
        }

        try {
            if (\App\Module::isActive('reports')) {
                \App\Module::setActive('reports', false);
            }
        } catch (\Throwable $e) {
            // The modules aren't loaded (fresh install).
        }
    }

    public function down()
    {
        Schema::dropIfExists('report_replies');
    }
}
