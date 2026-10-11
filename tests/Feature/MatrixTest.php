<?php

namespace Tests\Feature;

use App\Conversation;
use App\Matrix\Connection;
use App\Matrix\Crypto\Account;
use App\Matrix\Crypto\CanonicalJson;
use App\Matrix\Crypto\Encoding;
use App\Matrix\Crypto\Megolm;
use App\Matrix\Crypto\OlmSession;
use App\Matrix\CryptoManager;
use App\Matrix\CryptoRecord;
use App\Matrix\Incoming;
use App\Matrix\Matrix;
use App\Matrix\MatrixEvent;
use App\Matrix\MatrixException;
use App\Matrix\MatrixMailbox;
use App\Matrix\MatrixRoom;
use App\Matrix\Outgoing;
use App\Matrix\RoomState;
use App\Matrix\Syncer;
use App\SendLog;
use App\Thread;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

class MatrixTest extends FeatureTestCase
{
    const HOME = 'https://matrix.example.org';
    const USER = '@support:example.org';
    const PEER = '@customer:example.org';
    const ROOM = '!private:example.org';

    protected function setUp(): void
    {
        parent::setUp();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
    }

    private function identity($mailbox = null, $user = self::USER)
    {
        $mailbox = $mailbox ?: $this->createMailbox();
        $identity = MatrixMailbox::create(['mailbox_id' => $mailbox->id, 'active_mailbox_id' => $mailbox->id,
            'homeserver' => self::HOME, 'user_id' => $user, 'user_hash' => hash('sha256', $user),
            'device_id' => 'TALLPORT', 'status' => 'ready', 'credentials' => ['access_token' => 'secret-access-token']]);
        $account = Account::create($user, 'TALLPORT');
        $account->uploadKeys(0);
        CryptoRecord::write($identity->id, 'account', 'device', $account->export());

        return [$identity, $account];
    }

    private function keys(Account $account, $user, $device)
    {
        $master = sodium_crypto_sign_keypair();
        $self = sodium_crypto_sign_keypair();
        $master_public = Encoding::base64(sodium_crypto_sign_publickey($master));
        $self_public = Encoding::base64(sodium_crypto_sign_publickey($self));
        $self_object = ['user_id' => $user, 'usage' => ['self_signing'], 'keys' => ['ed25519:'.$self_public => $self_public]];
        $self_object['signatures'][$user]['ed25519:'.$master_public] = CanonicalJson::sign($self_object, sodium_crypto_sign_secretkey($master));
        $device_object = $account->deviceKeys();
        $device_object['signatures'][$user]['ed25519:'.$self_public] = CanonicalJson::sign($device_object, sodium_crypto_sign_secretkey($self));

        return ['master_keys' => [$user => ['user_id' => $user, 'usage' => ['master'], 'keys' => ['ed25519:'.$master_public => $master_public]]],
            'self_signing_keys' => [$user => $self_object], 'device_keys' => [$user => [$device => $device_object]]];
    }

    private function state($encrypted = true, $extra = [])
    {
        $events = [
            ['type' => 'm.room.join_rules', 'state_key' => '', 'content' => ['join_rule' => 'invite']],
            ['type' => 'm.room.member', 'state_key' => self::USER, 'content' => ['membership' => 'join']],
            ['type' => 'm.room.member', 'state_key' => self::PEER, 'content' => ['membership' => 'join']],
        ];
        if ($encrypted) {
            $events[] = ['type' => 'm.room.encryption', 'state_key' => '', 'content' => ['algorithm' => 'm.megolm.v1.aes-sha2']];
        }

        return array_merge($events, $extra);
    }

    private function batch($cursor, array $events = [], array $to_device = [], $state = [])
    {
        return ['next_batch' => $cursor, 'device_one_time_keys_count' => ['signed_curve25519' => 50],
            'to_device' => ['events' => $to_device], 'rooms' => ['join' => [self::ROOM => ['state' => ['events' => $state],
                'timeline' => ['events' => $events, 'limited' => false, 'prev_batch' => 'previous']]]]];
    }

    private function message($id, $body, $sender = self::PEER)
    {
        return ['event_id' => $id, 'sender' => $sender, 'type' => 'm.room.message', 'content' => ['msgtype' => 'm.text', 'body' => $body]];
    }

    public function testLoginKeepsSecretsEncryptedAndReconnectsTheSameDevice()
    {
        $mailbox = $this->createMailbox();
        $query = $this->keys(Account::create(self::USER, 'PHONE'), self::USER, 'PHONE');
        Http::fake(function ($request) use (&$query) {
            if (str_ends_with($request->url(), '/versions')) {
                return Http::response(['versions' => ['v1.11', 'v1.12']]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['flows' => [['type' => 'm.login.password']]]);
            }
            if (str_ends_with($request->url(), '/login')) {
                return Http::response(['user_id' => self::USER, 'device_id' => $request['device_id'], 'access_token' => 'token-never-serialized']);
            }
            if (str_ends_with($request->url(), '/keys/query')) {
                return Http::response($query);
            }
            if (isset($request['device_keys'])) {
                $query['device_keys'][self::USER][$request['device_keys']['device_id']] = $request['device_keys'];
            }

            return Http::response(['one_time_key_counts' => ['signed_curve25519' => 50]]);
        });
        $identity = (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'password-never-stored');
        $state = CryptoRecord::read($identity->id, 'account', 'device');
        $again = (new Connection())->connect($mailbox->id, self::HOME, self::USER, 'password-never-stored');
        $this->assertSame($identity->device_id, $again->device_id);
        $this->assertSame($state['signing'], CryptoRecord::read($again->id, 'account', 'device')['signing']);
        $this->assertStringNotContainsString('token-never-serialized', $identity->toJson());
        $this->assertStringNotContainsString('token-never-serialized', $identity->getRawOriginal('credentials'));
        $this->assertStringNotContainsString('password-never-stored', json_encode($identity->getAttributes()));
        $this->assertSame('verification', $identity->status);
        $this->expectException(MatrixException::class);
        (new Connection())->connect($this->createMailbox()->id, self::HOME, self::USER, 'password-never-stored');
    }

    /** @dataProvider deviceStatuses */
    public function testInitialSyncSkipsHistoryAndLaterMessagesUseSharedConversationPolicies($status)
    {
        [$identity] = $this->identity();
        $identity->status = $status;
        $identity->save();
        $batches = [$this->batch('one', [$this->message('$old', 'old history')]),
            $this->batch('two', [$this->message('$new', '<script>new</script>')]),
            $this->batch('three', [$this->message('$new', '<script>new</script>'), $this->message('$next', 'next')])];
        Http::fake(function ($request) use (&$batches) {
            return Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/state') ? $this->state(false) : array_shift($batches));
        });
        \App\Jobs\SyncMatrixMailbox::dispatchSync($identity->id);
        $this->assertSame(0, Conversation::where('channel', Matrix::CHANNEL)->count());
        (new Syncer())->run($identity);
        $conversation = Conversation::where('channel', Matrix::CHANNEL)->firstOrFail();
        $this->assertStringContainsString('&lt;script&gt;new&lt;/script&gt;', $conversation->threads()->first()->body);
        $this->assertSame(['identity' => $identity->id, 'room' => self::ROOM], $conversation->getMeta('matrix'));
        $conversation->last_reply_at = now()->subDays(31);
        $conversation->save();
        (new Syncer())->run($identity);
        $this->assertSame(2, Conversation::where('channel', Matrix::CHANNEL)->count());
        $this->assertSame(2, MatrixEvent::where('kind', 'incoming')->count());
        $this->assertSame('three', $identity->fresh()->sync_token);
    }

    /** @dataProvider deviceStatuses */
    public function testPendingEncryptedMessageIsFilledInPlaceAndReplayCannotCreateAnotherMessage($status)
    {
        [$identity, $account] = $this->identity();
        $identity->status = $status;
        $identity->save();
        $peer = Account::create(self::PEER, 'PHONE');
        $query = $this->keys($peer, self::PEER, 'PHONE');
        Http::fake(['*' => Http::response($query)]);
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state());
        $session = Megolm::create();
        $session_key = $session->sessionKey();
        $cipher = $session->encrypt(json_encode(['room_id' => self::ROOM, 'type' => 'm.room.message', 'content' => ['msgtype' => 'm.text', 'body' => 'decrypted hello']]));
        $event = ['event_id' => '$encrypted', 'sender' => self::PEER, 'type' => 'm.room.encrypted', 'content' => [
            'algorithm' => 'm.megolm.v1.aes-sha2', 'sender_key' => $peer->curveKey(), 'session_id' => $session->id(), 'ciphertext' => $cipher, 'device_id' => 'PHONE']];
        $record = MatrixEvent::create(['matrix_mailbox_id' => $identity->id, 'local_key' => hash('sha256', '$encrypted'),
            'kind' => 'incoming', 'room_id' => self::ROOM, 'payload' => $event]);
        (new Incoming($identity, new CryptoManager($identity)))->process();
        $thread_id = $record->fresh()->thread_id;
        $this->assertTrue((bool) Thread::findOrFail($thread_id)->getMeta('chat_pending'));
        \App\Ai\Agents\ChatTranslator::fake()->preventStrayPrompts();
        $pending = Thread::findOrFail($thread_id);
        \App\Option::set('aiassistant.api_key', encrypt('sk-test'));
        \App\Option::set('aiassistant.mailbox_chat_translation', [$identity->mailbox_id => 1]);
        \App\Option::$cache = [];
        $this->assertTrue(\App\Ai\ChatTranslation::isOn($pending->conversation));
        $this->assertFalse(\App\Ai\Translations::isWanted($pending));
        $this->assertFalse(\App\Ai\Translations::isMissing($pending, 'de'));
        $this->assertSame([], \App\Ai\ChatTranslation::translateIncoming($pending->conversation, 'de'));
        $this->assertNull(\App\Ai\Translations::forceTranslate($pending, $this->createAdmin()));
        \App\Ai\Agents\ChatTranslator::assertNeverPrompted();
        \Illuminate\Support\Facades\Queue::fake();
        \App\Jobs\AiDetectCustomerLanguage::request($pending);
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Jobs\AiDetectCustomerLanguage::class);
        \App\Option::set('aiassistant.api_key', '');
        \App\Option::$cache = [];
        $otks = (array) $account->uploadKeys(0)['one_time_keys'];
        $olm = OlmSession::create($peer->curveSecret(), $account->curveKey(), reset($otks)['key']);
        $room_key = $olm->encrypt($peer->envelope(self::USER, $account->signingKey(), 'm.room_key', ['algorithm' => 'm.megolm.v1.aes-sha2',
            'room_id' => self::ROOM, 'session_id' => $session->id(), 'session_key' => $session_key]));
        \DB::transaction(fn () => (new CryptoManager($identity))->receiveOlm(['sender' => self::PEER, 'content' => [
            'algorithm' => 'm.olm.v1.curve25519-aes-sha2', 'sender_key' => $peer->curveKey(), 'ciphertext' => [$account->curveKey() => $room_key]]]));
        (new Incoming($identity, new CryptoManager($identity)))->process();
        $this->assertSame($thread_id, $record->fresh()->thread_id);
        $this->assertSame('<p>decrypted hello</p>', Thread::findOrFail($thread_id)->body);
        $this->assertFalse((bool) Thread::findOrFail($thread_id)->getMeta('chat_pending'));
        $this->assertTrue(\App\Ai\Translations::isMissing(Thread::findOrFail($thread_id), 'de'));
        $copy = $record->replicate();
        $copy->local_key = hash('sha256', '$replay');
        $copy->thread_id = null;
        $copy->conversation_id = null;
        $copy->replay_hash = null;
        $copy->status = 'pending';
        $copy->payload = array_replace($event, ['event_id' => '$replay']);
        $copy->save();
        (new Incoming($identity, new CryptoManager($identity)))->process();
        $this->assertSame('rejected', $copy->fresh()->status);
        $this->assertSame(1, Thread::where('body', '<p>decrypted hello</p>')->count());
    }

    /** @dataProvider deviceStatuses */
    public function testEncryptedSendRetriesExactlyTheSameCiphertextAndTransaction($status)
    {
        [$identity, $account] = $this->identity();
        $identity->status = $status;
        $identity->save();
        $peer = Account::create(self::PEER, 'PHONE');
        $own_keys = $this->keys($account, self::USER, 'TALLPORT');
        $peer_keys = $this->keys($peer, self::PEER, 'PHONE');
        if ($status === 'verification') {
            $own_keys['device_keys'][self::USER]['TALLPORT'] = $account->deviceKeys();
        } else {
            CryptoRecord::write($identity->id, 'trust', self::USER, \App\Matrix\Crypto\DeviceKeys::crossSigning($own_keys, self::USER));
        }
        $otks = (array) $peer->uploadKeys(0)['one_time_keys'];
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state());
        $customer = $this->createCustomer();
        $conversation = Conversation::create(['type' => Conversation::TYPE_EMAIL, 'subject' => 'Support', 'source_type' => Conversation::SOURCE_TYPE_API, 'mailbox_id' => $identity->mailbox_id,
            'channel' => Matrix::CHANNEL], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Question']], $customer)['conversation']->fresh();
        $conversation->setMeta('matrix', ['identity' => $identity->id, 'room' => self::ROOM]);
        $conversation->save();
        \Illuminate\Support\Facades\Event::fake([\App\Events\UserReplied::class]);
        $thread = Thread::createExtended(['type' => Thread::TYPE_MESSAGE, 'body' => '<p>Answer</p>', 'created_by_user_id' => $this->createAdmin()->id, 'after_commit' => true], $conversation, $customer);
        \App\Attachment::create('report.txt', 'text/plain', null, 'encrypted file contents', null, false, $thread->id);
        $uploads = [];
        $sends = [];
        $keys_sent = [];
        Http::fake(function ($request) use ($own_keys, $peer_keys, $otks, &$sends, &$keys_sent, &$uploads) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/state')) {
                return Http::response($this->state());
            }
            if (str_ends_with($path, '/keys/query')) {
                return Http::response(isset($request['device_keys'][self::USER]) ? $own_keys : $peer_keys);
            }
            if (str_ends_with($path, '/upload')) {
                $uploads[] = $request->body();
                return Http::response(['content_uri' => 'mxc://example.org/file']);
            }
            if (str_ends_with($path, '/keys/claim')) {
                return Http::response(['one_time_keys' => [self::PEER => ['PHONE' => [array_key_first($otks) => reset($otks)]]]]);
            }
            if (str_contains($path, '/sendToDevice/')) {
                $keys_sent[] = $request->data();
                return Http::response([]);
            }
            $sends[] = ['url' => $request->url(), 'data' => $request->data()];
            return count($sends) === 1 ? Http::response(['errcode' => 'M_UNKNOWN'], 503) : Http::response(['event_id' => '$sent'.count($sends)]);
        });
        try {
            (new Outgoing())->send($thread);
            $this->fail('Expected a lost send response.');
        } catch (MatrixException $e) {
            $this->assertSame(503, $e->getCode());
        }
        MatrixEvent::where('kind', 'outgoing')->update(['retry_at' => null]);
        \App\Jobs\SendReplyToMatrix::dispatchSync($thread->id);
        $this->assertCount(3, $sends);
        $this->assertCount(1, $uploads);
        $this->assertSame($sends[0], $sends[1]);
        $this->assertCount(1, $keys_sent);
        $key_content = $keys_sent[0]['messages'][self::PEER]['PHONE'];
        $decrypted_key = $peer->receive($account->curveKey(), $key_content['ciphertext'][$peer->curveKey()]['body'], self::USER, $account->signingKey());
        $group = Megolm::receive($decrypted_key['event']['content']['session_key']);
        $plain = json_decode($group->decrypt($sends[1]['data']['ciphertext'])['plaintext'], true);
        $this->assertSame('Answer', $plain['content']['body']);
        $file = json_decode($group->decrypt($sends[2]['data']['ciphertext'])['plaintext'], true)['content']['file'];
        $this->assertSame('mxc://example.org/file', $file['url']);
        $this->assertSame('encrypted file contents', \App\Matrix\Crypto\Attachment::decrypt($uploads[0], $file));
        $this->assertSame(SendLog::STATUS_ACCEPTED, (int) $thread->fresh()->send_status);
        $this->assertSame(0, MatrixEvent::where('status', 'sent')->whereNotNull('payload')->count());
        (new Outgoing())->send($thread);
        $this->assertCount(3, $sends);
        $this->assertCount(1, $uploads);
    }

    public function testRoomStopsBeingSupportedWhenAnotherUserIsInvitedAndNeverChangesCustomer()
    {
        [$identity] = $this->identity();
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state());
        $this->assertTrue($room->state['supported']);
        RoomState::apply($identity, $room, [['type' => 'm.room.member', 'state_key' => '@other:example.org', 'content' => ['membership' => 'invite']]]);
        $this->assertFalse($room->state['supported']);
        RoomState::apply($identity, $room, [['type' => 'm.room.member', 'state_key' => self::PEER, 'content' => ['membership' => 'leave']]]);
        $this->assertFalse($room->state['supported']);
        $this->assertSame(self::PEER, $room->customer_user_id);
    }

    /** @dataProvider roomAccessFailures */
    public function testOnlyConfirmedLostRoomAccessClosesTheChatAndStopsDeliveryRetries($status, $joined, $unavailable)
    {
        [$identity] = $this->identity();
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state(false));
        $conversation = Conversation::create(['type' => Conversation::TYPE_EMAIL, 'subject' => 'Support', 'source_type' => Conversation::SOURCE_TYPE_API,
            'mailbox_id' => $identity->mailbox_id, 'channel' => Matrix::CHANNEL], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Question']], $this->createCustomer())['conversation'];
        $conversation->setMeta('matrix', ['identity' => $identity->id, 'room' => self::ROOM], true);
        $room->conversation_id = $conversation->id;
        $room->save();
        $reply = Thread::create($conversation, Thread::TYPE_MESSAGE, 'Answer', ['source_via' => Thread::PERSON_USER, 'source_type' => Thread::SOURCE_TYPE_WEB]);
        Http::fake([
            self::HOME.'/_matrix/client/v3/rooms/'.rawurlencode(self::ROOM).'/state' => Http::response(['errcode' => $status === 403 ? 'M_FORBIDDEN' : 'M_UNKNOWN'], $status),
            self::HOME.'/_matrix/client/v3/joined_rooms' => Http::response($joined),
        ]);
        $job = (new \App\Jobs\SendReplyToMatrix($reply->id))->withFakeQueueInteractions();
        try {
            $job->handle();
            $this->assertTrue($unavailable, 'A temporary failure should still retry.');
        } catch (MatrixException $e) {
            $this->assertFalse($unavailable, 'A closed chat should stop retrying.');
        }
        $this->assertSame($unavailable, $conversation->fresh()->isChatUnavailable());
        $this->assertSame($unavailable ? Conversation::STATUS_CLOSED : Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
        Http::assertSentCount($status === 403 ? 2 : 1);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
        if ($unavailable) {
            $this->assertFalse($reply->fresh()->canRetrySend());
            $job->assertNotReleased()->assertNotFailed();
            $job->handle();
            Http::assertSentCount(2);
        }
    }

    public static function roomAccessFailures()
    {
        return [
            'membership lost' => [403, ['joined_rooms' => []], true],
            'still joined' => [403, ['joined_rooms' => [self::ROOM]], false],
            'unknown membership' => [403, [], false],
            'expired login' => [401, [], false],
            'temporary server failure' => [503, [], false],
        ];
    }

    /** @dataProvider departedMembers */
    public function testSyncClosesAChatWhenEitherParticipantLeavesTheRoom($customer_left)
    {
        [$identity] = $this->identity();
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state(false));
        $conversation = Conversation::create(['type' => Conversation::TYPE_EMAIL, 'subject' => 'Support', 'source_type' => Conversation::SOURCE_TYPE_API,
            'mailbox_id' => $identity->mailbox_id, 'channel' => Matrix::CHANNEL], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Question']], $this->createCustomer())['conversation'];
        $room->conversation_id = $conversation->id;
        $room->save();
        $response = $customer_left
            ? $this->batch('left', [], [], [['type' => 'm.room.member', 'state_key' => self::PEER, 'content' => ['membership' => 'leave']]])
            : ['next_batch' => 'left', 'device_one_time_keys_count' => ['signed_curve25519' => 50], 'rooms' => ['leave' => [self::ROOM => ['timeline' => ['events' => []]]]]];
        $pending = [];
        foreach (['outgoing', 'to_device'] as $kind) {
            $pending[] = MatrixEvent::outgoing($identity->id, 'pending-'.$kind, $kind, ['type' => 'm.room.encrypted', 'messages' => []], self::ROOM);
        }
        Http::fake([self::HOME.'/_matrix/client/v3/sync*' => Http::response($response)]);
        (new Syncer())->run($identity);
        $this->assertTrue($conversation->fresh()->isChatUnavailable());
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
        $this->assertFalse($room->fresh()->state['supported']);
        $this->assertSame(2, MatrixEvent::where('room_id', self::ROOM)->where('status', 'cancelled')->count());
        foreach ($pending as $event) {
            \App\Matrix\Outbox::deliver($identity, $event);
        }
        Http::assertSentCount(1);
    }

    public static function departedMembers()
    {
        return ['mailbox left' => [false], 'customer left' => [true]];
    }

    public function testLimitedTimelineResumesAcrossRunsBeforeAdvancingItsCursor()
    {
        [$identity] = $this->identity();
        $initial = $this->batch('one', [$this->message('$anchor', 'old')]);
        $limited = $this->batch('two', [$this->message('$new', 'new')]);
        $limited['rooms']['join'][self::ROOM]['timeline']['limited'] = true;
        $batches = [$initial, $limited];
        $pages = [[$this->message('$d', 'd')], [$this->message('$c', 'c')], [$this->message('$b', 'b')],
            [$this->message('$a', 'a'), $this->message('$anchor', 'old')]];
        $page = 0;
        Http::fake(function ($request) use (&$batches, &$pages, &$page) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/state')) {
                return Http::response($this->state(false));
            }
            if (str_ends_with($path, '/messages')) {
                return Http::response(['chunk' => array_shift($pages), 'end' => 'page'.(++$page)]);
            }
            return Http::response(array_shift($batches));
        });
        (new Syncer())->run($identity);
        (new Syncer())->run($identity);
        $this->assertSame('one', $identity->fresh()->sync_token);
        $this->assertSame(0, Thread::count());
        (new Syncer())->run($identity->fresh());
        $this->assertSame('two', $identity->fresh()->sync_token);
        $this->assertSame(['<p>a</p>', '<p>b</p>', '<p>c</p>', '<p>d</p>', '<p>new</p>'], Thread::where('type', Thread::TYPE_CUSTOMER)->orderBy('id')->pluck('body')->all());
        $this->assertNull(CryptoRecord::read($identity->id, 'sync', 'batch'));
    }

    public function testOwnDeviceRepliesAreNotAttributedToAnAgentOrSentBackToMatrix()
    {
        [$identity] = $this->identity();
        $identity->sync_token = 'one';
        $identity->save();
        $batches = [$this->batch('two', [$this->message('$own', 'Sent in Element', self::USER)])];
        Http::fake(function ($request) use (&$batches) {
            return Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/state') ? $this->state(false) : array_shift($batches));
        });
        \Illuminate\Support\Facades\Event::fake([\App\Events\UserReplied::class, \App\Events\CustomerReplied::class]);
        (new Syncer())->run($identity);
        $thread = Thread::where('body', '<p>Sent in Element</p>')->firstOrFail();
        $this->assertSame(Thread::TYPE_MESSAGE, (int) $thread->type);
        $this->assertNull($thread->created_by_user_id);
        $this->assertSame(self::USER, $thread->getMeta('chat_external_sender'));
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\UserReplied::class);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\CustomerReplied::class);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function testUnexpectedPlaintextAndMalformedEncryptedMessagesDoNotStopSync()
    {
        [$identity] = $this->identity();
        $identity->sync_token = 'one';
        $identity->save();
        $bad = $this->message('$bad', '');
        $bad['type'] = 'm.room.encrypted';
        $bad['content'] = ['algorithm' => 'm.megolm.v1.aes-sha2', 'sender_key' => [], 'session_id' => []];
        Http::fake(function ($request) use ($bad) {
            return Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/state') ? $this->state()
                : $this->batch('two', [$this->message('$plain', 'unauthenticated content'), $bad], [
                    ['sender' => self::USER, 'type' => 'm.key.verification.request', 'content' => ['from_device' => []]],
                ]));
        });
        (new Syncer())->run($identity);
        $this->assertSame('two', $identity->fresh()->sync_token);
        $this->assertSame(0, Thread::where('body', 'like', '%unauthenticated content%')->count());
        $this->assertSame(2, MatrixEvent::where('kind', 'incoming')->where('status', 'rejected')->count());
        $this->assertSame('rejected', MatrixEvent::where('kind', 'device_in')->firstOrFail()->status);
    }

    public function testQueuedRoomKeyIsCancelledIfRoomGainsAnotherMember()
    {
        [$identity] = $this->identity();
        $peer = Account::create(self::PEER, 'PHONE');
        $keys = $this->keys($peer, self::PEER, 'PHONE');
        $device = \App\Matrix\Crypto\DeviceKeys::crossSigning($keys, self::PEER)['devices']['PHONE'];
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state());
        $event = MatrixEvent::outgoing($identity->id, 'key', 'to_device', ['type' => 'm.room.encrypted',
            'recipient' => ['user' => self::PEER, 'device' => $device], 'messages' => ['unused']], self::ROOM);
        Http::fake(function ($request) use ($keys) {
            return Http::response(str_ends_with($request->url(), '/state') ? $this->state(true, [
                ['type' => 'm.room.member', 'state_key' => '@third:example.org', 'content' => ['membership' => 'join']],
            ]) : $keys);
        });
        \App\Matrix\Outbox::flush($identity);
        $this->assertSame('cancelled', $event->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sendToDevice/'));
    }

    public function testTrustChangesRequireAnExactFingerprintAndRetirePendingSends()
    {
        [$identity] = $this->identity();
        $peer = Account::create(self::PEER, 'PHONE');
        $old = $this->keys($peer, self::PEER, 'PHONE');
        $replacement = $this->keys(Account::create(self::PEER, 'PHONE'), self::PEER, 'PHONE');
        $response = $old;
        Http::fake(function () use (&$response) { return Http::response($response); });
        (new CryptoManager($identity))->trusted(self::PEER);
        $response = $replacement;
        try {
            (new CryptoManager($identity))->trusted(self::PEER);
            $this->fail('Changed account identity was trusted.');
        } catch (MatrixException $e) {
            $review = CryptoRecord::read($identity->id, 'trust_review', self::PEER);
            $this->assertNotNull($review);
        }
        $outgoing = MatrixEvent::outgoing($identity->id, 'pending', 'outgoing', ['content' => []]);
        try {
            (new CryptoManager($identity))->approve(self::PEER, str_repeat('0', 64));
            $this->fail('Stale fingerprint was accepted.');
        } catch (MatrixException $e) {
            $this->assertSame('pending', $outgoing->fresh()->status);
        }
        (new CryptoManager($identity))->approve(self::PEER, $review['fingerprint']);
        $this->assertSame('cancelled', $outgoing->fresh()->status);
        $this->assertSame($peer->deviceKeys()['device_id'], (new CryptoManager($identity))->trusted(self::PEER)['PHONE']['id']);
        $this->assertNull(CryptoRecord::read($identity->id, 'trust_review', self::PEER));
    }

    public function testRateLimitedSyncWaitsAndRefreshesExpiredAccessTokens()
    {
        [$identity] = $this->identity();
        $identity->credentials = ['access_token' => 'old', 'refresh_token' => 'refresh-secret', 'expires_at' => time() - 1];
        $identity->save();
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            if (str_ends_with($request->url(), '/refresh')) {
                $this->assertSame('refresh-secret', $request['refresh_token']);
                return Http::response(['access_token' => 'new', 'refresh_token' => 'rotated', 'expires_in_ms' => 3600000]);
            }
            $this->assertSame(['Bearer new'], $request->header('Authorization'));
            return Http::response(['errcode' => 'M_LIMIT_EXCEEDED', 'retry_after_ms' => 600000], 429);
        });
        \App\Jobs\SyncMatrixMailbox::dispatchSync($identity->id);
        \App\Jobs\SyncMatrixMailbox::dispatchSync($identity->id);
        $this->assertSame(2, $calls);
        $this->assertSame('rotated', $identity->fresh()->credentials['refresh_token']);
        $this->assertNull($identity->fresh()->sync_token);
    }

    public function testRoomIdentifiersRemainCaseSensitiveAndConversationBindingCannotMove()
    {
        [$identity] = $this->identity();
        $first = MatrixRoom::forRoom($identity->id, '!a:example.org');
        RoomState::apply($identity, $first, $this->state(false));
        $second = MatrixRoom::forRoom($identity->id, '!A:example.org');
        RoomState::apply($identity, $second, $this->state(false));
        $this->assertNotSame($first->id, $second->id);
        $conversation = Conversation::create(['type' => Conversation::TYPE_EMAIL, 'subject' => 'Support', 'source_type' => Conversation::SOURCE_TYPE_API, 'mailbox_id' => $identity->mailbox_id, 'channel' => Matrix::CHANNEL], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Question']], $this->createCustomer())['conversation'];
        $conversation->setMeta('matrix', ['identity' => $identity->id, 'room' => '!a:example.org']);
        $conversation->save();
        $other = Conversation::create(['type' => Conversation::TYPE_EMAIL, 'subject' => 'Support', 'source_type' => Conversation::SOURCE_TYPE_API, 'mailbox_id' => $identity->mailbox_id, 'channel' => Matrix::CHANNEL], [['type' => Thread::TYPE_CUSTOMER, 'body' => 'Question']], $this->createCustomer())['conversation'];
        $other->setMeta('matrix', ['identity' => $identity->id, 'room' => '!A:example.org']);
        $other->save();
        try {
            $conversation->mergeConversations($other, $this->createAdmin());
            $this->fail('Different Matrix rooms were merged.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $conversation->moveToMailbox($this->createMailbox(), $this->createAdmin());
    }

    public function testSignedJsonObjectsSurviveEncryptedPersistence()
    {
        [$identity] = $this->identity();
        $json = '{"type":"signed","empty":{},"numeric":{"0":"value"},"list":[]}';
        $value = CanonicalJson::decode($json);
        $record = CryptoRecord::write($identity->id, 'test', 'json', $value);
        $event = MatrixEvent::outgoing($identity->id, 'json', 'to_device', $value);
        $this->assertSame(CanonicalJson::encode($value), CanonicalJson::encode($record->fresh()->value));
        $this->assertSame(CanonicalJson::encode($value), CanonicalJson::encode($event->fresh()->payload));
        $this->assertStringNotContainsString('signed', $event->getRawOriginal('payload'));
    }

    public function testLosingLocalKeysCreatesANewDeviceBeforeLoggingIn()
    {
        [$identity, $old] = $this->identity();
        CryptoRecord::record($identity->id, 'account', 'device')->delete();
        CryptoRecord::write($identity->id, 'inbound', 'old-room', ['key' => 'retained']);
        $query = $this->keys($old, self::USER, 'TALLPORT');
        Http::fake(function ($request) use (&$query) {
            if (str_ends_with($request->url(), '/versions')) {
                return Http::response(['versions' => ['v1.11']]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['flows' => [['type' => 'm.login.password']]]);
            }
            if (str_ends_with($request->url(), '/login')) {
                $this->assertNotSame('TALLPORT', $request['device_id']);
                return Http::response(['user_id' => self::USER, 'device_id' => $request['device_id'], 'access_token' => 'new-token']);
            }
            if (str_ends_with($request->url(), '/keys/query')) {
                return Http::response($query);
            }
            if (isset($request['device_keys'])) {
                $query['device_keys'][self::USER][$request['device_keys']['device_id']] = $request['device_keys'];
            }
            return Http::response(['one_time_key_counts' => ['signed_curve25519' => 50]]);
        });
        $new = (new Connection())->connect($identity->mailbox_id, self::HOME, self::USER, 'secret');
        $this->assertSame('verification', $new->status);
        $this->assertNotSame($old->curveKey(), Account::restore(CryptoRecord::read($new->id, 'account', 'device'))->curveKey());
        $this->assertSame(['key' => 'retained'], CryptoRecord::read($new->id, 'inbound', 'old-room'));
    }

    public function testDeletedConversationRetainsAnEventTombstone()
    {
        [$identity] = $this->identity();
        $identity->sync_token = 'one';
        $identity->save();
        Http::fake(fn ($request) => Http::response(str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/state') ? $this->state(false)
            : $this->batch('two', [$this->message('$delete-me', 'Delete this body')])));
        (new Syncer())->run($identity);
        $record = MatrixEvent::where('kind', 'incoming')->firstOrFail();
        Conversation::deleteConversationsForever([$record->conversation_id]);
        $this->assertSame('deleted', $record->fresh()->status);
        $this->assertNull($record->fresh()->payload);
        (new Syncer())->run($identity);
        $this->assertSame(0, Conversation::where('channel', Matrix::CHANNEL)->count());
        $this->assertSame(1, MatrixEvent::where('kind', 'incoming')->count());
    }

    public function testEncryptedFilesAreAuthenticatedBeforeTheyBecomeAttachments()
    {
        [$identity] = $this->identity();
        $peer = Account::create(self::PEER, 'PHONE');
        $keys = $this->keys($peer, self::PEER, 'PHONE');
        $device = \App\Matrix\Crypto\DeviceKeys::crossSigning($keys, self::PEER)['devices']['PHONE'];
        $room = MatrixRoom::forRoom($identity->id, self::ROOM);
        RoomState::apply($identity, $room, $this->state());
        $group = Megolm::create();
        $incoming_key = Megolm::receive($group->sessionKey())->export();
        CryptoRecord::write($identity->id, 'inbound', self::ROOM.'|'.$peer->curveKey().'|'.$group->id(), ['session' => $incoming_key, 'sender' => self::PEER, 'device' => $device]);
        $file = \App\Matrix\Crypto\Attachment::encrypt('downloaded secret');
        $download = $file['ciphertext'];
        Http::fake(function ($request) use ($keys, &$download) {
            return str_contains($request->url(), '/media/download/') ? Http::response($download, 200, ['Content-Type' => 'application/octet-stream']) : Http::response($keys);
        });
        foreach (['$valid-file', '$tampered-file'] as $id) {
            $ciphertext = $group->encrypt(json_encode(['room_id' => self::ROOM, 'type' => 'm.room.message', 'content' => [
                'msgtype' => 'm.file', 'body' => 'report.txt', 'file' => $file['file'] + ['url' => 'mxc://example.org/file'],
            ]]));
            $record = MatrixEvent::create(['matrix_mailbox_id' => $identity->id, 'kind' => 'incoming', 'room_id' => self::ROOM,
                'local_key' => hash('sha256', $id), 'payload' => ['sender' => self::PEER, 'event_id' => $id, 'type' => 'm.room.encrypted',
                    'content' => ['algorithm' => 'm.megolm.v1.aes-sha2', 'sender_key' => $peer->curveKey(), 'session_id' => $group->id(), 'ciphertext' => $ciphertext]]]);
            (new Incoming($identity, new CryptoManager($identity)))->process();
            if ($id === '$valid-file') {
                $thread = Thread::findOrFail($record->fresh()->thread_id);
                $this->assertCount(1, $thread->attachments);
                $this->assertSame('downloaded secret', $thread->attachments->first()->getFileContents());
            } else {
                $this->assertSame('rejected', $record->fresh()->status);
                $this->assertCount(0, Thread::findOrFail($record->fresh()->thread_id)->attachments);
            }
            $download[0] = chr(ord($download[0]) ^ 1);
        }
    }

    public function testClientRejectsUnsafeUrlsAndDoesNotFollowRedirects()
    {
        foreach (['http://matrix.example.org', 'https://user:secret@matrix.example.org', 'https://127.0.0.1', 'https://matrix.example.org/path'] as $url) {
            try {
                (new \App\Matrix\Client($url, 'secret'))->call('GET', 'versions');
                $this->fail('Unsafe homeserver was contacted.');
            } catch (MatrixException $e) {
                $this->assertStringNotContainsString('secret', $e->getMessage());
            }
        }
        Http::assertNothingSent();
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://other.example.org'])]);
        try {
            (new \App\Matrix\Client(self::HOME, 'secret'))->call('GET', 'versions');
            $this->fail('Redirect was accepted.');
        } catch (MatrixException $e) {
            $this->assertSame(302, $e->getCode());
        }
        Http::assertSentCount(1);
    }

    public function testMailboxAccountsKeepIndependentCursorsAndEventClaims()
    {
        [$first] = $this->identity();
        [$second] = $this->identity(null, '@second:example.org');
        foreach ([$first, $second] as $identity) {
            $identity->sync_token = 'previous-'.$identity->id;
            $identity->credentials = ['access_token' => 'account-'.$identity->id];
            $identity->save();
        }
        Http::fake(function ($request) use ($first, $second) {
            $identity = $request->header('Authorization') === ['Bearer account-'.$first->id] ? $first : $second;
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/state')) {
                $state = $this->state(false);
                $state[1]['state_key'] = $identity->user_id;
                return Http::response($state);
            }
            $this->assertSame('previous-'.$identity->id, $request['since']);
            return Http::response($this->batch('next-'.$identity->id, [$this->message('$same-id', 'Mailbox '.$identity->id)]));
        });
        (new Syncer())->run($first);
        (new Syncer())->run($second);
        $this->assertSame('next-'.$first->id, $first->fresh()->sync_token);
        $this->assertSame('next-'.$second->id, $second->fresh()->sync_token);
        $this->assertSame(2, MatrixEvent::where('remote_id', '$same-id')->count());
        foreach ([$first, $second] as $identity) {
            $conversation = Conversation::where('mailbox_id', $identity->mailbox_id)->where('channel', Matrix::CHANNEL)->firstOrFail();
            $this->assertSame($identity->id, $conversation->getMeta('matrix')['identity']);
            $this->assertSame('<p>Mailbox '.$identity->id.'</p>', $conversation->threads()->first()->body);
        }
    }

    /** @dataProvider unexpectedOwnDevices */
    public function testUnverifiedOperationStillChecksTheLocalKeysAndOtherDevices($change)
    {
        [$identity, $account] = $this->identity();
        $identity->status = 'verification';
        $identity->save();
        $keys = $this->keys($account, self::USER, 'TALLPORT');
        $keys['device_keys'][self::USER]['TALLPORT'] = $account->deviceKeys();
        if ($change === 'missing') {
            unset($keys['device_keys'][self::USER]['TALLPORT']);
        } elseif ($change === 'replacement') {
            $keys['device_keys'][self::USER]['TALLPORT'] = Account::create(self::USER, 'TALLPORT')->deviceKeys();
        } else {
            $keys['device_keys'][self::USER]['OTHER'] = Account::create(self::USER, 'OTHER')->deviceKeys();
        }
        Http::fake(['*' => Http::response($keys)]);
        try {
            (new CryptoManager($identity))->trusted(self::USER);
            $this->fail('Unexpected device keys must still require review.');
        } catch (MatrixException $e) {
            $this->assertSame('Matrix device trust needs review.', $e->getMessage());
            $this->assertNotNull(CryptoRecord::read($identity->id, 'trust_review', self::USER));
            $this->assertNull(CryptoRecord::read($identity->id, 'trust', self::USER));
        }
    }

    public static function unexpectedOwnDevices()
    {
        return [['missing'], ['replacement'], ['unsigned peer']];
    }

    public static function deviceStatuses()
    {
        return ['verified' => ['ready'], 'unverified' => ['verification']];
    }

    public function testVerificationNeedsTheLiveCrossSignatureForThisDevice()
    {
        [$identity, $account] = $this->identity();
        $identity->status = 'verification';
        $identity->save();
        $keys = $this->keys($account, self::USER, 'TALLPORT');
        $trusted = \App\Matrix\Crypto\DeviceKeys::crossSigning($keys, self::USER);
        CryptoRecord::write($identity->id, 'verification', 'active', ['version' => 1, 'phase' => 'compare', 'expires' => time() + 100,
            'secret' => Encoding::base64(random_bytes(32)), 'confirmed' => true, 'mac_received' => true,
            'peer_keys' => ['ed25519:'.$trusted['master'] => $trusted['master']]]);
        $response = $keys;
        $device_signature = $response['device_keys'][self::USER]['TALLPORT']['signatures'][self::USER]['ed25519:TALLPORT'];
        $response['device_keys'][self::USER]['TALLPORT']['signatures'][self::USER] = ['ed25519:TALLPORT' => $device_signature];
        Http::fake(function () use (&$response) { return Http::response($response); });
        (new \App\Matrix\Verification($identity))->activate();
        $this->assertSame('verification', $identity->fresh()->status);
        $this->assertTrue($identity->isReady());
        $this->assertFalse($identity->isVerified());
        (new CryptoManager($identity))->trusted(self::USER);
        $response = $keys;
        (new \App\Matrix\Verification($identity))->activate();
        $this->assertSame('ready', $identity->fresh()->status);
        $this->assertTrue($identity->isVerified());
        $this->assertSame($trusted['master'], CryptoRecord::read($identity->id, 'trust', self::USER)['master']);
    }
}
