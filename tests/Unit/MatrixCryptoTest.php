<?php

namespace Tests\Unit;

use App\Matrix\Crypto\CanonicalJson;
use App\Matrix\Crypto\Encoding;
use App\Matrix\Crypto\Megolm;
use App\Matrix\Crypto\OlmSession;
use Tests\TestCase;

class MatrixCryptoTest extends TestCase
{
    private function fixtures()
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/matrix/libolm.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMegolmDecryptsReferenceMessagesInAnyOrderAndAfterRestart()
    {
        $fixture = $this->fixtures()['megolm'];
        $session = Megolm::receive($fixture['key']);
        foreach (array_reverse($fixture['messages']) as $message) {
            $session = Megolm::restore($session->export());
            $this->assertSame(['plaintext' => $message['plaintext'], 'index' => $message['index']], $session->decrypt($message['body']));
        }
    }

    public function testMegolmProducesReferenceCiphertextAndRotatesAtCounterBoundaries()
    {
        $fixture = $this->fixtures()['megolm'];
        $keypair = sodium_crypto_sign_seed_keypair(Encoding::decode($fixture['signing_seed'], 32));
        $state = Megolm::receive($fixture['key'])->export();
        $state['secret'] = Encoding::base64(sodium_crypto_sign_secretkey($keypair));
        $session = Megolm::restore($state);
        $expected = array_column($fixture['messages'], 'body', 'index');
        for ($index = 0; $index <= 256; $index++) {
            $body = $session->encrypt('reference group message '.$index);
            if (isset($expected[$index])) {
                $this->assertSame($expected[$index], $body);
            }
        }
        foreach ([255, 65535, 16777215, 4294967294] as $index) {
            $state['index'] = $index;
            $state['ratchet'] = Encoding::base64(substr(Encoding::decode($fixture['exports'][$index]), 5, 128));
            $session = Megolm::restore($state);
            $session->encrypt('boundary');
            $this->assertSame(Encoding::base64(substr(Encoding::decode($fixture['exports'][$index + 1]), 5, 128)), $session->export()['ratchet']);
        }
    }

    public function testMegolmRejectsTamperingAndKeysWithoutAuthenticSignatures()
    {
        $fixture = $this->fixtures()['megolm'];
        $session = Megolm::receive($fixture['key']);
        $before = $session->export();
        $wire = Encoding::decode($fixture['messages'][0]['body']);
        foreach ([0, 4, strlen($wire) - 65, strlen($wire) - 1] as $offset) {
            $tampered = $wire;
            $tampered[$offset] = chr(ord($tampered[$offset]) ^ 1);
            $this->rejects(fn () => $session->decrypt(Encoding::base64($tampered)));
            $this->assertSame($before, $session->export());
        }
        $key = Encoding::decode($fixture['key']);
        $key[5] = chr(ord($key[5]) ^ 1);
        $this->rejects(fn () => Megolm::receive(Encoding::base64($key)));
        $this->assertSame($fixture['messages'][0]['plaintext'], $session->decrypt($fixture['messages'][0]['body'])['plaintext']);
    }

    public function testMegolmEnforcesEarliestKeyAndSendingCounterExhaustion()
    {
        $outbound = Megolm::create();
        $early = $outbound->encrypt('early');
        $inbound = Megolm::receive($outbound->sessionKey());
        $this->rejects(fn () => $inbound->decrypt($early));
        $this->assertSame('later', $inbound->decrypt($outbound->encrypt('later'))['plaintext']);
        $state = $outbound->export();
        $state['index'] = 0xffffffff;
        $outbound = Megolm::restore($state);
        $last = Megolm::receive($outbound->sessionKey());
        $this->assertSame('last', $last->decrypt($outbound->encrypt('last'))['plaintext']);
        $this->expectException(\LogicException::class);
        $outbound->encrypt('overflow');
    }

    public function testOlmCreatesReferenceCompatiblePreKeyMessagesAndReceivesRatchetedReplies()
    {
        $fixture = $this->fixtures();
        $outbound = OlmSession::restore($fixture['olm_outbound_state']);
        $this->assertSame($fixture['olm_outbound_initial'], $outbound->encrypt('Tallport initial'));
        foreach ($fixture['olm_outbound_replies'] as $message) {
            $session = OlmSession::restore($message['state']);
            $this->assertSame($message['plaintext'], $session->decrypt($message['type'], $message['body']));
            $this->assertSame(1, $session->encrypt('reply')['type']);
        }
    }

    public function testOlmReceivesReferencePreKeysAndOutOfOrderMessagesAfterRestart()
    {
        $fixture = $this->fixtures()['olm_inbound'];
        $received = OlmSession::receive(Encoding::decode($fixture['identity_secret']), Encoding::decode($fixture['one_time_secret']), $fixture['sender'], $fixture['messages'][0]['body']);
        $this->assertSame('reference initial', $received['plaintext']);
        $session = $received['session'];
        foreach ([2, 1] as $index) {
            $session = OlmSession::restore($session->export());
            $message = $fixture['messages'][$index];
            $this->assertSame($message['plaintext'], $session->decrypt($message['type'], $message['body']));
        }
        $this->rejects(fn () => $session->decrypt(0, $fixture['messages'][1]['body']));
        $message = $fixture['ratcheted'];
        $session = OlmSession::restore($message['state']);
        $this->assertSame($message['plaintext'], $session->decrypt($message['type'], $message['body']));
    }

    public function testOlmRejectsWrongIdentityAndTamperingWithoutConsumingKeys()
    {
        $fixture = $this->fixtures()['olm_inbound'];
        $args = [Encoding::decode($fixture['identity_secret']), Encoding::decode($fixture['one_time_secret']), $fixture['sender'], $fixture['messages'][0]['body']];
        $wrong = $args;
        $wrong[2] = Encoding::base64(str_repeat('x', 32));
        $this->rejects(fn () => OlmSession::receive(...$wrong));
        $session = OlmSession::receive(...$args)['session'];
        $before = $session->export();
        $wire = Encoding::decode($fixture['messages'][2]['body']);
        $wire[strlen($wire) - 1] = chr(ord($wire[strlen($wire) - 1]) ^ 1);
        $this->rejects(fn () => $session->decrypt(0, Encoding::base64($wire)));
        $this->assertSame($before, $session->export());
        $this->assertSame('reference third', $session->decrypt(0, $fixture['messages'][2]['body']));
    }

    public function testOlmExchangesMultipleRatchetsAndPreservesSkippedKeys()
    {
        $alice_secret = str_repeat('a', 32);
        $bob_secret = str_repeat('b', 32);
        $one_time = str_repeat('o', 32);
        $alice = OlmSession::create($alice_secret, Encoding::base64(sodium_crypto_scalarmult_base($bob_secret)), Encoding::base64(sodium_crypto_scalarmult_base($one_time)));
        $first = $alice->encrypt('hello');
        $received = OlmSession::receive($bob_secret, $one_time, Encoding::base64(sodium_crypto_scalarmult_base($alice_secret)), $first['body']);
        $bob = $received['session'];
        $this->assertSame($alice->id(), $bob->id());
        for ($round = 0; $round < 8; $round++) {
            $older = $bob->encrypt('older');
            $newer = $bob->encrypt('newer');
            $this->assertSame('newer', $alice->decrypt($newer['type'], $newer['body']));
            $reply = $alice->encrypt('reply');
            $this->assertSame('reply', $bob->decrypt($reply['type'], $reply['body']));
            $this->assertSame('older', $alice->decrypt($older['type'], $older['body']));
            $alice = OlmSession::restore($alice->export());
            $bob = OlmSession::restore($bob->export());
        }
    }

    public function testCanonicalJsonPreservesObjectKeysAndRejectsUnsafeNumbers()
    {
        $value = json_decode('{"z":{},"a":["日/本",{"10":true,"2":null}],"empty":[]}');
        $this->assertSame('{"a":["日/本",{"10":true,"2":null}],"empty":[],"z":{}}', CanonicalJson::encode($value));
        foreach ([1.5, NAN, INF, 9007199254740992] as $value) {
            $this->rejects(fn () => CanonicalJson::encode($value));
        }
        $keys = sodium_crypto_sign_seed_keypair(str_repeat('k', 32));
        $object = ['user_id' => '@test:example.org', 'keys' => (object) [], 'unsigned' => ['label' => 'before']];
        $signature = CanonicalJson::sign($object, sodium_crypto_sign_secretkey($keys));
        $public = Encoding::base64(sodium_crypto_sign_publickey($keys));
        $object['unsigned']['label'] = 'after';
        $this->assertTrue(CanonicalJson::verify($object, $signature, $public));
        $object['user_id'] = '@different:example.org';
        $this->assertFalse(CanonicalJson::verify($object, $signature, $public));
    }

    public function testBinaryParserRejectsNonCanonicalTruncatedDuplicateAndOversizedFields()
    {
        foreach (["\x03\x08\x80\x00", "\x03\x08\xff\xff\xff\xff\x10", "\x03\x08\x01\x08\x02", "\x03\x12\x05x", "\x04\x08\x00"] as $wire) {
            $this->rejects(fn () => Encoding::fields($wire, [ord($wire[1])]));
        }
        foreach (['A', 'ab', 'YQ==', 'YQ ', '-_'] as $base64) {
            $this->rejects(fn () => Encoding::decode($base64));
        }
        $this->assertSame([8 => 0xffffffff], Encoding::fields("\x03\x08\xff\xff\xff\xff\x0f", [8]));
    }

    private function rejects($callback)
    {
        try {
            $callback();
            $this->fail('Accepted invalid Matrix crypto input.');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }
}
