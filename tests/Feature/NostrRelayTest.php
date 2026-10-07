<?php

namespace Tests\Feature;

use App\Conversation;
use App\Nostr\EventBuilder;
use App\Nostr\GiftWrap;
use App\Nostr\IncomingMessageHandler;
use App\Nostr\Keys;
use App\Nostr\Listener;
use App\Nostr\ListenerStatus;
use App\Nostr\NostrEvent;
use App\Nostr\NostrMailbox;
use App\Nostr\RelayClient;
use App\Nostr\Websocket\Client;
use Tests\FeatureTestCase;

/**
 * Talking to relays: the websocket client, the synchronous relay client
 * (publish, fetch, NIP-42 AUTH) and the long-running listener, against
 * scripted relays on 127.0.0.1 that run in forked child processes.
 *
 * A relay script gets the listening socket and a channel back to the test:
 * what it writes there (one JSON value per line) is what the test reads
 * with relayReport().
 */
class NostrRelayTest extends FeatureTestCase
{
    protected $relay_pids = [];
    protected $reports = [];
    protected $storage_path;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('The scripted relays need pcntl.');
        }
    }

    protected function tearDown(): void
    {
        // Listener::run() handles these signals; the test process must not keep that.
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        foreach ($this->relay_pids as $pid) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        if ($this->storage_path) {
            @unlink($this->storage_path.'/app/nostr-listen.lock');
            @rmdir($this->storage_path.'/app');
            @rmdir($this->storage_path);
        }

        parent::tearDown();
    }

    // The scripted relay (runs in the child process).

    /**
     * Run $script(server socket, report channel) in a child process.
     *
     * @return string the relay's ws:// address
     */
    protected function startRelay(callable $script, $path = '')
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $address = stream_socket_get_name($server, false);
        [$parent_end, $child_end] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGTERM, SIG_DFL);
            fclose($parent_end);
            try {
                $script($server, $child_end);
            } catch (\Throwable $e) {
                fwrite($child_end, json_encode(['error' => $e->getMessage()])."\n");
            }
            // No shutdown functions or destructors of the test process.
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($server);
        fclose($child_end);
        $this->relay_pids[] = $pid;
        $this->reports[] = $parent_end;

        return 'ws://'.$address.$path;
    }

    /**
     * Next line the relay reported, decoded.
     */
    protected function relayReport($relay = 0)
    {
        $channel = $this->reports[$relay];
        stream_set_timeout($channel, 15);
        $line = fgets($channel);

        return $line === false ? null : json_decode($line, true);
    }

    /**
     * Tell the test something; binary strings (close codes) arrive as "hex:...".
     */
    protected function report($channel, $data)
    {
        if (is_array($data)) {
            array_walk_recursive($data, function (&$value) {
                if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                    $value = 'hex:'.bin2hex($value);
                }
            });
        }
        fwrite($channel, json_encode($data)."\n");
    }

    /**
     * Accept a connection and answer its upgrade request.
     *
     * @return array [connection, request headers]
     */
    protected function acceptClient($server, $response = null, $after_headers = '')
    {
        $conn = stream_socket_accept($server, 15);
        stream_set_timeout($conn, 15);
        $request = '';
        while (strpos($request, "\r\n\r\n") === false && !feof($conn)) {
            $request .= fread($conn, 4096);
        }
        preg_match('/Sec-WebSocket-Key: (\S+)/i', $request, $m);
        $accept = base64_encode(sha1(($m[1] ?? '').Client::GUID, true));
        fwrite($conn, ($response ?? "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n").$after_headers);

        return [$conn, $request];
    }

    /**
     * A frame from the relay (unmasked unless $mask is given).
     */
    protected function frame($payload, $opcode = 0x1, $fin = true, $mask = null)
    {
        $len = strlen($payload);
        $frame = chr(($fin ? 0x80 : 0) | $opcode);
        $mask_bit = $mask === null ? 0 : 0x80;
        if ($len < 126) {
            $frame .= chr($mask_bit | $len);
        } elseif ($len < 65536) {
            $frame .= chr($mask_bit | 126).pack('n', $len);
        } else {
            $frame .= chr($mask_bit | 127).pack('J', $len);
        }
        if ($mask !== null) {
            return $frame.$mask.($payload ^ str_pad('', $len, $mask));
        }

        return $frame.$payload;
    }

    protected function sendJson($conn, array $data)
    {
        fwrite($conn, $this->frame(json_encode($data, JSON_UNESCAPED_SLASHES)));
    }

    protected function readExactly($conn, $length)
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($conn, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($conn))) {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * Next frame from the client.
     *
     * @return array|null [opcode, payload], null when the connection ended
     */
    protected function readFrame($conn)
    {
        $head = $this->readExactly($conn, 2);
        if ($head === null) {
            return null;
        }
        $len = ord($head[1]) & 0x7f;
        if ($len === 126) {
            $len = unpack('n', $this->readExactly($conn, 2))[1];
        } elseif ($len === 127) {
            $len = unpack('J', $this->readExactly($conn, 8))[1];
        }
        $mask = (ord($head[1]) & 0x80) ? $this->readExactly($conn, 4) : null;
        $payload = $len ? $this->readExactly($conn, $len) : '';
        if ($mask !== null && $len) {
            $payload ^= str_pad('', $len, $mask);
        }

        return [ord($head[0]) & 0x0f, $payload];
    }

    /**
     * Next text message from the client, decoded (pings and pongs are skipped).
     */
    protected function readJson($conn)
    {
        while ($frame = $this->readFrame($conn)) {
            if ($frame[0] === 0x1) {
                return json_decode($frame[1], true);
            }
            if ($frame[0] === 0x8) {
                return null;
            }
        }

        return null;
    }

    /**
     * Wait until the client goes away.
     */
    protected function drain($conn)
    {
        do {
            $frame = $this->readFrame($conn);
        } while ($frame);
    }

    // Test data.

    protected function mailboxConfig(array $relays)
    {
        $mailbox = $this->createMailbox();
        $cfg = NostrMailbox::forMailbox($mailbox->id);
        $cfg->setPrivateKey(Keys::generatePrivateKey());
        $cfg->setInboxRelays($relays);
        $cfg->setAnnounceRelays(['ws://127.0.0.1:1']);
        $cfg->enabled = true;
        $cfg->save();

        return $cfg;
    }

    protected function signedNote($content, $created_at = null)
    {
        return EventBuilder::finalize(['kind' => 1, 'content' => $content, 'created_at' => $created_at ?? time()], Keys::generatePrivateKey());
    }

    /**
     * A listener whose protected parts the test can drive, which never stops
     * other processes on this machine.
     */
    protected function listener(IncomingMessageHandler $handler, array &$log, $lifetime = 60)
    {
        $this->storage_path = sys_get_temp_dir().'/tallport-listener-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->storage_path.'/app', 0777, true);
        $this->app->useStoragePath($this->storage_path);

        $listener = null;
        $logger = function ($message) use (&$log, &$listener) {
            $log[] = $message;
            if (isset($listener->stop_when) && str_contains($message, $listener->stop_when)) {
                $listener->stop('test');
            }
        };
        $listener = new class($handler, $lifetime, $logger) extends Listener {
            public $stop_when = null;

            public function call($method, ...$arguments)
            {
                return $this->$method(...$arguments);
            }

            public function get($property)
            {
                return $this->$property;
            }

            public function set($property, $value)
            {
                $this->$property = $value;
            }

            protected function stopOtherListeners()
            {
                // Never signal processes outside the test.
            }
        };

        return $listener;
    }

    /**
     * Drive the listener's connections until $done returns true (or 10 seconds pass).
     */
    protected function serviceUntil($listener, callable $done)
    {
        $deadline = microtime(true) + 10;
        while (!$done() && microtime(true) < $deadline) {
            $listener->call('connectDue');
            foreach (array_keys($listener->get('connections')) as $key) {
                $listener->call('service', $key);
            }
            usleep(20000);
        }
        $this->assertTrue((bool) $done(), 'The listener did not get there in time.');
    }

    // The websocket client.

    public function testWebsocketClientReadsFramesAndAnswersTheRelay()
    {
        $url = $this->startRelay(function ($server, $channel) {
            $big = str_repeat('b', 70000);
            // A message right behind the headers, then every kind of frame.
            [$conn, $request] = $this->acceptClient($server, null, $this->frame('first'));
            $this->report($channel, strtok($request, "\r\n").' | '.(preg_match('/^Host: (.*)$/mi', $request, $m) ? trim($m[1]) : ''));
            fwrite($conn, $this->frame(str_repeat('m', 300)));
            fwrite($conn, $this->frame($big));
            fwrite($conn, $this->frame('frag', 0x1, false).$this->frame('ment', 0x0, true));
            fwrite($conn, $this->frame('binary', 0x2).$this->frame('bin', 0x2, false).$this->frame('ary', 0x0, true));
            fwrite($conn, $this->frame('masked', 0x1, true, 'abcd'));
            fwrite($conn, $this->frame('', 0xA));
            fwrite($conn, $this->frame('are you there', 0x9));
            $this->report($channel, $this->readFrame($conn));
            $this->report($channel, $this->readFrame($conn));
            $large = $this->readFrame($conn);
            $this->report($channel, [$large[0], strlen($large[1])]);
            // A frame that arrives in pieces.
            $frame = $this->frame(str_repeat('p', 200), 0x1, true, 'wxyz');
            foreach ([1, 1, 1, 1, 2, 50] as $length) {
                fwrite($conn, substr($frame, 0, $length));
                $frame = substr($frame, $length);
                usleep(30000);
            }
            fwrite($conn, $frame);
            $this->readFrame($conn);
            fwrite($conn, $this->frame(pack('n', 1001).'going away', 0x8));
            $this->report($channel, $this->readFrame($conn));
        }, '/inbox?x=1');

        $client = new Client($url);
        $this->assertSame($url, $client->getUrl());
        $this->assertTrue($client->open(5));
        $this->assertSame(Client::STATE_OPEN, $client->getState());
        $this->assertNotNull($client->getStream());

        $this->assertSame('GET /inbox?x=1 HTTP/1.1 | '.parse_url($url, PHP_URL_HOST).':'.parse_url($url, PHP_URL_PORT), $this->relayReport());
        $this->assertSame('first', $client->receive(5));
        $this->assertSame(str_repeat('m', 300), $client->receive(5));
        $this->assertSame(70000, strlen($client->receive(5)));
        $this->assertSame('fragment', $client->receive(5));
        $this->assertSame('masked', $client->receive(5));
        // The relay's ping is answered with a pong carrying the same data.
        $this->assertSame([0xA, 'are you there'], $this->relayReport());
        $client->send('hello');
        $this->assertSame([0x1, 'hello'], $this->relayReport());
        $client->send(str_repeat('s', 70000));
        $this->assertSame([0x1, 70000], $this->relayReport());
        $this->assertSame(str_repeat('p', 200), $client->receive(5));
        $client->send('next');

        // The relay closes: the close is answered, the reason kept.
        try {
            $client->receive(5);
            $this->fail('receive() on a closed connection must throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('closed by relay 1001 going away', $e->getMessage());
        }
        $this->assertSame([0x8, 'hex:'.bin2hex(pack('n', 1001))], $this->relayReport());
        $this->assertTrue($client->isClosed());
        $this->assertSame('closed by relay 1001 going away', $client->getError());
    }

    public function testWebsocketClientClosesAndPings()
    {
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $this->report($channel, $this->readFrame($conn));
            $this->report($channel, $this->readFrame($conn));
            $this->report($channel, $this->readFrame($conn));
        });

        $client = new Client($url);
        $this->assertTrue($client->open(5));
        $client->ping();
        $this->assertSame([0x9, ''], $this->relayReport());
        $client->close(4000);
        $this->assertSame([0x8, 'hex:'.bin2hex(pack('n', 4000))], $this->relayReport());
        $this->assertNull($this->relayReport());
        $this->assertTrue($client->isClosed());
        $this->assertNull($client->getError());
        $this->expectExceptionMessage('connection closed');
        $client->receive(1);
    }

    public function testWebsocketClientNoticesARelayThatHangsUp()
    {
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            fclose($conn);
        });

        $client = new Client($url);
        $this->assertTrue($client->open(5));
        try {
            $client->receive(5);
            $this->fail('receive() must throw once the relay has gone.');
        } catch (\RuntimeException $e) {
            $this->assertSame('connection closed by relay', $e->getMessage());
        }

        // Sending on a closed connection fails with the reason.
        $this->expectExceptionMessage('connection closed by relay');
        $client->send('x');
    }

    /**
     * A relay that doesn't complete the upgrade.
     *
     * @dataProvider failedHandshakes
     */
    public function testWebsocketClientRejectsFailedHandshakes($response, $error)
    {
        $url = $this->startRelay(function ($server, $channel) use ($response) {
            if ($response === 'hang up') {
                $conn = stream_socket_accept($server, 15);
                fread($conn, 4096);
                fclose($conn);
            } elseif ($response === 'silence') {
                $conn = stream_socket_accept($server, 15);
                sleep(10);
            } else {
                [$conn] = $this->acceptClient($server, $response);
            }
            $this->drain($conn);
        });

        $client = new Client($url);
        $this->assertFalse($client->open(1));
        $this->assertTrue($client->isClosed());
        $this->assertSame($error, $client->getError());
    }

    public static function failedHandshakes()
    {
        return [
            'not an upgrade' => ["HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n", 'handshake rejected: HTTP/1.1 403 Forbidden'],
            'wrong accept key' => ["HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: bm9wZQ==\r\n\r\n", 'handshake rejected: bad accept key'],
            'hung up' => ['hang up', 'connection closed during handshake'],
            'no answer' => ['silence', 'handshake timed out'],
        ];
    }

    public function testWebsocketClientRefusesTooLargeMessages()
    {
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $this->readFrame($conn);
            fwrite($conn, chr(0x81).chr(127).pack('J', Client::MAX_MESSAGE + 1));
            $this->drain($conn);
        });

        $client = new Client($url);
        $this->assertTrue($client->open(5));
        $client->send('go');
        $this->expectExceptionMessage('message too large');
        $client->receive(5);
    }

    public function testWebsocketClientConnectionFailures()
    {
        // Nothing listens on port 1.
        $client = new Client('ws://127.0.0.1:1');
        $this->assertFalse($client->open(2));
        $this->assertMatchesRegularExpression('/^(connection refused|could not connect: .+)$/', $client->getError());

        // TLS with a server that doesn't speak it.
        $url = $this->startRelay(function ($server, $channel) {
            $conn = stream_socket_accept($server, 15);
            fread($conn, 4096);
            fwrite($conn, "HTTP/1.1 400 Bad Request\r\n\r\n");
            fclose($conn);
        });
        $client = new Client(str_replace('ws://', 'wss://', $url));
        $this->assertFalse($client->open(5));
        $this->assertStringStartsWith('TLS handshake failed', $client->getError());

        // Not a websocket address.
        $this->expectException(\InvalidArgumentException::class);
        new Client('https://relay.example.org');
    }

    // Publishing and fetching.

    public function testPublishReportsWhatEachRelaySaid()
    {
        $accepting = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $message = $this->readJson($conn);
            $this->report($channel, $message[0]);
            $this->sendJson($conn, ['NOTICE', 'welcome']);
            $this->sendJson($conn, ['OK', 'another-event', false, 'not this one']);
            $this->sendJson($conn, ['OK', $message[1]['id'], true, '']);
            $this->drain($conn);
        });
        $refusing = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $message = $this->readJson($conn);
            $this->sendJson($conn, ['OK', $message[1]['id'], false, 'blocked: spam']);
            $this->drain($conn);
        });
        $silent = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $this->drain($conn);
        });
        $log = [];
        $client = new RelayClient(null, function ($message) use (&$log) {
            $log[] = $message;
        });
        $event = $this->signedNote('Hello');

        $results = $client->publish($event, [$accepting, $refusing, $silent, $accepting, 'ws://127.0.0.1:1'], 2);

        $this->assertSame('EVENT', $this->relayReport(0));
        $this->assertSame(['ok' => true, 'message' => ''], $results[$accepting]);
        $this->assertSame(['ok' => false, 'message' => 'blocked: spam'], $results[$refusing]);
        $this->assertSame(['ok' => false, 'message' => 'timeout'], $results[$silent]);
        $this->assertFalse($results['ws://127.0.0.1:1']['ok']);
        $this->assertCount(4, $results);
        $this->assertTrue(RelayClient::anySucceeded($results));
        $this->assertFalse(RelayClient::anySucceeded([$results[$refusing]]));
        $this->assertContains('notice from '.$accepting.': welcome', $log);
        $this->assertContains(sprintf('publish %s to %s: failed (blocked: spam)', substr($event['id'], 0, 8), $refusing), $log);
    }

    public function testPublishStaysWithinTheMessageSizeLimit()
    {
        \Option::set('nostr.max_message_bytes', 200);
        $client = new RelayClient();

        $results = $client->publish($this->signedNote(str_repeat('x', 300)), ['ws://127.0.0.1:1']);

        $this->assertSame(['ws://127.0.0.1:1' => ['ok' => false, 'message' => 'Encrypted message exceeds the relay limit of 200 bytes']], $results);
    }

    public function testPublishAuthenticatesWhenTheRelayAsks()
    {
        $private = Keys::generatePrivateKey();
        $relay = function ($server, $channel) use ($private) {
            $check_auth = function ($conn, $challenge, $accept = true) use ($channel, $private) {
                $auth = $this->readJson($conn);
                $valid = $auth[0] === 'AUTH' && EventBuilder::verify($auth[1]) && $auth[1]['kind'] === 22242
                    && EventBuilder::firstTag($auth[1], 'challenge') === $challenge && $auth[1]['pubkey'] === Keys::pubkeyFromPrivate($private);
                $this->report($channel, ['auth', $valid]);
                $this->sendJson($conn, ['OK', $auth[1]['id'], $accept && $valid, $accept ? '' : 'restricted: no']);
            };

            // First publish: refused until authenticated.
            [$conn] = $this->acceptClient($server);
            $this->sendJson($conn, ['AUTH', 'challenge-1']);
            $event = $this->readJson($conn);
            $this->sendJson($conn, ['OK', $event[1]['id'], false, 'auth-required: we only accept DMs from authenticated users']);
            $check_auth($conn, 'challenge-1');
            $event = $this->readJson($conn);
            $this->report($channel, $event[0]);
            $this->sendJson($conn, ['OK', $event[1]['id'], true, '']);
            $this->drain($conn);

            // Second publish: the client remembers and waits for the challenge first.
            [$conn] = $this->acceptClient($server);
            $this->sendJson($conn, ['NOTICE', 'hello again']);
            $this->sendJson($conn, ['AUTH', 'challenge-2']);
            $check_auth($conn, 'challenge-2');
            $event = $this->readJson($conn);
            $this->sendJson($conn, ['OK', $event[1]['id'], true, '']);
            $this->drain($conn);

            // Third: authentication is refused.
            [$conn] = $this->acceptClient($server);
            $this->sendJson($conn, ['AUTH', 'challenge-3']);
            $check_auth($conn, 'challenge-3', false);
            $this->drain($conn);
        };
        $url = $this->startRelay($relay);
        $log = [];
        $client = new RelayClient(RelayClient::authSignerForKey($private), function ($message) use (&$log) {
            $log[] = $message;
        });

        $this->assertSame(['ok' => true, 'message' => ''], $client->publish($this->signedNote('One'), [$url], 5)[$url]);
        $this->assertSame(['auth', true], $this->relayReport());
        $this->assertSame('EVENT', $this->relayReport());
        $this->assertTrue(\Cache::get('nostr.relay_auth.'.hash('sha256', $url)));

        $this->assertSame(['ok' => true, 'message' => ''], $client->publish($this->signedNote('Two'), [$url], 5)[$url]);
        $this->assertSame(['auth', true], $this->relayReport());
        $this->assertContains('notice from '.$url.': hello again', $log);

        $this->assertSame(['ok' => false, 'message' => 'relay authentication failed'], $client->publish($this->signedNote('Three'), [$url], 5)[$url]);
        $this->assertSame(['auth', true], $this->relayReport());
    }

    public function testARememberedAuthDoesNotStallARelayThatStoppedAsking()
    {
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $event = $this->readJson($conn);
            $this->sendJson($conn, ['OK', $event[1]['id'], true, '']);
            $this->drain($conn);
        });
        $key = 'nostr.relay_auth.'.hash('sha256', $url);
        \Cache::put($key, true, 60);
        $client = new RelayClient(RelayClient::authSignerForKey(Keys::generatePrivateKey()));

        $started = microtime(true);
        $this->assertTrue($client->publish($this->signedNote('Hi'), [$url], 5)[$url]['ok']);

        $this->assertLessThan(3, microtime(true) - $started);
        $this->assertNull(\Cache::get($key));
    }

    public function testFetchMergesRelaysNewestFirst()
    {
        $old = $this->signedNote('old', time() - 100);
        $new = $this->signedNote('new', time() - 10);
        $other = $this->signedNote('other', time() - 50);
        $forged = $this->signedNote('forged', time());
        $forged['content'] = 'changed';
        $a = $this->startRelay(function ($server, $channel) use ($old, $new, $forged) {
            [$conn] = $this->acceptClient($server);
            $req = $this->readJson($conn);
            $this->report($channel, $req);
            $this->sendJson($conn, ['EVENT', 'someone-else', $old]);
            $this->sendJson($conn, ['NOTICE', 'slow down']);
            foreach ([$old, $new, $forged, ['no' => 'id']] as $event) {
                $this->sendJson($conn, ['EVENT', $req[1], $event]);
            }
            $this->sendJson($conn, ['EOSE', $req[1]]);
            $this->report($channel, $this->readJson($conn));
            $this->drain($conn);
        });
        $b = $this->startRelay(function ($server, $channel) use ($new, $other) {
            [$conn] = $this->acceptClient($server);
            $req = $this->readJson($conn);
            $this->sendJson($conn, ['EVENT', $req[1], $new]);
            $this->sendJson($conn, ['EVENT', $req[1], $other]);
            $this->sendJson($conn, ['CLOSED', $req[1], 'error: shutting down']);
            $this->drain($conn);
        });
        $log = [];
        $client = new RelayClient(null, function ($message) use (&$log) {
            $log[] = $message;
        });
        $filter = ['kinds' => [1], 'limit' => 5];

        $events = $client->fetch([$filter], [$a, $b, 'ws://127.0.0.1:1'], 3);

        $this->assertSame([$new['id'], $other['id'], $old['id']], array_column($events, 'id'));
        $req = $this->relayReport(0);
        $this->assertSame('REQ', $req[0]);
        $this->assertSame($filter, $req[2]);
        $this->assertSame(['CLOSE', $req[1]], $this->relayReport(0));
        $this->assertContains('notice from '.$a.': slow down', $log);
        $this->assertContains('subscription closed by '.$b.': error: shutting down', $log);
        $this->assertMatchesRegularExpression('/^fetch from ws:\/\/127\.0\.0\.1:1 failed: /', implode("\n", array_filter($log, function ($line) {
            return str_starts_with($line, 'fetch from');
        })));
    }

    public function testFetchCanKeepUnverifiedEventsAndAuthenticates()
    {
        $private = Keys::generatePrivateKey();
        $forged = $this->signedNote('forged');
        $forged['content'] = 'changed';
        $url = $this->startRelay(function ($server, $channel) use ($forged) {
            [$conn] = $this->acceptClient($server);
            $req = $this->readJson($conn);
            $this->sendJson($conn, ['AUTH', 'fetch-challenge']);
            $this->sendJson($conn, ['CLOSED', $req[1], 'auth-required: show who you are']);
            $auth = $this->readJson($conn);
            $this->report($channel, [$auth[0], EventBuilder::firstTag($auth[1], 'challenge')]);
            $this->sendJson($conn, ['OK', $auth[1]['id'], true, '']);
            $req = $this->readJson($conn);
            $this->sendJson($conn, ['EVENT', $req[1], $forged]);
            $this->sendJson($conn, ['EOSE', $req[1]]);
            $this->drain($conn);
        });
        $client = new RelayClient(RelayClient::authSignerForKey($private));

        $events = $client->fetch([['kinds' => [1]]], [$url], 5, false);

        $this->assertSame(['AUTH', 'fetch-challenge'], $this->relayReport());
        $this->assertSame([$forged['id']], array_column($events, 'id'));
    }

    // The listener.

    public function testListenerReceivesMessagesAuthenticatesAndStops()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $customer_private = Keys::generatePrivateKey();
        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => 'Help from the listener', 'tags' => [['p', $cfg->pubkey]]], $customer_private, $cfg->pubkey);
        [$ignored] = GiftWrap::wrap(['kind' => 14, 'content' => 'Old subscription', 'tags' => [['p', $cfg->pubkey]]], $customer_private, $cfg->pubkey);
        $legacy = EventBuilder::finalize(['kind' => 4, 'content' => 'b64?iv=x', 'tags' => [['p', $cfg->pubkey]]], $customer_private);
        $url = $this->startRelay(function ($server, $channel) use ($wrap, $ignored, $legacy) {
            [$conn] = $this->acceptClient($server);
            $first = $this->readJson($conn);
            $this->report($channel, $first);
            $this->sendJson($conn, ['AUTH', 'listen-challenge']);
            $auth = $this->readJson($conn);
            $this->report($channel, [$auth[0], EventBuilder::verify($auth[1]), EventBuilder::firstTag($auth[1], 'challenge'), EventBuilder::firstTag($auth[1], 'relay')]);
            $this->sendJson($conn, ['OK', $auth[1]['id'], true, '']);
            $close = $this->readJson($conn);
            $second = $this->readJson($conn);
            $this->report($channel, [$close, $second[0], $second[1] !== $first[1]]);
            $this->sendJson($conn, ['EVENT', $first[1], $ignored]);
            $this->sendJson($conn, ['EVENT', $second[1], $wrap]);
            $this->sendJson($conn, ['EVENT', $second[1], $legacy]);
            $this->sendJson($conn, ['EVENT', $second[1], ['kind' => 1]]);
            $this->sendJson($conn, ['EOSE', $second[1]]);
            $this->sendJson($conn, ['NOTICE', 'done']);
            $this->drain($conn);
        });
        $cfg->setInboxRelays([$url]);
        $cfg->save();
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        $listener->stop_when = 'notice from '.$url.': done';
        \Bus::fake([\App\Jobs\NostrTask::class]);

        $this->assertTrue($listener->run());

        $req = $this->relayReport();
        $this->assertSame('REQ', $req[0]);
        $this->assertSame([GiftWrap::KIND_WRAP, IncomingMessageHandler::KIND_LEGACY_DM], $req[2]['kinds']);
        $this->assertSame([$cfg->pubkey], $req[2]['#p']);
        $this->assertEqualsWithDelta(time() - 3 * 86400, $req[2]['since'], 30);
        $this->assertSame(['AUTH', true, 'listen-challenge', $url], $this->relayReport());
        $this->assertSame([['CLOSE', $req[1]], 'REQ', true], $this->relayReport());

        $conversations = Conversation::where('mailbox_id', $cfg->mailbox_id)->get();
        $this->assertCount(1, $conversations);
        $this->assertStringContainsString('Help from the listener', $conversations[0]->threads()->first()->body);
        $this->assertTrue(NostrEvent::seenWrap($legacy['id']));
        $this->assertFalse(NostrEvent::seenWrap($ignored['id']));
        \Bus::assertDispatched(\App\Jobs\NostrTask::class);

        $status = ListenerStatus::read();
        $this->assertSame(getmypid(), $status['pid']);
        $this->assertSame('test', $status['stop_reason']);
        $this->assertSame(60, $status['lifetime']);
        $this->assertSame($status['started_at'] + 60, $status['ends_at']);
        $connection = $status['connections'][0];
        $expected = ['mailbox_id' => $cfg->mailbox_id, 'url' => $url, 'state' => 'connected', 'caught_up' => true, 'authed' => true, 'events' => 1, 'legacy' => 1, 'error' => null];
        $this->assertSame($expected, array_intersect_key($connection, $expected));
        $this->assertSame('listener started (lifetime 60s)', $log[0]);
        $this->assertContains('authenticated with '.$url, $log);
        $this->assertContains('caught up with '.$url, $log);
        $this->assertSame('listener stopped', end($log));

        // One listener at a time: the lock names this process.
        $this->assertSame((string) getmypid(), file_get_contents($this->storage_path.'/app/nostr-listen.lock'));
    }

    public function testListenerHandlesAuthRequestsLostConnectionsAndSilence()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $req = $this->readJson($conn);
            // Before any challenge there is nothing to answer with.
            $this->sendJson($conn, ['CLOSED', $req[1], 'auth-required: first']);
            $this->sendJson($conn, ['AUTH', 'c1']);
            $auth = $this->readJson($conn);
            $this->sendJson($conn, ['OK', $auth[1]['id'], false, 'restricted: not you']);
            $this->sendJson($conn, ['CLOSED', $req[1], 'auth-required: try again']);
            $auth = $this->readJson($conn);
            $this->report($channel, [$auth[0], EventBuilder::firstTag($auth[1], 'challenge')]);
            $this->sendJson($conn, ['OK', $auth[1]['id'], true, '']);
            $this->readJson($conn);
            $req = $this->readJson($conn);
            // Once authenticated, a CLOSED for auth subscribes again.
            $this->sendJson($conn, ['CLOSED', $req[1], 'auth-required: still']);
            $close = $this->readJson($conn);
            $again = $this->readJson($conn);
            $this->report($channel, [$close[0], $again[0]]);
            $this->sendJson($conn, ['CLOSED', 'other-sub', 'auth-required: not yours']);
            fwrite($conn, $this->frame('not json'));
            fclose($conn);

            // The reconnect.
            [$conn] = $this->acceptClient($server);
            $this->report($channel, $this->readJson($conn)[0]);
            $this->drain($conn);
        });
        $cfg->setInboxRelays([$url]);
        $cfg->save();
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        $listener->call('reload');
        $key = $cfg->id.'|'.$url;
        $state = function ($field) use ($listener, $key) {
            return $listener->get('connections')[$key][$field];
        };

        $this->serviceUntil($listener, function () use ($state) {
            return $state('error') !== null;
        });

        $this->assertSame(['AUTH', 'c1'], $this->relayReport());
        $this->assertSame(['CLOSE', 'REQ'], $this->relayReport());
        $this->assertContains('authentication rejected by '.$url.': restricted: not you', $log);
        $this->assertContains('subscription closed by '.$url.': auth-required: still', $log);
        $this->assertNotContains('subscription closed by '.$url.': auth-required: not yours', $log);
        $this->assertSame('connection closed by relay', $state('error'));
        $this->assertSame(Listener::MIN_BACKOFF, $state('backoff'));
        $this->assertContains('reconnecting to '.$url.' in 5s', $log);
        $connection = ListenerStatus::read()['connections'][0];
        $this->assertSame('reconnecting', $connection['state']);
        $this->assertEqualsWithDelta(5, $connection['retry_in'], 1);

        // Each failure doubles the wait, up to the maximum.
        $listener->call('scheduleReconnect', $key);
        $this->assertSame(10, $state('backoff'));
        $connections = $listener->get('connections');
        $connections[$key]['backoff'] = 200;
        $listener->set('connections', $connections);
        $listener->call('scheduleReconnect', $key);
        $this->assertSame(Listener::MAX_BACKOFF, $state('backoff'));

        // When the time comes it connects again, and the wait starts over.
        $connections = $listener->get('connections');
        $connections[$key]['retry_at'] = time();
        $listener->set('connections', $connections);
        $this->serviceUntil($listener, function () use ($state) {
            return $state('opened');
        });
        $this->assertSame('REQ', $this->relayReport());
        $this->assertSame(0, $state('backoff'));
        $this->assertNull($state('error'));

        // A relay that sends nothing for too long is reconnected.
        $client = $state('client');
        (new \ReflectionProperty($client, 'lastActivity'))->setValue($client, time() - Listener::IDLE_TIMEOUT - 1);
        $listener->call('service', $key);
        $this->assertNull($state('client'));
        $this->assertSame('no data for 120s', $state('error'));
        $this->assertTrue($client->isClosed());
    }

    public function testListenerTickPingsFollowsSettingsAndEndsItsLifetime()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $url = $this->startRelay(function ($server, $channel) {
            [$conn] = $this->acceptClient($server);
            $this->readJson($conn);
            $this->report($channel, $this->readFrame($conn));
            do {
                $frame = $this->readFrame($conn);
            } while ($frame && $frame[0] === 0x9);
            $this->report($channel, $frame);
        });
        $cfg->inbox_relays = json_encode([$url, 'https://not-a-relay.example.org']);
        $cfg->save();
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        $listener->set('startedAt', time());
        $listener->call('reload');
        $key = $cfg->id.'|'.$url;
        $this->serviceUntil($listener, function () use ($listener, $key) {
            return $listener->get('connections')[$key]['opened'];
        });

        // A relay address that isn't a websocket waits for the longest backoff.
        $bad = $listener->get('connections')[$cfg->id.'|https://not-a-relay.example.org'];
        $this->assertSame('Invalid websocket URL: https://not-a-relay.example.org', $bad['error']);
        $this->assertEqualsWithDelta(time() + Listener::MAX_BACKOFF, $bad['retry_at'], 2);

        // Open connections are pinged.
        $listener->call('tick');
        $this->assertSame([0x9, ''], $this->relayReport());

        // Another mailbox's change leaves this connection alone.
        $client = $listener->get('connections')[$key]['client'];
        $other = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $listener->call('tick');
        $this->assertSame($client, $listener->get('connections')[$key]['client']);
        $this->assertArrayHasKey($other->id.'|ws://127.0.0.1:1', $listener->get('connections'));
        $other->enabled = false;
        $other->save();

        // A settings change is picked up: no enabled mailbox, no connections.
        $cfg->enabled = false;
        $cfg->save();
        $listener->call('tick');
        $this->assertSame([], $listener->get('connections'));
        $this->assertContains('settings changed, updating subscriptions', $log);
        $this->assertContains('no enabled Nostr mailboxes, waiting', $log);
        $this->assertSame([0x8, 'hex:'.bin2hex(pack('n', 1000))], $this->relayReport());

        // After its lifetime the listener stops, for the scheduler to start a new one.
        $listener->set('startedAt', time() - 61);
        $listener->call('tick');
        $this->assertTrue($listener->get('stopping'));
        $this->assertSame('lifetime', ListenerStatus::read()['stop_reason']);
        $this->assertContains('lifetime reached, exiting', $log);
        $count = count($log);
        $listener->call('tick');
        $listener->stop();
        $this->assertCount($count, $log);
    }

    public function testAStoppingListenerKeepsItsSuccessorsHeartbeat()
    {
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        ListenerStatus::write(['pid' => getmypid() + 1, 'host' => gethostname(), 'started_at' => time(), 'connections' => []]);

        $listener->stop('signal');

        $this->assertSame(getmypid() + 1, ListenerStatus::read()['pid']);
        $this->assertNull(ListenerStatus::read()['stop_reason'] ?? null);
    }

    public function testListenerSurvivesHandlerErrorsAndReconnectsTheDatabase()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $handler = new class() extends IncomingMessageHandler {
            public $failures = [];
            public $calls = 0;

            public function handleGiftWrap(NostrMailbox $cfg, array $wrap, $relayUrl = null)
            {
                $this->calls++;
                if ($failure = array_shift($this->failures)) {
                    throw $failure;
                }

                return null;
            }

            public function handleLegacyMessage(NostrMailbox $cfg, array $event, $relayUrl = null)
            {
                throw new \RuntimeException('legacy broke');
            }
        };
        $log = [];
        $listener = $this->listener($handler, $log);
        $listener->call('reload');
        $key = $cfg->id.'|ws://127.0.0.1:1';
        $connections = $listener->get('connections');
        $connections[$key]['sub'] = 'sub1';
        $listener->set('connections', $connections);
        $wrap = EventBuilder::finalize(['kind' => GiftWrap::KIND_WRAP, 'content' => 'x', 'tags' => [['p', $cfg->pubkey]]], Keys::generatePrivateKey());
        $legacy = EventBuilder::finalize(['kind' => 4, 'content' => 'x', 'tags' => [['p', $cfg->pubkey]]], Keys::generatePrivateKey());
        $lost = new \Illuminate\Database\QueryException('mysql', 'select 1', [], new \Exception('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));

        // An error is logged and the listener carries on.
        $handler->failures = [new \RuntimeException('handler broke')];
        $listener->call('onMessage', $key, json_encode(['EVENT', 'sub1', $wrap]));
        $this->assertContains('error handling event '.substr($wrap['id'], 0, 8).': handler broke', $log);
        $this->assertSame(1, $listener->get('connections')[$key]['events']);

        $listener->call('onMessage', $key, json_encode(['EVENT', 'sub1', $legacy]));
        $this->assertContains('error recording legacy message: legacy broke', $log);

        // A lost database connection is reconnected and the work tried again.
        $db = app('db');
        $reconnects = 0;
        \DB::swap(new class($db, $reconnects) {
            protected $db;
            protected $reconnects;

            public function __construct($db, &$reconnects)
            {
                $this->db = $db;
                $this->reconnects = &$reconnects;
            }

            public function reconnect()
            {
                $this->reconnects++;
            }

            public function __call($method, $arguments)
            {
                return $this->db->$method(...$arguments);
            }
        });
        try {
            $handler->failures = [$lost];
            $handler->calls = 0;
            $listener->call('onMessage', $key, json_encode(['EVENT', 'sub1', $wrap]));
            $this->assertSame(1, $reconnects);
            $this->assertSame(2, $handler->calls);
            $this->assertContains('database connection lost, reconnecting', $log);

            // Other database errors are not retried.
            $handler->failures = [new \Illuminate\Database\QueryException('mysql', 'select 1', [], new \Exception('Unknown column'))];
            $handler->calls = 0;
            $listener->call('onMessage', $key, json_encode(['EVENT', 'sub1', $wrap]));
            $this->assertSame(1, $handler->calls);
            $this->assertSame(1, $reconnects);
        } finally {
            \DB::swap($db);
        }
    }

    public function testRunOnceFetchesPendingMessages()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $customer_private = Keys::generatePrivateKey();
        [$wrap] = GiftWrap::wrap(['kind' => 14, 'content' => 'Fetched once', 'tags' => [['p', $cfg->pubkey]]], $customer_private, $cfg->pubkey);
        $legacy = EventBuilder::finalize(['kind' => 4, 'content' => 'b64?iv=x', 'tags' => [['p', $cfg->pubkey]]], $customer_private);
        $url = $this->startRelay(function ($server, $channel) use ($wrap, $legacy) {
            [$conn] = $this->acceptClient($server);
            $req = $this->readJson($conn);
            $this->sendJson($conn, ['EVENT', $req[1], $wrap]);
            $this->sendJson($conn, ['EVENT', $req[1], $legacy]);
            $this->sendJson($conn, ['EOSE', $req[1]]);
            $this->drain($conn);
        });
        $cfg->setInboxRelays([$url]);
        $cfg->save();
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        \Bus::fake([\App\Jobs\NostrTask::class]);

        $this->assertSame(1, $listener->runOnce());

        $this->assertContains(sprintf('mailbox %d: 2 gift wrap(s) on 1 relay(s)', $cfg->mailbox_id), $log);
        $this->assertSame(1, Conversation::where('mailbox_id', $cfg->mailbox_id)->count());
        $this->assertTrue(NostrEvent::seenWrap($legacy['id']));
    }

    public function testANewListenerTakesOverFromARunningOne()
    {
        $log = [];
        $listener = $this->listener(new IncomingMessageHandler(), $log);
        $path = $this->storage_path.'/app/nostr-listen.lock';
        $this->startRelay(function ($server, $channel) use ($path) {
            $handle = fopen($path, 'c+');
            flock($handle, LOCK_EX);
            fwrite($handle, (string) getmypid());
            fflush($handle);
            $this->report($channel, getmypid());
            sleep(30);
        });
        $other = $this->relayReport();

        $this->assertTrue($listener->call('acquireLock'));

        $this->assertContains('another listener (pid '.$other.') is running, asking it to stop', $log);
        $this->assertSame((string) getmypid(), file_get_contents($path));
        $listener->call('releaseLock');

        // Without a writable lock file it runs anyway.
        $this->app->useStoragePath($this->storage_path.'/missing');
        $this->assertTrue($listener->call('acquireLock'));
        $this->assertStringStartsWith('cannot open ', end($log));
    }

    // Delivery through a relay that stores events.

    /**
     * A relay that answers queries from $stored (by kinds, authors and #p)
     * and accepts every event, reporting it, for any number of connections.
     */
    protected function storeRelay(array $stored)
    {
        return $this->startRelay(function ($server, $channel) use ($stored) {
            while (true) {
                [$conn] = $this->acceptClient($server);
                while ($message = $this->readJson($conn)) {
                    if ($message[0] === 'EVENT') {
                        $this->report($channel, $message[1]);
                        $this->sendJson($conn, ['OK', $message[1]['id'], true, '']);
                    } elseif ($message[0] === 'REQ') {
                        foreach (array_slice($message, 2) as $filter) {
                            foreach ($stored as $event) {
                                if (in_array($event['kind'], $filter['kinds'] ?? [$event['kind']])
                                    && in_array($event['pubkey'], $filter['authors'] ?? [$event['pubkey']])
                                    && (!isset($filter['#p']) || array_intersect($filter['#p'], EventBuilder::tagValues($event, 'p')))
                                ) {
                                    $this->sendJson($conn, ['EVENT', $message[1], $event]);
                                }
                            }
                        }
                        $this->sendJson($conn, ['EOSE', $message[1]]);
                    }
                }
                fclose($conn);
            }
        });
    }

    public function testRepliesAndAutoRepliesAreDeliveredToTheCustomersRelays()
    {
        $customer_private = Keys::generatePrivateKey();
        $customer_pubkey = Keys::pubkeyFromPrivate($customer_private);
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        // The customer has no DM relay list: replies go to the mailbox's inbox relays.
        $url = $this->storeRelay([]);
        $cfg->setInboxRelays([$url]);
        $cfg->auto_reply_enabled = true;
        $cfg->auto_reply_text = 'Thanks, we will answer soon.';
        $cfg->save();
        [$wrap, $rumor] = GiftWrap::wrap(['kind' => 14, 'content' => 'Hello', 'tags' => [['p', $cfg->pubkey], ['subject', 'Order 12']]], $customer_private, $cfg->pubkey);
        \Bus::fake([\App\Jobs\NostrTask::class]);
        $thread = (new IncomingMessageHandler())->handleGiftWrap($cfg, $wrap, $url);
        $conversation = $thread->conversation;
        \Bus::assertDispatched(\App\Jobs\NostrTask::class, function ($job) {
            return $job->task === 'auto_reply';
        });

        // The auto reply: delivered, so it shows as a line item.
        $this->assertTrue((new \App\Nostr\OutgoingMessageSender())->sendAutoReply($conversation->id, $cfg->id, $customer_pubkey));
        $sent = GiftWrap::unwrap($this->relayReport(), $customer_private, $customer_pubkey)['rumor'];
        $this->assertSame('Thanks, we will answer soon.', $sent['content']);
        $this->assertSame([['p', $customer_pubkey], ['subject', 'Order 12'], ['e', $rumor['id'], $url, 'reply']], $sent['tags']);
        $line = $conversation->threads()->where('type', \App\Thread::TYPE_LINEITEM)->first();
        $this->assertSame(\App\Nostr\OutgoingMessageSender::ACTION_TYPE_AUTO_REPLY, (int) $line->action_type);
        $this->assertSame('Thanks, we will answer soon.', $line->body);
        $this->assertStringContainsString('Nostr-Relays: '.$url.' (accepted)', $line->headers);
        $this->assertSame($line->id, NostrEvent::where('rumor_id', $sent['id'])->value('thread_id'));

        // An agent's reply is sent as soon as it is saved.
        $agent = $this->createUser();
        $reply = \App\Thread::createExtended(['type' => \App\Thread::TYPE_MESSAGE, 'body' => '<p>We are <b>on it</b>.</p>', 'created_by_user_id' => $agent->id], $conversation, $conversation->customer);
        $sent = GiftWrap::unwrap($this->relayReport(), $customer_private, $customer_pubkey)['rumor'];
        $this->assertSame('We are on it.', $sent['content']);
        $reply = $reply->fresh();
        $this->assertSame(\App\SendLog::STATUS_ACCEPTED, (int) $reply->send_status);
        $this->assertStringContainsString('Nostr-Sender: '.Keys::npub($cfg->pubkey).' ('.$cfg->pubkey.', current key)', $reply->headers);
        $this->assertStringContainsString('Nostr-Parent-Id: '.$rumor['id'], $reply->headers);
        $this->assertTrue(NostrEvent::sentForThread($reply->id));
    }

    public function testRepliesUseTheCustomersDmRelays()
    {
        $customer_private = Keys::generatePrivateKey();
        $customer_pubkey = Keys::pubkeyFromPrivate($customer_private);
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $customer_relay = $this->storeRelay([]);
        $directory = $this->storeRelay([EventBuilder::finalize(['kind' => 10050, 'tags' => [['relay', $customer_relay], ['relay', $customer_relay.'/']]], $customer_private)]);
        $cfg->setAnnounceRelays([$directory]);
        $cfg->save();
        $customer = \App\Customer::createWithoutEmail(['first_name' => 'Nostr']);
        \App\Nostr\CustomerKey::link($customer, $customer_pubkey);
        $sender = new \App\Nostr\OutgoingMessageSender();

        // Looked up on the mailbox's relays, then remembered.
        $this->assertSame([$customer_relay, 'wss://extra.example.org'], $sender->targetRelays($cfg, $customer_pubkey, ['extra.example.org/']));
        $this->assertSame([$customer_relay], \App\Nostr\CustomerKey::byPubkey($customer_pubkey)->getDmRelays());

        $result = $sender->sendText($cfg, $customer_pubkey, 'Hi there');
        $this->assertTrue($result['ok']);
        $this->assertSame([$customer_relay], $result['relays']);
        $this->assertSame('Hi there', GiftWrap::unwrap($this->relayReport(0), $customer_private, $customer_pubkey)['rumor']['content']);
    }

    public function testNewCustomersProfileIsFetched()
    {
        $customer_private = Keys::generatePrivateKey();
        $customer_pubkey = Keys::pubkeyFromPrivate($customer_private);
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $url = $this->storeRelay([
            EventBuilder::finalize(['kind' => 0, 'content' => json_encode(['display_name' => 'Alice  van Dijk', 'name' => 'alice', 'about' => ['not', 'text'], 'picture' => 'ipfs://avatar', 'website' => ' https://alice.example.org '])], $customer_private),
            EventBuilder::finalize(['kind' => 10050, 'tags' => [['relay', 'wss://dm.example.org']]], $customer_private),
        ]);
        $cfg->setInboxRelays([$url]);
        $cfg->save();
        $customer = \App\Customer::createWithoutEmail(['first_name' => Keys::shortNpub($customer_pubkey)]);
        \App\Nostr\CustomerKey::link($customer, $customer_pubkey);

        \App\Nostr\Nostr::fetchProfile($customer->id, $customer_pubkey, $cfg->id);

        $customer = $customer->fresh();
        $this->assertSame('Alice', $customer->first_name);
        $this->assertSame('van Dijk', $customer->last_name);
        $key = \App\Nostr\CustomerKey::byPubkey($customer_pubkey);
        $this->assertSame('Alice  van Dijk', $key->getDisplayName());
        $this->assertSame('https://alice.example.org', $key->getProfile()['website']);
        $this->assertArrayNotHasKey('about', $key->getProfile());
        // Only web addresses are fetched as the photo (Customer::setPhotoFromRemoteFile() goes online, so not tested here).
        $this->assertSame('ipfs://avatar', $key->getProfile()['picture']);
        $this->assertNull($customer->photo_url);
        $this->assertSame(['wss://dm.example.org'], $key->getDmRelays());

        // A name the agents gave is kept.
        $customer->first_name = 'Alice from sales';
        $customer->last_name = null;
        $customer->save();
        \App\Nostr\Nostr::fetchProfile($customer->id, $customer_pubkey, $cfg->id);
        $this->assertSame('Alice from sales', $customer->fresh()->first_name);

        // Nothing to do for a customer or mailbox that is gone.
        \App\Nostr\Nostr::fetchProfile(0, $customer_pubkey, $cfg->id);
    }

    public function testAnnounceCommandPublishesProfileAndRelayLists()
    {
        $cfg = $this->mailboxConfig(['ws://127.0.0.1:1']);
        $url = $this->storeRelay([]);
        $cfg->setInboxRelays([$url]);
        $cfg->setAnnounceRelays([$url]);
        $cfg->profile_name = 'Example Support';
        $cfg->profile_about = 'We help.';
        $cfg->profile_picture = 'https://example.org/logo.png';
        $cfg->nip05 = 'support@example.org';
        $cfg->save();
        $empty = $this->mailboxConfig([]);
        $empty->setAnnounceRelays([]);
        $empty->save();

        $this->artisan('tallport:nostr-announce', ['--mailbox' => $cfg->mailbox_id])
            ->expectsOutput('Mailbox '.$cfg->mailbox_id.' ('.$cfg->getNpub().')')
            ->expectsOutput('  kind 10050: accepted by 1 of 1 relay(s)')
            ->expectsOutput('  kind 10002: accepted by 1 of 1 relay(s)')
            ->expectsOutput('  kind 0: accepted by 1 of 1 relay(s)')
            ->assertExitCode(0);

        $events = [$this->relayReport(), $this->relayReport(), $this->relayReport()];
        $this->assertSame([10050, 10002, 0], array_column($events, 'kind'));
        foreach ($events as $event) {
            $this->assertTrue(EventBuilder::verify($event));
            $this->assertSame($cfg->pubkey, $event['pubkey']);
        }
        $this->assertSame([['relay', $url]], $events[0]['tags']);
        $this->assertSame([['r', $url]], $events[1]['tags']);
        $this->assertSame(['name' => 'Example Support', 'display_name' => 'Example Support', 'about' => 'We help.', 'picture' => 'https://example.org/logo.png', 'nip05' => 'support@example.org'], json_decode($events[2]['content'], true));
        $this->assertNotNull($cfg->fresh()->last_announced_at);

        $this->artisan('tallport:nostr-announce', ['--mailbox' => $empty->mailbox_id])
            ->expectsOutput('  nothing published (no relays configured)')
            ->assertExitCode(0);
    }
}
