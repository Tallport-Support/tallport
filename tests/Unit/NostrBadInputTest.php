<?php

namespace Tests\Unit;

use App\Nostr\Crypto\Bech32;
use App\Nostr\Crypto\ChaCha20;
use App\Nostr\Crypto\Nip44;
use App\Nostr\Crypto\Schnorr;
use App\Nostr\EventBuilder;
use App\Nostr\GiftWrap;
use App\Nostr\Keys;
use Tests\TestCase;

/**
 * What Nostr's encodings, keys, events and gift wraps refuse: everything
 * here comes from strangers on public relays.
 */
class NostrBadInputTest extends TestCase
{
    protected function assertFailsWith($message, callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertSame($message, $e->getMessage());

            return;
        }
        $this->fail('Expected: '.$message);
    }

    public function testBech32AndKeys()
    {
        $npub = 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6';
        $this->assertFailsWith('Mixed case bech32 string', function () use ($npub) {
            Bech32::decode('Npub'.substr($npub, 4));
        });
        $this->assertFailsWith('Invalid bech32 string', function () {
            Bech32::decode('npub1abc');
        });
        $this->assertFailsWith('Invalid bech32 character', function () use ($npub) {
            Bech32::decode(substr($npub, 0, -1).'b');
        });
        $this->assertFailsWith('Invalid bech32 checksum', function () use ($npub) {
            Bech32::decode(substr($npub, 0, -1).'q');
        });
        $this->assertSame(strtolower($npub), Keys::npub(Keys::toHex(strtoupper($npub))));
        $this->assertFailsWith('Invalid value for bit conversion', function () {
            Bech32::convertBits([300], 8, 5, true);
        });
        $this->assertFailsWith('Invalid padding in bit conversion', function () {
            Bech32::convertBits([31, 31], 5, 8, false);
        });

        // A TLV with a truncated entry stops there.
        $this->assertSame([0 => ['abc']], Bech32::parseTlv(chr(0).chr(3).'abc'.chr(1).chr(9).'xy'));

        $hex = Keys::toHex($npub);
        $this->assertSame($hex, Keys::toHex('nostr:'.$npub));
        $this->assertNull(Keys::toHex(''));
        $this->assertNull(Keys::toHex(Bech32::encode('npub', 'short')));
        $this->assertNull(Keys::toHex(Bech32::encode('nprofile', chr(0).chr(4).'abcd')));
        $this->assertNull(Keys::toHex(Bech32::encode('note', hex2bin($hex))));
        $this->assertFalse(Keys::isValidPrivateKey('xyz'));
        $this->assertFalse(Keys::isValidPrivateKey(str_repeat('0', 64)));
        $this->assertFalse(Keys::isValidPrivateKey(str_repeat('f', 64)));
    }

    public function testSignaturesAndEncryption()
    {
        $this->assertFailsWith('Invalid private key', function () {
            Schnorr::pubkey(str_repeat('0', 64));
        });
        $this->assertFailsWith('Invalid private key', function () {
            Schnorr::sign(str_repeat('a', 64), str_repeat('0', 64));
        });
        $this->assertFailsWith('Aux must be 32 bytes', function () {
            Schnorr::sign(str_repeat('a', 64), Keys::generatePrivateKey(), 'abcd');
        });
        $this->assertFailsWith('Invalid private key', function () {
            Schnorr::ecdhX(str_repeat('0', 64), Keys::pubkeyFromPrivate(Keys::generatePrivateKey()));
        });
        $this->assertFailsWith('Invalid public key', function () {
            Schnorr::ecdhX(Keys::generatePrivateKey(), str_repeat('f', 64));
        });
        $this->assertFailsWith('Invalid hex string', function () {
            Schnorr::pubkey('not hex');
        });
        $this->assertFalse(Schnorr::verify('short', str_repeat('a', 64), str_repeat('b', 128)));
        $this->assertFalse(Schnorr::verify(str_repeat('a', 64), str_repeat('f', 64), str_repeat('b', 128)));

        $key = random_bytes(32);
        $this->assertFailsWith('ChaCha20 needs a 32 byte key and a 12 byte nonce', function () use ($key) {
            ChaCha20::crypt($key, 'short', 'data');
        });
        $this->assertSame('', ChaCha20::crypt($key, random_bytes(12), ''));
        $this->assertFailsWith('Nonce must be 32 bytes', function () use ($key) {
            Nip44::encrypt('hello', $key, 'short');
        });
        $this->assertFailsWith('Invalid plaintext length', function () use ($key) {
            Nip44::encrypt('', $key);
        });
        $this->assertFailsWith('Conversation key must be 32 bytes', function () {
            Nip44::encrypt('hello', 'short');
        });
        $this->assertFailsWith('Invalid payload length', function () use ($key) {
            Nip44::decrypt(str_repeat('A', 4 * (int) ceil((Nip44::MAX_PLAINTEXT + 71) / 3) + 4), $key);
        });
    }

    public function testEventsThatAreNotValid()
    {
        $event = EventBuilder::finalize(['kind' => 1, 'content' => 'x', 'tags' => [['t', 'a'], ['t', 'b'], 'junk', [5]]], Keys::generatePrivateKey());
        $this->assertSame([['t', 'a'], ['t', 'b'], ['5']], $event['tags']);
        $this->assertSame([['t', 'a'], ['t', 'b']], EventBuilder::tags($event, 't'));

        $this->assertFalse(EventBuilder::verify('not an event'));
        $missing = $event;
        unset($missing['sig']);
        $this->assertFalse(EventBuilder::verify($missing));
        $this->assertFalse(EventBuilder::verify(['id' => 'xyz'] + $event));
        $this->assertFalse(EventBuilder::verify(['tags' => 'none'] + $event));

        $this->assertNull(EventBuilder::fromJson('{"content":"no kind"}'));
        $this->assertNull(EventBuilder::fromJson('not json'));
        $this->assertSame(['kind' => 1, 'tags' => [['p', 'x']]], EventBuilder::fromJson(['kind' => 1, 'tags' => (object) [['p', 'x']]]));
    }

    /**
     * A wrap around whatever "seal" JSON is given, for the recipient.
     */
    protected function wrapAround($seal_json, $recipient_pub)
    {
        $ephemeral = Keys::generatePrivateKey();

        return EventBuilder::finalize([
            'kind' => GiftWrap::KIND_WRAP,
            'tags' => [['p', $recipient_pub]],
            'content' => Nip44::encrypt($seal_json, Nip44::conversationKey($ephemeral, $recipient_pub)),
        ], $ephemeral);
    }

    protected function sealAround($rumor_json, $sender_priv, $recipient_pub)
    {
        return json_encode(EventBuilder::finalize([
            'kind' => GiftWrap::KIND_SEAL,
            'content' => Nip44::encrypt($rumor_json, Nip44::conversationKey($sender_priv, $recipient_pub)),
        ], $sender_priv));
    }

    public function testGiftWrapsThatAreNotValid()
    {
        $recipient = Keys::generatePrivateKey();
        $recipient_pub = Keys::pubkeyFromPrivate($recipient);
        $sender = Keys::generatePrivateKey();
        $sender_pub = Keys::pubkeyFromPrivate($sender);
        $unwrap = function ($wrap) use ($recipient, $recipient_pub) {
            return GiftWrap::unwrap($wrap, $recipient, $recipient_pub);
        };

        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => 'Hi'], $sender, $recipient_pub);
        $this->assertFailsWith('Not a gift wrap', function () use ($unwrap, $wrap) {
            $unwrap(['kind' => 1] + $wrap);
        });
        $this->assertFailsWith('Gift wrap is not addressed to this key', function () use ($wrap, $recipient) {
            GiftWrap::unwrap($wrap, $recipient, str_repeat('a', 64));
        });

        $cases = [
            'Invalid seal' => $this->wrapAround('not json', $recipient_pub),
            'Invalid seal signature' => $this->wrapAround(json_encode(['kind' => 13, 'content' => 'x', 'pubkey' => $sender_pub, 'id' => str_repeat('a', 64), 'sig' => str_repeat('b', 128), 'tags' => [], 'created_at' => 1]), $recipient_pub),
            'Invalid rumor' => $this->wrapAround($this->sealAround('{"kind":14}', $sender, $recipient_pub), $recipient_pub),
            'Rumor author does not match seal author' => $this->wrapAround($this->sealAround(json_encode(['kind' => 14, 'content' => 'Hi', 'pubkey' => str_repeat('c', 64)]), $sender, $recipient_pub), $recipient_pub),
            'Rumor must not be signed' => $this->wrapAround($this->sealAround(json_encode(['kind' => 14, 'content' => 'Hi', 'pubkey' => $sender_pub, 'sig' => str_repeat('d', 128)]), $sender, $recipient_pub), $recipient_pub),
        ];
        foreach ($cases as $message => $bad) {
            $this->assertFailsWith($message, function () use ($unwrap, $bad) {
                $unwrap($bad);
            });
        }

        // A rumor without an id gets one.
        $rumor = ['kind' => 14, 'content' => 'Hi', 'pubkey' => strtoupper($sender_pub), 'created_at' => 1700000000];
        $result = $unwrap($this->wrapAround($this->sealAround(json_encode($rumor), $sender, $recipient_pub), $recipient_pub));
        $this->assertSame(EventBuilder::id(['pubkey' => $sender_pub, 'tags' => []] + $rumor), $result['rumor']['id']);
        $this->assertSame($sender_pub, $result['rumor']['pubkey']);
    }
}
