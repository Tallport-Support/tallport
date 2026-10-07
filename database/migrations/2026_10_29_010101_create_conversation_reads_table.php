<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which conversations each user has read (App\ConversationRead): up to which thread. What came
 * before is read for everyone, so the lists don't start full of unread conversations.
 */
class CreateConversationReadsTable extends Migration
{
    public function up()
    {
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('conversation_id');
            // The last thread when the user read it; null: marked as unread.
            $table->unsignedInteger('thread_id')->nullable();

            $table->primary(['user_id', 'conversation_id']);
            $table->index('conversation_id');
        });
        \Option::set(\App\ConversationRead::OPTION_READ_UP_TO, (int) \DB::table('threads')->max('id'));
    }

    public function down()
    {
        Schema::dropIfExists('conversation_reads');
        \Option::remove(\App\ConversationRead::OPTION_READ_UP_TO);
    }
}
