<?php

namespace App\Matrix;

class RoomState
{
    public static function refresh(MatrixMailbox $identity, MatrixRoom $room)
    {
        try {
            $events = $identity->client()->call('GET', 'v3/rooms/'.rawurlencode($room->room_id).'/state');
        } catch (MatrixException $e) {
            if ($e->getCode() === 403) {
                $joined = $identity->client()->call('GET', 'v3/joined_rooms');
                if (is_array($joined['joined_rooms'] ?? null) && !in_array($room->room_id, $joined['joined_rooms'], true)) {
                    self::unavailable($room);
                }
            }
            throw $e;
        }
        self::apply($identity, $room, $events, true);

        return $room;
    }

    public static function apply(MatrixMailbox $identity, MatrixRoom $room, array $events, $full = false)
    {
        $state = $room->state ?: [];
        if ($full) {
            $state['members'] = [];
            $state['join_rule'] = null;
        }
        foreach ($events as $event) {
            $content = $event['content'] ?? [];
            if (!is_string($event['state_key'] ?? null)) {
                continue;
            }
            if ($content instanceof \stdClass) {
                $content = (array) $content;
            }
            if (!is_array($content)) {
                throw new MatrixException('Invalid Matrix room state.');
            }
            if ($event['type'] === 'm.room.member') {
                if (!in_array($content['membership'] ?? null, ['join', 'invite', 'leave', 'ban', 'knock'], true)) {
                    throw new MatrixException('Invalid Matrix membership.');
                }
                $state['members'][$event['state_key']] = $content['membership'] ?? 'leave';
            } elseif ($event['type'] === 'm.room.join_rules' && $event['state_key'] === '') {
                $state['join_rule'] = $content['join_rule'] ?? null;
            } elseif ($event['type'] === 'm.room.encryption' && $event['state_key'] === '') {
                $state['encryption'] = $content ?: ['algorithm' => 'unsupported'];
            } elseif ($event['type'] === 'm.room.history_visibility' && $event['state_key'] === '') {
                $state['history_visibility'] = $content['history_visibility'] ?? null;
            } elseif ($event['type'] === 'm.room.create' && $event['state_key'] === '') {
                $state['room_type'] = $content['type'] ?? null;
            }
        }
        $members = array_keys(array_filter($state['members'] ?? [], fn ($membership) => in_array($membership, ['join', 'invite'], true)));
        $others = array_values(array_diff($members, [$identity->user_id]));
        if (count($others) === 1 && $room->customer_user_id === null) {
            $room->customer_user_id = $others[0];
            $room->customer_hash = hash('sha256', $others[0]);
        }
        $state['supported'] = ($state['join_rule'] ?? null) === 'invite' && ($state['members'][$identity->user_id] ?? null) === 'join'
            && ($state['history_visibility'] ?? null) !== 'world_readable'
            && count($others) === 1 && $others[0] === $room->customer_user_id && empty($state['room_type'])
            && (!isset($state['encryption']) || ($state['encryption']['algorithm'] ?? null) === 'm.megolm.v1.aes-sha2');
        $room->state = $state;
        $room->save();
        foreach ([$identity->user_id, $room->customer_user_id] as $user) {
            if ($user && in_array($state['members'][$user] ?? null, ['leave', 'ban'], true)) {
                self::unavailable($room);
                break;
            }
        }
    }

    public static function unavailable(MatrixRoom $room)
    {
        $state = $room->state;
        $state['supported'] = false;
        $room->state = $state;
        $room->save();
        MatrixEvent::where('matrix_mailbox_id', $room->matrix_mailbox_id)->where('room_id', $room->room_id)
            ->whereIn('kind', ['outgoing', 'to_device'])->where('status', 'pending')->update(['status' => 'cancelled']);
        \App\Misc\ChatConversations::markUnavailable($room->conversation);
    }
}
