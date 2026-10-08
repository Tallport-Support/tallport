<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AllowUnknownAiUsage extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->nullable()->default(null)->change();
            $table->unsignedInteger('output_tokens')->nullable()->default(null)->change();
        });
    }

    public function down()
    {
        \DB::table('aiassistant_usage')->whereNull('input_tokens')->update(['input_tokens' => 0]);
        \DB::table('aiassistant_usage')->whereNull('output_tokens')->update(['output_tokens' => 0]);

        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->unsignedInteger('input_tokens')->default(0)->change();
            $table->unsignedInteger('output_tokens')->default(0)->change();
        });
    }
}
