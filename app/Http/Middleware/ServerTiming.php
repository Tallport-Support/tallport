<?php

namespace App\Http\Middleware;

use Closure;

/**
 * A Server-Timing header for signed-in users: how long the server took (app), of
 * which starting PHP and Laravel before the request was handled (boot), and its
 * database queries (db), as the browser's network panel shows them.
 */
class ServerTiming
{
    protected static $listening = false;

    protected static $db_time = 0;

    protected static $db_count = 0;

    public function handle($request, Closure $next)
    {
        if (!self::$listening) {
            self::$listening = true;
            \DB::listen(function ($query) {
                self::$db_time += $query->time;
                self::$db_count++;
            });
        }
        self::$db_time = 0;
        self::$db_count = 0;
        $start = $request->server('REQUEST_TIME_FLOAT') ?: LARAVEL_START;
        $handling = microtime(true);

        $response = $next($request);

        if (auth()->check()) {
            $response->headers->set('Server-Timing', sprintf(
                'app;dur=%.1f, boot;dur=%.1f, db;dur=%.1f;desc="%d queries"',
                (microtime(true) - $start) * 1000,
                ($handling - $start) * 1000,
                self::$db_time,
                self::$db_count
            ));
        }

        return $response;
    }
}
