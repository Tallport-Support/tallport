<?php

namespace App\Matrix\Crypto;

/**
 * A persistent Matrix device identity and its unpublished/claimed one-time keys.
 */
class Account
{
    private $state;

    private function __construct(#[\SensitiveParameter] array $state)
    {
        $this->state = $state;
    }

    public static function create($user, $device)
    {
        $signing = sodium_crypto_sign_keypair();

        return new self(['version' => 1, 'user' => $user, 'device' => $device,
            'curve' => Encoding::base64(random_bytes(32)), 'signing' => Encoding::base64(sodium_crypto_sign_secretkey($signing)),
            'next_key' => 0, 'one_time' => []]);
    }

    public function curveKey()
    {
        return Encoding::base64(sodium_crypto_scalarmult_base($this->curveSecret()));
    }

    public function signingKey()
    {
        return Encoding::base64(sodium_crypto_sign_publickey_from_secretkey(Encoding::decode($this->state['signing'], 64)));
    }

    public function curveSecret()
    {
        return Encoding::decode($this->state['curve'], 32);
    }

    public function sign(array $object)
    {
        $object['signatures'][$this->state['user']]['ed25519:'.$this->state['device']] = CanonicalJson::sign($object, Encoding::decode($this->state['signing'], 64));

        return $object;
    }

    public function deviceKeys()
    {
        return $this->sign(['user_id' => $this->state['user'], 'device_id' => $this->state['device'],
            'algorithms' => ['m.olm.v1.curve25519-aes-sha2', 'm.megolm.v1.aes-sha2'],
            'keys' => ['curve25519:'.$this->state['device'] => $this->curveKey(), 'ed25519:'.$this->state['device'] => $this->signingKey()]]);
    }

    /**
     * Repeating an upload after a lost response sends the same unpublished keys.
     */
    public function uploadKeys($server_count)
    {
        $unpublished = count(array_filter($this->state['one_time'], fn ($key) => !$key['published']));
        $needed = max(0, 50 - max(0, (int) $server_count) - $unpublished);
        for ($i = 0; $i < $needed; $i++) {
            $id = (string) $this->state['next_key']++;
            $this->state['one_time'][$id] = ['secret' => Encoding::base64(random_bytes(32)), 'published' => false];
        }
        while (count($this->state['one_time']) > 100) {
            unset($this->state['one_time'][array_key_first($this->state['one_time'])]);
        }
        $keys = [];
        foreach ($this->state['one_time'] as $id => $key) {
            if (!$key['published']) {
                $public = Encoding::base64(sodium_crypto_scalarmult_base(Encoding::decode($key['secret'], 32)));
                $keys['signed_curve25519:'.$id] = $this->sign(['key' => $public]);
            }
        }

        return ['device_keys' => $this->deviceKeys(), 'one_time_keys' => (object) $keys];
    }

    public function markPublished(array $ids)
    {
        foreach ($ids as $id) {
            $id = substr($id, strlen('signed_curve25519:'));
            if (isset($this->state['one_time'][$id])) {
                $this->state['one_time'][$id]['published'] = true;
            }
        }
    }

    public function receive($sender_curve, $message, $sender_user, $sender_signing)
    {
        $public = OlmSession::oneTimeKey($message);
        foreach ($this->state['one_time'] as $id => $key) {
            $secret = Encoding::decode($key['secret'], 32);
            if (hash_equals($public, Encoding::base64(sodium_crypto_scalarmult_base($secret)))) {
                $result = OlmSession::receive($this->curveSecret(), $secret, $sender_curve, $message);
                $result['event'] = $this->validateEnvelope($result['plaintext'], $sender_user, $sender_signing);
                unset($this->state['one_time'][$id]);

                return $result;
            }
        }
        throw new \InvalidArgumentException('Unknown Olm one-time key.');
    }

    public function envelope($recipient, $recipient_signing, $type, array $content)
    {
        return CanonicalJson::encode(['sender' => $this->state['user'], 'sender_device' => $this->state['device'],
            'keys' => ['ed25519' => $this->signingKey()], 'recipient' => $recipient,
            'recipient_keys' => ['ed25519' => $recipient_signing], 'type' => $type, 'content' => (object) $content]);
    }

    public function validateEnvelope(#[\SensitiveParameter] $plaintext, $sender_user, $sender_signing)
    {
        $event = json_decode($plaintext, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($event) || ($event['sender'] ?? null) !== $sender_user || ($event['recipient'] ?? null) !== $this->state['user']
            || ($event['recipient_keys']['ed25519'] ?? null) !== $this->signingKey() || ($event['keys']['ed25519'] ?? null) !== $sender_signing
            || !is_string($event['type'] ?? null) || !is_array($event['content'] ?? null)) {
            throw new \InvalidArgumentException('Olm event identity mismatch.');
        }

        return $event;
    }

    public function export()
    {
        return $this->state;
    }

    public static function restore(#[\SensitiveParameter] array $state)
    {
        if (($state['version'] ?? null) !== 1 || !is_string($state['user'] ?? null) || !is_string($state['device'] ?? null)
            || !is_int($state['next_key'] ?? null) || $state['next_key'] < 0 || !is_array($state['one_time'] ?? null) || count($state['one_time']) > 100) {
            throw new \InvalidArgumentException('Invalid Matrix account state.');
        }
        Encoding::decode($state['curve'], 32);
        Encoding::decode($state['signing'], 64);
        foreach ($state['one_time'] as $key) {
            Encoding::decode($key['secret'], 32);
            if (!is_bool($key['published'])) {
                throw new \InvalidArgumentException('Invalid Matrix one-time key state.');
            }
        }

        return new self($state);
    }

    public function __debugInfo()
    {
        return ['user' => $this->state['user'], 'device' => $this->state['device']];
    }
}
