<?php

namespace App\Auth\Login;

use Illuminate\Validation\ValidationException;

/**
 * A login step: modules can refuse a login with the login.custom_check
 * filter (errors by field).
 */
class RunCustomLoginChecks
{
    public function handle($request, $next)
    {
        $errors = \Eventy::filter('login.custom_check', [], $request);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }
}
