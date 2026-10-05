<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Conversations no longer have a chat type (3): their channel (Telegram, Nostr)
 * says how they came in. They become ordinary conversations, as new ones are.
 */
class ConversationsWithoutTheChatType extends Migration
{
    public function up()
    {
        \DB::table('conversations')->where('type', 3)->update(['type' => 1]);
    }

    public function down()
    {
        \DB::table('conversations')->where('type', 1)->whereNotNull('channel')->update(['type' => 3]);
    }
}
