<?php

namespace App\Matrix\Crypto;

/**
 * Respond to a verification started in an existing trusted Matrix client.
 */
class SasVerification
{
    private $state;

    private function __construct(#[\SensitiveParameter] array $state)
    {
        $this->state = $state;
    }

    public static function request($user, $device, array $peer, $master, array $request, $now)
    {
        if (($request['from_device'] ?? null) !== $peer['id'] || $peer['id'] === $device
            || !is_string($request['transaction_id'] ?? null) || strlen($request['transaction_id']) > 255 || $request['transaction_id'] === ''
            || !is_int($request['timestamp'] ?? null) || abs($now * 1000 - $request['timestamp']) > 300000
            || !in_array('m.sas.v1', $request['methods'] ?? [], true)) {
            throw new \InvalidArgumentException('Invalid Matrix verification request.');
        }
        Encoding::decode($peer['signing'], 32);
        Encoding::decode($master, 32);

        return new self(['version' => 1, 'user' => $user, 'device' => $device, 'peer' => $peer['id'],
            'peer_keys' => ['ed25519:'.$peer['id'] => $peer['signing'], 'ed25519:'.$master => $master],
            'transaction' => $request['transaction_id'], 'expires' => $now + 300, 'phase' => 'requested',
            'secret' => Encoding::base64(random_bytes(32)), 'start' => null, 'peer_key' => null,
            'confirmed' => false, 'mac_received' => false]);
    }

    public function ready($now)
    {
        $this->check($now);
        if ($this->state['phase'] !== 'requested') {
            throw new \InvalidArgumentException('Unexpected Matrix verification approval.');
        }
        $this->state['phase'] = 'ready';

        return $this->event('ready', ['from_device' => $this->state['device'], 'methods' => ['m.sas.v1']]);
    }

    public function handle($sender, $type, array $content, $now)
    {
        $this->check($now);
        if ($sender !== $this->state['user'] || ($content['transaction_id'] ?? null) !== $this->state['transaction']) {
            throw new \InvalidArgumentException('Matrix verification transaction mismatch.');
        }
        if ($type === 'm.key.verification.cancel') {
            $this->state['phase'] = 'cancelled';

            return null;
        }
        if ($type === 'm.key.verification.start' && $this->state['phase'] === 'ready') {
            return $this->accept($content);
        }
        if ($type === 'm.key.verification.key' && $this->state['phase'] === 'accepted') {
            $peer = Encoding::decode($content['key'] ?? '', 32);
            sodium_crypto_scalarmult(Encoding::decode($this->state['secret'], 32), $peer);
            $this->state['peer_key'] = $content['key'];
            $this->state['phase'] = 'compare';

            return $this->event('key', ['key' => $this->publicKey()]);
        }
        if ($type === 'm.key.verification.mac' && $this->state['phase'] === 'compare' && !$this->state['mac_received']) {
            $this->verifyMac($content);
            $this->state['mac_received'] = true;

            return $this->complete() ? $this->event('done', []) : null;
        }
        if ($type === 'm.key.verification.done' && $this->complete()) {
            return null;
        }
        throw new \InvalidArgumentException('Unexpected Matrix verification message.');
    }

    private function accept(array $start)
    {
        if (($start['from_device'] ?? null) !== $this->state['peer'] || ($start['method'] ?? null) !== 'm.sas.v1'
            || !in_array('sha256', $start['hashes'] ?? [], true)
            || !in_array('curve25519-hkdf-sha256', $start['key_agreement_protocols'] ?? [], true)
            || !in_array('hkdf-hmac-sha256.v2', $start['message_authentication_codes'] ?? [], true)
            || !in_array('decimal', $start['short_authentication_string'] ?? [], true)) {
            throw new \InvalidArgumentException('Unsupported Matrix verification method.');
        }
        $this->state['start'] = $start;
        $this->state['phase'] = 'accepted';

        return $this->event('accept', ['method' => 'm.sas.v1', 'hash' => 'sha256', 'key_agreement_protocol' => 'curve25519-hkdf-sha256',
            'message_authentication_code' => 'hkdf-hmac-sha256.v2', 'short_authentication_string' => ['decimal'],
            'commitment' => Encoding::base64(hash('sha256', $this->publicKey().CanonicalJson::encode($start), true))]);
    }

    public function decimals($now)
    {
        $this->check($now);
        if ($this->state['phase'] !== 'compare') {
            throw new \InvalidArgumentException('Matrix verification is not ready to compare.');
        }
        $info = implode('|', ['MATRIX_KEY_VERIFICATION_SAS', $this->state['user'], $this->state['peer'], $this->state['peer_key'],
            $this->state['user'], $this->state['device'], $this->publicKey(), $this->state['transaction'],]);
        $b = array_values(unpack('C*', hash_hkdf('sha256', $this->shared(), 5, $info)));

        return [(($b[0] << 5) | ($b[1] >> 3)) + 1000,
            ((($b[1] & 7) << 10) | ($b[2] << 2) | ($b[3] >> 6)) + 1000,
            ((($b[3] & 63) << 7) | ($b[4] >> 1)) + 1000,];
    }

    public function confirm($signing, $now)
    {
        $this->decimals($now);
        if ($this->state['confirmed']) {
            throw new \InvalidArgumentException('Matrix verification was already confirmed.');
        }
        Encoding::decode($signing, 32);
        $id = 'ed25519:'.$this->state['device'];
        $this->state['confirmed'] = true;

        return $this->event('mac', ['keys' => $this->mac($id, 'KEY_IDS', false), 'mac' => [$id => $this->mac($signing, $id, false)]]);
    }

    private function verifyMac(array $content)
    {
        $macs = $content['mac'] ?? [];
        if (!is_array($macs) || !$macs || count($macs) > 2 || !isset($macs['ed25519:'.$this->state['peer']])) {
            throw new \InvalidArgumentException('Matrix verification omitted the device key.');
        }
        $ids = array_keys($macs);
        sort($ids, SORT_STRING);
        if (!hash_equals($this->mac(implode(',', $ids), 'KEY_IDS', true), $content['keys'] ?? '')) {
            throw new \InvalidArgumentException('Matrix verification key list mismatch.');
        }
        foreach ($macs as $id => $mac) {
            if (!isset($this->state['peer_keys'][$id]) || !hash_equals($this->mac($this->state['peer_keys'][$id], $id, true), $mac)) {
                throw new \InvalidArgumentException('Matrix verification key mismatch.');
            }
        }
        if (array_diff(array_keys($this->state['peer_keys']), $ids)) {
            throw new \InvalidArgumentException('Matrix verification omitted the account identity.');
        }
    }

    private function mac($input, $id, $incoming)
    {
        $sender = $incoming ? $this->state['peer'] : $this->state['device'];
        $recipient = $incoming ? $this->state['device'] : $this->state['peer'];
        $info = 'MATRIX_KEY_VERIFICATION_MAC'.$this->state['user'].$sender.$this->state['user'].$recipient.$this->state['transaction'].$id;

        return Encoding::base64(hash_hmac('sha256', $input, hash_hkdf('sha256', $this->shared(), 32, $info), true));
    }

    private function shared()
    {
        return sodium_crypto_scalarmult(Encoding::decode($this->state['secret'], 32), Encoding::decode($this->state['peer_key'], 32));
    }

    private function publicKey()
    {
        return Encoding::base64(sodium_crypto_scalarmult_base(Encoding::decode($this->state['secret'], 32)));
    }

    public function complete()
    {
        return $this->state['phase'] === 'compare' && $this->state['confirmed'] && $this->state['mac_received'];
    }

    public function cancel()
    {
        $this->state['phase'] = 'cancelled';

        return $this->event('cancel', ['code' => 'm.user', 'reason' => 'Verification cancelled.']);
    }

    private function check($now)
    {
        if ($this->state['phase'] === 'cancelled' || $now >= $this->state['expires']) {
            throw new \InvalidArgumentException('Matrix verification expired or was cancelled.');
        }
    }

    private function event($type, array $content)
    {
        return ['type' => 'm.key.verification.'.$type, 'content' => $content + ['transaction_id' => $this->state['transaction']]];
    }

    public function export()
    {
        return $this->state;
    }

    public static function restore(#[\SensitiveParameter] array $state)
    {
        if (($state['version'] ?? null) !== 1 || !is_int($state['expires'] ?? null)
            || !in_array($state['phase'] ?? '', ['requested', 'ready', 'accepted', 'compare', 'cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid Matrix verification state.');
        }
        Encoding::decode($state['secret'], 32);

        return new self($state);
    }

    public function __debugInfo()
    {
        return ['phase' => $this->state['phase']];
    }
}
