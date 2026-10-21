<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The local inbox's change log (App\Inbox\Changes): a conversation's list row changed. Its IDs
 * are the browsers' sync cursor; kept 30 days.
 */
class CreateInboxChangesTable extends Migration
{
    public function up()
    {
        Schema::create('inbox_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('conversation_id');
            $table->unsignedInteger('mailbox_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::dropIfExists('inbox_changes');
    }
}
