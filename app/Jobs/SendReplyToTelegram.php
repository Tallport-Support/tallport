<?php

namespace App\Jobs;

use App\Attachment;
use App\Misc\ChatDelivery;
use App\SendLog;
use App\Telegram\Formatter;
use App\Telegram\Telegram;
use App\Telegram\TelegramException;
use App\Telegram\TelegramSend;
use App\Thread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Send an agent's reply to the customer's Telegram chat: the text, then
 * the files. Like email replies, a reply that can't be sent is retried,
 * then shown as not sent and its conversation reopened. Parts already sent
 * are not sent again.
 */
class SendReplyToTelegram implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Seconds before each next try.
     */
    const RETRY_DELAYS = [60, 300, 900, 3600, 3600];

    /**
     * Telegram's limit for photos; larger images are sent as files.
     */
    const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

    public $thread_id;

    public $tries = 6;

    public $timeout = 300;

    /**
     * Telegram messages this try sent (Outgoing Telegram log).
     */
    protected $sent_message_ids = [];

    public function __construct($thread_id)
    {
        $this->thread_id = $thread_id;
        $this->onConnection(\Helper::queueConnection('emails'));
        $this->onQueue('emails');
    }

    public function handle()
    {
        $thread = ChatDelivery::findReply($this->thread_id);
        // Undone (a draft again), deleted or sent already.
        if (!$thread || $thread->isSendStatusSuccess()) {
            return;
        }
        $conversation = $thread->conversation;
        $mailbox = $conversation->mailbox;
        $this->sent_message_ids = [];

        try {
            if (!Telegram::isEnabled($mailbox)) {
                throw new TelegramException('Telegram is not set up for this mailbox.', 400);
            }
            $chat_id = $conversation->customer ? $conversation->customer->getChannelId(Telegram::CHANNEL) : null;
            if (!$chat_id) {
                throw new TelegramException('The customer has no Telegram chat.', 400);
            }
            $this->send($thread, Telegram::client($mailbox), $chat_id);
        } catch (TelegramException $e) {
            $this->failedTry($thread, $e);

            return;
        }
        if ($thread->fresh()->state != Thread::STATE_PUBLISHED) {
            return;
        }

        ChatDelivery::recordStatus($thread, SendLog::STATUS_ACCEPTED, ['msg' => '']);
        TelegramSend::record($thread, $this->attempts(), TelegramSend::STATUS_SENT, $this->sent_message_ids);
    }

    protected function send(Thread $thread, $client, $chat_id)
    {
        $data = $thread->getSendStatusData();
        $sent = (array) ($data['telegram_sent'] ?? []);
        // Message IDs, for Undo.
        $message_ids = (array) ($data['telegram_messages'] ?? []);
        $done = function ($part, $message) use ($thread, &$sent, &$message_ids) {
            $sent[] = $part;
            if (!empty($message['message_id'])) {
                $message_ids[] = $message['message_id'];
                $this->sent_message_ids[] = $message['message_id'];
            }
            $thread->updateSendStatusData(['telegram_sent' => $sent, 'telegram_messages' => $message_ids]);
            $thread->save();
        };
        // Undone while sending: no more parts.
        $undone = function () use ($thread) {
            return Thread::where('id', $thread->id)->value('state') != Thread::STATE_PUBLISHED;
        };

        foreach (Formatter::messages($thread->body) as $i => $message) {
            if (in_array('text'.$i, $sent)) {
                continue;
            }
            if ($undone()) {
                return;
            }
            [$text, $is_html] = $message;
            try {
                $result = $client->sendMessage($chat_id, $text, $is_html);
            } catch (TelegramException $e) {
                // Formatting Telegram doesn't take: send it as plain text.
                if (!$is_html || stripos($e->getMessage(), 'parse entities') === false) {
                    throw $e;
                }
                $result = $client->sendMessage($chat_id, Formatter::plain($text));
            }
            $done('text'.$i, $result);
        }

        foreach ($thread->attachments as $attachment) {
            if (in_array('file'.$attachment->id, $sent)) {
                continue;
            }
            if ($undone()) {
                return;
            }
            $contents = $attachment->getFileContents();
            if ((string) $contents === '') {
                throw new TelegramException('The file '.$attachment->file_name.' is missing.', 400);
            }
            $as_photo = $attachment->type == Attachment::TYPE_IMAGE
                && in_array(strtolower($attachment->mime_type), ['image/jpeg', 'image/png', 'image/webp'])
                && strlen($contents) <= self::MAX_PHOTO_BYTES;
            $done('file'.$attachment->id, $client->sendFile($chat_id, $contents, $attachment->file_name, $as_photo));
        }
    }

    /**
     * Undo: delete what has been sent of the reply from the customer's chat
     * (bots can delete their messages for 48 hours), so that it can be sent
     * again as a new reply.
     */
    public static function undo(Thread $thread)
    {
        $data = $thread->getSendStatusData();
        $conversation = $thread->conversation;
        $chat_id = $conversation->customer ? $conversation->customer->getChannelId(Telegram::CHANNEL) : null;
        if ($chat_id && Telegram::isEnabled($conversation->mailbox)) {
            foreach ((array) ($data['telegram_messages'] ?? []) as $message_id) {
                try {
                    Telegram::client($conversation->mailbox)->deleteMessage($chat_id, $message_id);
                } catch (TelegramException $e) {
                    Telegram::log('Undo: message '.$message_id.' of reply '.$thread->id.' not deleted: '.$e->getMessage(), $conversation->mailbox);
                }
            }
        }

        ChatDelivery::recordStatus($thread, null, ['msg' => '', 'telegram_sent' => [], 'telegram_messages' => []]);
    }

    protected function failedTry(Thread $thread, TelegramException $e)
    {
        Telegram::log('Reply '.$thread->id.' in conversation #'.$thread->conversation->number.' not sent (try '.$this->attempts().'): '.$e->getMessage(), $thread->conversation->mailbox);

        $retry = !$e->isPermanent() && $this->attempts() < $this->tries;
        TelegramSend::record($thread, $this->attempts(), $retry ? TelegramSend::STATUS_RETRYING : TelegramSend::STATUS_FAILED, $this->sent_message_ids, $e->getMessage());

        if ($retry) {
            // After about 20 minutes the agent sees it hasn't been sent yet.
            if ($this->attempts() >= 3) {
                $this->markNotSent($thread, $e->getMessage(), SendLog::STATUS_SEND_INTERMEDIATE_ERROR);
            }
            $this->release($e->retry_after ?: self::RETRY_DELAYS[$this->attempts() - 1] ?? 3600);

            return;
        }

        $this->markNotSent($thread, $e->getMessage(), SendLog::STATUS_SEND_ERROR);
        // A failed job: Retry sends it again.
        $this->fail($e);
    }

    public function failed(\Throwable $e)
    {
        $thread = Thread::find($this->thread_id);
        if ($thread && !$thread->isSendStatusSuccess() && $thread->send_status != SendLog::STATUS_SEND_ERROR) {
            TelegramSend::record($thread, $this->attempts(), TelegramSend::STATUS_FAILED, $this->sent_message_ids, $e->getMessage());
            $this->markNotSent($thread, $e->getMessage(), SendLog::STATUS_SEND_ERROR);
        }
    }

    protected function markNotSent(Thread $thread, $message, $status)
    {
        ChatDelivery::recordStatus($thread, $status, ['msg' => $message]);
        ChatDelivery::reopenConversation($thread->conversation);
    }
}
