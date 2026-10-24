<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox's short name, for its addresses (App\Misc\Addresses): /mailbox/support/drafts.
 * It runs again after a run that stopped half way (MariaDB keeps the column it added): the
 * column and its index once, and a mailbox keeps a short name it has already.
 */
class AddMailboxesSlug extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('mailboxes', 'slug')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->string('slug', 100)->nullable();
            });
        }
        foreach (\App\Mailbox::orderBy('id')->get() as $mailbox) {
            $slug = $mailbox->slug && \App\Mailbox::makeSlug($mailbox->slug, $mailbox->id) === $mailbox->slug
                ? $mailbox->slug
                : \App\Mailbox::makeSlug($mailbox->name, $mailbox->id);
            \DB::table('mailboxes')->where('id', $mailbox->id)->update(['slug' => $slug]);
        }
        if (!collect(Schema::getIndexes('mailboxes'))->contains(fn ($index) => $index['unique'] && $index['columns'] === ['slug'])) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->unique('slug');
            });
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
