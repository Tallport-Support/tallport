<?php

namespace App\Matrix\Crypto;

/**
 * One sending or receiving session. Persist sending state before publishing ciphertext.
 */
class Megolm
{
    private $ratchet;
    private $index;
    private $public;
    private $secret;

    private function __construct()
    {
    }

    public static function create()
    {
        $session = new self();
        $keys = sodium_crypto_sign_keypair();
        $session->secret = sodium_crypto_sign_secretkey($keys);
        $session->public = sodium_crypto_sign_publickey($keys);
        $session->ratchet = random_bytes(128);
        $session->index = 0;

        return $session;
    }

    public static function receive(#[\SensitiveParameter] $session_key)
    {
        $bytes = Encoding::decode($session_key, 229);
        if ($bytes[0] !== "\x02" || !sodium_crypto_sign_verify_detached(substr($bytes, 165), substr($bytes, 0, 165), substr($bytes, 133, 32))) {
            throw new \InvalidArgumentException('Invalid Megolm session key.');
        }
        $session = new self();
        $session->index = unpack('N', substr($bytes, 1, 4))[1];
        $session->ratchet = substr($bytes, 5, 128);
        $session->public = substr($bytes, 133, 32);

        return $session;
    }

    public function id()
    {
        return Encoding::base64($this->public);
    }

    public function index()
    {
        return $this->index;
    }

    public function sessionKey()
    {
        if ($this->secret === null || $this->index > 0xffffffff) {
            throw new \LogicException('Megolm session cannot share a sending key.');
        }
        $bytes = "\x02".pack('N', $this->index).$this->ratchet.$this->public;

        return Encoding::base64($bytes.sodium_crypto_sign_detached($bytes, $this->secret));
    }

    public function encrypt(#[\SensitiveParameter] $plaintext)
    {
        if ($this->secret === null || $this->index > 0xffffffff) {
            throw new \LogicException('Megolm session cannot send.');
        }
        [$key, $mac, $iv] = Encoding::keys($this->ratchet, 'MEGOLM_KEYS');
        $wire = "\x03\x08".Encoding::varint($this->index).Encoding::bytes(0x12, Encoding::encrypt($plaintext, $key, $iv));
        $wire .= Encoding::mac($wire, $mac);
        $wire .= sodium_crypto_sign_detached($wire, $this->secret);
        if ($this->index < 0xffffffff) {
            $this->ratchet = self::advance($this->ratchet, $this->index, $this->index + 1);
        }
        $this->index++;

        return Encoding::base64($wire);
    }

    /**
     * Receiving never discards earlier keys; replay binding belongs to the event store.
     */
    public function decrypt($message)
    {
        $wire = Encoding::decode($message);
        if (strlen($wire) < 76 || !sodium_crypto_sign_verify_detached(substr($wire, -64), substr($wire, 0, -64), $this->public)) {
            throw new \InvalidArgumentException('Invalid Megolm message signature.');
        }
        $body = substr($wire, 0, -72);
        $fields = Encoding::fields($body, [0x08, 0x12]);
        $ratchet = self::advance($this->ratchet, $this->index, $fields[0x08]);
        [$key, $mac, $iv] = Encoding::keys($ratchet, 'MEGOLM_KEYS');
        if (!hash_equals(Encoding::mac($body, $mac), substr($wire, -72, 8))) {
            throw new \InvalidArgumentException('Invalid Megolm message authentication.');
        }

        return ['plaintext' => Encoding::decrypt($fields[0x12], $key, $iv), 'index' => $fields[0x08]];
    }

    /**
     * Advance at most 255 steps per byte, even for an attacker-chosen index.
     */
    private static function advance(#[\SensitiveParameter] $ratchet, $from, $to)
    {
        if ($to < $from || $to > 0xffffffff) {
            throw new \InvalidArgumentException('Megolm message predates its session key.');
        }
        $parts = str_split($ratchet, 32);
        for ($part = 0; $part < 4; $part++) {
            $shift = (3 - $part) * 8;
            $steps = (($to >> $shift) - ($from >> $shift)) & 255;
            if (!$steps) {
                continue;
            }
            for ($step = 1; $step < $steps; $step++) {
                $parts[$part] = hash_hmac('sha256', chr($part), $parts[$part], true);
            }
            for ($child = 3; $child >= $part; $child--) {
                $parts[$child] = hash_hmac('sha256', chr($child), $parts[$part], true);
            }
            $from = $to & (0xffffffff << $shift);
        }

        return implode('', $parts);
    }

    public function export()
    {
        return ['version' => 1, 'ratchet' => Encoding::base64($this->ratchet), 'index' => $this->index,
            'public' => $this->id(), 'secret' => $this->secret === null ? null : Encoding::base64($this->secret)];
    }

    public static function restore(#[\SensitiveParameter] array $state)
    {
        if (($state['version'] ?? null) !== 1 || !is_int($state['index'] ?? null) || $state['index'] < 0 || $state['index'] > 0x100000000) {
            throw new \InvalidArgumentException('Invalid Megolm state.');
        }
        $session = new self();
        $session->ratchet = Encoding::decode($state['ratchet'], 128);
        $session->public = Encoding::decode($state['public'], 32);
        $session->secret = ($state['secret'] ?? null) === null ? null : Encoding::decode($state['secret'], 64);
        $session->index = $state['index'];
        if ($session->secret !== null && !hash_equals($session->public, sodium_crypto_sign_publickey_from_secretkey($session->secret))) {
            throw new \InvalidArgumentException('Invalid Megolm signing key.');
        }

        return $session;
    }

    public function __debugInfo()
    {
        return ['id' => $this->id(), 'index' => $this->index];
    }
}
