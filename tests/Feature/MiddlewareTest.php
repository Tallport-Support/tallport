<?php

namespace Tests\Feature;

use App\Api\ApiKey;
use App\Http\Middleware\CanInstall;
use App\Http\Middleware\CheckBrowser;
use App\Http\Middleware\FrameGuard;
use App\Http\Middleware\HttpsRedirect;
use App\Http\Middleware\TrustHosts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\FeatureTestCase;

/**
 * Middleware whose checks tests otherwise switch off or never meet:
 * trusted hosts, the browser check, the installer lock, HTTPS, framing,
 * guests-only pages and the API's module hook.
 */
class MiddlewareTest extends FeatureTestCase
{
    protected $filters = [];

    protected function tearDown(): void
    {
        try {
            foreach ($this->filters as [$name, $callback]) {
                \Eventy::removeFilter($name, $callback, 20);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function addFilter($name, $callback)
    {
        \Eventy::addFilter($name, $callback, 20, 2);
        $this->filters[] = [$name, $callback];
    }

    /**
     * Run a middleware on a request; returns 'passed' when it lets the
     * request through, else its response.
     */
    protected function through($middleware, Request $request)
    {
        $this->app->instance('request', $request);

        return $middleware->handle($request, function () {
            return 'passed';
        });
    }

    /**
     * The HTTP status a middleware aborts with, or null.
     */
    protected function abortStatus(callable $callback, &$message = null)
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $message = $e->getMessage();

            return $e->getStatusCode();
        }

        return null;
    }

    // TrustHosts: tests run with it off (runningUnitTests()); here it's on.

    protected function trustHosts()
    {
        $app = \Mockery::mock(\Illuminate\Contracts\Foundation\Application::class);
        $app->shouldReceive('runningUnitTests')->andReturn(false);

        return new TrustHosts($app);
    }

    public function testTrustHostsLetsTheAppHostThrough()
    {
        config(['app.url' => 'https://help.example.org']);

        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://help.example.org/mailbox/1')));
        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://HELP.example.org:8443/mailbox/1')), 'Any case, any port.');
    }

    public function testTrustHostsFromTheEnvironmentAndModules()
    {
        config(['app.url' => 'https://help.example.org', 'app.trusted_hosts' => ' Other.example.org , ,proxy.example.org']);
        $this->addFilter('app.is_trusted_host', function ($trusted, $host) {
            return $host == 'module.example.org' ? true : $trusted;
        });

        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://other.example.org/')));
        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://proxy.example.org/')));
        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://module.example.org/')));

        $status = $this->abortStatus(function () {
            $this->through($this->trustHosts(), Request::create('https://evil.example.org/login'));
        }, $message);
        $this->assertSame(403, $status);
        $this->assertStringContainsString('Untrusted Host: evil.example.org.', $message);
        $this->assertStringContainsString("APP_TRUSTED_HOSTS ='evil.example.org'", $message);
        $this->assertStringEndsWith('[display]', $message, 'Shown to the visitor.');
    }

    /**
     * Before installing, APP_URL is the example one: the installer opens
     * at any host, nothing else does.
     */
    public function testTrustHostsDuringInstallation()
    {
        config(['app.url' => 'https://example.com']);

        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://help.example.org/install')));
        $this->assertSame('passed', $this->through($this->trustHosts(), Request::create('https://help.example.org/install/requirements')));
        $this->assertSame(403, $this->abortStatus(function () {
            $this->through($this->trustHosts(), Request::create('https://help.example.org/installer-not'));
        }));
    }

    // CheckBrowser.

    public function testOldBrowsersAreRefused()
    {
        $old = Request::create('https://tallport.test/login', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1; Trident/4.0)']);
        $modern = Request::create('https://tallport.test/login', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36']);

        $this->assertSame('passed', $this->through(new CheckBrowser(), $modern));
        $status = $this->abortStatus(function () use ($old) {
            $this->through(new CheckBrowser(), $old);
        }, $message);
        $this->assertSame(403, $status);
        $this->assertStringContainsString('does not support Content Security Policy', $message);

        config(['app.disable_browser_check' => true]);
        $this->assertSame('passed', $this->through(new CheckBrowser(), $old), 'Unless the check is turned off.');
    }

    // FrameGuard.

    public function testFrameOptions()
    {
        $header = function ($setting) {
            config(['app.x_frame_options' => $setting]);
            $response = (new FrameGuard())->handle(Request::create('https://tallport.test/'), function () {
                return new Response('page');
            });

            return $response->headers->get('X-Frame-Options');
        };

        $this->assertSame('SAMEORIGIN', $header(true));
        $this->assertSame('DENY', $header('DENY'));
        $this->assertSame('ALLOW-FROM https://portal.example.org', $header('ALLOW-FROM https://portal.example.org'));
        $this->assertSame('SAMEORIGIN', $header('anything else'));
        $this->assertNull($header('false'));
        $this->assertNull($header(false));
    }

    // HttpsRedirect: off on the command line, so it's turned on here.

    public function testHttpRequestsAreRedirectedToHttps()
    {
        $server = $_SERVER;
        $is_console = \Helper::$is_console;
        unset($_SERVER['HTTPS'], $_SERVER['X_FORWARDED_PROTO'], $_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTP_CF_VISITOR'], $_SERVER['REQUEST_URI']);
        \Helper::$is_console = false;

        try {
            config(['app.url' => 'https://tallport.test']);
            $response = $this->through(new HttpsRedirect(), Request::create('http://tallport.test/mailbox/1?page=2'));
            $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $response);
            $this->assertSame('https://tallport.test/mailbox/1?page=2', $response->getTargetUrl());

            // Behind a proxy that ended HTTPS.
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
            $this->assertSame('passed', $this->through(new HttpsRedirect(), Request::create('http://tallport.test/mailbox/1')));
            $this->assertSame('on', $_SERVER['HTTPS'], 'PHP is told the request is HTTPS.');

            // An http APP_URL: no redirect.
            unset($_SERVER['HTTP_X_FORWARDED_PROTO'], $_SERVER['HTTPS']);
            config(['app.url' => 'http://tallport.test']);
            $this->assertSame('passed', $this->through(new HttpsRedirect(), Request::create('http://tallport.test/mailbox/1')));
        } finally {
            $_SERVER = $server;
            \Helper::$is_console = $is_console;
        }
    }

    // RedirectIfAuthenticated.

    public function testLoginPageSendsSignedInUsersHome()
    {
        $this->actingAs($this->createUser())->get(route('login'))->assertRedirect(url('/home'));
    }

    // ApiAuthenticate.

    public function testModulesCanRefuseApiRequests()
    {
        $this->addFilter('api.auth.allowed', function ($allowed, $request) {
            return $request->is('api/mailboxes') ? false : $allowed;
        });

        $this->json('GET', '/api/mailboxes', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])
            ->assertStatus(403)->assertExactJson(['message' => 'Forbidden']);
        $this->json('GET', '/api/users', [], ['X-FreeScout-API-Key' => ApiKey::globalKey()])->assertOk();
        // Tallport's own name for the header too.
        $this->json('GET', '/api/users', [], ['X-Tallport-API-Key' => ApiKey::globalKey()])->assertOk();
    }

    // CanInstall: the installer closes once installed.

    protected function withStorage(callable $callback)
    {
        $dir = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        mkdir($dir);
        $storage = $this->app->storagePath();
        $this->app->useStoragePath($dir);

        try {
            $callback($dir);
        } finally {
            $this->app->useStoragePath($storage);
            (new Filesystem())->deleteDirectory($dir);
        }
    }

    protected function databaseConfigured($password = 'secret')
    {
        config([
            'app.url'                              => 'https://tallport.test',
            'database.connections.mysql.host'      => '127.0.0.1',
            'database.connections.mysql.port'      => '3306',
            'database.connections.mysql.database'  => 'tallport',
            'database.connections.mysql.username'  => 'tallport',
            'database.connections.mysql.password'  => $password,
        ]);
    }

    public function testInstallerOpenUntilConfigured()
    {
        $this->withStorage(function () {
            $this->databaseConfigured('');
            $this->get('/install')->assertOk();

            // Configured and the database answers: installed.
            $this->databaseConfigured();
            $this->get('/install/requirements')->assertRedirect(route('dashboard'));
        });
    }

    public function testInstallerLastStepsStayOpen()
    {
        $this->withStorage(function () {
            $this->databaseConfigured();
            $router = $this->app['router'];
            $current = new \ReflectionProperty($router, 'current');
            foreach (['LaravelInstaller::database' => false, 'LaravelInstaller::final' => false, 'LaravelInstaller::permissions' => true] as $name => $installed) {
                $current->setValue($router, $router->getRoutes()->getByName($name));
                $this->assertSame($installed, (new CanInstall())->alreadyInstalled(), $name);
            }
        });
    }

    public function testInstallerOpenWhileTheDatabaseIsUnreachable()
    {
        $this->withStorage(function () {
            $this->databaseConfigured();
            $default = config('database.default');
            config([
                'database.default'             => 'unreachable',
                'database.connections.unreachable' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/tallport.sqlite'],
            ]);

            try {
                $this->assertFalse((new CanInstall())->alreadyInstalled());
            } finally {
                config(['database.default' => $default]);
                \DB::purge('unreachable');
            }
        });
    }

    public function testInstalledMarkerClosesTheInstaller()
    {
        $this->withStorage(function ($dir) {
            $this->databaseConfigured('');
            file_put_contents($dir.'/.installed', '');
            $this->assertTrue((new CanInstall())->alreadyInstalled());

            config(['installer.installedAlreadyAction' => 'abort', 'installer.installed.redirectOptions.abort.type' => 403]);
            $this->get('/install')->assertForbidden();
            config(['installer.installedAlreadyAction' => '404']);
            $this->get('/install')->assertNotFound();
        });
    }
}
