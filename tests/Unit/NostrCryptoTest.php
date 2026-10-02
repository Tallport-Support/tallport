<?php

namespace Tests\Unit;

use App\Nostr\Crypto\ChaCha20;
use App\Nostr\Crypto\Nip44;
use App\Nostr\Crypto\Schnorr;
use App\Nostr\EventBuilder;
use App\Nostr\GiftWrap;
use App\Nostr\Keys;
use Tests\TestCase;

/**
 * Nostr cryptography against the official vectors: keys (NIP-19),
 * signatures (BIP-340), encryption (NIP-44) and gift wraps (NIP-59).
 */
class NostrCryptoTest extends TestCase
{
    protected function vectors()
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/nostr/nip44.json')), true)['v2'];
    }

    public function testKeys()
    {
        $hex = '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d';
        $this->assertSame('npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6', Keys::npub($hex));
        $this->assertSame($hex, Keys::toHex('npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6'));
        $this->assertSame('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5', Keys::nsec('67dea2ed018072d675f5415ecfaed7d2597555e202d85b3d65ea4e58d2d92ffa'));
        $this->assertSame('67dea2ed018072d675f5415ecfaed7d2597555e202d85b3d65ea4e58d2d92ffa', Keys::toHex('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5', 'priv'));
        $this->assertNull(Keys::toHex('nsec1vl029mgpspedva04g90vltkh6fvh240zqtv9k0t9af8935ke9laqsnlfe5'));
        $this->assertSame($hex, Keys::toHex('nprofile1qqsrhuxx8l9ex335q7he0f09aej04zpazpl0ne2cgukyawd24mayt8gpp4mhxue69uhhytnc9e3k7mgpz4mhxue69uhkg6nzv9ejuumpv34kytnrdaksjlyr9p'));
        $this->assertSame($hex, Keys::toHex(strtoupper($hex)));
        $this->assertNull(Keys::toHex(str_repeat('f', 64)));
        $this->assertNull(Keys::toHex('hello'));

        $private = Keys::generatePrivateKey();
        $this->assertTrue(Keys::isValidPrivateKey($private));
        $this->assertTrue(Keys::isValidPubkey(Keys::pubkeyFromPrivate($private)));
    }

    public function testBip340Signatures()
    {
        $rows = array_map('str_getcsv', file(base_path('tests/Fixtures/nostr/bip340.csv')));
        array_shift($rows);
        foreach ($rows as $row) {
            [$index, $sk, $pk, $aux, $msg, $sig, $expected] = $row;
            if ($sk !== '') {
                $this->assertSame(strtolower($pk), strtolower(Schnorr::pubkey($sk)), 'pubkey #'.$index);
                $this->assertSame(strtolower($sig), strtolower(Schnorr::sign($msg, $sk, $aux)), 'signature #'.$index);
            }
            $this->assertSame(strtoupper($expected) === 'TRUE', Schnorr::verify($msg, $pk, $sig), 'verify #'.$index);
        }
    }

    public function testEvents()
    {
        $event = EventBuilder::finalize(['kind' => 1, 'created_at' => 1700000000, 'tags' => [['t', 'test']], 'content' => "hello / world \"quoted\" \n ünïcode"], Keys::generatePrivateKey());
        $this->assertTrue(EventBuilder::verify($event));
        $tampered = $event;
        $tampered['content'] .= '!';
        $this->assertFalse(EventBuilder::verify($tampered));
        $this->assertStringContainsString('"hello / world \"quoted\" \n ünïcode"', EventBuilder::serialize($event));
    }

    public function testNip44()
    {
        $vectors = $this->vectors();
        foreach ($vectors['valid']['get_conversation_key'] as $case) {
            $this->assertSame($case['conversation_key'], bin2hex(Nip44::conversationKey($case['sec1'], $case['pub2'])));
        }
        foreach ([false, true] as $pure) {
            ChaCha20::forcePure($pure);
            foreach ($vectors['valid']['encrypt_decrypt'] as $case) {
                $key = hex2bin($case['conversation_key']);
                $this->assertSame($case['payload'], Nip44::encrypt($case['plaintext'], $key, hex2bin($case['nonce'])));
                $this->assertSame($case['plaintext'], Nip44::decrypt($case['payload'], $key));
            }
        }
        ChaCha20::forcePure(false);
        foreach ($vectors['valid']['calc_padded_len'] as $case) {
            $this->assertSame($case[1], Nip44::calcPaddedLen($case[0]));
        }
        $keys = $vectors['valid']['get_message_keys'];
        foreach ($keys['keys'] as $case) {
            [$chacha_key, $chacha_nonce, $hmac_key] = Nip44::messageKeys(hex2bin($keys['conversation_key']), hex2bin($case['nonce']));
            $this->assertSame([$case['chacha_key'], $case['chacha_nonce'], $case['hmac_key']], [bin2hex($chacha_key), bin2hex($chacha_nonce), bin2hex($hmac_key)]);
        }
        foreach ($vectors['valid']['encrypt_decrypt_long_msg'] as $case) {
            $key = hex2bin($case['conversation_key']);
            $plain = str_repeat($case['pattern'], $case['repeat']);
            $payload = Nip44::encrypt($plain, $key, hex2bin($case['nonce']));
            $this->assertSame($case['payload_sha256'], hash('sha256', $payload));
            $this->assertSame($plain, Nip44::decrypt($payload, $key));
        }
        foreach ($vectors['invalid']['decrypt'] ?? [] as $case) {
            try {
                Nip44::decrypt($case['payload'], hex2bin($case['conversation_key']));
                $this->fail('Accepted an invalid payload: '.$case['note']);
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            }
        }
    }

    public function testChaCha20()
    {
        $vector = ChaCha20::cryptPure(hex2bin('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'), hex2bin('000000000000004a00000000'), 'Ladies and Gentlemen of the class of \'99: If I could offer you only one tip for the future, sunscreen would be it.', 1);
        $this->assertSame('6e2e359a2568f98041ba0728dd0d6981', bin2hex(substr($vector, 0, 16)));
        if (ChaCha20::opensslAvailable()) {
            $key = random_bytes(32);
            $nonce = random_bytes(12);
            $data = random_bytes(1000);
            $this->assertSame(ChaCha20::crypt($key, $nonce, $data, 7), ChaCha20::cryptPure($key, $nonce, $data, 7));
        }
    }

    public function testExtendedLengthsAndLargeGiftWraps()
    {
        $key = str_repeat("\x01", 32);
        foreach ([65535, 65536, 100000, 1024 * 1024] as $length) {
            $text = str_repeat('x', $length);
            $this->assertSame($text, Nip44::decrypt(Nip44::encrypt($text, $key), $key));
        }
        $sender = str_pad('1', 64, '0', STR_PAD_LEFT);
        $recipient = str_pad('2', 64, '0', STR_PAD_LEFT);
        $pubkey = Keys::pubkeyFromPrivate($recipient);
        $conversation = Nip44::conversationKey($sender, $pubkey);
        // The same vectors are checked by the Rust implementation in VPX.
        foreach ([
            65535  => '850f88b3b96b6502ad0a6739e73dd6b69da1e9532a4e98395330ca62cf9031c2',
            65536  => 'c5c0b735bc65602b0b1acb208e4ffa1eff74013d68269d26427727b8853684cb',
            100000 => 'abc580729fd50ea973b7bbbc9494416fdc1dcac7357a4fad75cd456287a97464',
        ] as $length => $hash) {
            $this->assertSame($hash, hash('sha256', Nip44::encrypt(str_repeat('x', $length), $conversation, str_repeat(chr(3), 32))));
        }
        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => str_repeat('x', 100000), 'tags' => [['p', $pubkey]]], $sender, $pubkey);
        $this->assertSame(str_repeat('x', 100000), GiftWrap::unwrap($wrap, $recipient, $pubkey)['rumor']['content']);
    }

    public function testGiftWraps()
    {
        $alice = Keys::generatePrivateKey();
        $alice_pub = Keys::pubkeyFromPrivate($alice);
        $bob = Keys::generatePrivateKey();
        $bob_pub = Keys::pubkeyFromPrivate($bob);
        [$wrap, $rumor] = GiftWrap::wrap(['kind' => 14, 'content' => 'Hi Bob 🙂', 'tags' => [['p', $bob_pub], ['subject', 'VPN']]], $alice, $bob_pub);

        $this->assertTrue(EventBuilder::verify($wrap));
        $this->assertSame(1059, $wrap['kind']);
        $this->assertNotSame($alice_pub, $wrap['pubkey']);
        $out = GiftWrap::unwrap($wrap, $bob, $bob_pub);
        $this->assertSame('Hi Bob 🙂', $out['rumor']['content']);
        $this->assertSame($alice_pub, $out['rumor']['pubkey']);
        $this->assertSame($rumor['id'], $out['rumor']['id']);
        $this->assertSame('VPN', EventBuilder::firstTag($out['rumor'], 'subject'));

        $this->expectException(\Throwable::class);
        GiftWrap::unwrap($wrap, $alice, $alice_pub);
    }
}
