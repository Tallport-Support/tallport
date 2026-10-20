<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website chat visitors blocked by an agent (App\WebChat\Spam), and their last address, kept only
 * as a keyed hash: enough to block it for a while, never shown.
 */
class AddWebChatVisitorsBlocking extends Migration
{
    public function up()
    {
        Schema::table('web_chat_visitors', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
        });
    }

    public function down()
    {
        Schema::table('web_chat_visitors', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'ip_hash']);
        });
    }
}
