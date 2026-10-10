<?php

namespace Tests\Unit;

use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\Attachment;
use App\Matrix\Crypto\CanonicalJson;
use App\Matrix\Crypto\DeviceKeys;
use App\Matrix\Crypto\Encoding;
use App\Matrix\Crypto\OlmSession;
use App\Matrix\Crypto\SasVerification;
use Tests\TestCase;

class MatrixDeviceCryptoTest extends TestCase
{
    public function testAccountPublishesStableSignedKeysAndConsumesOnlyAuthenticatedRecipientMessages()
    {
        $alice = Account::create('@alice:example.org', 'ALICE');
        $bob = Account::create('@bob:example.org', 'BOB');
        $upload = $bob->uploadKeys(0);
        $this->assertCount(50, (array) $upload['one_time_keys']);
        $this->assertSame(json_encode($upload), json_encode(Account::restore($bob->export())->uploadKeys(0)));
        $device = DeviceKeys::device($upload['device_keys'], '@bob:example.org', 'BOB');
        $keys = (array) $upload['one_time_keys'];
        $key = reset($keys);
        $public = DeviceKeys::oneTime($key, '@bob:example.org', $device);
        $bob->markPublished(array_keys($keys));
        $this->assertSame([], (array) $bob->uploadKeys(50)['one_time_keys']);
        $session = OlmSession::create($alice->curveSecret(), $bob->curveKey(), $public);
        $wrong = $session->encrypt($alice->envelope('@eve:example.org', $bob->signingKey(), 'm.room_key', ['room_id' => '!test:example.org']));
        $before = $bob->export();
        $this->rejects(fn () => $bob->receive($alice->curveKey(), $wrong['body'], '@alice:example.org', $alice->signingKey()));
        $this->assertSame($before, $bob->export());
        $correct = $session->encrypt($alice->envelope('@bob:example.org', $bob->signingKey(), 'm.room_key', ['room_id' => '!test:example.org']));
        $received = $bob->receive($alice->curveKey(), $correct['body'], '@alice:example.org', $alice->signingKey());
        $this->assertSame('!test:example.org', $received['event']['content']['room_id']);
        $this->assertCount(49, $bob->export()['one_time']);
        $this->rejects(fn () => $bob->receive($alice->curveKey(), $correct['body'], '@alice:example.org', $alice->signingKey()));
    }

    public function testCrossSigningChecksTheEntireChainAndSeparatesUnsignedDevices()
    {
        $user = '@alice:example.org';
        $master = sodium_crypto_sign_seed_keypair(str_repeat('m', 32));
        $self = sodium_crypto_sign_seed_keypair(str_repeat('s', 32));
        $master_public = Encoding::base64(sodium_crypto_sign_publickey($master));
        $self_public = Encoding::base64(sodium_crypto_sign_publickey($self));
        $self_object = ['user_id' => $user, 'usage' => ['self_signing'], 'keys' => ['ed25519:'.$self_public => $self_public]];
        $self_object['signatures'][$user]['ed25519:'.$master_public] = CanonicalJson::sign($self_object, sodium_crypto_sign_secretkey($master));
        $device = Account::create($user, 'PHONE')->deviceKeys();
        $query = ['master_keys' => [$user => ['user_id' => $user, 'usage' => ['master'], 'keys' => ['ed25519:'.$master_public => $master_public]]],
            'self_signing_keys' => [$user => $self_object], 'device_keys' => [$user => ['PHONE' => $device]]];
        $this->assertFalse(DeviceKeys::crossSigning($query, $user)['devices']['PHONE']['cross_signed']);
        $device['signatures'][$user]['ed25519:'.$self_public] = CanonicalJson::sign($device, sodium_crypto_sign_secretkey($self));
        $query['device_keys'][$user]['PHONE'] = $device;
        $verified = DeviceKeys::crossSigning($query, $user);
        $this->assertSame($master_public, $verified['master']);
        $this->assertTrue($verified['devices']['PHONE']['cross_signed']);
        $query['self_signing_keys'][$user]['user_id'] = '@eve:example.org';
        $this->rejects(fn () => DeviceKeys::crossSigning($query, $user));
        $this->rejects(fn () => DeviceKeys::device($device, $user, 'OTHER'));
    }

    public function testSasMatchesReferenceAndRequiresBothHumanConfirmationAndPeerMac()
    {
        $f = $this->sasFixture();
        $sas = $this->sasAtComparison($f);
        $this->assertSame($f['decimals'], $sas->decimals($f['now']));
        $this->assertFalse($sas->complete());
        $this->assertNull($sas->handle($f['state']['user'], 'm.key.verification.mac', $f['peer_mac'], $f['now']));
        $this->assertFalse($sas->complete());
        $sas = SasVerification::restore($sas->export());
        $this->assertSame($f['our_mac'], $sas->confirm($f['our_signing'], $f['now']));
        $this->assertTrue($sas->complete());
        $sas = $this->sasAtComparison($f);
        $sas->confirm($f['our_signing'], $f['now']);
        $this->assertFalse($sas->complete());
        $this->assertSame('m.key.verification.done', $sas->handle($f['state']['user'], 'm.key.verification.mac', $f['peer_mac'], $f['now'])['type']);
        $this->assertTrue($sas->complete());
    }

    public function testSasRejectsWrongTransactionsDowngradesMissingKeysAndCancelledOrExpiredComparisons()
    {
        $f = $this->sasFixture();
        $sas = $this->sasAtComparison($f);
        $wrong = $f['peer_mac'];
        $wrong['transaction_id'] = 'wrong';
        $this->rejects(fn () => $sas->handle($f['state']['user'], 'm.key.verification.mac', $wrong, $f['now']));
        $this->rejects(fn () => $sas->handle('@eve:example.org', 'm.key.verification.mac', $f['peer_mac'], $f['now']));
        $wrong = $f['peer_mac'];
        unset($wrong['mac']['ed25519:ELEMENT']);
        $this->rejects(fn () => $sas->handle($f['state']['user'], 'm.key.verification.mac', $wrong, $f['now']));
        $this->assertFalse($sas->complete());
        $this->rejects(fn () => $sas->confirm($f['our_signing'], $f['state']['expires']));
        $sas->cancel();
        $this->rejects(fn () => $sas->confirm($f['our_signing'], $f['now']));
        $sas = SasVerification::restore($f['state']);
        $sas->ready($f['now']);
        $start = $f['start'];
        $start['message_authentication_codes'] = ['hkdf-hmac-sha256'];
        $this->rejects(fn () => $sas->handle($f['state']['user'], 'm.key.verification.start', $start, $f['now']));
    }

    public function testEncryptedAttachmentsAuthenticateCiphertextBeforeReturningBytes()
    {
        $bytes = "PDF\0binary\xff";
        $encrypted = Attachment::encrypt($bytes);
        $this->assertNotSame($bytes, $encrypted['ciphertext']);
        $this->assertSame($bytes, Attachment::decrypt($encrypted['ciphertext'], $encrypted['file']));
        $this->rejects(fn () => Attachment::decrypt($encrypted['ciphertext'].'x', $encrypted['file']));
        $encrypted['file']['v'] = 'v1';
        $this->rejects(fn () => Attachment::decrypt($encrypted['ciphertext'], $encrypted['file']));
    }

    private function sasFixture()
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/matrix/libolm.json')), true)['sas'];
    }

    private function sasAtComparison($f)
    {
        $sas = SasVerification::restore($f['state']);
        $sas->ready($f['now']);
        $accepted = $sas->handle($f['state']['user'], 'm.key.verification.start', $f['start'], $f['now']);
        $key = $sas->handle($f['state']['user'], 'm.key.verification.key', ['transaction_id' => $f['state']['transaction'], 'key' => $f['peer_key']], $f['now']);
        $this->assertSame(Encoding::base64(hash('sha256', $key['content']['key'].CanonicalJson::encode($f['start']), true)), $accepted['content']['commitment']);

        return $sas;
    }

    private function rejects($callback)
    {
        try {
            $callback();
            $this->fail('Accepted invalid Matrix device input.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }
}
