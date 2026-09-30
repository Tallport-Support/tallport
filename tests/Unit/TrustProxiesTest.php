<?php

namespace Tests\Unit;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * X-Forwarded-* headers are only believed from proxies listed in
 * APP_TRUSTED_PROXIES (config trustedproxy.proxies).
 */
class TrustProxiesTest extends TestCase
{
    protected function handle($trusted_proxies)
    {
        config(['trustedproxy.proxies' => $trusted_proxies]);
        $request = Request::create('http://tallport.test/', 'GET', [], [], [], [
            'REMOTE_ADDR'            => '10.0.0.5',
            'HTTP_X_FORWARDED_FOR'   => '203.0.113.9',
            'HTTP_X_FORWARDED_HOST'  => 'proxied.example.org',
        ]);

        return $this->app->make(TrustProxies::class)->handle($request, function ($request) {
            return [$request->getClientIp(), $request->getHost()];
        });
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
        parent::tearDown();
    }

    public function testForwardedHeadersIgnoredWithoutTrustedProxies()
    {
        // Production default: APP_TRUSTED_PROXIES unset.
        $this->assertSame(['10.0.0.5', 'tallport.test'], $this->handle(['']));
    }

    public function testForwardedHeadersBelievedFromTrustedProxy()
    {
        $this->assertSame(['203.0.113.9', 'proxied.example.org'], $this->handle(['10.0.0.5']));
    }

    public function testForwardedHeadersIgnoredFromOtherProxy()
    {
        $this->assertSame(['10.0.0.5', 'tallport.test'], $this->handle(['10.0.0.6']));
    }

    public function testStarTrustsTheCallingProxy()
    {
        $this->assertSame(['203.0.113.9', 'proxied.example.org'], $this->handle('*'));
    }
}
