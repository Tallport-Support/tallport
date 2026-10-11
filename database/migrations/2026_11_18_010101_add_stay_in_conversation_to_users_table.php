<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a conversation leaves the folder the user is in, they stay in the conversation
 * (users.stay_in_conversation) or in the folder. It replaces users.after_send: 1 stayed
 * on the conversation, 2 (and unset) went to the next one.
 */
class AddStayInConversationToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('stay_in_conversation')->default(false);
        });
        if (Schema::hasColumn('users', 'after_send')) {
            DB::table('users')->where('after_send', 1)->update(['stay_in_conversation' => true]);
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('after_send');
            });
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('after_send')->nullable();
        });
        DB::table('users')->where('stay_in_conversation', true)->update(['after_send' => 1]);
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stay_in_conversation');
        });
    }
}
