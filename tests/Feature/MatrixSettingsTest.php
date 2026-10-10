<?php

namespace Tests\Feature;

use App\Livewire\MatrixSettings;
use App\Matrix\Crypto\Account;
use App\Matrix\CryptoRecord;
use App\Matrix\MatrixEvent;
use App\Matrix\MatrixMailbox;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\FeatureTestCase;

class MatrixSettingsTest extends FeatureTestCase
{
    public function testTransportDiagnosticsDoNotLogRequestSecrets()
    {
        \Log::spy();
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: private-password private-token'));
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $this->createMailbox()->id])
            ->set('homeserver', 'matrix.example.org')->call('checkHomeserver')
            ->assertSee('Could not reach the Matrix homeserver. Check the connection and try again.')->assertDontSee('private-password')->assertDontSee('private-token');
        $entry = \App\ActivityLog::where('log_name', 'matrix')->firstOrFail();
        $this->assertSame('connection', $entry->properties['type']);
        $this->assertStringNotContainsString('private-password', $entry->toJson());
        $this->assertStringNotContainsString('private-token', $entry->toJson());
        \Log::shouldHaveReceived('error')->with('Matrix transport failed.', ['method' => 'GET', 'path' => '/_matrix/client/versions',
            'exception' => \Illuminate\Http\Client\ConnectionException::class, 'curl_error' => 28])->once();
        \Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'Matrix settings failed.' && $context['reason'] === 'Matrix request failed.')->once();
    }

    public function testBlockedDnsAddressIsExplainedAndAnExplicitExceptionAllowsTheHomeserverCheck()
    {
        \Helper::$resolver = fn ($host) => ['198.19.94.180'];
        config(['app.remote_host_white_list' => '']);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://matrix.example.org/_matrix/client/versions' => Http::response(['versions' => ['v1.11']]),
            'https://matrix.example.org/_matrix/client/v3/login' => Http::response(['flows' => [['type' => 'm.login.password']]]),
        ]);
        $component = Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $this->createMailbox()->id])
            ->set('homeserver', 'matrix.example.org')->call('checkHomeserver')
            ->assertSee('Domain or IP address is not allowed: 198.19.94.180.')
            ->assertDontSee('Password login and Matrix v1.11')->assertDontSee('matrix-password');
        Http::assertNothingSent();
        config(['app.remote_host_white_list' => '198.19.94.180']);
        $component->call('checkHomeserver')->assertSet('error', false)->assertSee('matrix-password');
        Http::assertSentCount(2);
        \Helper::$resolver = fn ($host) => ['198.19.94.181'];
        $component->call('changeHomeserver')->call('checkHomeserver')
            ->assertSee('Domain or IP address is not allowed: 198.19.94.181.')->assertDontSee('matrix-password');
        Http::assertSentCount(2);
    }

    /** @dataProvider unsupportedHomeservers */
    public function testCredentialsStayHiddenUntilTheHomeserverSupportsLogin($versions, $flows, $status)
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://matrix.example.org/_matrix/client/versions' => Http::response(['versions' => $versions], $status),
            'https://matrix.example.org/_matrix/client/v3/login' => Http::response(['flows' => $flows]),
        ]);
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $this->createMailbox()->id])
            ->assertDontSee('matrix-password')->set('homeserver', 'matrix.example.org')->call('checkHomeserver')
            ->assertSet('checked_homeserver', null)->assertDontSee('matrix-password')
            ->assertSee($status === 503 ? 'The Matrix homeserver could not complete the request. Try again later. (HTTP 503)' : 'Password login and Matrix v1.11 or newer are required.');
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/versions'));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public static function unsupportedHomeservers()
    {
        return [
            'SSO only' => [['v1.11'], [['type' => 'm.login.sso']], 200],
            'older server' => [['v1.10'], [['type' => 'm.login.password']], 200],
            'unreachable server' => [[], [], 503],
        ];
    }

    public function testChangingTheHomeserverRequiresAnotherCheckBeforeSendingCredentials()
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake([
            'https://matrix.example.org/_matrix/client/versions' => Http::response(['versions' => ['v1.11']]),
            'https://matrix.example.org/_matrix/client/v3/login' => Http::response(['flows' => [['type' => 'm.login.password']]]),
        ]);
        $component = Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $this->createMailbox()->id])
            ->set('homeserver', 'matrix.example.org')->set('matrix_user', 'support')
            ->call('connect', 'secret')->assertSet('error', true)->assertDontSee('matrix-password');
        Http::assertNothingSent();
        $component->call('checkHomeserver')->assertSet('error', false)->assertSee('matrix-password')
            ->call('changeHomeserver')->assertSet('checked_homeserver', null)->assertDontSee('matrix-password')
            ->call('checkHomeserver')->set('homeserver', 'other.example.org')->call('connect', 'secret')
            ->assertSet('checked_homeserver', null)->assertSet('error', true)->assertDontSee('matrix-password');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), 'other.example.org'));
        $this->assertSame(0, MatrixMailbox::count());
    }

    public function testSettingsRequireMailboxPermissionAndLinkFromChat()
    {
        $admin = $this->createAdmin();
        $user = $this->createUser();
        $mailbox = $this->createMailbox();
        $url = '/mailbox/settings/'.$mailbox->id.'/matrix';
        $this->get($url)->assertRedirect();
        $this->actingAs($user)->get($url)->assertForbidden();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Homeserver URL')->assertSeeLivewire(MatrixSettings::class);
        $pages = \App\Misc\MailboxSettings::pages($mailbox, $admin);
        $matrix = collect($pages)->firstWhere('label', 'Matrix');
        $this->assertTrue($matrix['chat']);
        $this->assertSame(route('mailboxes.matrix', ['id' => $mailbox->id]), $matrix['url']);
        Livewire::actingAs($user)->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])->assertForbidden();
    }

    public function testPasswordIsNeverStoredInTheComponentAndErrorsCannotEchoServerSecrets()
    {
        \Log::spy();
        $admin = $this->createAdmin();
        $mailbox = $this->createMailbox();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/versions')) {
                return Http::response(['versions' => ['v1.11']]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['flows' => [['type' => 'm.login.password']]]);
            }
            return Http::response(['errcode' => 'M_FORBIDDEN', 'error' => 'private-password'], 403);
        });
        Livewire::actingAs($admin)->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])
            ->set('homeserver', 'https://matrix.example.org')->call('checkHomeserver')->set('matrix_user', '@support:example.org')
            ->call('connect', 'private-password')->assertSee('The homeserver rejected the login. Check the Matrix account and password. (HTTP 403, M_FORBIDDEN)')
            ->assertDontSee('Check the Matrix connection and device verification.')->assertDontSee('private-password');
        $this->assertSame(0, MatrixMailbox::count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/login') && $request->method() === 'POST' && $request['password'] === 'private-password');
        \Log::shouldHaveReceived('error')->with('Matrix request failed.', ['method' => 'POST', 'path' => '/_matrix/client/v3/login', 'status' => 403, 'code' => 'M_FORBIDDEN'])->once();
        \Log::shouldHaveReceived('error')->with('Matrix settings failed.', ['mailbox_id' => $mailbox->id, 'exception' => \App\Matrix\MatrixException::class, 'code' => 403,
            'reason' => 'Matrix M_FORBIDDEN.'])->once();
    }

    /** @dataProvider homeserverErrors */
    public function testHomeserverErrorsExplainTheFailureWithoutEchoingResponseSecrets($status, $code, $message, $path = 'login')
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($status, $code, $path) {
            if (str_ends_with($request->url(), '/versions')) {
                return Http::response(['versions' => ['v1.11']]);
            }
            if ($request->method() === 'GET') {
                return Http::response(['flows' => [['type' => 'm.login.password']]]);
            }
            if ($path !== 'login' && str_ends_with($request->url(), '/login')) {
                return Http::response(['user_id' => '@support:example.org', 'device_id' => $request['device_id'], 'access_token' => 'private-token']);
            }
            return Http::response(['errcode' => $code, 'error' => '<script>private-password private-token</script>'], $status);
        });
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $this->createMailbox()->id])
            ->set('homeserver', 'matrix.example.org')->call('checkHomeserver')->set('matrix_user', 'support')
            ->call('connect', 'private-password')->assertSee($message)
            ->assertDontSee('Check the Matrix connection and device verification.')
            ->assertDontSee('private-password')->assertDontSee('private-token');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/'.$path));
        $entry = \App\ActivityLog::where('log_name', 'matrix')->firstOrFail();
        $this->assertStringNotContainsString('private-password', $entry->toJson());
        $this->assertStringNotContainsString('private-token', $entry->toJson());
    }

    public static function homeserverErrors()
    {
        return [
            'deactivated account' => [403, 'M_USER_DEACTIVATED', 'The Matrix account has been deactivated. Contact the homeserver administrator. (HTTP 403, M_USER_DEACTIVATED)'],
            'rate limited' => [429, 'M_LIMIT_EXCEEDED', 'The homeserver is rate limiting requests. Wait a moment and try again. (HTTP 429, M_LIMIT_EXCEEDED)'],
            'server error' => [500, 'M_UNKNOWN', 'The Matrix homeserver could not complete the request. Try again later. (HTTP 500, M_UNKNOWN)'],
            'expired session' => [401, 'M_UNKNOWN_TOKEN', 'The Matrix session is no longer valid. Sign in again. (HTTP 401, M_UNKNOWN_TOKEN)'],
            'unknown code' => [400, 'M_CUSTOM_ERROR', 'The Matrix homeserver rejected the request. (HTTP 400, M_CUSTOM_ERROR)'],
            'unsafe code' => [400, '<script>private-token</script>', 'The Matrix homeserver rejected the request. (HTTP 400)'],
            'proxy error' => [502, null, 'The Matrix homeserver could not complete the request. Try again later. (HTTP 502)'],
            'keys rejected after successful login' => [403, 'M_FORBIDDEN', 'The Matrix homeserver rejected the request. (HTTP 403, M_FORBIDDEN)', 'keys/upload'],
            'additional authentication is not an expired session' => [401, null, 'The Matrix homeserver rejected the request. (HTTP 401)', 'keys/upload'],
        ];
    }

    public function testExpiredSessionFromBackgroundSyncIsExplainedWhenOpeningSettings()
    {
        $mailbox = $this->createMailbox();
        $identity = MatrixMailbox::create(['mailbox_id' => $mailbox->id, 'active_mailbox_id' => $mailbox->id,
            'homeserver' => 'https://matrix.example.org', 'user_id' => '@support:example.org', 'user_hash' => hash('sha256', '@support:example.org'),
            'device_id' => 'DEVICE', 'status' => 'ready', 'credentials' => ['access_token' => 'private-token', 'refresh_token' => 'private-refresh', 'expires_at' => 1]]);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://matrix.example.org/_matrix/client/v3/refresh' => Http::response(['errcode' => 'M_UNKNOWN_TOKEN', 'error' => 'private-refresh'], 401)]);
        (new \App\Jobs\SyncMatrixMailbox($identity->id))->handle();
        $this->assertSame('login', $identity->fresh()->status);
        Livewire::actingAs($this->createAdmin())->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])
            ->assertSee('The Matrix session is no longer valid. Sign in again. (HTTP 401, M_UNKNOWN_TOKEN)')
            ->assertDontSee('Check the homeserver address.')->assertDontSee('private-refresh')->assertDontSee('private-token');
        Http::assertSentCount(1);
    }

    public function testResetUsesANewSendingDeviceAndKeepsReceivedKeys()
    {
        $admin = $this->createAdmin();
        $mailbox = $this->createMailbox();
        $identity = MatrixMailbox::create(['mailbox_id' => $mailbox->id, 'active_mailbox_id' => $mailbox->id,
            'homeserver' => 'https://matrix.example.org', 'user_id' => '@support:example.org', 'user_hash' => hash('sha256', '@support:example.org'),
            'device_id' => 'OLD', 'status' => 'ready', 'credentials' => ['access_token' => 'private-access-token']]);
        Livewire::withoutLazyLoading();
        Livewire::actingAs($admin)->test(\App\Livewire\SystemStatus::class)
            ->assertSee($identity->user_id)->assertSee('Last successful sync')->assertDontSee('private-access-token');
        $account = Account::create($identity->user_id, 'OLD');
        CryptoRecord::write($identity->id, 'account', 'device', $account->export());
        CryptoRecord::write($identity->id, 'inbound', 'received', ['session_key' => 'kept']);
        CryptoRecord::write($identity->id, 'outbound', 'sending', ['session_key' => 'discarded']);
        $pending = MatrixEvent::outgoing($identity->id, 'pending', 'outgoing', ['ciphertext' => 'old']);
        Livewire::actingAs($admin)->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])
            ->assertSee('Reset device')->assertDontSee('private-access-token')
            ->call('toggleEnabled')->assertSee('Disabled')->call('toggleEnabled')->assertSee('Connected')
            ->call('resetDevice')->assertSee('Homeserver URL');
        $identity->refresh();
        $this->assertNotSame('OLD', $identity->device_id);
        $this->assertSame('login', $identity->status);
        $this->assertSame([], $identity->credentials);
        $this->assertSame(['session_key' => 'kept'], CryptoRecord::read($identity->id, 'inbound', 'received'));
        $this->assertNull(CryptoRecord::read($identity->id, 'outbound', 'sending'));
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertNotSame($account->curveKey(), Account::restore(CryptoRecord::read($identity->id, 'account', 'device'))->curveKey());
        $component = Livewire::actingAs($admin)->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id]);
        $this->actingAs($this->createUser());
        $component->call('resetDevice')->assertForbidden();
        Http::fake(['*' => Http::response([])]);
        Livewire::actingAs($admin)->test(MatrixSettings::class, ['mailbox_id' => $mailbox->id])->call('disconnect');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/logout'));
        $this->assertNull(MatrixMailbox::forMailbox($mailbox->id));
    }
}
