<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team chat: pinned messages (seen by everyone in the room), and the room a
 * user's Team Chat opens (App\TeamMessage::roomFor()).
 */
class TeamChatPins extends Migration
{
    public function up()
    {
        Schema::table('team_messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedInteger('pinned_by_user_id')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('team_chat_mailbox_id')->nullable();
        });
    }

    public function down()
    {
        Schema::table('team_messages', function (Blueprint $table) {
            $table->dropColumn(['pinned_at', 'pinned_by_user_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('team_chat_mailbox_id');
        });
    }
}
