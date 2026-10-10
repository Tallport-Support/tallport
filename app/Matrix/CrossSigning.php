<?php

namespace App\Matrix;

use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\CanonicalJson;
use App\Matrix\Crypto\DeviceKeys;
use App\Matrix\Crypto\Encoding;

class CrossSigning
{
    public function connect(MatrixMailbox $identity)
    {
        $user = $identity->user_id;
        $query = $identity->client()->call('POST', 'v3/keys/query', ['device_keys' => [$user => []], 'timeout' => 10000]);
        if ((array) ($query['failures'] ?? []) || !isset($query['device_keys'][$user][$identity->device_id])) {
            throw new MatrixException('Matrix device list is unavailable.');
        }
        $account = Account::restore(CryptoRecord::read($identity->id, 'account', 'device'));
        $device = DeviceKeys::device($query['device_keys'][$user][$identity->device_id], $user, $identity->device_id);
        if ($device['signing'] !== $account->signingKey() || $device['curve'] !== $account->curveKey()) {
            throw new MatrixException('Matrix device keys changed.');
        }
        $master = ((array) ($query['master_keys'] ?? []))[$user] ?? null;
        $self = ((array) ($query['self_signing_keys'] ?? []))[$user] ?? null;
        $saved = CryptoRecord::read($identity->id, 'cross_signing', 'identity');
        $known = CryptoRecord::read($identity->id, 'trust', $user);
        if (!$saved) {
            if ($master !== null) {
                DeviceKeys::crossSigning($query, $user);

                return;
            }
            if ($self !== null || $known) {
                throw new MatrixException('Matrix account signing keys are missing.');
            }
            $saved = [
                'master' => Encoding::base64(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
                'self_signing' => Encoding::base64(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())),
            ];
            CryptoRecord::write($identity->id, 'cross_signing', 'identity', $saved);
        }
        $master_secret = Encoding::decode($saved['master'], 64);
        $self_secret = Encoding::decode($saved['self_signing'], 64);
        $master_public = Encoding::base64(sodium_crypto_sign_publickey_from_secretkey($master_secret));
        $self_public = Encoding::base64(sodium_crypto_sign_publickey_from_secretkey($self_secret));
        $master_object = ['user_id' => $user, 'usage' => ['master'], 'keys' => ['ed25519:'.$master_public => $master_public]];
        $self_object = ['user_id' => $user, 'usage' => ['self_signing'], 'keys' => ['ed25519:'.$self_public => $self_public]];
        $self_object['signatures'][$user]['ed25519:'.$master_public] = CanonicalJson::sign($self_object, $master_secret);
        if ($master !== null) {
            $keys = DeviceKeys::crossSigning($query, $user);
            if ($keys['master'] !== $master_public || CanonicalJson::signingBytes($self) !== CanonicalJson::signingBytes($self_object)) {
                return;
            }
        } else {
            if ($self !== null || $known) {
                throw new MatrixException('Matrix account signing keys are missing.');
            }
            // v1.11 permits first-time uploads without UIA. Never authorize an identity replacement.
            $identity->client()->call('POST', 'v3/keys/device_signing/upload', [
                'master_key' => $account->sign($master_object), 'self_signing_key' => $self_object,
            ]);
        }
        $device_object = $account->deviceKeys();
        $device_object['signatures'][$user]['ed25519:'.$self_public] = CanonicalJson::sign($device_object, $self_secret);
        $result = $identity->client()->call('POST', 'v3/keys/signatures/upload', [$user => [
            $identity->device_id => $device_object, $master_public => $account->sign($master_object),
        ]]);
        if ((array) ($result['failures'] ?? []) || !(new Verification($identity))->activateWithMaster($master_public)) {
            throw new MatrixException('Matrix device signature was not accepted.');
        }
    }
}
