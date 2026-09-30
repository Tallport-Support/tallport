<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies (all X-Forwarded-*
     * except Prefix, as before).
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Trusted proxies from APP_TRUSTED_PROXIES (config/trustedproxy.php),
     * which fideloper/proxy read before Laravel 9.
     *
     * @return array|string|null
     */
    protected function proxies()
    {
        return $this->proxies ?: config('trustedproxy.proxies');
    }
}
