<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

/**
 * Commands are tallport:*; the freescout:* names still work, as modules,
 * cron jobs and older updaters (freescout:after-app-update) use them.
 */
class CommandNamesTest extends TestCase
{
    public function testFreeScoutNamesAreAliases()
    {
        $commands = $this->app->make(Kernel::class)->all();
        $tallport = array_filter(array_keys($commands), function ($name) {
            return str_starts_with($name, 'tallport:');
        });

        $this->assertGreaterThan(20, count($tallport));
        foreach ($tallport as $name) {
            $old = 'freescout:'.substr($name, strlen('tallport:'));
            $this->assertArrayHasKey($old, $commands, $name);
            $this->assertSame($commands[$name], $commands[$old], $name);
        }
    }
}
