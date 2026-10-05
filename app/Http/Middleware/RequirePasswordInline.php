<?php

namespace App\Http\Middleware;

use App\Auth\PasswordConfirmation;
use Closure;

/**
 * password.confirm without a page of its own: pages open and ask for the password
 * in place (<x-password-gate>); a change made without a recent confirmation goes
 * back to its page (or gets an error, for scripts) instead of being made.
 */
class RequirePasswordInline
{
    public function handle($request, Closure $next)
    {
        if ($request->isMethod('GET') || PasswordConfirmation::isRecent()) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['status' => 'error', 'message' => __('Confirm your password first.'), 'msg' => __('Confirm your password first.')], 423);
        }

        return redirect()->back();
    }
}
