<?php

namespace App\Jobs;

use App\Attachment;
use App\SendLog;
use App\Telegram\Formatter;
use App\Telegram\Telegram;
use App\Telegram\TelegramException;
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

    public function __construct($thread_id)
    {
        $this->thread_id = $thread_id;
        $this->onQueue('emails');
    }

    public function handle()
    {
        $thread = Thread::find($this->thread_id);
        // Undone (a draft again), deleted or sent already.
        if (!$thread || $thread->state != Thread::STATE_PUBLISHED || $thread->type != Thread::TYPE_MESSAGE || $thread->isSendStatusSuccess()) {
            return;
        }
        $conversation = $thread->conversation;
        $mailbox = $conversation->mailbox;

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

        $thread->send_status = SendLog::STATUS_ACCEPTED;
        $thread->updateSendStatusData(['msg' => '']);
        $thread->save();
    }

    protected function send(Thread $thread, $client, $chat_id)
    {
        $sent = (array) ($thread->getSendStatusData()['telegram_sent'] ?? []);
        $done = function ($part) use ($thread, &$sent) {
            $sent[] = $part;
            $thread->updateSendStatusData(['telegram_sent' => $sent]);
            $thread->save();
        };

        foreach (Formatter::messages($thread->body) as $i => $message) {
            if (in_array('text'.$i, $sent)) {
                continue;
            }
            [$text, $is_html] = $message;
            try {
                $client->sendMessage($chat_id, $text, $is_html);
            } catch (TelegramException $e) {
                // Formatting Telegram doesn't take: send it as plain text.
                if (!$is_html || stripos($e->getMessage(), 'parse entities') === false) {
                    throw $e;
                }
                $client->sendMessage($chat_id, Formatter::plain($text));
            }
            $done('text'.$i);
        }

        foreach ($thread->attachments as $attachment) {
            if (in_array('file'.$attachment->id, $sent)) {
                continue;
            }
            $contents = $attachment->getFileContents();
            if ((string) $contents === '') {
                throw new TelegramException('The file '.$attachment->file_name.' is missing.', 400);
            }
            $as_photo = $attachment->type == Attachment::TYPE_IMAGE
                && in_array(strtolower($attachment->mime_type), ['image/jpeg', 'image/png', 'image/webp'])
                && strlen($contents) <= self::MAX_PHOTO_BYTES;
            $client->sendFile($chat_id, $contents, $attachment->file_name, $as_photo);
            $done('file'.$attachment->id);
        }
    }

    protected function failedTry(Thread $thread, TelegramException $e)
    {
        Telegram::log('Reply '.$thread->id.' in conversation #'.$thread->conversation->number.' not sent (try '.$this->attempts().'): '.$e->getMessage(), $thread->conversation->mailbox);

        if (!$e->isPermanent() && $this->attempts() < $this->tries) {
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
            $this->markNotSent($thread, $e->getMessage(), SendLog::STATUS_SEND_ERROR);
        }
    }

    protected function markNotSent(Thread $thread, $message, $status)
    {
        $thread->send_status = $status;
        $thread->updateSendStatusData(['msg' => $message]);
        $thread->save();
        SendReplyToCustomer::reopenConversation($thread->conversation);
    }
}
