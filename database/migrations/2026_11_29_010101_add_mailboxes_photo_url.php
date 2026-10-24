<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A mailbox's image (Mailbox::savePhoto()), as users and customers have a photo: what customers
 * see beside its name in chats.
 */
class AddMailboxesPhotoUrl extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('photo_url', 255)->nullable();
        });
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('photo_url');
        });
    }
}
