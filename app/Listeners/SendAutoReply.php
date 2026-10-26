<?php

namespace App\Listeners;

use App\Conversation;
use App\Mailbox;
use App\SendLog;

class SendAutoReply
{
    const CHECK_PERIOD = 180; // min

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle($event)
    {
        $conversation = $event->conversation;

        // no_autoreply meta value is checked in the SendAutoReply job.

        // On its channel: the mailbox's auto reply, or the channel's own (App\AutoReply\ChannelMessages).
        $channel = \App\AutoReply\ChannelMessages::key($conversation);
        if (!$conversation->imported
            && $channel && \App\AutoReply\ChannelMessages::hasAutoReply($conversation->mailbox, $channel)
        ) {
            $thread = $conversation->threads()->first();

            // Do not send auto reply to auto responders.
            if ($thread->isAutoResponder() || \MailHelper::isAutoReplySubject($conversation->subject)) {
                return;
            }
            // Do not send auto replies to bounces.
            if ($thread->isBounce()) {
                return;
            }

            // Do not send auto replies to spam messages.
            if ($conversation->isSpam()) {
                return;
            }

            // A chat's: on its channel.
            if ($channel != 'email') {
                \App\Jobs\SendChatAutoReply::dispatch($conversation->id)->onQueue('emails');

                return;
            }
            // By email: from a mailbox with email.
            if (!$conversation->mailbox->hasEmail()) {
                return;
            }

            if (!\Eventy::filter('autoreply.should_send', true, $conversation)) {
                return;
            }

            // We can not send auto reply to incoming bounce messages, as it will lead to the infinite loop:
            // application will be sending auto replies and mail server will be sending bounce messages to auto replies.
            // Bounce detection can not be 100% reliable.
            // So to prevent infinite loop, we are checking number of auto replies sent to the customer recently.
            $created_at = \Illuminate\Support\Carbon::now()->subMinutes(self::CHECK_PERIOD);

            $auto_replies_sent = SendLog::where('customer_id', $conversation->customer_id)
                ->where('mail_type', SendLog::MAIL_TYPE_AUTO_REPLY)
                ->where('created_at', '>', $created_at)
                ->count();

            if ($auto_replies_sent >= 10) {
                return;
            }

            if ($auto_replies_sent >= 2) {
                // Find conversations from this customer 
                $prev_conversations = Conversation::select('subject', 'id')
                    ->where('customer_id', $conversation->customer_id)
                    ->where('created_at', '>', $created_at)
                    ->get();

                foreach ($prev_conversations as $prev_conv) {
                    if ($prev_conv->subject == $conversation->subject && $prev_conv->id != $conversation->id) {
                        return;
                    }
                }
            }

            // Do not send autoreplies to own mailboxes.
            if ($conversation->customer_email) {
                $is_internal_email = Mailbox::where('email', $conversation->customer_email)->exists();
                if ($is_internal_email) {
                    return;
                }
            }

            // 24h limit has been disabled: https://github.com/freescout-helpdesk/freescout/pull/95
            // Send auto reply once in 24h
            /*$created_at = \Illuminate\Support\Carbon::now()->subDays(1);
            $auto_reply_sent = SendLog::where('customer_id', $conversation->customer_id)
                ->where('mail_type', SendLog::MAIL_TYPE_AUTO_REPLY)
                ->where('created_at', '>', $created_at)
                ->first();

            if ($auto_reply_sent) {
                return;
            }*/

            \App\Jobs\SendAutoReply::dispatch($conversation, $thread, $conversation->mailbox, $conversation->customer)
            ->onQueue('emails');
        }
    }
}
