<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manage » Logs » Outgoing Telegram: each try at sending a reply to a customer's
 * Telegram chat (App\Telegram\TelegramSend), sent or failed. Outgoing Nostr reads
 * nostr_events, which gets an index for its outgoing messages.
 */
class OutgoingChannelLogs extends Migration
{
    public function up()
    {
        Schema::create('telegram_sends', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('mailbox_id');
            $table->unsignedInteger('conversation_id')->nullable();
            $table->unsignedInteger('thread_id')->nullable()->index();
            $table->unsignedInteger('customer_id')->nullable();
            // Which try of the job (it is tried again after a while).
            $table->unsignedTinyInteger('attempt')->default(1);
            // 1 = sent, 2 = failed, 3 = failed, tried again later.
            $table->unsignedTinyInteger('status');
            // JSON: the Telegram messages this try sent.
            $table->text('message_ids')->nullable();
            // The reply's files.
            $table->unsignedSmallInteger('files')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['mailbox_id', 'created_at']);
            $table->index('created_at');
        });

        if (!Schema::hasIndex('nostr_events', ['direction', 'id'])) {
            Schema::table('nostr_events', function (Blueprint $table) {
                $table->index(['direction', 'id']);
            });
        }
    }

    public function down()
    {
        if (Schema::hasIndex('nostr_events', ['direction', 'id'])) {
            Schema::table('nostr_events', function (Blueprint $table) {
                $table->dropIndex(['direction', 'id']);
            });
        }
        Schema::dropIfExists('telegram_sends');
    }
}
