<?php

namespace App\Matrix\Crypto;

class DeviceKeys
{
    public static function device(array $device, $user, $id)
    {
        $signing = $device['keys']['ed25519:'.$id] ?? '';
        $curve = $device['keys']['curve25519:'.$id] ?? '';
        Encoding::decode($signing, 32);
        Encoding::decode($curve, 32);
        if (($device['user_id'] ?? null) !== $user || ($device['device_id'] ?? null) !== $id
            || !in_array('m.olm.v1.curve25519-aes-sha2', $device['algorithms'] ?? [], true)
            || !in_array('m.megolm.v1.aes-sha2', $device['algorithms'] ?? [], true)
            || !CanonicalJson::verify($device, $device['signatures'][$user]['ed25519:'.$id] ?? '', $signing)) {
            throw new \InvalidArgumentException('Invalid Matrix device signature or identity.');
        }

        return ['id' => $id, 'signing' => $signing, 'curve' => $curve];
    }

    public static function crossSigning(array $query, $user)
    {
        $master = $query['master_keys'][$user] ?? [];
        $self = $query['self_signing_keys'][$user] ?? [];
        $master_key = self::crossKey($master, $user, 'master');
        $self_key = self::crossKey($self, $user, 'self_signing');
        if (!CanonicalJson::verify($self, $self['signatures'][$user]['ed25519:'.$master_key] ?? '', $master_key)) {
            throw new \InvalidArgumentException('Invalid Matrix cross-signing chain.');
        }
        $devices = [];
        foreach ($query['device_keys'][$user] ?? [] as $id => $device) {
            $verified = self::device($device, $user, (string) $id);
            $verified['cross_signed'] = CanonicalJson::verify($device, $device['signatures'][$user]['ed25519:'.$self_key] ?? '', $self_key);
            $devices[(string) $id] = $verified;
        }

        return ['master' => $master_key, 'devices' => $devices];
    }

    private static function crossKey(array $object, $user, $usage)
    {
        $keys = $object['keys'] ?? [];
        $public = count($keys) === 1 ? reset($keys) : '';
        Encoding::decode($public, 32);
        if (($object['user_id'] ?? null) !== $user || ($object['usage'] ?? null) !== [$usage] || array_key_first($keys) !== 'ed25519:'.$public) {
            throw new \InvalidArgumentException('Invalid Matrix cross-signing identity.');
        }

        return $public;
    }

    public static function oneTime(array $key, $user, array $device)
    {
        $public = $key['key'] ?? '';
        Encoding::decode($public, 32);
        if (!CanonicalJson::verify($key, $key['signatures'][$user]['ed25519:'.$device['id']] ?? '', $device['signing'])) {
            throw new \InvalidArgumentException('Invalid Matrix one-time key signature.');
        }

        return $public;
    }
}
