<?php

namespace App\Providers;

use App\Auth\Login\MarkPasswordConfirmed;
use App\Auth\Login\RedirectIfTwoFactorAuthenticatable;
use App\Auth\Login\RunCustomLoginChecks;
use App\Auth\TrustedDevices;
use App\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

/**
 * Logging in with Laravel Fortify (config/fortify.php): a password, then a
 * two-factor code if the user has two-factor authentication on (unless the
 * device is remembered), or a passkey.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Tallport's routes (routes/web.php): its own login path, and its
        // own password reset and registration.
        Fortify::ignoreRoutes();

        // Five failed logins lock the login for 10 minutes (as before).
        $this->app->bind(\Laravel\Fortify\LoginRateLimiter::class, \App\Auth\LoginRateLimiter::class);
    }

    public function boot()
    {
        Fortify::loginView(function () {
            return view('auth.login');
        });
        Fortify::twoFactorChallengeView(function () {
            return view('auth.two_factor_challenge');
        });
        Fortify::confirmPasswordView(function () {
            return view('auth.confirm_password');
        });

        Fortify::authenticateUsing(function (Request $request) {
            // Called twice per login (before the two-factor step and to log in).
            if (!$request->attributes->has('tallport.login_user')) {
                $request->attributes->set('tallport.login_user', self::userForCredentials($request));
            }

            return $request->attributes->get('tallport.login_user');
        });

        Fortify::authenticateThrough(function () {
            return [
                EnsureLoginIsNotThrottled::class,
                CanonicalizeUsername::class,
                RunCustomLoginChecks::class,
                RedirectIfTwoFactorAuthenticatable::class,
                AttemptToAuthenticate::class,
                PrepareAuthenticatedSession::class,
                MarkPasswordConfirmed::class,
            ];
        });

        // As with a password: only active users, and modules can refuse.
        \Laravel\Passkeys\Passkeys::authorizeLoginUsing(function ($request, $user) {
            return $user && $user->status == User::STATUS_ACTIVE
                && !\Eventy::filter('login.custom_check', [], $request);
        });

        // "Remember this device" on the two-factor code page.
        \Event::listen(\Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided::class, function ($event) {
            MarkPasswordConfirmed::mark(request());
            if (request()->boolean('remember_device')) {
                TrustedDevices::remember($event->user, request());
            }
        });
        \Event::listen(\Laravel\Passkeys\Events\PasskeyVerified::class, function ($event) {
            MarkPasswordConfirmed::mark(request());
        });
        \Event::listen(\Laravel\Fortify\Events\TwoFactorAuthenticationDisabled::class, function ($event) {
            TrustedDevices::forget($event->user);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }

    /**
     * The active user with these credentials. Disabled and deleted users
     * are not found, so they get the same error as a wrong password. The
     * password is checked by App\Auth\EloquentUserProvider (with the
     * session_guard.validate_credentials hook modules use).
     */
    public static function userForCredentials(Request $request)
    {
        $provider = auth()->guard(config('fortify.guard'))->getProvider();
        $credentials = [
            'email'    => (string) $request->input('email'),
            'password' => (string) $request->input('password'),
            'status'   => User::STATUS_ACTIVE,
        ];

        $user = $provider->retrieveByCredentials($credentials);
        if (!$user || !$provider->validateCredentials($user, $credentials)) {
            return null;
        }

        return $user;
    }
}
