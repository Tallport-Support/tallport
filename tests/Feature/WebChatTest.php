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

    /**
     * A solved proof of work for a new chat (App\WebChat\Spam), as the window's worker finds it.
     */
    protected function proof($at_least = 0)
    {
        $challenge = \App\WebChat\Spam::challenge($this->mailbox, $at_least);
        $salt = explode('.', $challenge['challenge'])[1];
        for ($nonce = 0; ; $nonce++) {
            $hash = hash('sha256', $salt.':'.$nonce, true);
            $bits = 0;
            foreach (str_split($hash) as $byte) {
                $byte = ord($byte);
                if ($byte == 0) {
                    $bits += 8;
                    continue;
                }
                while (($byte & 0x80) == 0) {
                    $bits++;
                    $byte <<= 1;
                }
                break;
            }
            if ($bits >= $challenge['difficulty']) {
                return $challenge['challenge'].':'.$nonce;
            }
        }
    }

    protected function api($method, $uri, array $data = [], $token = null, array $headers = [])
    {
        // Messages carry the proof a new chat needs (ignored after).
        if ($method == 'POST' && str_starts_with($uri, '/t/m') && !array_key_exists('proof', $data)) {
            $data['proof'] = $this->proof();
        }
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

        // The PHP proxy example passes on every one of them (a path it drops breaks the chat there).
        $this->assertSame(1, preg_match("#preg_match\\('(.+?)', \\\$path\\)#", file_get_contents(base_path('examples/website-chat/proxy/php/index.php')), $example));
        $paths = ['t.js'];
        foreach (\Route::getRoutes() as $route) {
            if (str_starts_with($route->uri(), 't/')) {
                $paths = array_merge($paths, $route->uri() == 't/{name}' ? explode('|', $route->wheres['name']) : [substr($route->uri(), 2)]);
            }
        }
        foreach ($paths as $path) {
            $this->assertSame(1, preg_match($example[1], $path), '/t/'.$path);
        }
    }

    public function testAVisitorWritesAndTheTeamAnswers()
    {
        $token = $this->visitorToken();
        $this->assertSame(0, Visitor::count(), 'Nothing stored before they write.');

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
        $this->assertSame('Chat with Forma, 1 unread message', $poll->json('unread_label'));

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

    /**
     * A website's proxy for /t/: the visitor's address it passes on counts only with the proxy secret.
     */
    public function testAProxyWithItsSecret()
    {
        $secret = WebChat::secret($this->mailbox, 'proxy_secret');
        $ip = fn (array $headers) => WebChat::clientIp(\Illuminate\Http\Request::create('/t/v', 'POST', [], [], [], $headers), $this->mailbox);

        $this->assertSame('203.0.113.7', $ip(['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 10.0.0.2', 'HTTP_X_TALLPORT_PROXY' => $secret, 'REMOTE_ADDR' => '198.51.100.1']));
        $this->assertSame('198.51.100.1', $ip(['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_X_TALLPORT_PROXY' => 'wrong', 'REMOTE_ADDR' => '198.51.100.1']));
        $this->assertSame('198.51.100.1', $ip(['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '198.51.100.1']));
        $this->assertSame('198.51.100.1', $ip(['HTTP_X_FORWARDED_FOR' => 'not-an-ip', 'HTTP_X_TALLPORT_PROXY' => $secret, 'REMOTE_ADDR' => '198.51.100.1']));

        // Each proxied visitor has their own limit.
        for ($i = 0; $i < 30; $i++) {
            $this->api('POST', '/t/v', [], null, ['X-Tallport-Proxy' => $secret, 'X-Forwarded-For' => '203.0.113.7'])->assertOk();
        }
        $this->api('POST', '/t/v', [], null, ['X-Tallport-Proxy' => $secret, 'X-Forwarded-For' => '203.0.113.7'])->assertStatus(429);
        $this->api('POST', '/t/v', [], null, ['X-Tallport-Proxy' => $secret, 'X-Forwarded-For' => '203.0.113.8'])->assertOk();

        // Made and rotated on the settings page.
        \Session::start();
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $this->mailbox->id]))->assertSee('Proxy Secret');
        $this->actingAs($this->admin)->post(route('mailboxes.web_chat.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'action' => 'rotate_proxy_secret'])->assertRedirect();
        $this->assertNotSame($secret, WebChat::secret($this->mailbox->fresh(), 'proxy_secret'));
    }

    /**
     * The greeting: Tallport's, in the visitor's language and by name once signed in; the
     * mailbox's own, by language, with its variables; or none.
     */
    public function testGreetings()
    {
        WebChat::saveSettings($this->mailbox, ['greeting_mode' => 'tallport']);
        $this->get('/t/w?widget=forma')->assertSee('Hi, how can we help?');
        $token = $this->visitorToken();
        $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-42', 'name' => 'Lena Novak'])], $token)->assertOk();
        $this->assertSame('Hi Lena, how can we help?', $this->api('GET', '/t/m', [], $token)->json('greeting'));

        WebChat::saveSettings($this->mailbox, ['greeting_mode' => 'custom', 'greeting' => 'Hello {%customer.firstName,fallback=there%}, welcome to {%mailbox.name%}.', 'greetings' => ['nl' => 'Hallo {%customer.firstName,fallback=daar%}!']]);
        $this->assertSame('Hello Lena, welcome to Forma.', $this->api('GET', '/t/m', [], $token)->json('greeting'));
        $this->assertSame('Hallo Lena!', $this->api('GET', '/t/m', [], $token, ['Accept-Language' => 'nl-NL'])->json('greeting'));
        $this->get('/t/w?widget=forma')->assertSee('Hello there, welcome to Forma.');
        $this->get('/t/w?widget=forma', ['Accept-Language' => 'nl'])->assertSee('Hallo daar!');

        WebChat::saveSettings($this->mailbox, ['greeting_mode' => 'none']);
        $this->assertSame('', $this->api('GET', '/t/m', [], $token)->json('greeting'));

        // Before there was a choice: a written greeting is the own one, none is Tallport's.
        WebChat::saveSettings($this->mailbox, ['greeting_mode' => null, 'greeting' => '']);
        $this->assertSame('tallport', WebChat::settings($this->mailbox->fresh())['greeting_mode']);

        // Versions by language on the settings page.
        \Session::start();
        $save = fn (array $data) => $this->actingAs($this->admin)->post(route('mailboxes.web_chat.save', ['id' => $this->mailbox->id]), ['_token' => csrf_token(), 'origins' => self::SITE] + $data);
        $save(['greeting_mode' => 'custom', 'greeting' => 'Hi!', 'add_language' => 1, 'add_language_code' => 'de'])->assertRedirect();
        $this->assertSame(['de' => ''], WebChat::settings($this->mailbox->fresh())['greetings']);
        $save(['greeting_mode' => 'custom', 'greeting' => 'Hi!', 'greetings' => ['de' => 'Hallo!']]);
        $this->assertSame(['de' => 'Hallo!'], WebChat::settings($this->mailbox->fresh())['greetings']);
        $save(['greeting_mode' => 'custom', 'greeting' => 'Hi!', 'greetings' => ['de' => 'Hallo!'], 'remove_language' => 'de']);
        $this->assertSame([], WebChat::settings($this->mailbox->fresh())['greetings']);
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $this->mailbox->id]))->assertOk()->assertSee('Your Own Greeting');
    }

    /**
     * The browser's first language is the customer's, until detected in what they write; an
     * agent's choice stands.
     */
    public function testTheBrowsersLanguage()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello', 'language' => 'en-GB'], $token)->assertOk();
        $customer = Visitor::firstOrFail()->customer;
        $this->assertSame('en', $customer->language);
        $this->assertTrue((bool) $customer->getMeta(\App\Ai\ChatTranslation::FROM_BROWSER));

        // Detected in their message: that one.
        \App\Ai\ChatTranslation::setCustomerLanguage($customer, 'nl');
        $customer->refresh();
        $this->assertSame('nl', $customer->language);
        $this->assertNull($customer->getMeta(\App\Ai\ChatTranslation::FROM_BROWSER));
        // And then no detection replaces it.
        \App\Ai\ChatTranslation::setCustomerLanguage($customer, 'de');
        $this->assertSame('nl', $customer->fresh()->language);

        // An agent's choice: the browser doesn't change it.
        $other = $this->createCustomer('casey@customer.example.org');
        \App\Ai\ChatTranslation::setCustomerLanguage($other, 'fr', true);
        \App\Ai\ChatTranslation::setCustomerLanguageFromBrowser($other, 'de-DE');
        $this->assertSame('fr', $other->fresh()->language);

        // Signing in, for a customer without one; regions map to Tallport's languages.
        $this->api('POST', '/t/i', ['identity' => $this->signed(['sub' => 'acct-9']), 'language' => 'zh-TW'])->assertOk();
        $this->assertSame('zh-Hant', Customer::getCustomerByChannel(WebChat::CHANNEL, 's:acct-9')->language);
    }

    /**
     * Images, by their contents, show as thumbnails and open in the browser; anything else downloads.
     */
    public function testImagesAsThumbnails()
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['files' => [
            UploadedFile::fake()->createWithContent('photo.png', $png),
            UploadedFile::fake()->createWithContent('drawing.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('fake.png', '<html><script>alert(1)</script></html>'),
        ]], $token)->assertOk();
        $files = collect($this->api('GET', '/t/m?after=0', [], $token)->json('messages.0.files'))->keyBy('name');

        $this->assertNotNull($files['photo.png']['thumbnail']);
        $this->get('/t/'.$files['photo.png']['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'inline; filename=photo.png');
        $this->get('/t/'.$files['photo.png']['thumbnail'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        foreach ($files->except('photo.png') as $name => $file) {
            $this->assertNull($file['thumbnail'], $name);
            $this->assertStringStartsWith('attachment', $this->get('/t/'.$file['url'])->assertOk()->headers->get('Content-Disposition'), $name);
        }
    }

    /**
     * The mailbox's image beside its name and on its own messages (the greeting too), an agent's
     * photo on theirs, both from beside the window; their initials without them.
     */
    public function testTeamImages()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Is the shop open today?'], $token)->assertOk();
        $conversation = Visitor::firstOrFail()->conversation;
        $this->reply($conversation);
        // Without an agent (a workflow's, say): the mailbox's own.
        \DB::table('threads')->where('id', $this->reply($conversation, 'Closed on Sundays.')->id)->update(['created_by_user_id' => null]);
        $this->travel(Conversation::UNDO_TIMOUT + 1)->seconds();
        $team = function () use ($token) {
            return array_map(fn ($message) => [$message['team'], $message['author'], $message['initials'], $message['photo']], array_slice($this->api('GET', '/t/m?after=0', [], $token)->json('messages'), 1));
        };

        $this->get('/t/w?widget=forma')->assertSee('<span class="f-avatar widget-team-mark" aria-hidden="true">F</span>', false);
        $this->assertSame([[false, 'Alex', 'A', null], [true, 'Forma', 'F', null]], $team());

        $this->mailbox->savePhoto(UploadedFile::fake()->image('forma.png', 300, 300));
        $this->mailbox->save();
        $this->agent->photo_url = $this->agent->savePhoto(UploadedFile::fake()->image('alex.jpg', 100, 100));
        $this->agent->save();
        $mailbox_photo = 'a?m='.$this->mailbox->photo_url;
        $agent_photo = 'a?u='.$this->agent->photo_url;

        $this->get('/t/w?widget=forma')->assertSee('<span class="f-avatar widget-team-mark" aria-hidden="true"><img src="'.$mailbox_photo.'" alt=""></span>', false)
            ->assertSee('teamPhoto', false);
        $this->assertSame([[false, 'Alex', 'A', $agent_photo], [true, 'Forma', 'F', $mailbox_photo]], $team());
        foreach ([$mailbox_photo, $agent_photo] as $photo) {
            $this->get('/t/'.$photo)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        }
        $this->get('/t/a?m=../users/'.$this->agent->photo_url)->assertNotFound();
        $this->get('/t/a?u=missing.jpg')->assertNotFound();
        $this->get('/t/a')->assertNotFound();

        // Set with the mailbox; its Website Chat settings show it and link there.
        $this->actingAs($this->admin)->get(route('mailboxes.web_chat', ['id' => $this->mailbox->id]))
            ->assertSee($this->mailbox->getPhotoUrl())->assertSee(route('mailboxes.update', ['id' => $this->mailbox->id]));
    }

    /**
     * Nothing is stored until someone writes, and a new chat needs its proof of work.
     */
    public function testNothingStoredUntilAProvenFirstMessage()
    {
        $token = $this->visitorToken();
        $this->api('GET', '/t/m?after=0', [], $token)->assertOk()->assertJson(['messages' => []]);
        $this->api('POST', '/t/r', ['thread_id' => 1], $token)->assertOk();
        $this->assertSame(0, Visitor::count());

        $this->api('POST', '/t/m', ['body' => 'Hello', 'proof' => ''], $token)->assertStatus(428)->assertJsonStructure(['challenge' => ['challenge', 'difficulty']]);
        $proof = $this->proof();
        $this->api('POST', '/t/m', ['body' => 'Hello', 'proof' => $proof], $token)->assertOk();
        $this->assertSame(1, Visitor::count());

        // A proof works once; a first message the moment the window loaded needs a harder one.
        $other = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hi', 'proof' => $proof], $other)->assertStatus(428);
        $harder = $this->api('POST', '/t/m', ['body' => 'Hi', 'elapsed' => 300], $other)->assertStatus(428)->json('challenge.difficulty');
        $this->assertSame(\App\WebChat\Spam::DIFFICULTY + 4, $harder);
        $this->api('POST', '/t/m', ['body' => 'Hi', 'elapsed' => 300, 'proof' => $this->proof($harder)], $other)->assertOk();
        $this->getJson('/t/p?widget=forma')->assertOk()->assertJson(['difficulty' => \App\WebChat\Spam::DIFFICULTY]);

        // A field only bots fill in (a password manager might): a harder proof, so a person still gets through.
        $filled = $this->visitorToken();
        $this->assertSame(\App\WebChat\Spam::DIFFICULTY + 4, $this->api('POST', '/t/m', ['body' => 'Hi', 'website' => 'https://lena.example'], $filled)->assertStatus(428)->json('challenge.difficulty'));
        $this->api('POST', '/t/m', ['body' => 'Hi', 'website' => 'https://lena.example', 'proof' => $this->proof(\App\WebChat\Spam::DIFFICULTY + 4)], $filled)->assertOk();
        $this->assertSame(3, Conversation::count());
    }

    /**
     * The widget's hourly limit on new chats: the button goes for new visitors; those who write keep their chat.
     */
    public function testNewChatsLimit()
    {
        WebChat::saveSettings($this->mailbox, ['limit_new_chats' => 2]);
        $first = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'One'], $first)->assertOk();
        $this->api('POST', '/t/m', ['body' => 'Two'], $this->visitorToken())->assertOk();

        $this->api('POST', '/t/m', ['body' => 'Three'], $this->visitorToken())->assertStatus(503)->assertJson(['unavailable' => true]);
        $this->assertFalse(\App\WebChat\Spam::available($this->mailbox));
        $this->get('/t/w?widget=forma')->assertSee('\u0022available\u0022:false', false);
        $this->api('POST', '/t/m', ['body' => 'Still here'], $first)->assertOk();
        $this->assertSame(2, Conversation::count());
    }

    /**
     * Writing too fast, or too many files: how long to wait.
     */
    public function testWaitingOverALimit()
    {
        $token = $this->visitorToken();
        for ($i = 0; $i < \App\WebChat\Spam::VISITOR_PER_MINUTE; $i++) {
            $this->api('POST', '/t/m', ['body' => 'Message '.$i], $token)->assertOk();
        }
        $wait = $this->api('POST', '/t/m', ['body' => 'One more'], $token)->assertStatus(429)->json();
        $this->assertSame('messages', $wait['kind']);
        $this->assertGreaterThan(0, $wait['wait']);

        WebChat::saveSettings($this->mailbox, ['limit_uploads_mb' => 1]);
        $other = $this->visitorToken();
        $file = fn () => UploadedFile::fake()->createWithContent('notes.txt', str_repeat('a', 600 * 1024));
        $this->api('POST', '/t/m', ['files' => [$file()]], $other)->assertOk();
        $this->assertSame('files', $this->api('POST', '/t/m', ['files' => [$file()]], $other)->assertStatus(429)->json('kind'));
    }

    /**
     * Block Visitor (or marking as spam): their messages, and those from their address, are taken
     * and dropped; unblocked, they count again.
     */
    public function testBlockingAVisitor()
    {
        $token = $this->visitorToken();
        $this->api('POST', '/t/m', ['body' => 'Hello'], $token)->assertOk();
        $conversation = Visitor::firstOrFail()->conversation;

        \Livewire\Livewire::actingAs($this->agent)->test(\App\Livewire\ConversationToolbar::class, ['conversation' => $conversation])->call('toggleBlockVisitor');
        $this->assertTrue(\App\WebChat\Spam::isBlocked($conversation));
        $this->api('POST', '/t/m', ['body' => 'Spam'], $token)->assertOk()->assertJson(['message' => ['id' => null]]);
        $this->api('POST', '/t/m', ['body' => 'Spam from a new visitor'], $this->visitorToken())->assertOk()->assertJson(['message' => ['id' => null]]);
        $this->assertSame(1, \App\Thread::where('type', Thread::TYPE_CUSTOMER)->count());

        \Livewire\Livewire::actingAs($this->agent)->test(\App\Livewire\ConversationToolbar::class, ['conversation' => $conversation])->call('toggleBlockVisitor');
        $this->api('POST', '/t/m', ['body' => 'Sorry'], $token)->assertOk()->assertJsonPath('message.body', 'Sorry');
        $this->assertNotNull($this->api('GET', '/t/m', [], $token)->json('messages.1.id'));

        // Marked as spam: blocked too.
        \App\Misc\ConversationActions::changeStatus($conversation->fresh(), Conversation::STATUS_SPAM, $this->agent, request());
        $this->assertTrue(\App\WebChat\Spam::isBlocked($conversation));
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
        $this->actingAs($this->agent)->get('/conversation/'.$visitor->conversation_id)->assertSee('Plan:')->assertSee('Pro')->assertDontSee('ignored');

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

        // The PHP example in examples/website-chat signs tokens Tallport takes.
        require_once base_path('examples/website-chat/sign-in/sign.php');
        $this->assertSame('acct-1', \App\WebChat\Identity::verify(tallport_token(['sub' => 'acct-1'], $secret), $secret)['sub']);

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
