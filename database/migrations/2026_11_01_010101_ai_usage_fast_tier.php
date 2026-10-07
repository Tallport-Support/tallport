<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI log: whether a call used the provider's faster, pricier tier (fast mode), besides
 * the fast options (less reasoning) in "fast".
 */
class AiUsageFastTier extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->boolean('fast_tier')->default(false)->after('fast');
        });
    }

    public function down()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->dropColumn('fast_tier');
        });
    }
}
