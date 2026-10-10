<?php

namespace App\Matrix\Crypto;

/**
 * The binary encodings used by Olm and Megolm.
 */
class Encoding
{
    const MAX_MESSAGE_BYTES = 1048576;

    public static function base64($bytes)
    {
        return sodium_bin2base64($bytes, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING);
    }

    public static function decode($value, $length = null)
    {
        if (!is_string($value) || strlen($value) > self::MAX_MESSAGE_BYTES * 2) {
            throw new \InvalidArgumentException('Invalid Matrix base64.');
        }
        try {
            $bytes = sodium_base642bin($value, SODIUM_BASE64_VARIANT_ORIGINAL_NO_PADDING, '');
        } catch (\SodiumException $e) {
            throw new \InvalidArgumentException('Invalid Matrix base64.');
        }
        if (($length !== null && strlen($bytes) !== $length) || self::base64($bytes) !== $value) {
            throw new \InvalidArgumentException('Invalid Matrix base64 length or encoding.');
        }

        return $bytes;
    }

    public static function varint($value)
    {
        if (!is_int($value) || $value < 0 || $value > 0xffffffff) {
            throw new \InvalidArgumentException('Invalid Matrix message index.');
        }
        $result = '';
        do {
            $byte = $value & 127;
            $value >>= 7;
            $result .= chr($byte | ($value ? 128 : 0));
        } while ($value);

        return $result;
    }

    public static function bytes($tag, $bytes)
    {
        return chr($tag).self::varint(strlen($bytes)).$bytes;
    }

    public static function fields($wire, array $tags)
    {
        $length = strlen($wire);
        if (!$length || $length > self::MAX_MESSAGE_BYTES || $wire[0] !== "\x03") {
            throw new \InvalidArgumentException('Invalid Matrix message version or length.');
        }
        $fields = [];
        $offset = 1;
        while ($offset < $length) {
            $tag = ord($wire[$offset++]);
            if (!in_array($tag, $tags, true) || array_key_exists($tag, $fields)) {
                throw new \InvalidArgumentException('Invalid Matrix message field.');
            }
            $value = self::readVarint($wire, $offset);
            if (($tag & 7) === 2) {
                if ($value > $length - $offset) {
                    throw new \InvalidArgumentException('Truncated Matrix message.');
                }
                $fields[$tag] = substr($wire, $offset, $value);
                $offset += $value;
            } else {
                $fields[$tag] = $value;
            }
        }
        if (count($fields) !== count($tags)) {
            throw new \InvalidArgumentException('Missing Matrix message field.');
        }

        return $fields;
    }

    private static function readVarint($wire, &$offset)
    {
        $start = $offset;
        $value = 0;
        $length = strlen($wire);
        for ($shift = 0; $shift <= 28 && $offset < $length; $shift += 7) {
            $byte = ord($wire[$offset++]);
            $value |= ($byte & 127) << $shift;
            if (!($byte & 128)) {
                if ($value > 0xffffffff || substr($wire, $start, $offset - $start) !== self::varint($value)) {
                    break;
                }

                return $value;
            }
        }
        throw new \InvalidArgumentException('Invalid Matrix message integer.');
    }

    public static function keys(#[\SensitiveParameter] $key, $info)
    {
        $expanded = hash_hkdf('sha256', $key, 80, $info);

        return [substr($expanded, 0, 32), substr($expanded, 32, 32), substr($expanded, 64, 16)];
    }

    public static function encrypt(#[\SensitiveParameter] $plaintext, #[\SensitiveParameter] $key, $iv)
    {
        if (strlen($plaintext) > self::MAX_MESSAGE_BYTES - 256) {
            throw new \InvalidArgumentException('Matrix message is too large.');
        }
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Matrix encryption failed.');
        }

        return $ciphertext;
    }

    public static function decrypt($ciphertext, #[\SensitiveParameter] $key, $iv)
    {
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \InvalidArgumentException('Invalid Matrix ciphertext.');
        }

        return $plaintext;
    }

    public static function mac($message, #[\SensitiveParameter] $key)
    {
        return substr(hash_hmac('sha256', $message, $key, true), 0, 8);
    }
}
