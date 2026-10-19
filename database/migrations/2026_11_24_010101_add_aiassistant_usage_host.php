<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an AI call ran on the keys the host provides (App\Ai\Settings::HOST): what the host bills.
 */
class AddAiassistantUsageHost extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->boolean('host')->default(false);
        });
    }

    public function down()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->dropColumn('host');
        });
    }
}
