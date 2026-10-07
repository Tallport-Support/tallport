<?php

namespace Tests\Feature;

use App\Api\ApiKey;
use App\Api\Webhook;
use App\Api\WebhookLog;
use App\Conversation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

/**
 * Webhooks: events as the API's JSON, signed, tried again when they fail;
 * Settings » API & Webhooks; webhooks through the API; Workflows.
 */
class WebhooksTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;
    protected $env_dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent, $this->admin], ['name' => 'Support']);

        // Settings write to the environment file: a temporary one.
        $this->env_dir = sys_get_temp_dir().'/tallport-env-'.uniqid();
        mkdir($this->env_dir);
        file_put_contents($this->env_dir.'/.env.testing', "APP_TIMEZONE=UTC\n");
        $this->app->useEnvironmentPath($this->env_dir);
    }

    protected function tearDown(): void
    {
        try {
            @unlink($this->env_dir.'/.env.testing');
            @rmdir($this->env_dir);
        } finally {
            parent::tearDown();
        }
    }

    protected function webhook($events, $mailboxes = null)
    {
        $webhook = new Webhook();
        $webhook->url = 'https://hooks.example.org/in';
        $webhook->events = $events;
        $webhook->mailboxes = $mailboxes;
        $webhook->save();

        return $webhook;
    }

    protected function conversation()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail([
            'from' => 'Casey Customer <casey@customer.example.org>', 'to' => $this->mailbox->email, 'subject' => 'Question',
        ]));

        return Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
    }

    /**
     * Events sent: [event => payload].
     */
    protected function sent()
    {
        return Http::recorded()->mapWithKeys(function ($pair) {
            return [$pair[0]->header('X-FreeScout-Event')[0] => json_decode($pair[0]->body(), true)];
        })->all();
    }

    public function testEventsAreSentSigned()
    {
        Http::fake(['hooks.example.org/*' => Http::response('ok')]);
        $this->webhook(['convo.created', 'customer.created', 'convo.status', 'convo.assigned', 'convo.note.created', 'convo.deleted_forever']);

        $conversation = $this->conversation();
        $conversation->changeUser($this->agent->id, $this->admin);
        $conversation->changeStatus(Conversation::STATUS_CLOSED, $this->admin);

        $sent = $this->sent();
        $this->assertEqualsCanonicalizing(['customer.created', 'convo.created', 'convo.assigned', 'convo.status'], array_keys($sent));
        $this->assertSame('Question', $sent['convo.created']['subject']);
        $this->assertSame('customer', $sent['convo.created']['_embedded']['threads'][0]['type']);
        $this->assertSame('active', $sent['convo.created']['status'], 'As it was at the event.');
        $this->assertSame('closed', $sent['convo.status']['status']);
        $this->assertContains('casey@customer.example.org', Http::recorded()->map(function ($pair) {
            return json_decode($pair[0]->body(), true)['_embedded']['emails'][0]['value'] ?? null;
        })->all());

        Http::assertSent(function (Request $request) {
            return $request->method() == 'POST'
                && $request->header('Content-Type')[0] == 'application/json'
                && $request->header('X-FreeScout-Signature')[0] === base64_encode(hash_hmac('sha1', $request->body(), md5(config('app.key').'webhook_key'), true));
        });
        $this->assertSame('', (string) Webhook::first()->last_run_error);

        $id = $conversation->id;
        $conversation->deleteForever();
        $this->assertSame($id, $this->sent()['convo.deleted_forever']['id'], 'Sent while the conversation still existed.');
    }

    public function testMailboxesAndEvents()
    {
        Http::fake();
        $other = $this->createMailbox([], ['name' => 'Other']);
        $this->webhook(['convo.created'], [$other->id]);

        $this->conversation();

        Http::assertNothingSent();
    }

    public function testFailedDeliveriesAreTriedAgain()
    {
        Http::fake(['hooks.example.org/*' => Http::sequence()->push('no', 500)->push('no', 503)->push('ok', 200)->whenEmpty(Http::response('down', 500))]);
        $webhook = $this->webhook(['customer.created']);

        \App\Customer::createWithoutEmail(['first_name' => 'Robin']);

        // Sync queue: the retries (2, 4 minutes later) run at once.
        $log = WebhookLog::first();
        $this->assertSame('customer.created', $log->event);
        $this->assertSame(2, $log->attempts, 'Two failures, then delivered.');
        $this->assertTrue($log->finished);
        $this->assertSame(200, $log->status_code);
        $this->assertSame('', (string) $webhook->fresh()->last_run_error);

        // Then the receiver is down.
        \App\Customer::createWithoutEmail(['first_name' => 'Sam']);
        $log = WebhookLog::orderBy('id', 'desc')->first();
        $this->assertSame(Webhook::MAX_ATTEMPTS, $log->attempts);
        $this->assertTrue($log->finished);
        $this->assertSame('Response status code: 500', $webhook->fresh()->last_run_error);

        // Kept for 3 days.
        $log->updated_at = now()->subDays(4);
        $log->save();
        $this->artisan('model:prune', ['--model' => [WebhookLog::class]]);
        $this->assertSame(1, WebhookLog::count());
    }

    public function testWebhooksThroughTheApi()
    {
        $key = ['X-FreeScout-API-Key' => ApiKey::globalKey()];

        $response = $this->json('POST', '/api/webhooks', ['url' => 'https://hooks.example.org/a', 'events' => 'convo.created,nonsense', 'mailboxes' => [$this->mailbox->id]], $key)->assertStatus(201);
        $response->assertJsonPath('events', ['convo.created'])->assertJsonPath('mailboxes', [$this->mailbox->id]);
        $id = $response->headers->get('Resource-ID');
        $this->json('POST', '/api/webhooks', ['url' => 'not a url', 'events' => ['convo.created']], $key)->assertStatus(400);
        $this->json('GET', '/api/webhooks', [], $key)->assertJsonPath('_embedded.webhooks.0.url', 'https://hooks.example.org/a');

        $user_key = ApiKey::generate($this->agent, 'Agent', ApiKey::ABILITY_WRITE)[1];
        $this->json('GET', '/api/webhooks', [], ['X-FreeScout-API-Key' => $user_key])->assertStatus(403);

        $this->json('DELETE', '/api/webhooks/'.$id, [], $key)->assertStatus(204);
        $this->assertSame(0, Webhook::count());
    }

    public function testSettings()
    {
        \Session::start();
        $this->actingAs($this->admin);
        $key = ApiKey::generate($this->agent, 'Script', ApiKey::ABILITY_READ)[0];

        $this->get(route('settings', ['section' => 'api']))->assertOk()
            ->assertSee(ApiKey::globalKey())->assertSee(Webhook::secret())->assertSee('Script')->assertSee('convo.customer.reply.created');

        $this->post(route('settings.save', ['section' => 'api']), ['_token' => csrf_token(), 'settings' => ['api.cors_hosts' => 'https://app.example.org']])->assertRedirect();
        $this->assertStringContainsString('APIWEBHOOKS_CORS_HOSTS=https://app.example.org', file_get_contents($this->env_dir.'/.env.testing'));

        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'regenerate_key'])->assertRedirect();
        $this->assertMatchesRegularExpression('/APIWEBHOOKS_API_KEY_SALT=\w{10}/', file_get_contents($this->env_dir.'/.env.testing'));

        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'save_webhook', 'url' => 'https://hooks.example.org/b', 'events' => ['convo.created'], 'mailboxes' => [$this->mailbox->id]]);
        $webhook = Webhook::first();
        $this->assertSame([$this->mailbox->id], $webhook->mailboxes);
        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'save_webhook', 'webhook_id' => $webhook->id, 'url' => 'https://hooks.example.org/c', 'events' => ['convo.status']]);
        $this->assertSame(['convo.status'], $webhook->fresh()->events);
        $this->assertNull($webhook->fresh()->mailboxes);
        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'save_webhook', 'url' => 'https://hooks.example.org/d', 'events' => ['nonsense']])->assertSessionHasErrors('events.0');

        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'revoke_key', 'key_id' => $key->id]);
        $this->assertNull(ApiKey::find($key->id));
        $this->post(route('settings.api.action'), ['_token' => csrf_token(), 'action' => 'delete_webhook', 'webhook_id' => $webhook->id]);
        $this->assertSame(0, Webhook::count());

        $this->actingAs($this->agent)->get(route('settings', ['section' => 'api']))->assertStatus(403);
    }

    public function testWorkflowsCanTriggerWebhooks()
    {
        Http::fake();
        $this->webhook(['custom.vip']);
        $conversation = $this->conversation();

        $this->assertSame('Trigger Webhook', \App\Workflows\Actions::item('webhook', $this->mailbox->id)['title']);

        $workflow = new \App\Workflow();
        $workflow->mailbox_id = $this->mailbox->id;
        $workflow->name = 'VIP';
        $workflow->type = \App\Workflow::TYPE_MANUAL;
        $workflow->active = true;
        $workflow->setActions([[['type' => 'webhook', 'value' => 'custom.vip']]]);
        $workflow->save();
        $this->assertContains('custom.vip', \App\Api\Webhook::allEvents());

        \App\Workflows\Runner::runManual($workflow, [$conversation], $this->agent);
        $this->assertSame($conversation->id, $this->sent()['custom.vip']['id']);
    }

    /**
     * A webhook's recent deliveries are listed under it: the status, green when delivered.
     */
    public function testRecentDeliveriesAreListed()
    {
        $webhook = $this->webhook(['convo.created']);
        foreach ([[200, true, 1, ''], [500, false, 3, 'Response status code: 500']] as [$status, $finished, $attempts, $error]) {
            $log = new WebhookLog();
            $log->forceFill(['webhook_id' => $webhook->id, 'event' => 'convo.created', 'status_code' => $status, 'finished' => $finished, 'attempts' => $attempts, 'error' => $error])->save();
        }

        $html = $this->actingAs($this->admin)->get(route('settings', ['section' => 'api']))->assertOk()
            ->assertSee('Failed deliveries (2)')->assertSee('Response status code: 500')->assertSee('3 / '.Webhook::MAX_ATTEMPTS)->getContent();
        $this->assertMatchesRegularExpression('/f-badge--success[^>]*>\s*200/', $html);
        $this->assertMatchesRegularExpression('/f-badge--danger[^>]*>\s*500/', $html);
        $this->assertSame($webhook->id, WebhookLog::first()->webhook->id);
    }
}
