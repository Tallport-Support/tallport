<?php

namespace App\Matrix\Crypto;

/**
 * Olm v1 double ratchet. Callers persist a successful operation atomically with its event.
 */
class OlmSession
{
    const MAX_SKIPPED = 1000;
    const MAX_CHAINS = 5;

    private $state;

    private function __construct(#[\SensitiveParameter] array $state)
    {
        $this->state = $state;
    }

    public static function create(#[\SensitiveParameter] $identity_secret, $recipient_identity, $recipient_one_time)
    {
        $identity = sodium_crypto_scalarmult_base($identity_secret);
        $remote = Encoding::decode($recipient_identity, 32);
        $one_time = Encoding::decode($recipient_one_time, 32);
        $base = random_bytes(32);
        $ratchet = random_bytes(32);
        $shared = sodium_crypto_scalarmult($identity_secret, $one_time)
            .sodium_crypto_scalarmult($base, $remote).sodium_crypto_scalarmult($base, $one_time);
        [$root, $chain] = self::root($shared, '', 'OLM_ROOT');

        return new self([
            'version' => 1, 'identity' => Encoding::base64($identity), 'base' => Encoding::base64(sodium_crypto_scalarmult_base($base)),
            'one_time' => $recipient_one_time, 'remote' => $recipient_identity, 'received' => false,
            'root' => Encoding::base64($root), 'ratchet' => Encoding::base64($ratchet),
            'send' => ['key' => Encoding::base64($chain), 'index' => 0], 'receive' => [], 'skipped' => [],
        ]);
    }

    /**
     * The account must retain its one-time key until this message and its identity are validated.
     */
    public static function receive(#[\SensitiveParameter] $identity_secret, #[\SensitiveParameter] $one_time_secret, $sender_identity, $message)
    {
        $fields = Encoding::fields(Encoding::decode($message), [0x0a, 0x12, 0x1a, 0x22]);
        $sender = Encoding::decode($sender_identity, 32);
        if (!hash_equals($sender, $fields[0x1a]) || !hash_equals(sodium_crypto_scalarmult_base($one_time_secret), $fields[0x0a]) || strlen($fields[0x12]) !== 32) {
            throw new \InvalidArgumentException('Olm pre-key identity mismatch.');
        }
        $shared = sodium_crypto_scalarmult($one_time_secret, $sender)
            .sodium_crypto_scalarmult($identity_secret, $fields[0x12]).sodium_crypto_scalarmult($one_time_secret, $fields[0x12]);
        [$root, $chain] = self::root($shared, '', 'OLM_ROOT');
        $inner = Encoding::fields(substr($fields[0x22], 0, -8), [0x0a, 0x10, 0x22]);
        if (strlen($inner[0x0a]) !== 32) {
            throw new \InvalidArgumentException('Invalid Olm ratchet key.');
        }
        $session = new self([
            'version' => 1, 'identity' => $sender_identity, 'base' => Encoding::base64($fields[0x12]),
            'one_time' => Encoding::base64($fields[0x0a]), 'remote' => $sender_identity, 'received' => true,
            'root' => Encoding::base64($root), 'ratchet' => null, 'send' => null,
            'receive' => [['ratchet' => Encoding::base64($inner[0x0a]), 'key' => Encoding::base64($chain), 'index' => 0]], 'skipped' => [],
        ]);
        $plaintext = $session->decrypt(0, $message);

        return ['session' => $session, 'plaintext' => $plaintext];
    }

    public static function oneTimeKey($message)
    {
        $fields = Encoding::fields(Encoding::decode($message), [0x0a, 0x12, 0x1a, 0x22]);
        if (strlen($fields[0x0a]) !== 32) {
            throw new \InvalidArgumentException('Invalid Olm one-time key.');
        }

        return Encoding::base64($fields[0x0a]);
    }

    public function id()
    {
        return Encoding::base64(hash('sha256', Encoding::decode($this->state['identity'], 32)
            .Encoding::decode($this->state['base'], 32).Encoding::decode($this->state['one_time'], 32), true));
    }

    public function encrypt(#[\SensitiveParameter] $plaintext)
    {
        $state = $this->state;
        if ($state['send'] === null) {
            $secret = random_bytes(32);
            [$root, $chain] = self::root(sodium_crypto_scalarmult($secret, Encoding::decode($state['receive'][0]['ratchet'], 32)), Encoding::decode($state['root'], 32), 'OLM_RATCHET');
            $state['root'] = Encoding::base64($root);
            $state['ratchet'] = Encoding::base64($secret);
            $state['send'] = ['key' => Encoding::base64($chain), 'index' => 0];
        }
        $chain = Encoding::decode($state['send']['key'], 32);
        [$key, $mac, $iv] = Encoding::keys(hash_hmac('sha256', "\x01", $chain, true), 'OLM_KEYS');
        $wire = "\x03".Encoding::bytes(0x0a, sodium_crypto_scalarmult_base(Encoding::decode($state['ratchet'], 32)))
            ."\x10".Encoding::varint($state['send']['index']).Encoding::bytes(0x22, Encoding::encrypt($plaintext, $key, $iv));
        $wire .= Encoding::mac($wire, $mac);
        $type = $state['received'] ? 1 : 0;
        if ($type === 0) {
            $wire = "\x03".Encoding::bytes(0x0a, Encoding::decode($state['one_time'], 32))
                .Encoding::bytes(0x12, Encoding::decode($state['base'], 32)).Encoding::bytes(0x1a, Encoding::decode($state['identity'], 32))
                .Encoding::bytes(0x22, $wire);
        }
        $state['send']['key'] = Encoding::base64(hash_hmac('sha256', "\x02", $chain, true));
        $state['send']['index']++;
        $this->state = $state;

        return ['type' => $type, 'body' => Encoding::base64($wire)];
    }

    public function decrypt($type, $message)
    {
        $wire = Encoding::decode($message);
        if ($type === 0) {
            $outer = Encoding::fields($wire, [0x0a, 0x12, 0x1a, 0x22]);
            foreach ([0x0a => 'one_time', 0x12 => 'base', 0x1a => 'identity'] as $tag => $name) {
                if (!hash_equals(Encoding::decode($this->state[$name], 32), $outer[$tag])) {
                    throw new \InvalidArgumentException('Olm pre-key session mismatch.');
                }
            }
            $wire = $outer[0x22];
        } elseif ($type !== 1) {
            throw new \InvalidArgumentException('Invalid Olm message type.');
        }
        $fields = Encoding::fields(substr($wire, 0, -8), [0x0a, 0x10, 0x22]);
        if (strlen($fields[0x0a]) !== 32) {
            throw new \InvalidArgumentException('Invalid Olm ratchet key.');
        }
        $state = $this->state;
        $ratchet = Encoding::base64($fields[0x0a]);
        $skipped_id = $ratchet.':'.$fields[0x10];
        if (isset($state['skipped'][$skipped_id])) {
            $message_key = Encoding::decode($state['skipped'][$skipped_id], 32);
            unset($state['skipped'][$skipped_id]);
        } else {
            $message_key = self::receiveKey($state, $ratchet, $fields[0x10]);
        }
        [$key, $mac, $iv] = Encoding::keys($message_key, 'OLM_KEYS');
        if (!hash_equals(Encoding::mac(substr($wire, 0, -8), $mac), substr($wire, -8))) {
            throw new \InvalidArgumentException('Invalid Olm message authentication.');
        }
        $plaintext = Encoding::decrypt($fields[0x22], $key, $iv);
        $state['received'] = true;
        $this->state = $state;

        return $plaintext;
    }

    private static function receiveKey(#[\SensitiveParameter] &$state, $ratchet, $index)
    {
        $position = array_search($ratchet, array_column($state['receive'], 'ratchet'), true);
        if ($position === false) {
            if ($state['send'] === null || $state['ratchet'] === null) {
                throw new \InvalidArgumentException('Unexpected Olm ratchet.');
            }
            [$root, $chain] = self::root(sodium_crypto_scalarmult(Encoding::decode($state['ratchet'], 32), Encoding::decode($ratchet, 32)), Encoding::decode($state['root'], 32), 'OLM_RATCHET');
            $state['root'] = Encoding::base64($root);
            array_unshift($state['receive'], ['ratchet' => $ratchet, 'key' => Encoding::base64($chain), 'index' => 0]);
            $state['receive'] = array_slice($state['receive'], 0, self::MAX_CHAINS);
            $state['send'] = null;
            $position = 0;
        }
        $chain = &$state['receive'][$position];
        if ($index < $chain['index'] || $index - $chain['index'] > self::MAX_SKIPPED || count($state['skipped']) + $index - $chain['index'] > self::MAX_SKIPPED) {
            throw new \InvalidArgumentException('Olm message index is outside the receiving window.');
        }
        do {
            $key = Encoding::decode($chain['key'], 32);
            $message_key = hash_hmac('sha256', "\x01", $key, true);
            $chain['key'] = Encoding::base64(hash_hmac('sha256', "\x02", $key, true));
            if ($chain['index'] !== $index) {
                $state['skipped'][$ratchet.':'.$chain['index']] = Encoding::base64($message_key);
            }
            $chain['index']++;
        } while ($chain['index'] <= $index);

        return $message_key;
    }

    private static function root(#[\SensitiveParameter] $shared, #[\SensitiveParameter] $salt, $info)
    {
        $keys = hash_hkdf('sha256', $shared, 64, $info, $salt);

        return [substr($keys, 0, 32), substr($keys, 32, 32)];
    }

    public function export()
    {
        return $this->state;
    }

    public static function restore(#[\SensitiveParameter] array $state)
    {
        if (($state['version'] ?? null) !== 1 || !is_bool($state['received'] ?? null)
            || !is_array($state['receive'] ?? null) || count($state['receive']) > self::MAX_CHAINS
            || !is_array($state['skipped'] ?? null) || count($state['skipped']) > self::MAX_SKIPPED) {
            throw new \InvalidArgumentException('Invalid Olm state.');
        }
        foreach (['identity', 'base', 'one_time', 'remote', 'root'] as $field) {
            Encoding::decode($state[$field], 32);
        }
        if ($state['ratchet'] !== null) {
            Encoding::decode($state['ratchet'], 32);
        }
        foreach (array_merge($state['receive'], $state['send'] === null ? [] : [$state['send']]) as $chain) {
            Encoding::decode($chain['key'], 32);
            if (!is_int($chain['index']) || $chain['index'] < 0 || $chain['index'] > 0x100000000) {
                throw new \InvalidArgumentException('Invalid Olm chain.');
            }
        }
        foreach ($state['receive'] as $chain) {
            Encoding::decode($chain['ratchet'], 32);
        }
        foreach ($state['skipped'] as $key) {
            Encoding::decode($key, 32);
        }

        return new self($state);
    }

    public function __debugInfo()
    {
        return ['id' => $this->id()];
    }
}
