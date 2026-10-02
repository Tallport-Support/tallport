<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram: update IDs, to recognise updates Telegram sends again. Bots
 * already set up for a mailbox get their webhook pointed at Tallport, and a
 * telegramintegration module, which would answer the same bots, is switched
 * off.
 */
class CreateTelegramUpdatesTable extends Migration
{
    public function up()
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->unsignedInteger('mailbox_id');
            $table->unsignedBigInteger('update_id');
            $table->timestamp('created_at')->nullable()->index();

            $table->primary(['mailbox_id', 'update_id']);
        });

        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('alias', 'telegramintegration')->update(['active' => false]);
        }

        self::registerWebhooks();
    }

    /**
     * Point the webhook of each mailbox's bot at Tallport.
     */
    public static function registerWebhooks()
    {
        foreach (\App\Mailbox::all() as $mailbox) {
            if (!\App\Telegram\Telegram::isEnabled($mailbox)) {
                continue;
            }
            try {
                \App\Telegram\Telegram::registerWebhook($mailbox);
            } catch (\Throwable $e) {
                // The mailbox's Telegram page says so; saving it tries again.
                \App\Telegram\Telegram::log('Webhook not set up during the update: '.$e->getMessage(), $mailbox);
            }
        }
    }

    public function down()
    {
        Schema::dropIfExists('telegram_updates');
    }
}
