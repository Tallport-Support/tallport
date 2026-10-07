<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI log (Manage » Logs » AI): every call to a model in aiassistant_usage, the failed ones
 * too (no tokens), with its provider, model, duration and outcome.
 */
class AiUsageLog extends Migration
{
    public function up()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            // App\Ai\Usage::STATUS_*: only "ok" rows count towards the budgets and caps.
            $table->string('status', 20)->default('ok');
            // The provider set up in the settings (its id, and its preset: Providers::PRESETS).
            $table->string('provider_id', 20)->nullable();
            $table->string('provider', 30)->nullable();
            $table->string('model', 191)->nullable();
            $table->boolean('backup')->default(false);
            $table->boolean('fast')->default(false);
            $table->boolean('streamed')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down()
    {
        Schema::table('aiassistant_usage', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['status', 'provider_id', 'provider', 'model', 'backup', 'fast', 'streamed', 'duration_ms', 'error']);
        });
    }
}
