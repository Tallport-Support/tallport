<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox's short name, for its addresses (App\Misc\Addresses): /mailbox/support/drafts.
 */
class AddMailboxesSlug extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->unique();
        });
        foreach (\App\Mailbox::orderBy('id')->get() as $mailbox) {
            \DB::table('mailboxes')->where('id', $mailbox->id)->update(['slug' => \App\Mailbox::makeSlug($mailbox->name, $mailbox->id)]);
        }
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
}
