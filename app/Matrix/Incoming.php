<?php

namespace App\Matrix;

use App\Conversation;
use App\Customer;
use App\Misc\ChatConversations;
use App\Thread;
use Illuminate\Support\Facades\DB;

class Incoming
{
    private $identity;
    private $crypto;

    public function __construct(MatrixMailbox $identity, CryptoManager $crypto)
    {
        $this->identity = $identity;
        $this->crypto = $crypto;
    }

    public function process()
    {
        $records = MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->where('kind', 'incoming')->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('retry_at')->orWhere('retry_at', '<=', now());
            })->orderBy('sort_order')->orderBy('id')->limit(100)->get();
        foreach ($records as $record) {
            $room = MatrixRoom::forRoom($this->identity->id, $record->room_id);
            $event = $record->payload;
            if (!($room->state['supported'] ?? false) || !in_array($event['sender'] ?? '', [$room->customer_user_id, $this->identity->user_id], true)) {
                $record->status = 'ignored';
                $record->payload = null;
                $record->save();
                continue;
            }
            try {
                if ($event['type'] === 'm.room.encrypted') {
                    $message = $this->crypto->decrypt($room, $event);
                    if ($message === null) {
                        $this->pending($room, $record, __('Waiting for the encrypted message key.'));
                        $this->requestKey($record, $event);
                        continue;
                    }
                } else {
                    if (isset($room->state['encryption'])) {
                        throw new \InvalidArgumentException('Plaintext in an encrypted Matrix room.');
                    }
                    $message = $event;
                }
                if (($message['type'] ?? '') !== 'm.room.message' || ($message['content']['m.relates_to']['rel_type'] ?? null) === 'm.replace') {
                    $record->status = 'ignored';
                    $record->payload = null;
                    $record->save();
                    continue;
                }
                if (!in_array($message['content']['msgtype'] ?? '', ['m.text', 'm.notice', 'm.emote', 'm.file', 'm.image', 'm.audio', 'm.video'], true)) {
                    $this->pending($room, $record, __('Unsupported Matrix message type.'));
                    $record->status = 'ignored';
                    $record->payload = null;
                    $record->save();
                    continue;
                }
                [$body, $attachments] = $this->content($message['content'], $event['type'] === 'm.room.encrypted');
                DB::transaction(function () use ($record, $room, $message, $body, $attachments) {
                    if (isset($message['replay_hash'])) {
                        $replay = MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->where('replay_hash', $message['replay_hash'])->where('id', '!=', $record->id)->exists();
                        if ($replay) {
                            throw new \InvalidArgumentException('Replayed Matrix message index.');
                        }
                        $record->replay_hash = $message['replay_hash'];
                    }
                    if ($record->thread_id) {
                        $thread = Thread::find($record->thread_id);
                        if ($thread && $thread->getMeta('chat_pending')) {
                            ChatConversations::resolvePending($thread, $body, $attachments);
                        }
                    } elseif (!$record->conversation_id) {
                        $this->store($room, $record, $body, $attachments, false);
                    }
                    $record->status = 'received';
                    $record->payload = null;
                    $record->save();
                });
            } catch (MatrixException $e) {
                \App\Misc\ChatLog::failure('matrix', $this->identity->mailbox_id, 'receive', $e);
                $record->attempts++;
                $this->pending($room, $record, __('Waiting for Matrix connection or device verification.'));
                $record->retry_at = now()->addMinutes(5);
                $record->save();
            } catch (\InvalidArgumentException | \JsonException | \SodiumException | \TypeError $e) {
                \App\Misc\ChatLog::failure('matrix', $this->identity->mailbox_id, 'receive', $e);
                $this->pending($room, $record, __('Matrix message could not be authenticated.'));
                $record->status = 'rejected';
                $record->payload = null;
                $record->save();
            }
        }
    }

    private function pending(MatrixRoom $room, MatrixEvent $record, $text)
    {
        if ($record->thread_id || $record->conversation_id) {
            $thread = $record->thread_id ? Thread::find($record->thread_id) : null;
            if ($thread && $thread->getMeta('chat_pending')) {
                ChatConversations::updatePending($thread, '<p>'.e($text).'</p>');
            }
            return;
        }
        DB::transaction(function () use ($room, $record, $text) {
            $this->store($room, $record, '<p>'.e($text).'</p>', [], true);
            $record->save();
        });
    }

    private function store(MatrixRoom $room, MatrixEvent $record, $body, array $attachments, $pending)
    {
        $customer = $room->customer_id ? Customer::find($room->customer_id) : null;
        if (!$customer) {
            $other = MatrixRoom::where('customer_hash', $room->customer_hash)->whereNotNull('customer_id')->first();
            $customer = $other ? Customer::find($other->customer_id) : null;
            $customer = $customer ?: Customer::createWithoutEmail(['first_name' => mb_substr($room->customer_user_id, 0, 255)]);
            $room->customer_id = $customer->id;
        }
        $mailbox = $this->identity->mailbox;
        $conversation = $room->conversation;
        if (!ChatConversations::canContinue($conversation, $mailbox)) {
            $conversation = null;
        }
        $data = ['mailbox_id' => $mailbox->id, 'channel' => Matrix::CHANNEL, 'source_type' => Conversation::SOURCE_TYPE_API,
            'subject' => Conversation::subjectFromText(strip_tags($body)) ?: 'Matrix', 'status' => Conversation::STATUS_ACTIVE];
        $message = ['body' => $body, 'attachments' => $attachments, 'after_commit' => true, 'chat_pending' => $pending];
        $result = $record->payload['sender'] === $this->identity->user_id
            ? ChatConversations::receiveMailboxReply($conversation, $customer, $data, $message, $this->identity->user_id)
            : ChatConversations::receive($conversation, $customer, $data, $message);
        if (!$result || !$result['thread']) {
            throw new \RuntimeException('Matrix message could not be saved.');
        }
        $conversation = $result['conversation'];
        $conversation->setMeta('matrix', ['identity' => $this->identity->id, 'room' => $room->room_id]);
        $conversation->save();
        $room->conversation_id = $conversation->id;
        $room->save();
        $record->thread_id = $result['thread']->id;
        $record->conversation_id = $conversation->id;
    }

    private function content(array $content, $encrypted)
    {
        $text = is_string($content['body'] ?? null) ? $content['body'] : '';
        $attachments = [];
        if (in_array($content['msgtype'], ['m.file', 'm.image', 'm.audio', 'm.video'], true)) {
            if (($content['info']['size'] ?? 0) > Client::MAX_FILE_BYTES) {
                throw new \InvalidArgumentException('Matrix attachment is too large.');
            }
            $file = $content['file'] ?? null;
            if ($encrypted && !is_array($file)) {
                throw new \InvalidArgumentException('Unencrypted file in an encrypted Matrix message.');
            }
            $bytes = $this->identity->client()->download($file['url'] ?? $content['url'] ?? '');
            if ($file) {
                $bytes = \App\Matrix\Crypto\Attachment::decrypt($bytes, $file);
            }
            $attachments[] = ['file_name' => mb_substr(basename($content['filename'] ?? $text ?: 'file'), 0, 255),
                'data' => base64_encode($bytes), 'mime_type' => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream'];
        }

        return ['<p>'.nl2br(e($text ?: __('(empty message)'))).'</p>', $attachments];
    }

    private function requestKey(MatrixEvent $record, array $event)
    {
        if ($record->attempts >= 3) {
            $record->retry_at = now()->addHour();
            $record->save();
            $thread = $record->thread_id ? Thread::find($record->thread_id) : null;
            if ($thread && $thread->getMeta('chat_pending')) {
                ChatConversations::updatePending($thread, '<p>'.e(__('Encrypted message key is unavailable.')).'</p>');
            }
            return;
        }
        if ($record->retry_at && $record->retry_at->isFuture()) {
            return;
        }
        $content = $event['content'];
        $sender_device = null;
        foreach ($this->crypto->trusted($event['sender']) as $device) {
            if ($device['curve'] === ($content['sender_key'] ?? null)) {
                $sender_device = $device['id'];
                break;
            }
        }
        if (!$sender_device) {
            throw new MatrixException('Matrix sending device is unavailable.');
        }
        DB::transaction(function () use ($record, $event, $content, $sender_device) {
            MatrixEvent::outgoing($this->identity->id, 'key_request:'.$record->id.':'.$record->attempts, 'to_device', ['type' => 'm.room_key_request',
                'messages' => [$event['sender'] => [$sender_device => ['action' => 'request', 'requesting_device_id' => $this->identity->device_id,
                    'request_id' => 'tallport-'.$record->id, 'body' => ['algorithm' => 'm.megolm.v1.aes-sha2', 'room_id' => $record->room_id,
                        'sender_key' => $content['sender_key'] ?? '', 'session_id' => $content['session_id'] ?? '']]]],]);
            $record->attempts++;
            $record->retry_at = now()->addMinutes(5);
            $record->save();
        });
    }
}
