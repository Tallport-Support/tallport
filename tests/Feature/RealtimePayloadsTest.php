<?php

namespace Tests\Feature;

use App\Conversation;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * What open pages hear through /polycast/receive: new threads in a
 * conversation and a mailbox, who else is viewing, and notifications, each
 * only for users who may see them.
 */
class RealtimePayloadsTest extends FeatureTestCase
{
    protected $agent;
    protected $outsider;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $this->outsider = $this->createUser(['first_name' => 'Olive', 'last_name' => 'Outsider']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Shop Support']);
    }

    protected function receiveCustomerEmail(array $options = [])
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(array_merge([
            'from'    => 'Casey Customer <casey@customer.example.org>',
            'to'      => $this->mailbox->email,
            'subject' => 'Question about my order',
            'body'    => 'Where is my parcel?',
        ], $options)));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Poll as the browser does: the events of the given channels since a
     * minute ago.
     */
    protected function poll($user, array $channels, array $data = [])
    {
        \Session::start();
        $response = $this->actingAs($user)->post('/polycast/receive', [
            '_token'   => csrf_token(),
            'channels' => $channels,
            'time'     => now()->subMinute()->toDateTimeString(),
            'data'     => $data,
        ]);
        $response->assertOk();

        return $response->json('payloads');
    }

    protected function payloadsOf(array $payloads, $event)
    {
        return array_values(array_filter($payloads, function ($item) use ($event) {
            return $item['event'] == $event;
        }));
    }

    public function testNewThreadInAConversation()
    {
        $conversation = $this->receiveCustomerEmail();
        $thread = $conversation->threads()->first();
        $event = 'App\Events\RealtimeConvNewThread';

        $payloads = $this->payloadsOf($this->poll($this->agent, ['conv.'.$conversation->id => [$event]]), $event);

        $this->assertCount(1, $payloads);
        $payload = $payloads[0]['payload'];
        $this->assertSame($thread->id, $payload['thread_id']);
        $this->assertSame($this->mailbox->id, $payload['mailbox_id']);
        $this->assertStringContainsString('Where is my parcel?', $payload['thread_html']);
        $this->assertEquals(Conversation::STATUS_ACTIVE, $payload['conversation_status']);
        $this->assertSame(Conversation::$status_classes[Conversation::STATUS_ACTIVE], $payload['conversation_status_class']);
        $this->assertSame(Conversation::$status_icons[Conversation::STATUS_ACTIVE], $payload['conversation_status_icon']);

        $payloads = $this->payloadsOf($this->poll($this->outsider, ['conv.'.$conversation->id => [$event]]), $event);
        $this->assertSame([], $payloads[0]['payload'], 'Users who can\'t see the conversation get nothing.');
    }

    public function testNewThreadPayloadForAThreadThatIsGone()
    {
        $this->actingAs($this->agent);

        $this->assertSame([], \App\Events\RealtimeConvNewThread::processPayload((object) ['thread_id' => 999999]));
        $this->auth = null;
        \Auth::logout();
        $this->assertSame([], \App\Events\RealtimeConvNewThread::processPayload((object) ['thread_id' => 1]));
    }

    public function testDraftsAreNotBroadcast()
    {
        $conversation = $this->receiveCustomerEmail();
        $thread = $conversation->threads()->first();
        $thread->state = Thread::STATE_DRAFT;
        \DB::table('polycast_events')->delete();

        \App\Events\RealtimeConvNewThread::dispatchSelf($thread);

        $this->assertSame(0, \DB::table('polycast_events')->count());
    }

    public function testNewThreadInAMailboxRefreshesItsFolders()
    {
        $conversation = $this->receiveCustomerEmail();
        $event = 'App\Events\RealtimeMailboxNewThread';

        $payloads = $this->payloadsOf($this->poll($this->agent, ['mailbox.'.$this->mailbox->id => [$event]]), $event);

        $this->assertNotEmpty($payloads);
        $payload = $payloads[0]['payload'];
        $this->assertSame($this->mailbox->id, $payload['mailbox_id']);
        $this->assertStringContainsString(route('mailboxes.view', ['id' => $this->mailbox->id]), $payload['folders_html']);

        $payloads = $this->payloadsOf($this->poll($this->outsider, ['mailbox.'.$this->mailbox->id => [$event]]), $event);
        $this->assertSame([], $payloads[0]['payload']);
    }

    public function testOthersSeeWhoIsViewingAndReplying()
    {
        $conversation = $this->receiveCustomerEmail();
        $colleague = $this->createUser(['first_name' => 'Cole', 'last_name' => 'League']);
        $this->mailbox->users()->attach($colleague->id);
        $event = 'App\Events\RealtimeConvView';

        // The colleague's page reports they started replying.
        $this->poll($colleague, ['conv' => [$event]], ['conversation_id' => $conversation->id, 'replying' => 0]);
        $this->poll($colleague, ['conv' => [$event]], ['conversation_id' => $conversation->id, 'replying' => 1]);

        $payloads = $this->payloadsOf($this->poll($this->agent, ['conv' => [$event]]), $event);
        $replying = array_values(array_filter($payloads, function ($item) {
            return !empty($item['payload']['replying']);
        }));
        $this->assertCount(1, $replying);
        $this->assertSame($conversation->id, $replying[0]['payload']['conversation_id']);
        $this->assertSame($colleague->id, $replying[0]['payload']['user_id']);
        $this->assertSame('Cole League', $replying[0]['payload']['user_name']);
        $this->assertSame([$colleague->id], array_keys(\Cache::get('conv_view')[$conversation->id]));

        $payloads = $this->payloadsOf($this->poll($this->outsider, ['conv' => [$event]]), $event);
        $this->assertSame([[]], array_values(array_unique(array_column($payloads, 'payload'), SORT_REGULAR)));
    }

    public function testOthersSeeWhenSomeoneStopsViewing()
    {
        $conversation = $this->receiveCustomerEmail();
        $event = 'App\Events\RealtimeConvViewFinish';
        event(new \App\Events\RealtimeConvViewFinish(['conversation_id' => $conversation->id, 'user_id' => $this->agent->id]));

        $payloads = $this->payloadsOf($this->poll($this->agent, ['conv' => [$event]]), $event);
        $this->assertSame($conversation->id, $payloads[0]['payload']['conversation_id']);
        $this->assertSame($this->agent->id, $payloads[0]['payload']['user_id']);

        $payloads = $this->payloadsOf($this->poll($this->outsider, ['conv' => [$event]]), $event);
        $this->assertSame([], $payloads[0]['payload']);
    }

    /**
     * The agent's conversation gets a customer reply: the notifications
     * menu and the browser notification arrive right away.
     */
    protected function notifiedReply()
    {
        $conversation = $this->receiveCustomerEmail(['message_id' => 'first@customer.example.org', 'body' => 'Where is my parcel?']);
        $conversation->user_id = $this->agent->id;
        $conversation->save();
        $this->receiveCustomerEmail(['message_id' => 'second@customer.example.org', 'in_reply_to' => 'first@customer.example.org', 'body' => 'Any news on the parcel?']);
        $event = 'App\Events\RealtimeBroadcastNotificationCreated';

        $payloads = $this->payloadsOf($this->poll($this->agent, ['private-App.User.'.$this->agent->id => [$event]]), $event);

        return [$conversation, $payloads];
    }

    public function testNotificationOfACustomerReply()
    {
        [$conversation, $payloads] = $this->notifiedReply();

        $this->assertCount(1, $payloads);
        $reply = Thread::where('message_id', 'second@customer.example.org')->first();
        $this->assertSame($reply->id, $payloads[0]['payload']['thread_id']);
        $this->assertSame('App\Notifications\BroadcastNotification', $payloads[0]['payload']['type']);
        $data = $payloads[0]['data'];
        $this->assertStringContainsString('Casey Customer replied to conversation #'.$conversation->number, $data['web']['html']);
        $this->assertStringContainsString('/conversation/'.$conversation->id.'#thread-', $data['web']['html']);
        $this->assertStringContainsString('Casey Customer replied', $data['browser']['text']);
        $this->assertStringStartsWith(url('/conversation/'.$conversation->id.'#'), $data['browser']['url']);
        $this->assertStringEndsWith('#thread-'.$reply->id, $data['browser']['url']);
    }

    /**
     * The menu entry previews the new message, as it does after a reload
     * (WebsiteNotification).
     */
    public function testNotificationPreviewsTheNewMessage()
    {
        [$conversation, $payloads] = $this->notifiedReply();

        $html = $payloads[0]['data']['web']['html'];
        $this->assertStringContainsString('Any news on the parcel?', $html);
        $this->assertStringNotContainsString('Where is my parcel?', $html);
    }

    public function testNotificationPayloadIsOnlyFilledForUsersWhoSeeTheMailbox()
    {
        $conversation = $this->receiveCustomerEmail();
        $payload = (object) ['thread_id' => $conversation->threads()->first()->id, 'mediums' => [\App\Subscription::MEDIUM_EMAIL]];

        $this->actingAs($this->outsider);
        $this->assertSame([], \App\Notifications\BroadcastNotification::fetchPayloadData($payload));

        $this->actingAs($this->agent);
        $this->assertSame(['web'], array_keys(\App\Notifications\BroadcastNotification::fetchPayloadData($payload)), 'Only the mediums given.');
        $this->assertSame([], \App\Notifications\BroadcastNotification::fetchPayloadData((object) ['thread_id' => $payload->thread_id, 'mediums' => []]));
    }

    public function testNotificationOfAMentionInTeamChat()
    {
        $colleague = $this->createUser(['first_name' => 'Cole', 'last_name' => 'League']);
        $this->mailbox->users()->attach($colleague->id);
        \Livewire\Livewire::actingAs($colleague)->test(\App\Livewire\TeamChat::class, ['mailbox' => $this->mailbox])
            ->set('body', '@Alex can you take this?')->call('send');
        $event = 'App\Events\RealtimeBroadcastNotificationCreated';

        $payloads = $this->payloadsOf($this->poll($this->agent, ['private-App.User.'.$this->agent->id => [$event]]), $event);

        $this->assertCount(1, $payloads);
        $data = $payloads[0]['data'];
        $this->assertStringContainsString('Cole League mentioned you in Shop Support Team Chat', $data['web']['html']);
        $this->assertStringContainsString('Cole League', $data['browser']['text']);
        $this->assertSame(route('mailboxes.team_chat', ['id' => $this->mailbox->id]), $data['browser']['url']);
    }

    public function testMentionPayloadIsEmptyForAMessageTheUserCannotSee()
    {
        $message = \App\TeamMessage::create(['mailbox_id' => $this->mailbox->id, 'user_id' => $this->agent->id, 'body' => 'Hello']);

        $this->actingAs($this->outsider);

        $this->assertSame([], \App\Notifications\TeamMentionNotification::fetchPayloadData((object) ['team_message_id' => $message->id]));
    }
}
