<?php

namespace App\Matrix\Crypto;

class CanonicalJson
{
    public static function decode($json)
    {
        return self::preserveObjects(json_decode($json, false, 128, JSON_THROW_ON_ERROR));
    }

    private static function preserveObjects($value)
    {
        if (is_array($value)) {
            return array_map([self::class, 'preserveObjects'], $value);
        }
        if ($value instanceof \stdClass) {
            $properties = array_map([self::class, 'preserveObjects'], (array) $value);

            return array_is_list($properties) ? (object) $properties : $properties;
        }

        return $value;
    }

    public static function encode($value)
    {
        if (is_array($value) && array_is_list($value)) {
            return '['.implode(',', array_map([self::class, 'encode'], $value)).']';
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $properties = (array) $value;
            ksort($properties, SORT_STRING);
            $pairs = [];
            foreach ($properties as $key => $item) {
                $pairs[] = self::encode((string) $key).':'.self::encode($item);
            }

            return '{'.implode(',', $pairs).'}';
        }
        if (is_float($value) || (is_int($value) && abs($value) > 9007199254740991) || is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Value is not Matrix canonical JSON.');
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
    }

    public static function signingBytes($object)
    {
        $object = (array) $object;
        unset($object['signatures'], $object['unsigned']);

        return self::encode((object) $object);
    }

    public static function sign($object, #[\SensitiveParameter] $secret)
    {
        return Encoding::base64(sodium_crypto_sign_detached(self::signingBytes($object), $secret));
    }

    public static function verify($object, $signature, $public)
    {
        try {
            return sodium_crypto_sign_verify_detached(Encoding::decode($signature, 64), self::signingBytes($object), Encoding::decode($public, 32));
        } catch (\InvalidArgumentException | \JsonException | \SodiumException $e) {
            return false;
        }
    }
}
