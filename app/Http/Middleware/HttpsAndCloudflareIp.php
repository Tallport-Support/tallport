<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Runs first, so everything after it sees the corrected request:
 * - HTTPS is determined by APP_URL (in the installer by the current URL),
 *   not only by what the web server reports, e.g. behind a proxy that
 *   doesn't send X-Forwarded-Proto.
 * - A request from Cloudflare has the visitor's IP in its CF-Connecting-IP
 *   header. From anywhere else the header is ignored: anyone could send it.
 */
class HttpsAndCloudflareIp
{
    /**
     * Cloudflare's addresses: https://www.cloudflare.com/ips/
     */
    const CLOUDFLARE_IPS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Whether this request's IP is the visitor's from Cloudflare (System Status).
     */
    public static $visitor_ip_from_cloudflare = false;

    /**
     * Correct HTTPS and the client IP before anything else runs.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $request->server->set('HTTPS', \Helper::isHttps() ? 'on' : 'off');

        self::$visitor_ip_from_cloudflare = false;
        $cloudflare_ip = $request->server->get('HTTP_CF_CONNECTING_IP');
        if ($cloudflare_ip
            && self::isCloudflare((string) $request->server->get('REMOTE_ADDR'))
            // https://github.com/freescout-help-desk/freescout/security/advisories/GHSA-9cm3-qvj2-8hg4
            && \Helper::isValidIp($cloudflare_ip)
        ) {
            $request->server->set('REMOTE_ADDR', $cloudflare_ip);
            // Code that reads $_SERVER directly gets the same IP.
            $_SERVER['REMOTE_ADDR'] = $cloudflare_ip;
            self::$visitor_ip_from_cloudflare = true;
        }

        return $next($request);
    }

    public static function isCloudflare($ip)
    {
        return $ip !== '' && IpUtils::checkIp($ip, self::CLOUDFLARE_IPS);
    }
}
