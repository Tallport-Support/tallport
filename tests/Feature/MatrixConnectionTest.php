<?php

namespace Tests\Feature;

use App\Livewire\MatrixSettings;
use App\Matrix\Connection;
use App\Matrix\Crypto\CanonicalJson;
use App\Matrix\Crypto\DeviceKeys;
use App\Matrix\CryptoRecord;
use App\Matrix\MatrixException;
use App\Matrix\MatrixMailbox;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class MatrixConnectionTest extends FeatureTestCase
{
    const HOME = 'https://matrix.example.org';
    const USER = '@support:example.org';

    private function server(&$server)
    {
        $server = ['device_keys' => [], 'master_keys' => [], 'self_signing_keys' => [], 'failures' => (object) []];
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$server) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/_matrix/client/versions') {
                return Http::response(['versions' => ['v1.11', 'v1.12']]);
            }
            if ($path === '/_matrix/client/v3/login') {
                return Http::response($request->method() === 'GET' ? ['flows' => [['type' => 'm.login.password']]]
                    : ['user_id' => self::USER, 'device_id' => $request['device_id'], 'access_token' => 'private-token']);
            }
            if ($path === '/_matrix/client/v3/keys/upload') {
                if (isset($request['device_keys'])) {
                    $device = $request['device_keys'];
                    $old = $server['device_keys'][self::USER][$device['device_id']] ?? [];
                    $device['signatures'] = array_replace_recursive($old['signatures'] ?? [], $device['signatures']);
                    $server['device_keys'][self::USER][$device['device_id']] = $device;
                }
                return Http::response(['one_time_key_counts' => ['signed_curve25519' => 50]]);
            }
            if ($path === '/_matrix/client/v3/keys/query') {
                return Http::response($server['query_override'] ?? $server);
            }
            if ($path === '/_matrix/client/v3/keys/device_signing/upload') {
                if (isset($server['signing_status'])) {
                    return Http::response(['flows' => [['stages' => ['m.login.password']]], 'session' => 'uia-session'], $server['signing_status']);
                }
                $server['master_keys'][self::USER] = $request['master_key'];
                $server['self_signing_keys'][self::USER] = $request['self_signing_key'];
                return Http::response('{}', !empty($server['lost_response']) ? 502 : 200);
            }
            if ($path === '/_matrix/client/v3/keys/signatures/upload') {
                if (!empty($server['signature_failure'])) {
                    return Http::response(['failures' => [self::USER => ['device' => ['errcode' => 'M_INVALID_SIGNATURE']]]]);
                }
                foreach ($request[self::USER] as $id => $object) {
                    if (isset($object['device_id']) && empty($server['ignore_signature'])) {
                        $server['device_keys'][self::USER][$id] = $object;
                    }
                }
                return Http::response(['failures' => (object) []]);
            }
            throw new \LogicException('Unexpected Matrix request: '.$path);
        });
    }

    public function testNewAccountConnectsWithoutAnotherClientAndStoresSigningKeysEncrypted()
    {
        $this->server($server);
        $mailbox = $this->createMailbox();
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])
            ->assertDontSee('matrix-password')->set('homeserver', 'matrix.example.org')->call('checkHomeserver')
            ->assertSet('checked_homeserver', self::HOME)->assertSee('matrix-password')->set('matrix_user', 'support')
            ->call('connect', 'private-password')->assertSet('error', false)->assertSee('Connected')->assertDontSee('Verify this device');
        $identity = MatrixMailbox::forMailbox($mailbox->id);
        $this->assertSame(self::USER, $identity->user_id);
        $this->assertSame(self::HOME, $identity->homeserver);
        $keys = DeviceKeys::crossSigning($server, self::USER);
        $this->assertTrue($keys['devices'][$identity->device_id]['cross_signed']);
        $this->assertSame($keys, CryptoRecord::read($identity->id, 'trust', self::USER));
        $secret = CryptoRecord::record($identity->id, 'cross_signing', 'identity');
        foreach ($secret->value as $value) {
            $this->assertStringNotContainsString($value, $secret->getRawOriginal('value'));
            $this->assertStringNotContainsString($value, $secret->toJson());
        }
        $this->assertStringNotContainsString('private-password', json_encode($identity->getAttributes()));
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/login') && $request['identifier']['user'] === 'support');
        $again = (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'private-password');
        $short = (new Connection())->connect($mailbox->id, ' matrix.example.org/ ', ' support ', 'private-password');
        $this->assertSame($identity->device_id, $again->device_id);
        $this->assertSame($identity->device_id, $short->device_id);
        $this->assertSame('ready', $short->status);
        $this->assertSame($identity->id, MatrixMailbox::forMailbox($mailbox->id)->id);
        $this->assertSame($secret->value, CryptoRecord::read($identity->id, 'cross_signing', 'identity'));
    }

    public function testDeviceResetReusesOwnedAccountSigningKeysAndKeepsReceivedKeys()
    {
        $this->server($server);
        $connection = new Connection();
        $identity = $connection->connect($this->createMailbox()->id, self::HOME, self::USER, 'secret');
        $device = $identity->device_id;
        $master = $server['master_keys'];
        CryptoRecord::write($identity->id, 'inbound', 'received', ['session_key' => 'kept']);
        $connection->resetDevice($identity);
        $again = $connection->connect($identity->mailbox_id, 'matrix.example.org', 'support', 'secret');
        $this->assertSame('ready', $again->status);
        $this->assertNotSame($device, $again->device_id);
        $this->assertSame($master, $server['master_keys']);
        $this->assertTrue(DeviceKeys::crossSigning($server, self::USER)['devices'][$again->device_id]['cross_signed']);
        $this->assertSame(['session_key' => 'kept'], CryptoRecord::read($identity->id, 'inbound', 'received'));
    }

    public function testLostSigningUploadResponseRetriesWithTheSameIdentity()
    {
        $this->server($server);
        $server['lost_response'] = true;
        $mailbox = $this->createMailbox();
        try {
            (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'secret');
            $this->fail('The lost response must leave setup incomplete.');
        } catch (MatrixException $e) {
            $this->assertSame(502, $e->getCode());
        }
        $identity = MatrixMailbox::forMailbox($mailbox->id);
        $this->assertSame('login', $identity->status);
        $master = $server['master_keys'];
        $again = (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'secret');
        $this->assertSame('ready', $again->status);
        $this->assertSame($identity->device_id, $again->device_id);
        $this->assertSame($master, $server['master_keys']);
        $uploads = Http::recorded(fn ($request) => str_ends_with($request->url(), '/keys/device_signing/upload'));
        $this->assertCount(1, $uploads);
    }

    public function testAnExistingIdentityWithoutItsPrivateKeysCanConnectUnverifiedAndIsNeverReplaced()
    {
        $this->server($server);
        $connection = new Connection();
        $identity = $connection->connect($this->createMailbox()->id, self::HOME, self::USER, 'secret');
        $master = $server['master_keys'];
        CryptoRecord::where('matrix_mailbox_id', $identity->id)->whereIn('kind', ['cross_signing', 'trust'])->delete();
        $connection->resetDevice($identity);
        $again = $connection->connect($identity->mailbox_id, self::HOME, self::USER, 'secret');
        $this->assertSame('verification', $again->status);
        $this->assertSame($master, $server['master_keys']);
        $this->assertNull(CryptoRecord::read($identity->id, 'cross_signing', 'identity'));
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/keys/device_signing/upload')));
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $identity->mailbox_id])
            ->assertSee('Connected')->assertSee('This device is unverified. You can still send and receive messages.');
        $this->assertTrue($again->isReady());
        $this->assertFalse($again->isVerified());
        $devices = (new \App\Matrix\CryptoManager($again))->trusted(self::USER);
        $this->assertFalse($devices[$again->device_id]['cross_signed']);
    }

    public function testAChangedAccountIdentityIsNotOverwrittenBySavedPrivateKeys()
    {
        $this->server($server);
        $identity = (new Connection())->connect($this->createMailbox()->id, self::HOME, self::USER, 'secret');
        $master = sodium_crypto_sign_keypair();
        $public = \App\Matrix\Crypto\Encoding::base64(sodium_crypto_sign_publickey($master));
        $server['master_keys'][self::USER] = ['user_id' => self::USER, 'usage' => ['master'], 'keys' => ['ed25519:'.$public => $public]];
        $self = $server['self_signing_keys'][self::USER];
        $self['signatures'] = [self::USER => ['ed25519:'.$public => CanonicalJson::sign($self, sodium_crypto_sign_secretkey($master))]];
        $server['self_signing_keys'][self::USER] = $self;
        $again = (new Connection())->connect($identity->mailbox_id, self::HOME, self::USER, 'secret');
        $this->assertSame('verification', $again->status);
        $this->assertSame($public, DeviceKeys::crossSigning($server, self::USER)['master']);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/keys/device_signing/upload')));
        $this->expectExceptionMessage('Matrix device trust needs review.');
        (new \App\Matrix\CryptoManager($again))->trusted(self::USER);
    }

    /** @dataProvider incompleteSetupResponses */
    public function testIncompleteSetupRemainsRetryableWithoutTrustingTheDevice($failure)
    {
        $this->server($server);
        $server = array_replace($server, $failure);
        $mailbox = $this->createMailbox();
        try {
            (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'secret');
            $this->fail('Incomplete setup must not activate the device.');
        } catch (MatrixException $e) {
            $identity = MatrixMailbox::forMailbox($mailbox->id);
            $this->assertSame('login', $identity->status);
            $this->assertNull(CryptoRecord::read($identity->id, 'trust', self::USER));
        }
        $uploads = Http::recorded(fn ($request) => str_ends_with($request->url(), '/keys/device_signing/upload'));
        $this->assertLessThanOrEqual(1, $uploads->count());
        foreach ($uploads as [$request]) {
            $this->assertArrayNotHasKey('auth', $request->data());
        }
    }

    public static function incompleteSetupResponses()
    {
        return [
            'identity created elsewhere: do not authorize overwrite' => [['signing_status' => 401]],
            'signature rejected with HTTP 200' => [['signature_failure' => true]],
            'signature not present on server' => [['ignore_signature' => true]],
            'failed device query' => [['query_override' => ['failures' => ['example.org' => ['errcode' => 'M_UNKNOWN']]]]],
            'incomplete device query' => [['query_override' => []]],
        ];
    }

    public function testShortLoginCannotConnectTheSameAccountToAnotherMailbox()
    {
        $this->server($server);
        (new Connection())->connect($this->createMailbox()->id, self::HOME, self::USER, 'secret');
        $this->expectException(MatrixException::class);
        (new Connection())->connect($this->createMailbox()->id, 'matrix.example.org', 'support', 'secret');
    }

    public function testExplicitHttpIsNotUpgradedOrAllowed()
    {
        $this->server($server);
        try {
            (new Connection())->connect($this->createMailbox()->id, 'http://matrix.example.org', 'support', 'secret');
            $this->fail('Explicit HTTP must not receive credentials.');
        } catch (MatrixException $e) {
            Http::assertNothingSent();
        }
    }
}
