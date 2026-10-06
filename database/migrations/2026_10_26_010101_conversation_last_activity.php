<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations' last activity (any message, note, assignment or status change), for the
 * list's Last Activity order, and each user's chosen order per folder type.
 */
class ConversationLastActivity extends Migration
{
    public function up()
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable();
            $table->index(['mailbox_id', 'last_activity_at']);
        });
        // What's known so far: the last message, or the team's last action.
        \DB::statement('UPDATE conversations SET last_activity_at = GREATEST(COALESCE(last_reply_at, created_at), COALESCE(user_updated_at, created_at))');

        Schema::table('users', function (Blueprint $table) {
            $table->text('list_sorting')->nullable();
        });
    }

    public function down()
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['mailbox_id', 'last_activity_at']);
            $table->dropColumn('last_activity_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('list_sorting');
        });
    }
}
