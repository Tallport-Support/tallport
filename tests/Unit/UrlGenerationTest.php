<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * How FreeScout generates URLs: the root comes from APP_URL (modules can
 * filter it), an installation in a subdirectory doesn't get the subdirectory
 * twice, and x_ query parameters (e.g. x_embed) follow the user around.
 */
class UrlGenerationTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $router->get('tp-url/a', function () {
            return route('tp.url.a').' '.route('tp.url.b');
        })->name('tp.url.a');
        $router->get('tp-url/b', function () {
        })->name('tp.url.b');
        $router->get('support/tp-sub', function () {
        })->name('tp.url.sub');
        $router->getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        if ($this->filter) {
            \Eventy::removeFilter('url_generator.app_url', $this->filter);
        }
        parent::tearDown();
    }

    protected function useRequest($url)
    {
        // Rebinding the request also resets the URL generator's cached root.
        $this->app->instance('request', Request::create($url));
    }

    public function testRootComesFromAppUrl()
    {
        config(['app.url' => 'https://help.example.org']);
        $this->useRequest('http://internal-host/whatever');

        $this->assertSame('https://help.example.org/tp-url/b', route('tp.url.b'));
        $this->assertSame('https://help.example.org/some/path', url('some/path'));
    }

    public function testTrailingSlashInAppUrl()
    {
        config(['app.url' => 'https://help.example.org/']);
        $this->useRequest('http://internal-host/');

        $this->assertSame('https://help.example.org/tp-url/b', route('tp.url.b'));
    }

    public function testDefaultAppUrlFallsBackToRequest()
    {
        config(['app.url' => 'http://localhost']);
        $this->useRequest('https://other.example.org/whatever');

        $this->assertSame('https://other.example.org/tp-url/b', route('tp.url.b'));
    }

    public function testModulesCanFilterTheRoot()
    {
        config(['app.url' => 'https://help.example.org']);
        $this->filter = function ($url) {
            return 'https://mailbox.example.org';
        };
        \Eventy::addFilter('url_generator.app_url', $this->filter, 20, 1);
        $this->useRequest('http://internal-host/');

        $this->assertSame('https://mailbox.example.org/tp-url/b', route('tp.url.b'));
    }

    public function testSubdirectoryIsNotRepeated()
    {
        // Routes are registered under the subdirectory (RouteServiceProvider),
        // and APP_URL already ends with it.
        config(['app.url' => 'https://example.org/support']);
        $this->useRequest('https://example.org/support/');

        $this->assertSame('https://example.org/support/tp-sub', route('tp.url.sub'));
        $this->assertSame('https://example.org/support/tp-url/b', route('tp.url.b'));
    }

    public function testXParametersAreKept()
    {
        config(['app.url' => 'https://help.example.org']);
        $this->useRequest('https://help.example.org/tp-url/a?x_embed=1&other=2');

        $this->assertSame('https://help.example.org/tp-url/b?x_embed=1', route('tp.url.b'));
    }

    public function testXsParametersAreKeptOnTheSamePageOnly()
    {
        $response = $this->get('/tp-url/a?x_embed=1&xs_tab=2');

        [$same_page, $other_page] = explode(' ', $response->getContent());
        $this->assertStringEndsWith('/tp-url/a?x_embed=1&xs_tab=2', $same_page);
        $this->assertStringEndsWith('/tp-url/b?x_embed=1', $other_page);
    }
}
