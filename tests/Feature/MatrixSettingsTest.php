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
            ->assertSet('error', true)->assertDontSee('private-password')->assertDontSee('private-token');
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
            ->assertSet('checked_homeserver', null)->assertSet('error', true)->assertDontSee('matrix-password')
            ->assertSee('Password login and Matrix v1.11 or newer are required.');
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
            ->call('connect', 'private-password')->assertSet('error', true)->assertDontSee('private-password');
        $this->assertSame(0, MatrixMailbox::count());
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/login') && $request->method() === 'POST' && $request['password'] === 'private-password');
        \Log::shouldHaveReceived('error')->with('Matrix request failed.', ['method' => 'POST', 'path' => '/_matrix/client/v3/login', 'status' => 403, 'code' => 'M_FORBIDDEN'])->once();
        \Log::shouldHaveReceived('error')->with('Matrix settings failed.', ['mailbox_id' => $mailbox->id, 'exception' => \App\Matrix\MatrixException::class, 'code' => 403,
            'reason' => 'Matrix M_FORBIDDEN.'])->once();
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
