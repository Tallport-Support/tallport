<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where to go after sending is the user's own preference (users.after_send),
 * moved there from each mailbox in 2026_10_19_010101.
 */
class DropAfterSendFromMailboxUserTable extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('mailbox_user', 'after_send')) {
            Schema::table('mailbox_user', function (Blueprint $table) {
                $table->dropColumn('after_send');
            });
        }
    }

    public function down()
    {
        Schema::table('mailbox_user', function (Blueprint $table) {
            $table->unsignedTinyInteger('after_send')->default(2);
        });
    }
}
