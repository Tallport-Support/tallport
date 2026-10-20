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

    protected function signed(array $claims, $secret = null)
    {
        return \App\WebChat\Identity::sign($claims + ['exp' => time() + 600], $secret ?? \App\WebChat\Identity::secret($this->mailbox));
    }

    /**
     * Signed in on the website: what they wrote moves to their account, and on another browser
     * they get their chat back; someone else on the same browser starts afresh.
     */
    public function testSignedInVisitors()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello, before signing in'], $token)->assertOk();
        $anonymous = Visitor::firstOrFail()->customer;

        $identity = $this->signed(['sub' => 'acct-42', 'email' => 'Lena@Example.org', 'name' => 'Lena Novak', 'data' => ['plan' => 'Pro', 'nested' => ['ignored']]]);
        $this->assertNull($this->api('POST', '/t/i', ['identity' => $identity], $token)->assertOk()->json('token'), 'The same visitor.');
        $visitor = Visitor::firstOrFail();
        $customer = $visitor->customer;
        $this->assertSame(['lena@example.org', 'Lena', 'Novak'], [$customer->getMainEmail(), $customer->first_name, $customer->last_name]);
        $this->assertNull(Customer::find($anonymous->id), 'Merged into their account.');
        $this->assertSame($customer->id, $visitor->conversation->customer_id);
        $this->assertSame($customer->id, Customer::getCustomerByChannel(WebChat::CHANNEL, 's:acct-42')->id);
        $this->actingAs($this->agent)->get('/conversation/'.$visitor->conversation_id)->assertSee('plan:')->assertSee('Pro')->assertDontSee('ignored');

        // Another browser: their chat back.
        $phone = $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-42'])])->assertOk()->json('token');
        $this->assertSame(['Hello, before signing in'], array_column($this->api('GET', '/t/m?after=0', [], $phone)->json('messages'), 'body'));

        // Someone else on the first browser: a new visitor, nothing of Lena's.
        $other = $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-7'])], $token)->assertOk()->json('token');
        $this->assertNotNull($other);
        $this->assertSame([], $this->api('GET', '/t/m?after=0', [], $other)->json('messages'));

        // An address the website vouches for finds the customer with it.
        $casey = $this->createCustomer('casey@customer.example.org');
        $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-9', 'email' => 'casey@customer.example.org'])])->assertOk();
        $this->assertSame($casey->id, Customer::getCustomerByChannel(WebChat::CHANNEL, 's:acct-9')->id);
    }

    public function testOnlyValidSignaturesCount()
    {
        $secret = \App\WebChat\Identity::secret($this->mailbox);
        $customers = Customer::count();
        foreach ([
            'wrong secret' => $this->signed(['sub' => 'a'], 'another-secret'),
            'expired'      => $this->signed(['sub' => 'a', 'exp' => time() - 3600]),
            'too long'     => $this->signed(['sub' => 'a', 'exp' => time() + 3 * 86400]),
            'no exp'       => \App\WebChat\Identity::sign(['sub' => 'a'], $secret),
            'no sub'       => $this->signed(['email' => 'a@example.org']),
            'alg none'     => rtrim(strtr(base64_encode('{"alg":"none"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode(json_encode(['sub' => 'a', 'exp' => time() + 60])), '+/', '-_'), '=').'.',
            'garbage'      => 'not.a.token',
        ] as $case => $identity) {
            $this->api('POST', '/t/i', ['identity' => $identity])->assertStatus(422);
        }
        $this->assertSame($customers, Customer::count(), 'Nobody matched or made.');

        // Rotated: the old secret no longer signs.
        $old = $this->signed(['sub' => 'a']);
        \Session::start();
        $this->actingAs($this->admin)->post(route('mailboxes.web_chat.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'action' => 'rotate_secret'])->assertRedirect();
        $this->assertNotSame($secret, \App\WebChat\Identity::secret($this->mailbox->fresh()));
        $this->api('POST', '/t/i', ['identity' => $old])->assertStatus(422);
        $this->assertTrue(WebChat::settings($this->mailbox->fresh())['enabled'], 'Rotating changes nothing else.');
    }

    protected function codeFrom($email)
    {
        $sent = collect($this->sentEmailsTo($email))->last();
        $this->assertNotNull($sent, 'A code was sent to '.$email);
        preg_match('/\b(\d{6})\b/', $sent->getSubject(), $match);

        return $match[1];
    }

    /**
     * "Get the reply by email": the address counts once the code from it is entered.
     */
    public function testEmailCodes()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/e', ['email' => 'lena@example.org'], $token)->assertStatus(422)->assertJson(['message' => 'Write a message first.']);
        $this->api('POST', '/t/m', ['body' => 'My secret order details'], $token)->assertOk();

        $this->api('POST', '/t/e', ['email' => 'not an address'], $token)->assertStatus(422);
        $this->api('POST', '/t/e', ['email' => 'Lena@Example.org'], $token)->assertOk()->assertJson(['pending' => 'lena@example.org', 'codes_left' => 2, 'email' => null]);
        $sent = collect($this->sentEmailsTo('lena@example.org'))->last();
        $this->assertStringNotContainsString('secret order', $sent->getBody(), 'Only the code and the team.');
        $this->assertStringContainsString('Forma', $sent->getBody());
        $code = $this->codeFrom('lena@example.org');

        // Not yet: the address doesn't count, and a wrong code says so.
        $this->assertNull($this->api('GET', '/t/m', [], $token)->json('email'));
        $this->api('POST', '/t/c', ['code' => $code == '000000' ? '111111' : '000000'], $token)->assertStatus(422);

        // Send Again: three codes in all; the latest works.
        $this->api('POST', '/t/e', ['email' => 'lena@example.org'], $token)->assertOk()->assertJson(['codes_left' => 1]);
        $this->api('POST', '/t/e', ['email' => 'lena@example.org'], $token)->assertOk()->assertJson(['codes_left' => 0]);
        $this->api('POST', '/t/e', ['email' => 'lena@example.org'], $token)->assertStatus(422);
        $this->api('POST', '/t/c', ['code' => $code], $token)->assertStatus(422);
        $this->api('POST', '/t/c', ['code' => $this->codeFrom('lena@example.org')], $token)->assertOk()->assertJson(['email' => 'lena@example.org', 'pending' => null]);

        $visitor = Visitor::firstOrFail();
        $this->assertSame('lena@example.org', WebChat::email($visitor->customer));
        $this->assertSame('lena@example.org', $visitor->conversation->customer_email);
        $this->assertSame('lena@example.org', $this->api('GET', '/t/m', [], $token)->json('email'));
    }

    public function testAConfirmedAddressFindsItsCustomer()
    {
        $casey = $this->createCustomer('casey@customer.example.org');
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello'], $token)->assertOk();
        $this->api('POST', '/t/e', ['email' => 'casey@customer.example.org'], $token)->assertOk();
        $this->api('POST', '/t/c', ['code' => $this->codeFrom('casey@customer.example.org')], $token)->assertOk();

        $this->assertSame($casey->id, Visitor::firstOrFail()->customer_id);
        $this->assertSame($casey->id, Visitor::firstOrFail()->conversation->customer_id);

        // An address row left without its customer is taken over.
        \DB::table('emails')->insert(['email' => 'left@example.org', 'customer_id' => 999999]);
        $left = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hi'], $left)->assertOk();
        $this->api('POST', '/t/e', ['email' => 'left@example.org'], $left)->assertOk();
        $this->api('POST', '/t/c', ['code' => $this->codeFrom('left@example.org')], $left)->assertOk()->assertJson(['email' => 'left@example.org']);

        // Unconfirmed addresses are forgotten when their code expires.
        $other = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hi'], $other)->assertOk();
        $this->api('POST', '/t/e', ['email' => 'someone@example.org'], $other)->assertOk();
        $this->travel(61)->minutes();
        $this->assertSame(1, \App\WebChat\EmailCode::forgetExpired());
        $this->assertNull(Visitor::whereNotNull('pending_email')->first());
    }

    /**
     * A reply the visitor doesn't see also goes by email; answering it moves the conversation to
     * email, and writing in the chat brings it back.
     */
    public function testUnseenRepliesGoByEmail()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-42', 'email' => 'lena@example.org', 'name' => 'Lena Novak'])], $token)->assertOk();
        $this->api('POST', '/t/m', ['body' => 'Where is my order?'], $token)->assertOk();
        $visitor = Visitor::firstOrFail();
        $conversation = $visitor->conversation;

        // While they're around (the reply's own job ran at once here): no email.
        $reply = $this->reply($conversation, 'It ships tomorrow.');
        $this->assertCount(0, $this->sentEmailsTo('lena@example.org'));

        // Gone from the website: by email.
        Visitor::query()->update(['last_seen_at' => now()->subMinutes(10)]);
        (new \App\Jobs\EmailWebChatReply($reply->id))->handle();
        $emails = $this->sentEmailsTo('lena@example.org');
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('It ships tomorrow.', $emails[0]->getBody());

        // Seen in the chat: not again.
        $visitor->refresh()->update(['read_thread_id' => $reply->id]);
        (new \App\Jobs\EmailWebChatReply($reply->id))->handle();
        $this->assertCount(1, $this->sentEmailsTo('lena@example.org'));

        // Answered by email: the conversation is an email conversation now, still in the chat.
        $message_id = trim((string) $emails[0]->getHeaders()->get('Message-ID')->getFieldBody(), '<>');
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Lena Novak <lena@example.org>', 'to' => $this->mailbox->email, 'subject' => 'Re: Where is my order?',
            'in_reply_to' => $message_id, 'body' => 'Thanks, tomorrow is fine.',
        ]));
        $conversation->refresh();
        $this->assertNull($conversation->channel);
        $this->assertSame(2, $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->count());
        $this->travel(Conversation::UNDO_TIMOUT + 1)->seconds();
        $this->assertContains('Thanks, tomorrow is fine.', array_column($this->api('GET', '/t/m', [], $token)->json('messages'), 'body'));

        // Back in the chat.
        $this->api('POST', '/t/m', ['body' => 'One more thing'], $token)->assertOk();
        $this->assertSame(WebChat::CHANNEL, (int) $conversation->fresh()->channel);
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
