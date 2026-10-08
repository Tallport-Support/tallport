<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An address emails couldn't reach (App\Misc\DeliveryReports): when, the kind of
 * report and the reason, until an agent clears it.
 */
class EmailDeliveryProblems extends Migration
{
    public function up()
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->text('delivery_problem')->nullable();
        });
    }

    public function down()
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropColumn('delivery_problem');
        });
    }
}
