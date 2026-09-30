<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        //
    ];

    /**
     * Do not send the XSRF-TOKEN cookie: it's not needed.
     * https://github.com/laravel/ideas/issues/873
     *
     * @var bool
     */
    protected $addHttpCookie = false;
}
