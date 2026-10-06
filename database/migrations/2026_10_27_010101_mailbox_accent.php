<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each mailbox's color (one of FruitUI's accents): its conversations' mark in views across
 * mailboxes and its icon in the sidebar. Existing mailboxes get them in order.
 */
class MailboxAccent extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('accent', 20)->nullable();
        });
        $accents = \FruitUI\Fruit::ACCENTS;
        foreach (\DB::table('mailboxes')->orderBy('id')->pluck('id')->values() as $i => $id) {
            \DB::table('mailboxes')->where('id', $id)->update(['accent' => $accents[$i % count($accents)]]);
        }
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('accent');
        });
    }
}
