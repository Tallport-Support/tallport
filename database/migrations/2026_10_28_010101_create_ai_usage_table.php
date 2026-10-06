<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI Assistant's tokens, call by call: per conversation (its sidebar), per mailbox and
 * day (the daily budget) and per customer (the hourly cap on chat translations).
 */
class CreateAiUsageTable extends Migration
{
    public function up()
    {
        Schema::create('aiassistant_usage', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('mailbox_id')->nullable();
            $table->unsignedInteger('conversation_id')->nullable();
            $table->unsignedInteger('customer_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('feature', 20);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            // Messages a call translated (a chat's are translated together).
            $table->unsignedSmallInteger('items')->default(1);
            $table->timestamp('created_at')->nullable();

            $table->index('conversation_id');
            $table->index(['mailbox_id', 'created_at']);
            $table->index(['customer_id', 'feature', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('aiassistant_usage');
    }
}
