<?php

namespace Tests\Unit;

use App\Http\Middleware\HttpsAndCloudflareIp;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * HTTPS follows APP_URL, and the client IP can come from Cloudflare.
 */
class HttpsAndCloudflareIpTest extends TestCase
{
    protected $remote_addr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->remote_addr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remote_addr;
        }
        parent::tearDown();
    }

    protected function handle(array $server = [])
    {
        $request = Request::create('http://tallport.test/', 'GET', [], [], [], array_merge(['REMOTE_ADDR' => '10.0.0.5'], $server));

        return (new HttpsAndCloudflareIp())->handle($request, function ($request) {
            return ['secure' => $request->isSecure(), 'ip' => $request->ip()];
        });
    }

    public function testHttpsFollowsAppUrl()
    {
        config(['app.url' => 'https://tallport.test']);
        $this->assertTrue($this->handle()['secure']);

        config(['app.url' => 'http://tallport.test']);
        $this->assertFalse($this->handle(['HTTPS' => 'on'])['secure']);
    }

    public function testRunsFirst()
    {
        $middleware = (new \ReflectionProperty(\App\Http\Kernel::class, 'middleware'))->getValue($this->app->make(\App\Http\Kernel::class));

        $this->assertSame(HttpsAndCloudflareIp::class, $middleware[0]);
    }

    public function testCloudflareIpWhenEnabled()
    {
        config(['app.cloudflare_is_used' => true]);

        $this->assertSame('203.0.113.9', $this->handle(['HTTP_CF_CONNECTING_IP' => '203.0.113.9'])['ip']);
        $this->assertSame('203.0.113.9', $_SERVER['REMOTE_ADDR']);
        $this->assertSame('10.0.0.5', $this->handle(['HTTP_CF_CONNECTING_IP' => '<script>'])['ip']);
    }

    public function testCloudflareHeaderIgnoredWhenDisabled()
    {
        config(['app.cloudflare_is_used' => false]);

        $this->assertSame('10.0.0.5', $this->handle(['HTTP_CF_CONNECTING_IP' => '203.0.113.9'])['ip']);
    }

    public function testSameSiteNoneCookiesAreSecure()
    {
        $_SERVER['SESSION_SAME_SITE'] = $_ENV['SESSION_SAME_SITE'] = 'none';
        try {
            $config = require config_path('session.php');
        } finally {
            unset($_SERVER['SESSION_SAME_SITE'], $_ENV['SESSION_SAME_SITE']);
        }

        $this->assertTrue($config['secure']);
        $this->assertSame('none', $config['same_site']);
    }
}
