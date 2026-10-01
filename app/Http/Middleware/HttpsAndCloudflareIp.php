<?php

namespace App\Http\Middleware;

use Closure;

/**
 * Runs first, so everything after it sees the corrected request:
 * - HTTPS is determined by APP_URL (in the installer by the current URL),
 *   not only by what the web server reports, e.g. behind a proxy that
 *   doesn't send X-Forwarded-Proto.
 * - With APP_CLOUDFLARE_IS_USED, the client IP is Cloudflare's
 *   CF-Connecting-IP header.
 */
class HttpsAndCloudflareIp
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $request->server->set('HTTPS', \Helper::isHttps() ? 'on' : 'off');

        $cloudflare_ip = $request->server->get('HTTP_CF_CONNECTING_IP');
        if ($cloudflare_ip
            && config('app.cloudflare_is_used')
            // https://github.com/freescout-help-desk/freescout/security/advisories/GHSA-9cm3-qvj2-8hg4
            && \Helper::isValidIp($cloudflare_ip)
        ) {
            $request->server->set('REMOTE_ADDR', $cloudflare_ip);
            // Code that reads $_SERVER directly gets the same IP.
            $_SERVER['REMOTE_ADDR'] = $cloudflare_ip;
        }

        return $next($request);
    }
}
