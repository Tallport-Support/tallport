<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider as BaseEloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Laravel's Eloquent user provider, with a hook that lets modules decide
 * whether a password is valid (session_guard.validate_credentials filter,
 * e.g. for LDAP).
 */
class EloquentUserProvider extends BaseEloquentUserProvider
{
    /**
     * Check the password, then let modules overrule the result.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  array  $credentials
     * @return bool
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        return (bool) \Eventy::filter('session_guard.validate_credentials', parent::validateCredentials($user, $credentials), $user, $credentials);
    }
}
