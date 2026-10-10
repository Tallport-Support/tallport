<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class MoveNostrChatWindowToMailboxes extends Migration
{
    public function up()
    {
        DB::table('mailboxes')
            ->join('nostr_mailboxes', 'nostr_mailboxes.mailbox_id', '=', 'mailboxes.id')
            ->select('mailboxes.id', 'mailboxes.meta', 'nostr_mailboxes.reopen_days')
            ->chunkById(100, function ($mailboxes) {
                foreach ($mailboxes as $mailbox) {
                    $meta = json_decode((string) $mailbox->meta, true) ?: [];
                    if (!array_key_exists('chat_reopen_days', $meta)) {
                        $meta['chat_reopen_days'] = (int) $mailbox->reopen_days ?: 30;
                        DB::table('mailboxes')->where('id', $mailbox->id)->update(['meta' => json_encode($meta)]);
                    }
                }
            }, 'mailboxes.id', 'id');
    }

    /**
     * Keep both the shared setting and the original Nostr value on rollback.
     */
    public function down()
    {
    }
}
