<?php

namespace Tests\Feature;

use App\Auth\TrustedDevices;
use App\User;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\FeatureTestCase;

/**
 * Two-factor authentication (Laravel Fortify, App\Providers\FortifyServiceProvider):
 * logging in with a code or recovery code, remembered devices, the
 * requirement, setting it up, the admin reset and importing existing
 * two-factor data.
 */
class TwoFactorTest extends FeatureTestCase
{
    const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function postForm($uri, array $data, $method = 'post')
    {
        \Session::start();

        return $this->$method($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function userWithTwoFactor(array $attributes = [], array $recovery_codes = ['AAAAA-11111', 'BBBBB-22222'])
    {
        $user = $this->createUser(array_merge(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')], $attributes));
        $user->forceFill([
            'two_factor_secret'         => encrypt(self::SECRET),
            'two_factor_recovery_codes' => encrypt(json_encode($recovery_codes)),
            'two_factor_confirmed_at'   => now(),
        ])->save();

        return $user;
    }

    protected function code($secret = self::SECRET)
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    protected function logIn()
    {
        return $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'secret-password']);
    }

    // Logging in.

    public function testPasswordThenCode()
    {
        $user = $this->userWithTwoFactor();

        $this->logIn()->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get('/two-factor-challenge')->assertStatus(200)->assertSee(__('Enter the code from your authenticator app.'));

        $this->postForm('/two-factor-challenge', ['code' => $this->code()])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testWrongCode()
    {
        $this->userWithTwoFactor();
        $this->logIn();

        $this->postForm('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function testRecoveryCodeWorksOnce()
    {
        $user = $this->userWithTwoFactor();
        $this->logIn();

        $this->postForm('/two-factor-challenge', ['recovery_code' => 'AAAAA-11111'])->assertRedirect('/home');

        $this->assertAuthenticatedAs($user);
        $this->assertNotContains('AAAAA-11111', $user->fresh()->recoveryCodes());
        $this->assertCount(2, $user->fresh()->recoveryCodes(), 'Replaced by a new one.');
    }

    public function testDisabledUserIsNotAskedForACode()
    {
        $this->userWithTwoFactor(['status' => User::STATUS_DISABLED]);

        $this->logIn()->assertSessionHasErrors('email');
        $this->assertNull(session('login.id'));
    }

    public function testRememberedDeviceSkipsTheCode()
    {
        $user = $this->userWithTwoFactor();
        $this->logIn();
        $response = $this->postForm('/two-factor-challenge', ['code' => $this->code(), 'remember_device' => '1']);
        $cookie = $response->getCookie(TrustedDevices::cookieName($user));
        $this->assertNotNull($cookie);
        $this->assertSame(1, TrustedDevices::count($user));

        $this->post('/logout', ['_token' => csrf_token()]);
        $this->flushSession();
        $this->assertGuest();

        $this->withCookie(TrustedDevices::cookieName($user), $cookie->getValue());
        $this->logIn()->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testAtMostThreeRememberedDevices()
    {
        $user = $this->userWithTwoFactor();
        for ($i = 0; $i < 5; $i++) {
            TrustedDevices::remember($user, request());
        }

        $this->assertSame(TrustedDevices::MAX, TrustedDevices::count($user));
    }

    // Required.

    public function testRequiredSendsUsersToTurnItOn()
    {
        config(['app.two_factor_required' => true]);
        $user = $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);
        $this->logIn()->assertRedirect('/home');

        $this->actingAs($user)->get('/mailbox/1')->assertRedirect(route('users.security', ['id' => $user->id]));
        $this->actingAs($user)->get(route('users.security', ['id' => $user->id]))->assertStatus(200)
            ->assertSee(__('Two-factor authentication is required. Turn it on to continue.'));
        $this->actingAs($user)->post('/conversation/ajax', ['_token' => csrf_token(), 'action' => 'x'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(403)->assertJson(['msg' => __('Turn on two-factor authentication first.')]);

        $with_two_factor = $this->userWithTwoFactor(['email' => 'other@example.org']);
        $this->actingAs($with_two_factor)->get('/users/profile/'.$with_two_factor->id)->assertStatus(200);
    }

    // Setting it up.

    public function testTurningItOn()
    {
        $user = $this->createUser();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->postForm('/user/two-factor-authentication', [])->assertRedirect();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertFalse($user->hasEnabledTwoFactorAuthentication(), 'Not before a code confirms it.');
        $this->get(route('users.security', ['id' => $user->id]))->assertSee('<svg', false);

        $secret = decrypt($user->two_factor_secret);
        $this->postForm('/user/confirmed-two-factor-authentication', ['code' => $this->code($secret)])->assertRedirect();
        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());

        $codes = $user->fresh()->recoveryCodes();
        $this->get(route('users.security', ['id' => $user->id, 'codes' => 1]))->assertSee($codes[0]);

        $this->postForm('/user/two-factor-recovery-codes', [])->assertRedirect();
        $this->assertNotSame($codes, $user->fresh()->recoveryCodes());

        TrustedDevices::remember($user, request());
        $this->postForm(route('users.security.forget_devices', ['id' => $user->id]), [])->assertRedirect();
        $this->assertSame(0, TrustedDevices::count($user));

        $this->postForm('/user/two-factor-authentication', [], 'delete')->assertRedirect();
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function testPasswordIsConfirmedFirst()
    {
        $user = $this->createUser(['password' => \Hash::make('secret-password')]);
        $this->actingAs($user);

        $this->get(route('users.security', ['id' => $user->id]))->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertStatus(200);
        $this->postForm(route('password.confirm.store'), ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->postForm(route('password.confirm.store'), ['password' => 'secret-password'])->assertRedirect(route('users.security', ['id' => $user->id]));
    }

    public function testAdminResetsAUser()
    {
        $admin = $this->createAdmin();
        $user = $this->userWithTwoFactor();
        TrustedDevices::remember($user, request());

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postForm(route('users.security.reset', ['id' => $user->id]), [])->assertStatus(403);
        $this->get(route('users.security', ['id' => $admin->id]))->assertStatus(403);

        $this->actingAs($admin);
        $this->get(route('users.security', ['id' => $user->id]))->assertStatus(200)->assertSee(__('Reset Two-Factor Authentication and Passkeys'));
        $this->postForm(route('users.security.reset', ['id' => $user->id]), [])->assertRedirect();

        $this->assertFalse($user->fresh()->hasEnabledTwoFactorAuthentication());
        $this->assertSame(0, TrustedDevices::count($user));
    }

    // Passkeys.

    public function testPasskeyEndpoints()
    {
        $this->getJson(route('passkey.login-options'))->assertStatus(200)->assertJsonStructure(['options' => ['challenge']]);
        $this->postJson(route('passkey.login'), ['credential' => []], ['X-CSRF-TOKEN' => csrf_token()])->assertStatus(422);

        $user = $this->createUser();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $this->getJson(route('passkey.registration-options'))->assertStatus(200)->assertJsonStructure(['options' => ['challenge', 'user']]);
        $this->postJson(route('passkey.store'), ['name' => 'Laptop', 'credential' => []], ['X-CSRF-TOKEN' => csrf_token()])->assertStatus(422);

        $passkey = $user->passkeys()->create(['name' => 'Phone', 'credential_id' => 'abc', 'credential' => []]);
        $this->get(route('users.security', ['id' => $user->id]))->assertSee('Phone');
        $this->postForm(route('passkey.destroy', ['passkey' => $passkey->id]), [], 'delete')->assertRedirect();
        $this->assertSame(0, $user->passkeys()->count());
    }

    public function testOnlyActiveUsersSignInWithAPasskey()
    {
        $user = $this->createUser(['status' => User::STATUS_DISABLED]);
        $passkey = $user->passkeys()->create(['name' => 'Phone', 'credential_id' => 'abc', 'credential' => []]);

        $this->assertFalse(\Laravel\Passkeys\Passkeys::allowsLogin(request(), $passkey));

        $user->status = User::STATUS_ACTIVE;
        $user->save();
        $this->assertTrue(\Laravel\Passkeys\Passkeys::allowsLogin(request(), $passkey->fresh()));
    }

    // Importing existing two-factor data.

    /**
     * The migration's steps run directly: creating its source table in a
     * test would commit the test's transaction (MariaDB DDL).
     */
    public function testExistingTwoFactorDataIsImported()
    {
        if (!class_exists('ImportExistingTwoFactorData')) {
            require base_path('database/migrations/2026_10_02_010104_import_existing_two_factor_data.php');
        }
        $user = $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);
        $binary = random_bytes(20);

        // A row of the two_factor_authentications table.
        \ImportExistingTwoFactorData::importUser((object) [
            'user_id'        => $user->id,
            'shared_secret'  => $binary,
            'enabled_at'     => '2025-01-02 03:04:05',
            'recovery_codes' => json_encode([['code' => 'USED01', 'used_at' => '2025-02-01 00:00:00'], ['code' => 'FRESH1', 'used_at' => null]]),
        ]);

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());
        $this->assertSame('2025-01-02 03:04:05', $user->two_factor_confirmed_at->format('Y-m-d H:i:s'));
        $this->assertSame(['FRESH1'], $user->recoveryCodes());

        // Not moved twice (Tallport's is kept).
        $secret = $user->two_factor_secret;
        \ImportExistingTwoFactorData::importUser((object) ['user_id' => $user->id, 'shared_secret' => random_bytes(20), 'enabled_at' => now(), 'recovery_codes' => null]);
        $this->assertSame($secret, $user->fresh()->two_factor_secret);

        // The authenticator app's code still works.
        $this->logIn()->assertRedirect(route('two-factor.login'));
        $this->postForm('/two-factor-challenge', ['code' => $this->code(\ParagonIE\ConstantTime\Base32::encodeUpperUnpadded($binary))])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testOldModuleIsTurnedOff()
    {
        if (!class_exists('ImportExistingTwoFactorData')) {
            require base_path('database/migrations/2026_10_02_010104_import_existing_two_factor_data.php');
        }
        DB::table('modules')->insert(['alias' => 'twofactorauth', 'active' => true]);

        (new \ImportExistingTwoFactorData())->up();

        $this->assertFalse((bool) DB::table('modules')->where('alias', 'twofactorauth')->value('active'));
    }
}
