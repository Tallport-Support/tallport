<?php

namespace App\Matrix;

use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\DeviceKeys;
use App\Matrix\Crypto\Megolm;
use App\Matrix\Crypto\OlmSession;
use Illuminate\Support\Facades\DB;

class CryptoManager
{
    private $identity;
    private $trusted = [];

    public function __construct(MatrixMailbox $identity)
    {
        $this->identity = $identity;
    }

    public function query($user)
    {
        $query = $this->identity->client()->call('POST', 'v3/keys/query', ['device_keys' => [$user => []], 'timeout' => 10000]);
        if ((array) ($query['failures'] ?? [])) {
            throw new MatrixException('Matrix device list is unavailable.');
        }

        return DeviceKeys::crossSigning($query, $user);
    }

    public function trusted($user)
    {
        if (isset($this->trusted[$user])) {
            return $this->trusted[$user];
        }
        $current = $this->query($user);
        $known = CryptoRecord::read($this->identity->id, 'trust', $user);
        $needs_review = $known && $known['master'] !== $current['master'];
        $account = $user === $this->identity->user_id ? Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device')) : null;
        if ($account) {
            $own = $current['devices'][$this->identity->device_id] ?? null;
            $needs_review = $needs_review || !$own || $own['signing'] !== $account->signingKey() || $own['curve'] !== $account->curveKey();
        }
        foreach ($current['devices'] as $id => $device) {
            $old = $known['devices'][$id] ?? null;
            $changed = $old && ($old['signing'] !== $device['signing'] || $old['curve'] !== $device['curve']);
            $approved = ($known['approved'][$id] ?? null) === self::fingerprint($device);
            $local = $account && $id === $this->identity->device_id && $device['signing'] === $account->signingKey() && $device['curve'] === $account->curveKey();
            if ($changed || (!$device['cross_signed'] && !$approved && !$local)) {
                $needs_review = true;
            }
        }
        if ($needs_review) {
            CryptoRecord::write($this->identity->id, 'trust_review', $user, ['user' => $user, 'keys' => $current, 'fingerprint' => self::fingerprint($current)]);
            throw new MatrixException('Matrix device trust needs review.');
        }
        CryptoRecord::write($this->identity->id, 'trust', $user, array_replace($current, ['devices' => $current['devices'] + ($known['devices'] ?? []), 'approved' => $known['approved'] ?? []]));
        $this->trusted[$user] = $current['devices'];

        return $this->trusted[$user];
    }

    public static function fingerprint(array $keys)
    {
        return hash('sha256', \App\Matrix\Crypto\CanonicalJson::encode($keys));
    }

    public function approve($user, $fingerprint)
    {
        $current = $this->query($user);
        if (!hash_equals(self::fingerprint($current), $fingerprint)) {
            throw new MatrixException('Matrix device keys changed again.');
        }
        $approved = [];
        foreach ($current['devices'] as $id => $device) {
            $approved[$id] = self::fingerprint($device);
        }
        DB::transaction(function () use ($user, $current, $approved) {
            CryptoRecord::write($this->identity->id, 'trust', $user, $current + ['approved' => $approved]);
            CryptoRecord::record($this->identity->id, 'trust_review', $user)->delete();
            MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->whereIn('kind', ['incoming', 'device_in'])->where('status', 'pending')->update(['retry_at' => null]);
            CryptoRecord::where('matrix_mailbox_id', $this->identity->id)->where('kind', 'outbound')->delete();
            MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->whereIn('kind', ['outgoing', 'to_device'])->where('status', 'pending')->update(['status' => 'cancelled']);
        });
        unset($this->trusted[$user]);
    }

    public function receiveOlm(array $event)
    {
        $content = $event['content'] ?? [];
        $sender = $event['sender'] ?? '';
        if (($content['algorithm'] ?? '') !== 'm.olm.v1.curve25519-aes-sha2') {
            return;
        }
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        $cipher = $content['ciphertext'][$account->curveKey()] ?? null;
        if (!is_array($cipher) || !in_array($cipher['type'] ?? null, [0, 1], true) || !is_string($cipher['body'] ?? null)) {
            throw new \InvalidArgumentException('Invalid Matrix to-device ciphertext.');
        }
        $device = null;
        foreach ($this->trusted($sender) as $candidate) {
            if ($candidate['curve'] === ($content['sender_key'] ?? null)) {
                $device = $candidate;
                break;
            }
        }
        if (!$device) {
            throw new \InvalidArgumentException('Unknown Matrix sending device.');
        }
        $key = $sender.'|'.$device['curve'];
        $saved = CryptoRecord::read($this->identity->id, 'olm', $key) ?? ['sessions' => []];
        $decoded = null;
        foreach ($saved['sessions'] as $id => $state) {
            $session = OlmSession::restore($state);
            try {
                $plain = $session->decrypt($cipher['type'], $cipher['body']);
                $decoded = $account->validateEnvelope($plain, $sender, $device['signing']);
            } catch (\InvalidArgumentException | \JsonException | \SodiumException $e) {
                continue;
            }
            $saved['sessions'][$id] = $session->export();
            break;
        }
        if ($decoded === null && $cipher['type'] === 0) {
            $received = $account->receive($device['curve'], $cipher['body'], $sender, $device['signing']);
            $decoded = $received['event'];
            $saved['sessions'] = [$received['session']->id() => $received['session']->export()] + $saved['sessions'];
            $saved['sessions'] = array_slice($saved['sessions'], 0, 5, true);
        }
        if ($decoded === null || ($decoded['sender_device'] ?? '') !== $device['id']) {
            throw new \InvalidArgumentException('Matrix to-device message could not be authenticated.');
        }
        if ($decoded['type'] === 'm.room_key') {
            $this->receiveRoomKey($sender, $device, $decoded['content']);
        }
        CryptoRecord::write($this->identity->id, 'account', 'device', $account->export());
        CryptoRecord::write($this->identity->id, 'olm', $key, $saved);
    }

    private function receiveRoomKey($sender, array $device, array $content)
    {
        if (($content['algorithm'] ?? '') !== 'm.megolm.v1.aes-sha2' || !is_string($content['room_id'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported Matrix room key.');
        }
        $room = MatrixRoom::forRoom($this->identity->id, $content['room_id']);
        if (!$room->exists || !($room->state['supported'] ?? false) || !in_array($sender, [$this->identity->user_id, $room->customer_user_id], true)) {
            throw new \InvalidArgumentException('Matrix room key has no supported room.');
        }
        $session = Megolm::receive($content['session_key'] ?? '');
        if ($session->id() !== ($content['session_id'] ?? null)) {
            throw new \InvalidArgumentException('Matrix room key session mismatch.');
        }
        $key = $room->room_id.'|'.$device['curve'].'|'.$session->id();
        $saved = CryptoRecord::read($this->identity->id, 'inbound', $key);
        if ($saved && ($saved['sender'] !== $sender || $saved['device']['signing'] !== $device['signing'])) {
            throw new \InvalidArgumentException('Matrix room key sender mismatch.');
        }
        if (!$saved || $saved['session']['index'] > $session->index()) {
            CryptoRecord::write($this->identity->id, 'inbound', $key, ['session' => $session->export(), 'sender' => $sender, 'device' => $device]);
            MatrixEvent::where('matrix_mailbox_id', $this->identity->id)->where('room_id', $room->room_id)->where('kind', 'incoming')->where('status', 'pending')->update(['retry_at' => null]);
        }
    }

    public function decrypt(MatrixRoom $room, array $event)
    {
        $content = $event['content'];
        if (($content['algorithm'] ?? null) !== 'm.megolm.v1.aes-sha2') {
            throw new \InvalidArgumentException('Unsupported Matrix room encryption.');
        }
        \App\Matrix\Crypto\Encoding::decode($content['sender_key'] ?? '', 32);
        \App\Matrix\Crypto\Encoding::decode($content['session_id'] ?? '', 32);
        $key = $room->room_id.'|'.$content['sender_key'].'|'.$content['session_id'];
        $saved = CryptoRecord::read($this->identity->id, 'inbound', $key);
        if (!$saved) {
            return null;
        }
        if ($saved['sender'] !== $event['sender']) {
            throw new \InvalidArgumentException('Matrix message sender does not match its session.');
        }
        $devices = $this->trusted($event['sender']);
        $device = $devices[$saved['device']['id']] ?? null;
        if (!$device || $device['signing'] !== $saved['device']['signing'] || $device['curve'] !== $saved['device']['curve']) {
            throw new \InvalidArgumentException('Matrix message device is no longer trusted.');
        }
        $result = Megolm::restore($saved['session'])->decrypt($content['ciphertext'] ?? '');
        $decoded = json_decode($result['plaintext'], true, 64, JSON_THROW_ON_ERROR);
        if (($decoded['room_id'] ?? null) !== $room->room_id || !is_string($decoded['type'] ?? null) || !is_array($decoded['content'] ?? null)) {
            throw new \InvalidArgumentException('Matrix encrypted room binding mismatch.');
        }

        return ['type' => $decoded['type'], 'content' => $decoded['content'], 'replay_hash' => hash('sha256', $key.'|'.$result['index'])];
    }

    public function encrypt(MatrixRoom $room, $type, array $content)
    {
        $recipients = [];
        foreach ([$this->identity->user_id, $room->customer_user_id] as $user) {
            foreach ($this->trusted($user) as $device) {
                if ($user !== $this->identity->user_id || $device['id'] !== $this->identity->device_id) {
                    $recipients[$user.'|'.$device['id']] = ['user' => $user, 'device' => $device];
                }
            }
        }
        ksort($recipients, SORT_STRING);
        $saved = CryptoRecord::read($this->identity->id, 'outbound', $room->room_id);
        $fingerprint = self::fingerprint(['recipients' => $recipients, 'members' => $room->state['members'] ?? []]);
        $rotation = $room->state['encryption'] ?? [];
        $max_messages = max(1, min(100, (int) ($rotation['rotation_period_msgs'] ?? 100)));
        $max_age = max(1, min(604800, (int) (($rotation['rotation_period_ms'] ?? 604800000) / 1000)));
        if (!$saved || $saved['recipients_hash'] !== $fingerprint || $saved['created'] + $max_age <= time() || $saved['session']['index'] >= $max_messages) {
            $session = Megolm::create();
            foreach ($recipients as $recipient) {
                $this->sendToDevice($recipient['user'], $recipient['device'], 'm.room_key', ['algorithm' => 'm.megolm.v1.aes-sha2',
                    'room_id' => $room->room_id, 'session_id' => $session->id(), 'session_key' => $session->sessionKey()]);
            }
            CryptoRecord::write($this->identity->id, 'sent_session', $room->room_id.'|'.$session->id(), ['key' => $session->sessionKey(), 'recipients' => $recipients, 'requests' => []]);
            $saved = ['session' => $session->export(), 'created' => time(), 'recipients_hash' => $fingerprint, 'recipients' => $recipients];
        }
        $session = Megolm::restore($saved['session']);
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        $encrypted = ['algorithm' => 'm.megolm.v1.aes-sha2', 'sender_key' => $account->curveKey(), 'device_id' => $this->identity->device_id,
            'session_id' => $session->id(), 'ciphertext' => $session->encrypt(json_encode(['room_id' => $room->room_id, 'type' => $type, 'content' => $content], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))];
        $saved['session'] = $session->export();
        CryptoRecord::write($this->identity->id, 'outbound', $room->room_id, $saved);

        return $encrypted;
    }

    public function reshare(array $event)
    {
        $request = $event['content'];
        $body = $request['body'] ?? [];
        $user = $event['sender'];
        $device_id = $request['requesting_device_id'] ?? '';
        if (($request['action'] ?? '') !== 'request' || ($body['algorithm'] ?? '') !== 'm.megolm.v1.aes-sha2'
            || !is_string($request['request_id'] ?? null) || strlen($request['request_id']) > 255
            || !is_string($body['room_id'] ?? null) || !is_string($body['session_id'] ?? null)) {
            return;
        }
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        if (($body['sender_key'] ?? '') !== $account->curveKey()) {
            return;
        }
        $room = MatrixRoom::forRoom($this->identity->id, $body['room_id']);
        if (!($room->state['supported'] ?? false) || !in_array($user, [$this->identity->user_id, $room->customer_user_id], true)) {
            return;
        }
        $record_key = $room->room_id.'|'.$body['session_id'];
        $saved = CryptoRecord::read($this->identity->id, 'sent_session', $record_key);
        $recipient = $user.'|'.$device_id;
        $entitled = $saved['recipients'][$recipient]['device'] ?? null;
        $device = $this->trusted($user)[$device_id] ?? null;
        if (!$entitled || !$device || $entitled['signing'] !== $device['signing'] || $entitled['curve'] !== $device['curve']
            || ($saved['requests'][$recipient] ?? 0) >= 3) {
            return;
        }
        $this->sendToDevice($user, $device, 'm.room_key', ['algorithm' => 'm.megolm.v1.aes-sha2', 'room_id' => $room->room_id,
            'session_id' => $body['session_id'], 'session_key' => $saved['key']]);
        $saved['requests'][$recipient] = ($saved['requests'][$recipient] ?? 0) + 1;
        CryptoRecord::write($this->identity->id, 'sent_session', $record_key, $saved);
    }

    private function sendToDevice($user, array $device, $type, array $content)
    {
        $key = $user.'|'.$device['curve'];
        $saved = CryptoRecord::read($this->identity->id, 'olm', $key) ?? ['sessions' => []];
        $account = Account::restore(CryptoRecord::read($this->identity->id, 'account', 'device'));
        if (!$saved['sessions']) {
            $claim = $this->identity->client()->call('POST', 'v3/keys/claim', ['one_time_keys' => [$user => [$device['id'] => 'signed_curve25519']], 'timeout' => 10000]);
            $keys = $claim['one_time_keys'][$user][$device['id']] ?? [];
            if (count($keys) !== 1 || !str_starts_with((string) array_key_first($keys), 'signed_curve25519:')) {
                throw new MatrixException('Matrix device has no available one-time key.');
            }
            $session = OlmSession::create($account->curveSecret(), $device['curve'], DeviceKeys::oneTime(reset($keys), $user, $device));
        } else {
            $session = OlmSession::restore(reset($saved['sessions']));
        }
        $cipher = $session->encrypt($account->envelope($user, $device['signing'], $type, $content));
        $saved['sessions'][$session->id()] = $session->export();
        CryptoRecord::write($this->identity->id, 'olm', $key, $saved);
        MatrixEvent::outgoing($this->identity->id, bin2hex(random_bytes(16)), 'to_device', ['type' => 'm.room.encrypted', 'recipient' => ['user' => $user, 'device' => $device],
            'messages' => [$user => [$device['id'] => ['algorithm' => 'm.olm.v1.curve25519-aes-sha2', 'sender_key' => $account->curveKey(), 'ciphertext' => [$device['curve'] => $cipher]]]],], $content['room_id'] ?? null);
    }
}
