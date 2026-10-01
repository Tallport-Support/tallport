<?php

namespace Tests\Unit;

use App\Misc\Eventy\Events;
use Tests\TestCase;

/**
 * The \Eventy hooks FreeScout and its modules use.
 */
class EventyTest extends TestCase
{
    public function testAppUsesTallportEvents()
    {
        $this->assertInstanceOf(Events::class, $this->app->make('eventy'));
    }

    public function testActionsRunByPriorityThenInOrderAdded()
    {
        $events = new Events();
        $calls = [];
        $events->addAction('tp.hook', function () use (&$calls) { $calls[] = 'b'; }, 20);
        $events->addAction('tp.hook', function () use (&$calls) { $calls[] = 'c'; }, 20);
        $events->addAction('tp.hook', function () use (&$calls) { $calls[] = 'a'; }, 10);
        $events->addAction('tp.other', function () use (&$calls) { $calls[] = 'other'; });

        $events->action('tp.hook');

        $this->assertSame(['a', 'b', 'c'], $calls);
    }

    public function testMissingArgumentsAreNull()
    {
        $events = new Events();
        $received = null;
        $events->addAction('tp.hook', function ($a, $b, $c) use (&$received) {
            $received = [$a, $b, $c];
        }, 20, 3);
        $events->addFilter('tp.filter', function ($value, $extra) {
            return $value.':'.var_export($extra, true);
        }, 20, 2);

        $events->action('tp.hook', 'one');

        $this->assertSame(['one', null, null], $received);
        $this->assertSame('v:NULL', $events->filter('tp.filter', 'v'));
    }

    public function testFiltersChainAndCanBeRemoved()
    {
        $events = new Events();
        $double = function ($value) { return $value * 2; };
        $events->addFilter('tp.filter', $double);
        $events->addFilter('tp.filter', function ($value) { return $value + 1; }, 30);

        $this->assertSame(5, $events->filter('tp.filter', 2));
        $this->assertSame('unchanged', $events->filter('tp.none', 'unchanged'));

        $events->removeFilter('tp.filter', $double);
        $this->assertSame(3, $events->filter('tp.filter', 2));

        $events->removeAllFilters('tp.filter');
        $this->assertSame(2, $events->filter('tp.filter', 2));
    }
}
