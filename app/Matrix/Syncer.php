<?php

namespace App\Matrix;

use Illuminate\Support\Facades\DB;

class Syncer
{
    public function run(MatrixMailbox $identity)
    {
        return $identity->locked(function () use ($identity) {
            if ($identity->active_mailbox_id === null || !in_array($identity->status, ['verification', 'ready'], true)) {
                return;
            }
            $retry = CryptoRecord::read($identity->id, 'sync', 'retry');
            if (($retry['after'] ?? 0) > time()) {
                return;
            }
            try {
                (new Connection())->refresh($identity);
                $crypto = new CryptoManager($identity);
                $batch = CryptoRecord::read($identity->id, 'sync', 'batch');
                if (!$batch) {
                    $params = ['timeout' => 0, 'set_presence' => 'offline', 'filter' => json_encode([
                        'room' => ['timeline' => ['limit' => 50], 'ephemeral' => ['types' => []]], 'presence' => ['types' => []],
                    ])];
                    if ($identity->sync_token !== null) {
                        $params['since'] = $identity->sync_token;
                    }
                    $response = $identity->client()->call('GET', 'v3/sync', $params);
                    if (!is_string($response['next_batch'] ?? null) || $response['next_batch'] === '') {
                        throw new MatrixException('Invalid Matrix sync response.');
                    }
                    $batch = ['response' => $response, 'initial' => $identity->sync_token === null, 'sequence' => $identity->sync_sequence + 1, 'rooms' => [], 'state_saved' => false];
                    CryptoRecord::write($identity->id, 'sync', 'batch', $batch);
                }
                if (!$batch['state_saved']) {
                    $this->state($identity, $batch);
                    $batch['state_saved'] = true;
                    CryptoRecord::write($identity->id, 'sync', 'batch', $batch);
                }
                $this->toDevice($identity, $crypto);
                (new Verification($identity))->activate();
                Outbox::flush($identity);
                if (!$this->timelines($identity, $batch)) {
                    return;
                }
                DB::transaction(function () use ($identity, $batch) {
                    $identity->sync_token = $batch['response']['next_batch'];
                    $identity->sync_sequence = $batch['sequence'];
                    $identity->last_synced_at = now();
                    $identity->error = null;
                    $identity->save();
                    CryptoRecord::record($identity->id, 'sync', 'batch')->delete();
                });
                $count = $batch['response']['device_one_time_keys_count']['signed_curve25519'] ?? 0;
                if ($count < 25) {
                    (new Connection())->uploadKeys($identity, $count);
                }
                if ($identity->isReady()) {
                    (new Incoming($identity, $crypto))->process();
                }
            } catch (MatrixException $e) {
                if ($e->retry_after > 0) {
                    CryptoRecord::write($identity->id, 'sync', 'retry', ['after' => time() + $e->retry_after]);
                }
                throw $e;
            }
        });
    }

    private function state(MatrixMailbox $identity, array &$batch)
    {
        foreach ($batch['response']['rooms']['join'] ?? [] as $id => $data) {
            $room = MatrixRoom::forRoom($identity->id, $id);
            if (!$room->exists) {
                RoomState::refresh($identity, $room);
            }
            RoomState::apply($identity, $room, array_merge($data['state']['events'] ?? [], $data['timeline']['events'] ?? []));
            $batch['rooms'][$id] = ['done' => false, 'page' => -1, 'from' => $data['timeline']['prev_batch'] ?? null, 'anchor' => $room->state['last_event'] ?? null];
        }
        foreach ($batch['response']['rooms']['leave'] ?? [] as $id => $data) {
            $room = MatrixRoom::forRoom($identity->id, $id);
            if ($room->exists) {
                $state = $room->state;
                $state['supported'] = false;
                $room->state = $state;
                $room->save();
            }
        }
        foreach ($batch['response']['rooms']['invite'] ?? [] as $id => $data) {
            $invite = collect($data['invite_state']['events'] ?? [])->first(fn ($event) => ($event['type'] ?? null) === 'm.room.member' && ($event['state_key'] ?? null) === $identity->user_id);
            if (!$invite || empty($invite['content']['is_direct'])) {
                continue;
            }
            $identity->client()->call('POST', 'v3/rooms/'.rawurlencode($id).'/join');
            $room = MatrixRoom::forRoom($identity->id, $id);
            RoomState::refresh($identity, $room);
            if (!($room->state['supported'] ?? false)) {
                $identity->client()->call('POST', 'v3/rooms/'.rawurlencode($id).'/leave');
            }
        }
        DB::transaction(function () use ($identity, $batch) {
            foreach ($batch['response']['to_device']['events'] ?? [] as $event) {
                $json = json_encode($event, JSON_THROW_ON_ERROR);
                MatrixEvent::firstOrCreate(['matrix_mailbox_id' => $identity->id, 'local_key' => hash('sha256', 'device:'.$json)], [
                    'kind' => 'device_in', 'payload' => $event, 'status' => 'pending',
                ]);
            }
        });
    }

    private function toDevice(MatrixMailbox $identity, CryptoManager $crypto)
    {
        $events = MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('kind', 'device_in')->where('status', 'pending')->where(function ($query) {
            $query->whereNull('retry_at')->orWhere('retry_at', '<=', now());
        })->orderBy('id')->limit(100)->get();
        foreach ($events as $record) {
            try {
                $event = $record->payload;
                if (!is_string($event['type'] ?? null) || !is_string($event['sender'] ?? null) || !is_array($event['content'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid Matrix to-device event.');
                }
                if ($event['type'] === 'm.room.encrypted' || $event['type'] === 'm.room_key_request') {
                    $crypto->trusted($event['sender']);
                }
                DB::transaction(function () use ($identity, $crypto, $record) {
                    $event = $record->payload;
                    if (str_starts_with($event['type'] ?? '', 'm.key.verification.')) {
                        (new Verification($identity))->receive($event);
                    } elseif (($event['type'] ?? '') === 'm.room.encrypted') {
                        $crypto->receiveOlm($event);
                    } elseif (($event['type'] ?? '') === 'm.room_key_request') {
                        $crypto->reshare($event);
                    }
                    $record->status = 'received';
                    $record->payload = null;
                    $record->save();
                });
            } catch (MatrixException $e) {
                \App\Misc\ChatLog::failure('matrix', $identity->mailbox_id, 'receive', $e);
                $record->attempts++;
                $record->retry_at = now()->addMinutes(5);
                $record->save();
            } catch (\InvalidArgumentException | \JsonException | \SodiumException | \TypeError $e) {
                \App\Misc\ChatLog::failure('matrix', $identity->mailbox_id, 'receive', $e);
                $record->status = 'rejected';
                $record->payload = null;
                $record->save();
            }
        }
    }

    private function timelines(MatrixMailbox $identity, array &$batch)
    {
        $pages = 0;
        foreach ($batch['rooms'] as $id => &$progress) {
            if ($progress['done']) {
                continue;
            }
            $room = MatrixRoom::forRoom($identity->id, $id);
            $timeline = $batch['response']['rooms']['join'][$id]['timeline'] ?? [];
            $events = $timeline['events'] ?? [];
            if (!$batch['initial'] && ($room->state['supported'] ?? false)) {
                if (!empty($timeline['limited']) && $progress['anchor']) {
                    while (true) {
                        if ($pages++ >= 3) {
                            CryptoRecord::write($identity->id, 'sync', 'batch', $batch);

                            return false;
                        }
                        if (!$progress['from']) {
                            throw new MatrixException('Matrix timeline gap has no pagination token.');
                        }
                        $response = $identity->client()->call('GET', 'v3/rooms/'.rawurlencode($id).'/messages', ['from' => $progress['from'], 'dir' => 'b', 'limit' => 100]);
                        $older = [];
                        $found = false;
                        foreach ($response['chunk'] ?? [] as $event) {
                            if (($event['event_id'] ?? null) === $progress['anchor']) {
                                $found = true;
                                break;
                            }
                            $older[] = $event;
                        }
                        if (!$found && (!$older || empty($response['end']) || $response['end'] === $progress['from'])) {
                            throw new MatrixException('Matrix timeline gap cannot yet be recovered.');
                        }
                        $this->storeEvents($identity, $room, array_reverse($older), $batch['sequence'], $progress['page']);
                        $progress['from'] = $response['end'] ?? null;
                        $progress['page']--;
                        if ($found) {
                            $progress['anchor'] = null;
                            break;
                        }
                        CryptoRecord::write($identity->id, 'sync', 'batch', $batch);
                    }
                }
                $this->storeEvents($identity, $room, $events, $batch['sequence'], 0);
            }
            $state = $room->state;
            if ($events) {
                $state['last_event'] = end($events)['event_id'] ?? ($state['last_event'] ?? null);
            }
            $room->state = $state;
            $room->save();
            $progress['done'] = true;
            CryptoRecord::write($identity->id, 'sync', 'batch', $batch);
        }

        return true;
    }

    private function storeEvents(MatrixMailbox $identity, MatrixRoom $room, array $events, $sequence, $page)
    {
        DB::transaction(function () use ($identity, $room, $events, $sequence, $page) {
            foreach ($events as $position => $event) {
                if (!is_string($event['event_id'] ?? null) || !in_array($event['type'] ?? '', ['m.room.message', 'm.room.encrypted'], true)) {
                    continue;
                }
                $hash = hash('sha256', $event['event_id']);
                if (MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('remote_hash', $hash)->exists()) {
                    continue;
                }
                $txn = $event['unsigned']['transaction_id'] ?? null;
                $own = $txn && ($event['sender'] ?? null) === $identity->user_id
                    ? MatrixEvent::where('matrix_mailbox_id', $identity->id)->where('kind', 'outgoing')->where('transaction_id', $txn)->first() : null;
                if ($own && $own->room_id === $room->room_id && ($own->payload['content'] ?? null) === ($event['content'] ?? null)) {
                    $own->remote_id = $event['event_id'];
                    $own->remote_hash = $hash;
                    $own->status = 'sent';
                    $own->save();
                    continue;
                }
                MatrixEvent::firstOrCreate(['matrix_mailbox_id' => $identity->id, 'local_key' => hash('sha256', 'event:'.$event['event_id'])], [
                    'kind' => 'incoming', 'room_id' => $room->room_id, 'remote_id' => $event['event_id'], 'remote_hash' => $hash,
                    'payload' => $event, 'status' => 'pending', 'sort_order' => sprintf('%020d:%010d:%06d', $sequence, 1000000000 + $page, $position),
                ]);
            }
        });
    }
}
