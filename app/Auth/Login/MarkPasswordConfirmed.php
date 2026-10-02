<?php

namespace App\Auth\Login;

/**
 * A login step: the password was just entered, so pages that ask for it
 * again (password.confirm: the security page) don't, for a while.
 */
class MarkPasswordConfirmed
{
    public function handle($request, $next)
    {
        self::mark($request);

        return $next($request);
    }

    public static function mark($request)
    {
        $request->session()->put('auth.password_confirmed_at', time());
    }
}
