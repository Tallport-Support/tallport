<?php

namespace App\Matrix\Crypto;

class Attachment
{
    public static function encrypt(#[\SensitiveParameter] $bytes)
    {
        $key = random_bytes(32);
        $iv = random_bytes(8).str_repeat("\0", 8);
        $ciphertext = openssl_encrypt($bytes, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Matrix file encryption failed.');
        }

        return ['ciphertext' => $ciphertext, 'file' => ['v' => 'v2', 'key' => ['kty' => 'oct', 'alg' => 'A256CTR',
            'ext' => true, 'key_ops' => ['encrypt', 'decrypt'], 'k' => sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)],
            'iv' => Encoding::base64($iv), 'hashes' => ['sha256' => Encoding::base64(hash('sha256', $ciphertext, true))]]];
    }

    public static function decrypt($ciphertext, array $file)
    {
        if (($file['v'] ?? null) !== 'v2' || ($file['key']['kty'] ?? null) !== 'oct' || ($file['key']['alg'] ?? null) !== 'A256CTR'
            || !in_array('decrypt', $file['key']['key_ops'] ?? [], true)) {
            throw new \InvalidArgumentException('Unsupported Matrix encrypted file.');
        }
        $expected = Encoding::decode($file['hashes']['sha256'] ?? '', 32);
        if (!hash_equals($expected, hash('sha256', $ciphertext, true))) {
            throw new \InvalidArgumentException('Matrix file hash mismatch.');
        }
        $key = Encoding::decode(strtr($file['key']['k'] ?? '', '-_', '+/'), 32);
        $iv = Encoding::decode($file['iv'] ?? '', 16);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);
        if ($plaintext === false) {
            throw new \InvalidArgumentException('Matrix file decryption failed.');
        }

        return $plaintext;
    }
}
