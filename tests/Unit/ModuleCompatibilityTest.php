<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Input;
use Tests\TestCase;

/**
 * Laravel 5.5 APIs that later versions removed but FreeScout modules use.
 */
class ModuleCompatibilityTest extends TestCase
{
    public function testInputFacade()
    {
        $this->app->instance('request', Request::create('/x', 'GET', ['folder_id' => '7']));

        $this->assertSame('7', Input::get('folder_id'));
        $this->assertSame('none', Input::get('missing', 'none'));
    }

    public function testEventFire()
    {
        $received = null;
        Event::listen('tallport.compat', function ($value) use (&$received) {
            $received = $value;
        });

        Event::fire('tallport.compat', ['fired']);
        $this->app['events']->fire('tallport.compat', ['fired again']);

        $this->assertSame('fired again', $received);
    }

    public function testOldHelpers()
    {
        $this->assertSame('b', array_get(['a' => 'b'], 'a'));
        $this->assertSame('hello_world', snake_case('helloWorld'));
        $this->assertSame(16, strlen(str_random(16)));
    }
}
