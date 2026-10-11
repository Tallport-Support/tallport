<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email shows as email and channels as chats: there is no choice of conversation view.
 */
class DropConversationViewsFromUsersTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('users', 'conversation_views')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('conversation_views');
            });
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('conversation_views')->nullable();
        });
    }
}
