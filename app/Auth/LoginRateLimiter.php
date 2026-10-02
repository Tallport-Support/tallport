<?php

namespace App\Auth;

/**
 * Five failed logins (by email address and IP address) lock the login for
 * 10 minutes, as before Fortify (which locks it for one minute).
 */
class LoginRateLimiter extends \Laravel\Fortify\LoginRateLimiter
{
    const DECAY_SECONDS = 600;

    public function increment(\Illuminate\Http\Request $request)
    {
        $this->limiter->hit($this->throttleKey($request), self::DECAY_SECONDS);
    }
}
