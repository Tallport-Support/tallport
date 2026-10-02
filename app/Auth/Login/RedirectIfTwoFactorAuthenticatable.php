<?php

namespace App\Auth\Login;

use App\Auth\TrustedDevices;

/**
 * A login step: users with two-factor authentication on are asked for a
 * code, unless they log in on a device they asked to be remembered.
 */
class RedirectIfTwoFactorAuthenticatable extends \Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);
        if ($user && TrustedDevices::isTrusted($user, $request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
