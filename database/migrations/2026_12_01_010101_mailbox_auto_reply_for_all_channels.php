<?php

use Illuminate\Database\Migrations\Migration;

/**
 * One auto reply and one greeting per mailbox for all its channels (App\AutoReply\ChannelMessages),
 * each channel behaving as before until changed: the website chat's greeting becomes the mailbox's;
 * Telegram's Start text its own greeting, Nostr's auto reply its own; a channel set up without an
 * auto reply (or Start text, or greeting: Matrix) gets none of its own. Email keeps the mailbox's
 * auto reply, its own of before. The auto reply's switch is for all channels now: a mailbox with
 * only Nostr's has it on, email's none. Versions in other languages are always on now: those that
 * were off go. The old settings stay where they were.
 */
class MailboxAutoReplyForAllChannels extends Migration
{
    public function up()
    {
        foreach (\App\Mailbox::all() as $mailbox) {
            $off = ['mode' => 'off', 'texts' => []];
            $channels = [];
            $web_chat = \App\WebChat\WebChat::settings($mailbox);
            $greeting = ['mode' => ['custom' => 'own', 'none' => 'off'][$web_chat['greeting_mode']] ?? 'tallport', 'texts' => array_filter(['' => $web_chat['greeting']] + $web_chat['greetings'], fn ($text) => trim((string) $text) !== '')];
            if ($web_chat['enabled']) {
                $channels['webchat']['auto_reply'] = $off;
            }
            $telegram = \App\Telegram\Telegram::settings($mailbox);
            if ($telegram['enabled'] && $telegram['token'] !== '') {
                $texts = array_filter(['' => $telegram['auto_reply']] + $telegram['auto_replies'], fn ($text) => trim((string) $text) !== '');
                $channels['telegram']['greeting'] = $texts && trim($telegram['auto_reply']) !== '' ? ['mode' => 'own', 'texts' => $texts] : $off;
                $channels['telegram']['auto_reply'] = $off;
            }
            if (\App\Matrix\MatrixMailbox::forMailbox($mailbox->id)) {
                $channels['matrix'] = ['auto_reply' => $off, 'greeting' => $off];
            }
            $nostr = \App\Nostr\NostrMailbox::where('mailbox_id', $mailbox->id)->where('enabled', true)->first();
            $text = $nostr ? trim((string) $nostr->auto_reply_text) : '';
            $nostr_auto_reply = $nostr && $nostr->auto_reply_enabled && $text !== '';
            if ($nostr) {
                $channels['nostr']['auto_reply'] = $nostr_auto_reply ? ['mode' => 'own', 'texts' => ['' => $text]] : $off;
            }
            if ($nostr_auto_reply && !$mailbox->auto_reply_enabled) {
                $channels['email']['auto_reply'] = $off;
                $mailbox->auto_reply_enabled = true;
                $mailbox->save();
            }
            $mailbox->setMetaParam(\App\AutoReply\ChannelMessages::META, ['greeting' => $greeting, 'channels' => $channels], true);
        }
        \App\MailboxAutoReply::where('enabled', false)->delete();
    }

    public function down()
    {
        foreach (\App\Mailbox::all() as $mailbox) {
            $mailbox->removeMetaParam(\App\AutoReply\ChannelMessages::META, true);
        }
    }
}
