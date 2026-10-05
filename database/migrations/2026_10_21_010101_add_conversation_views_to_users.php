<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each user's view of conversations per channel (users/preferences): email or
 * chat, keyed by the channel (email: "email"); unset channels use the defaults.
 */
class AddConversationViewsToUsers extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'conversation_views')) {
                $table->text('conversation_views')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('conversation_views');
        });
    }
}
