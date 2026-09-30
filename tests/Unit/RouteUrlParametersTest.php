<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * route() fills parameters the way Laravel 5.5 did: null values produce an
 * empty segment instead of an error. Modules (e.g. GlobalMailbox) rely on it.
 */
class RouteUrlParametersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $router = $this->app['router'];
        $router->get('tp/{a}/x', function () {
        })->name('tp.required');
        $router->get('tp/{a}/{b?}', function () {
        })->name('tp.optional');
        $router->getRoutes()->refreshNameLookups();
    }

    /**
     * @dataProvider cases
     */
    public function testRoute($name, $parameters, $expected)
    {
        $this->assertSame($expected, route($name, $parameters, false));
    }

    public function cases()
    {
        return [
            'value'                   => ['tp.required', [5], '/tp/5/x'],
            'null positional'         => ['tp.required', [null], '/tp//x'],
            'null named'              => ['tp.required', ['a' => null], '/tp//x'],
            'named with extra'        => ['tp.required', ['a' => 5, 'q' => 1], '/tp/5/x?q=1'],
            'optional missing'        => ['tp.optional', [5], '/tp/5'],
            'optional null'           => ['tp.optional', [5, null], '/tp/5'],
            'optional named'          => ['tp.optional', ['a' => 5, 'b' => 6], '/tp/5/6'],
        ];
    }
}
