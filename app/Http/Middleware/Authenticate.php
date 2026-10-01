<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Lets modules act before authentication (auth_middleware.handle hook).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string[]  ...$guards
     * @return mixed
     */
    public function handle($request, Closure $next, ...$guards)
    {
        \Eventy::action('auth_middleware.handle', $request, $guards, $next);

        return parent::handle($request, $next, ...$guards);
    }
}
