<?php

use Laravel\Fortify\Features;

/*
 * Laravel Fortify: logging in with a password and a two-factor code, or with
 * a passkey. Tallport registers the routes itself (routes/web.php) and
 * shows its own views (App\Providers\FortifyServiceProvider).
 */
return [
    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    // Where to go after logging in (unless another page was asked for).
    'home' => '/home',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    // Login attempts are limited by App\Auth\LoginRateLimiter (in the login
    // steps, so the login form shows the error), not by route middleware.
    'limiters' => [
        'login'      => null,
        'two-factor' => 'two-factor',
        'passkeys'   => 'passkeys',
    ],

    'views' => true,

    'passkeys' => [
        'relying_party_id' => parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST),
        'allowed_origins'  => [rtrim((string) env('APP_URL', 'http://localhost'), '/')],
        'timeout'          => 60000,
    ],

    'features' => [
        Features::twoFactorAuthentication([
            'confirm'         => true,
            'confirmPassword' => true,
            // Codes from one step (30 seconds) before or after are accepted,
            // as by FreeScout's Two-Factor Authentication module.
            'window'          => 1,
        ]),
        Features::passkeys([
            'confirmPassword' => true,
        ]),
    ],
];
