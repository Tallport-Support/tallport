<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox's email address is optional: email is a channel like the others (Mailbox::hasEmail()).
 * Still unique; a mailbox without one has none (null).
 */
class MailboxesEmailOptional extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('email', 128)->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('email', 128)->nullable(false)->change();
        });
    }
}
