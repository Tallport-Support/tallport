<?php

namespace App\Http\Middleware;

use Closure;

/**
 * With two-factor authentication required (config app.two_factor_required),
 * users who haven't turned it on can only do that (and log out).
 */
class RequireTwoFactor
{
    /**
     * What a user without two-factor authentication can still use.
     */
    const ALLOWED_ROUTES = [
        'users.security', 'two-factor.*', 'password.confirm', 'password.confirm.store', 'password.confirmation',
        'passkey.*', 'logout',
    ];

    public function handle($request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !self::mustTurnOn($user) || $request->routeIs(self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['status' => 'error', 'msg' => __('Turn on two-factor authentication first.')], 403);
        }

        return redirect()->route('users.security', ['id' => $user->id]);
    }

    public static function mustTurnOn($user)
    {
        return config('app.two_factor_required') && !$user->hasEnabledTwoFactorAuthentication();
    }
}
