<?php

namespace Tests\Unit;

use App\Misc\JsRoutes;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Routes exported to JavaScript (App\Misc\JsRoutes, laroute.js): the app's
 * file gets only app routes, each module's file only that module's routes.
 */
class LarouteRoutesTest extends TestCase
{
    protected function routes($prefix = '')
    {
        $router = new Router(new Dispatcher());
        $add = function ($uri, $uses, $name, $laroute = true) use ($router, $prefix) {
            $router->get($prefix.$uri, ['uses' => $uses, 'as' => $name, 'laroute' => $laroute]);
        };
        $add('conversation/ajax', 'App\Http\Controllers\ConversationsController@ajax', 'conversations.ajax');
        $add('settings', 'App\Http\Controllers\SettingsController@view', 'settings', false);
        $add('nostr/ajax', 'Modules\Nostr\Http\Controllers\NostrController@ajax', 'nostr.ajax');
        $add('other/ajax', 'Modules\Other\Http\Controllers\OtherController@ajax', 'other.ajax');

        return $router->getRoutes();
    }

    public function testAppFileHasOnlyAppRoutesMarkedForLaroute()
    {
        $this->assertSame(
            [['uri' => 'conversation/ajax', 'name' => 'conversations.ajax']],
            JsRoutes::routes(null, $this->routes())
        );
    }

    public function testModuleFileHasOnlyThatModulesRoutes()
    {
        $this->assertSame(
            [['uri' => 'nostr/ajax', 'name' => 'nostr.ajax']],
            JsRoutes::routes('nostr', $this->routes())
        );
    }

    /**
     * In a subdirectory installation the JS file gets URIs without it.
     */
    public function testSubdirectoryIsRemoved()
    {
        config(['app.url' => 'https://example.org/support']);

        $this->assertSame(
            [['uri' => 'conversation/ajax', 'name' => 'conversations.ajax']],
            JsRoutes::routes(null, $this->routes('support/'))
        );
    }

    public function testGenerateFillsTheTemplate()
    {
        $file = sys_get_temp_dir().'/tallport-laroute-'.uniqid().'.js';
        try {
            JsRoutes::generate('resources/assets/js/laroute.js', $file);
            $js = file_get_contents($file);
        } finally {
            @unlink($file);
        }

        $this->assertStringContainsString('"name": "conversations.ajax"', $js);
        $this->assertStringContainsString('absolute: true', $js);
        $this->assertStringNotContainsString('$ROUTES$', $js);
    }

    public function testGenerateCommand()
    {
        $dir = sys_get_temp_dir().'/tallport-laroute-'.uniqid();
        try {
            \Artisan::call('laroute:generate', ['--path' => $dir]);
            $this->assertStringContainsString('Created: '.$dir.'/laroute.js', \Artisan::output());
            $this->assertStringContainsString('"name": "conversations.ajax"', file_get_contents($dir.'/laroute.js'));
        } finally {
            @unlink($dir.'/laroute.js');
            @rmdir($dir);
        }
    }
}
