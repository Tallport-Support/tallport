<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ID a sending service gave a sent email (Amazon SES, Mailgun, Postmark,
 * Resend), which replies and delivery reports refer to when the service
 * replaced Tallport's Message-ID.
 */
class AddProviderMessageIdToSendLogs extends Migration
{
    public function up()
    {
        Schema::table('send_logs', function (Blueprint $table) {
            $table->string('provider_message_id', 191)->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('send_logs', function (Blueprint $table) {
            $table->dropIndex(['provider_message_id']);
            $table->dropColumn('provider_message_id');
        });
    }
}
