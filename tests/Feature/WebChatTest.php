<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Thread;
use App\WebChat\Visitor;
use App\WebChat\WebChat;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * Website chat (App\WebChat): the window a website frames, its API (a visitor token, no cookies),
 * the mailbox's settings, and the conversation it makes.
 */
class WebChatTest extends FeatureTestCase
{
    const SITE = 'https://www.forma.example';

    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser(['first_name' => 'Alex']);
        $this->mailbox = $this->createMailbox([$this->agent], ['name' => 'Forma']);
        WebChat::saveSettings($this->mailbox, [
            'enabled' => true, 'widget' => 'forma', 'origins' => [self::SITE], 'greeting' => 'Hi there.', 'reply_time' => 'We usually reply within an hour', 'accent' => 'green',
        ]);
        \Illuminate\Support\Facades\RateLimiter::clear('web-chat:start:127.0.0.1');
    }

    protected function api($method, $uri, array $data = [], $token = null, array $headers = [])
    {
        $headers = array_merge(['Accept' => 'application/json'], $token ? ['Authorization' => 'Bearer '.$token] : [], $headers);
        $uri .= (str_contains($uri, '?') ? '&' : '?').'widget=forma';

        return $method == 'GET' ? $this->getJson($uri, $headers) : $this->post($uri, $data, $headers);
    }

    protected function visitorToken()
    {
        return $this->api('POST', '/t/v')->assertOk()->json('token');
    }

    protected function reply(Conversation $conversation, $body = 'Alex here, looking into it.', array $fields = [])
    {
        $this->sendReply($this->agent, array_merge(['mailbox_id' => $this->mailbox->id, 'conversation_id' => $conversation->id, 'body' => $body], $fields));

        return $conversation->threads()->orderBy('id', 'desc')->first();
    }

    public function testTheWindowIsOnlyForItsWebsites()
    {
        $response = $this->get('/t/w?widget=forma&origin='.urlencode(self::SITE.'/'), ['Accept-Language' => 'nl-NL,nl;q=0.9']);
        $response->assertOk()->assertSee('Forma')->assertSee('We usually reply within an hour')->assertSee('data-fruit-accent="green"', false)
            ->assertSee('Chat met Forma')->assertHeaderMissing('X-Frame-Options')->assertHeaderMissing('Set-Cookie');
        $this->assertStringContainsString("frame-ancestors 'self' ".self::SITE, $response->headers->get('Content-Security-Policy'));

        // Opened on its own (the website's link).
        $this->get('/t/w?widget=forma')->assertOk();

        $this->get('/t/w?widget=forma&origin='.urlencode('https://elsewhere.example'))->assertNotFound()->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $this->get('/t/w?widget=unknown')->assertNotFound();
        WebChat::saveSettings($this->mailbox, ['enabled' => false]);
        $this->get('/t/w?widget=forma')->assertNotFound();
    }

    public function testTheWindowsFilesAreBesideIt()
    {
        $this->get('/t/s')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8')->assertSee('.widget-bubble', false);
        $this->get('/t/j')->assertOk()->assertSee('tallportWebChat', false);
        $this->assertFileExists(public_path('t/t.js'));
    }

    public function testAVisitorWritesAndTheTeamAnswers()
    {
        $token = $this->visitorToken();
        $this->assertNull(Visitor::firstOrFail()->customer_id, 'No customer before they write.');

        $first = $this->api('POST', '/t/m', ['body' => "Where is my order?\n#1042", 'page' => self::SITE.'/orders#top'], $token)->assertOk()->json('message');
        $this->assertSame('visitor', $first['kind']);
        $this->assertSame("Where is my order?\n#1042", $first['body']);

        $visitor = Visitor::firstOrFail();
        $conversation = $visitor->conversation;
        $this->assertSame(WebChat::CHANNEL, (int) $conversation->channel);
        $this->assertSame('Website', $conversation->getChannelName());
        $this->assertSame('Where is my order? #1042', $conversation->subject);
        $this->assertSame(self::SITE.'/orders', $visitor->page);
        $this->assertSame($conversation->customer_id, Customer::getCustomerByChannel(WebChat::CHANNEL, 'v'.$visitor->id)->id);

        // The next message continues it, with a file.
        $this->api('POST', '/t/m', ['files' => [UploadedFile::fake()->createWithContent('receipt.pdf', '%PDF-1.4 receipt')]], $token)->assertOk();
        $this->assertSame(2, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count());
        $this->assertTrue((bool) $conversation->fresh()->has_attachments);

        // The team's reply: no email, and shown once it can't be undone; notes never.
        $reply = $this->reply($conversation);
        $this->reply($conversation, 'Internal: check the warehouse', ['is_note' => true]);
        $this->assertCount(0, $this->sentEmails());
        $this->assertEquals(\App\SendLog::STATUS_ACCEPTED, $reply->fresh()->send_status);
        $poll = $this->api('GET', '/t/m?after=0', [], $token)->assertOk();
        $this->assertSame(['visitor', 'visitor'], array_column($poll->json('messages'), 'kind'));
        $this->assertLessThan($reply->id, $poll->json('cursor'), 'The reply that can still be undone comes later.');

        $this->travel(Conversation::UNDO_TIMOUT + 1)->seconds();
        $poll = $this->api('GET', '/t/m?after='.$first['id'], [], $token)->assertOk();
        $messages = $poll->json('messages');
        $this->assertSame(['visitor', 'team'], array_column($messages, 'kind'));
        $this->assertSame(['Alex', 'A', 'Alex here, looking into it.'], [$messages[1]['author'], $messages[1]['initials'], $messages[1]['body']]);
        $this->assertSame('receipt.pdf', $messages[0]['files'][0]['name']);
        // Shown at when the visitor could see it: once Undo had passed.
        $this->assertSame($reply->created_at->copy()->addSeconds(Conversation::UNDO_TIMOUT)->toIso8601String(), $messages[1]['time']);
        $this->assertSame(1, $poll->json('unread'));

        // Read: the unread count goes.
        $this->api('POST', '/t/r', ['thread_id' => $reply->id], $token)->assertOk();
        $this->assertSame(0, $this->api('GET', '/t/m?after=0', [], $token)->json('unread'));

        // Its file, by the link's own token (a link can't carry the visitor's).
        $file = $messages[0]['files'][0];
        $this->get('/t/'.$file['url'])->assertOk();
        $this->get('/t/f?id='.$file['id'].'&token=wrong')->assertNotFound();

        // The agent sees the page in the customer panel.
        $this->actingAs($this->agent)->get('/conversation/'.$conversation->id)->assertSee('www.forma.example/orders');
    }

    public function testOnlyTheVisitorsOwnConversation()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello'], $token)->assertOk();

        $this->api('GET', '/t/m', [], 'not-a-token-'.str_repeat('x', 40))->assertUnauthorized();
        $this->api('GET', '/t/m')->assertUnauthorized();
        $other = $this->visitorToken();
        $this->assertSame([], $this->api('GET', '/t/m?after=0', [], $other)->json('messages'));

        // Another mailbox's widget doesn't know the token.
        $mailbox = $this->createMailbox();
        WebChat::saveSettings($mailbox, ['enabled' => true, 'widget' => 'other', 'origins' => []]);
        $this->getJson('/t/m?widget=other', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();

        // Only from the window: Tallport's own origin or an allowed website serving /t/ itself.
        $this->api('POST', '/t/v', [], null, ['Origin' => 'https://elsewhere.example'])->assertForbidden();
        $this->api('POST', '/t/v', [], null, ['Origin' => self::SITE])->assertOk();
    }

    public function testMessagesAreChecked()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => '  '], $token)->assertStatus(422);
        $this->api('POST', '/t/m', ['body' => str_repeat('a', WebChat::MAX_LENGTH + 1)], $token)->assertStatus(422);
        $this->api('POST', '/t/m', ['files' => array_fill(0, WebChat::MAX_FILES + 1, UploadedFile::fake()->create('a.pdf', 1))], $token)->assertStatus(422);
        $this->assertSame(0, Conversation::count());
    }

    /**
     * A conversation continues within the mailbox's Reopen Window; after it, a new one starts.
     */
    public function testANewConversationAfterTheReopenWindow()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'First'], $token)->assertOk();
        Conversation::query()->update(['created_at' => now()->subDays(31), 'last_reply_at' => now()->subDays(31)]);
        $this->api('POST', '/t/m', ['body' => 'Much later'], $token)->assertOk();

        $this->assertSame(2, Conversation::where('channel', WebChat::CHANNEL)->count());
        $this->assertSame(1, Conversation::where('channel', WebChat::CHANNEL)->distinct()->count('customer_id'), 'The same customer.');
        $this->assertSame(['Much later'], array_column($this->api('GET', '/t/m?after=0', [], $token)->json('messages'), 'body'));
    }

    public function testStartingVisitorsIsLimited()
    {
        for ($i = 0; $i < 30; $i++) {
            $this->api('POST', '/t/v')->assertOk();
        }
        $this->api('POST', '/t/v')->assertStatus(429);
    }

    public function testSettings()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Studio']);
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $mailbox->id]))->assertOk()->assertDontSee("tallport('init'");
        $this->actingAs($this->agent)->get(route('mailboxes.web_chat', ['id' => $mailbox->id]))->assertForbidden();

        \Session::start();
        $this->actingAs($this->admin)->post(route('mailboxes.web_chat.save', ['id' => $mailbox->id]), [
            '_token' => csrf_token(), 'enabled' => 1, 'origins' => "studio.example\nhttps://www.studio.example:443/shop\nhttp://localhost:8080", 'greeting' => ' Hello! ', 'accent' => 'purple',
        ])->assertRedirect();
        $settings = WebChat::settings($mailbox->fresh());
        $this->assertTrue($settings['enabled']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $settings['widget']);
        $this->assertSame(['https://studio.example', 'https://www.studio.example', 'http://localhost:8080'], $settings['origins']);
        $this->assertSame(['Hello!', 'purple'], [$settings['greeting'], $settings['accent']]);
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $mailbox->id]))->assertSee("tallport('init', { widget: '".$settings['widget']."' });");

        $this->actingAs($this->admin)->post(route('mailboxes.web_chat.save', ['id' => $mailbox->id]), ['_token' => csrf_token(), 'origins' => 'not a website'])->assertSessionHasErrors('origins');
        $this->assertSame($settings['widget'], WebChat::settings($mailbox->fresh())['widget'], 'The widget ID stays.');
    }

    public function testRetentionAndMerging()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello'], $token)->assertOk();
        $visitor = Visitor::firstOrFail();

        $other = $this->createCustomer('casey@customer.example.org');
        $other->mergeWith($visitor->customer, $this->admin);
        $this->assertSame($other->id, $visitor->fresh()->customer_id);

        \App\Option::set('retention_enabled', true);
        $this->travel(13)->months();
        \App\Retention\Retention::deleteVisitors();
        $this->assertNull($visitor->fresh());
    }
}
