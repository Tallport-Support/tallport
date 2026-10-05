<?php

namespace App\Auth;

/**
 * "Confirmed the password recently" (auth.password_timeout, as Laravel's
 * password.confirm): pages with extra-security settings show them only then,
 * and ask for the password in place otherwise (<x-password-gate>).
 */
class PasswordConfirmation
{
    public static function isRecent()
    {
        $confirmed_at = (int) session('auth.password_confirmed_at', 0);

        return $confirmed_at && time() - $confirmed_at < (int) config('auth.password_timeout', 10800);
    }

    public static function mark()
    {
        session()->put('auth.password_confirmed_at', time());
    }
}
