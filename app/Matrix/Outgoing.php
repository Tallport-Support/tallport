<?php

namespace App\Matrix;

use App\Misc\ChatDelivery;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\DB;

class Outgoing
{
    public function send(Thread $thread)
    {
        if (!Matrix::isMatrix($thread->conversation) || $thread->getMeta('chat_external_sender')) {
            return;
        }
        $binding = $thread->conversation->getMeta('matrix', []);
        $identity = MatrixMailbox::find($binding['identity'] ?? 0);
        if (!$identity || (int) $identity->mailbox_id !== (int) $thread->conversation->mailbox_id) {
            throw new MatrixException('Matrix conversation account is unavailable.');
        }
        $identity->locked(function () use ($identity, $thread, $binding) {
            $thread->refresh();
            if ($thread->send_status == SendLog::STATUS_ACCEPTED) {
                return;
            }
            if (!$identity->isReady()) {
                throw new MatrixException('Matrix account is not connected.');
            }
            (new Connection())->refresh($identity);
            $room = MatrixRoom::forRoom($identity->id, $binding['room'] ?? '');
            if (!$room->exists) {
                throw new MatrixException('Matrix conversation room is unavailable.');
            }
            RoomState::refresh($identity, $room);
            if (!($room->state['supported'] ?? false)) {
                throw new MatrixException('Matrix room is no longer a private conversation.');
            }
            $crypto = new CryptoManager($identity);
            $encrypted = isset($room->state['encryption']);
            if ($encrypted) {
                $crypto->trusted($identity->user_id);
                if (!$crypto->trusted($room->customer_user_id)) {
                    throw new MatrixException('Matrix customer has no available encryption devices.');
                }
            }
            $audience = CryptoManager::fingerprint(['members' => $room->state['members'] ?? [],
                'devices' => $encrypted ? [$crypto->trusted($identity->user_id), $crypto->trusted($room->customer_user_id)] : [],]);
            $text = ChatDelivery::plainText($thread);
            $parts = [];
            if ($text !== '') {
                $parts['text'] = ['msgtype' => 'm.text', 'body' => $text];
            }
            foreach ($thread->all_attachments as $attachment) {
                $parts['file:'.$attachment->id] = $this->upload($identity, $thread, $attachment, $encrypted);
            }
            if (!$parts) {
                throw new MatrixException('Matrix reply is empty.');
            }
            foreach ($parts as $part => $content) {
                $event = MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('local_key', hash('sha256', 'reply:'.$thread->id.':'.$part))->first();
                if (!$event) {
                    $event = DB::transaction(function () use ($identity, $room, $thread, $part, $content, $crypto, $encrypted, $audience) {
                        $payload = ['audience' => $audience, 'type' => $encrypted ? 'm.room.encrypted' : 'm.room.message',
                            'content' => $encrypted ? $crypto->encrypt($room, 'm.room.message', $content) : $content,];
                        $event = MatrixEvent::outgoing($identity->id, 'reply:'.$thread->id.':'.$part, 'outgoing', $payload, $room->room_id, $thread->id);
                        $event->conversation_id = $thread->conversation_id;
                        $event->save();

                        return $event;
                    });
                }
                if ($event->status === 'cancelled') {
                    throw new MatrixException('Matrix reply belongs to a retired sending device.');
                }
                if ($event->status === 'sent') {
                    continue;
                }
                if (($event->payload['audience'] ?? null) !== $audience) {
                    throw new MatrixException('Matrix recipients changed before this reply was confirmed.');
                }
                if ($encrypted && $event->payload['type'] !== 'm.room.encrypted') {
                    throw new MatrixException('Matrix room encryption changed before this reply was confirmed.');
                }
                Outbox::flush($identity);
                if (MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('kind', 'to_device')->where('status', 'pending')->exists()) {
                    throw new MatrixException('Matrix room keys are still being delivered.');
                }
                Outbox::deliver($identity, $event);
            }
            DB::transaction(function () use ($identity, $thread) {
                ChatDelivery::recordStatus($thread, SendLog::STATUS_ACCEPTED, ['msg' => '']);
                MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('thread_id', $thread->id)
                    ->whereIn('kind', ['outgoing', 'upload'])->where('status', 'sent')->update(['payload' => null]);
            });
        });
    }

    private function upload(MatrixMailbox $identity, Thread $thread, $attachment, $encrypted)
    {
        $key = 'upload:'.$thread->id.':'.$attachment->id.':'.(int) $encrypted;
        $record = MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('local_key', hash('sha256', $key))->first();
        if (!$record) {
            $bytes = $attachment->getFileContents();
            if (!is_string($bytes) || strlen($bytes) > Client::MAX_FILE_BYTES) {
                throw new MatrixException('Matrix reply file is missing or too large.');
            }
            $file = $encrypted ? \App\Matrix\Crypto\Attachment::encrypt($bytes) : ['ciphertext' => $bytes, 'file' => null];
            $record = MatrixEvent::outgoing($identity->id, $key, 'upload', ['bytes' => base64_encode($file['ciphertext']), 'file' => $file['file'],
                'body' => $attachment->file_name, 'mime' => $attachment->mime_type, 'size' => strlen($bytes)], null, $thread->id);
            $record->conversation_id = $thread->conversation_id;
            $record->save();
        }
        $payload = $record->payload;
        if (empty($payload['url'])) {
            $payload['url'] = $identity->client()->upload(base64_decode($payload['bytes']));
            unset($payload['bytes']);
            $record->payload = $payload;
            $record->status = 'sent';
            $record->save();
        }
        $content = ['msgtype' => 'm.file', 'body' => $payload['body'], 'filename' => $payload['body'], 'info' => ['mimetype' => $payload['mime'], 'size' => $payload['size']]];
        if ($payload['file']) {
            $content['file'] = $payload['file'] + ['url' => $payload['url']];
        } else {
            $content['url'] = $payload['url'];
        }

        return $content;
    }
}
