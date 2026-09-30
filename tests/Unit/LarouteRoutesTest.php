<?php

namespace Tests\Unit;

use Axn\Laroute\Routes\Collection;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * Routes exported to JavaScript (laroute): the app's file gets only app
 * routes, each module's file only that module's routes (patched in
 * overrides/axn and overrides/lord).
 */
class LarouteRoutesTest extends TestCase
{
    protected function routes()
    {
        $router = new Router(new Dispatcher());
        $add = function ($uri, $uses, $name, $laroute = true) use ($router) {
            $router->get($uri, ['uses' => $uses, 'as' => $name, 'laroute' => $laroute]);
        };
        $add('conversation/ajax', 'App\Http\Controllers\ConversationsController@ajax', 'conversations.ajax');
        $add('settings', 'App\Http\Controllers\SettingsController@view', 'settings', false);
        $add('nostr/ajax', 'Modules\Nostr\Http\Controllers\NostrController@ajax', 'nostr.ajax');
        $add('other/ajax', 'Modules\Other\Http\Controllers\OtherController@ajax', 'other.ajax');

        return $router->getRoutes();
    }

    protected function names(Collection $collection)
    {
        return array_column($collection->toArray(), 'name');
    }

    public function testAppFileHasOnlyAppRoutesMarkedForLaroute()
    {
        $this->assertSame(['conversations.ajax'], $this->names(new Collection($this->routes(), 'only', '')));
    }

    public function testModuleFileHasOnlyThatModulesRoutes()
    {
        $this->assertSame(['nostr.ajax'], $this->names(new Collection($this->routes(), 'only', '', 'nostr')));
    }

    public function testOnlyUriAndNameAreExported()
    {
        $this->assertSame(
            [['uri' => 'conversation/ajax', 'name' => 'conversations.ajax']],
            (new Collection($this->routes(), 'only', ''))->toArray()
        );
    }
}
