<?php

use Illuminate\Database\Migrations\Migration;

/**
 * The auto replies chats got, kept as plain text, as HTML (App\AutoReply\AutoReplies::line()): the
 * conversation shows them as the mailbox's message, and the AI reads them.
 */
class AutoRepliesAsHtml extends Migration
{
    public function up()
    {
        \App\Thread::where('type', \App\Thread::TYPE_LINEITEM)
            ->whereIn('action_type', [\App\Thread::ACTION_TYPE_AUTO_REPLY, \App\Thread::ACTION_TYPE_NOSTR_AUTO_REPLY])
            ->select(['id', 'body'])
            ->chunkById(500, function ($threads) {
                foreach ($threads as $thread) {
                    // One sent since the update, before this ran.
                    if (preg_match('/<[a-z][^>]*>/i', (string) $thread->body)) {
                        continue;
                    }
                    \DB::table('threads')->where('id', $thread->id)->update(['body' => \App\AutoReply\AutoReplies::textHtml($thread->body)]);
                }
            });
    }

    public function down()
    {
        //
    }
}
