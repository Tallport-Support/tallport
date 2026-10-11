<?php

namespace App\Matrix;

class Outbox
{
    public static function flush(MatrixMailbox $identity, $kind = 'to_device')
    {
        $events = MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('kind', $kind)->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('retry_at')->orWhere('retry_at', '<=', now());
            })
            ->orderBy('id')->limit(20)->get();
        foreach ($events as $event) {
            self::deliver($identity, $event);
        }
    }

    public static function deliver(MatrixMailbox $identity, MatrixEvent $event)
    {
        $event->refresh();
        if ($event->status !== 'pending') {
            return;
        }
        if ($event->retry_at && $event->retry_at->isFuture()) {
            throw new MatrixException('Matrix delivery is waiting to retry.', 429, max(1, $event->retry_at->timestamp - time()));
        }
        try {
            $payload = $event->payload;
            if ($event->kind === 'to_device') {
                if ($event->room_id && isset($payload['recipient'])) {
                    $room = RoomState::refresh($identity, MatrixRoom::forRoom($identity->id, $event->room_id));
                    $recipient = $payload['recipient'];
                    $device = (new CryptoManager($identity))->trusted($recipient['user'])[$recipient['device']['id']] ?? null;
                    if (!($room->state['supported'] ?? false) || !in_array($recipient['user'], [$identity->user_id, $room->customer_user_id], true)
                        || !$device || $device['signing'] !== $recipient['device']['signing'] || $device['curve'] !== $recipient['device']['curve']) {
                        $event->status = 'cancelled';
                        $event->save();
                        return;
                    }
                }
                $identity->client()->call('PUT', 'v3/sendToDevice/'.rawurlencode($payload['type']).'/'.$event->transaction_id, ['messages' => $payload['messages']]);
                $event->payload = null;
            } else {
                $response = $identity->client()->call('PUT', 'v3/rooms/'.rawurlencode($event->room_id).'/send/'.rawurlencode($payload['type']).'/'.$event->transaction_id, $payload['content']);
                if (!is_string($response['event_id'] ?? null) || $response['event_id'] === '') {
                    throw new MatrixException('Invalid Matrix send response.');
                }
                $event->remote_id = $response['event_id'];
                $event->remote_hash = hash('sha256', $response['event_id']);
            }
            $event->status = 'sent';
            $event->save();
        } catch (MatrixException $e) {
            $event->attempts++;
            $event->retry_at = now()->addSeconds(max($e->retry_after, min(3600, 30 * (2 ** min($event->attempts, 6)))));
            $event->save();
            throw $e;
        }
    }
}
