<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team chat (App\Livewire\TeamChat): a room per mailbox for the users who work
 * in it, each user's last read message there, and attachments of its messages.
 * Messages are encrypted (App\TeamMessage), hence the longer column.
 */
class TeamChat extends Migration
{
    public function up()
    {
        Schema::create('team_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('user_id');
            $table->mediumText('body');
            $table->timestamps();
            $table->index(['mailbox_id', 'id']);
        });
        Schema::create('team_chat_reads', function (Blueprint $table) {
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('last_read_id')->default(0);
            $table->primary(['mailbox_id', 'user_id']);
        });
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedInteger('team_message_id')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('team_messages');
        Schema::dropIfExists('team_chat_reads');
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn('team_message_id');
        });
    }
}
