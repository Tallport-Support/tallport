<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website chat visitors (App\WebChat): a browser on a website, known by the token its chat window
 * keeps. A customer once they write (or sign in on the website); their conversation, followed
 * whichever channel it moves to.
 */
class CreateWebChatVisitorsTable extends Migration
{
    public function up()
    {
        Schema::create('web_chat_visitors', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id')->index();
            // SHA-256 of the token the window keeps.
            $table->string('token', 64)->unique();
            $table->unsignedInteger('customer_id')->nullable()->index();
            $table->unsignedInteger('conversation_id')->nullable()->index();
            // Signed in on the website (App\WebChat\Identity): who the website says they are, and what it
            // tells the team about them (plan, account ID).
            $table->string('identity', 64)->nullable()->index();
            $table->text('data')->nullable();
            // The newest of the team's messages the visitor has seen (the unread count).
            $table->unsignedInteger('read_thread_id')->default(0);
            // The page of the website they were on.
            $table->string('page', 2048)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('web_chat_visitors');
    }
}
