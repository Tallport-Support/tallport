<?php

namespace App\Jobs;

use App\AutoReply\ChannelMessages;
use App\Conversation;
use App\Misc\HtmlToText;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A new chat conversation's auto reply (App\AutoReply\ChannelMessages: the mailbox's, or the
 * channel's own), in the customer's language: sent on Telegram, Matrix and Nostr, shown by the website
 * chat's window; a line in the conversation says it was ("System sent the auto reply").
 */
class SendChatAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $conversation_id;

    /**
     * Tried again only while a Matrix account is busy (syncing).
     */
    public $tries = 5;

    public function __construct($conversation_id)
    {
        $this->conversation_id = $conversation_id;
    }

    public function handle()
    {
        $conversation = Conversation::find($this->conversation_id);
        // Once per conversation.
        if ($conversation && $conversation->threads()->where('type', Thread::TYPE_LINEITEM)->where('action_type', Thread::ACTION_TYPE_AUTO_REPLY)->exists()) {
            return;
        }
        $mailbox = $conversation ? $conversation->mailbox : null;
        $auto_reply = $mailbox ? ChannelMessages::autoReply($conversation, $mailbox) : null;
        if (!$auto_reply) {
            return;
        }
        $data = ['mailbox' => $mailbox, 'conversation' => $conversation, 'customer' => $conversation->customer];
        $text = trim(HtmlToText::convert(\MailHelper::replaceMailVars($auto_reply['message'], $data)));
        if ($text === '') {
            return;
        }

        switch (ChannelMessages::key($conversation)) {
            case 'telegram':
                $chat_id = $conversation->customer ? $conversation->customer->getChannelId(\App\Telegram\Telegram::CHANNEL) : null;
                if (!$chat_id) {
                    return;
                }
                try {
                    \App\Telegram\Telegram::client($mailbox)->sendMessage($chat_id, $text);
                } catch (\App\Telegram\TelegramException $e) {
                    \App\Telegram\Telegram::log('Auto reply not sent: '.$e->getMessage(), $mailbox, 'failed', 'send');

                    return;
                }
                break;
            case 'matrix':
                try {
                    (new \App\Matrix\Outgoing())->conversationNotice($conversation, 'auto_reply:'.$conversation->id, $text);
                } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                    // Its account is busy: in a moment (sent once, Outgoing::notice()).
                    if ($this->job && $this->attempts() < $this->tries) {
                        $this->release(30);
                    }

                    return;
                } catch (\Throwable $e) {
                    \App\Misc\ChatLog::failure('matrix', $mailbox->id, 'send', $e, ['message' => 'auto_reply']);

                    return;
                }
                break;
            case 'nostr':
                // Sent, with its line, by the Nostr sender.
                $cfg = \App\Nostr\NostrMailbox::where('mailbox_id', $mailbox->id)->where('enabled', true)->first();
                $pubkey = \App\Nostr\NostrEvent::chatKey($conversation->id);
                if ($cfg && $pubkey) {
                    (new \App\Nostr\OutgoingMessageSender(\App\Nostr\Nostr::logger()))->sendAutoReply($conversation->id, $cfg->id, $pubkey, $text);
                }

                return;
            case 'webchat':
                // The window shows the line (WebChatController).
                break;
            default:
                return;
        }

        $thread = new Thread();
        $thread->conversation_id = $conversation->id;
        $thread->type = Thread::TYPE_LINEITEM;
        $thread->action_type = Thread::ACTION_TYPE_AUTO_REPLY;
        $thread->body = $text;
        $thread->status = $conversation->status;
        $thread->state = Thread::STATE_PUBLISHED;
        $thread->source_via = Thread::PERSON_USER;
        $thread->source_type = Thread::SOURCE_TYPE_API;
        // No person: "System sent the auto reply".
        $thread->customer_id = null;
        $thread->created_by_user_id = null;
        $thread->save();
    }
}
