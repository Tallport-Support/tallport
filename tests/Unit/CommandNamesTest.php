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
    /**
     * The commands FreeScout had.
     */
    const FREESCOUT_COMMANDS = [
        'after-app-update', 'build', 'check-conv-viewers', 'check-requirements', 'clean-notifications-table',
        'clean-send-log', 'clean-tmp', 'clear-cache', 'create-user', 'fetch-emails', 'fetch-monitor',
        'generate-vars', 'logout-users', 'logs-monitor', 'module-build', 'module-check-licenses',
        'module-install', 'module-laroute', 'module-update', 'parse-eml', 'send-monitor', 'update',
        'update-folder-counters',
    ];

    public function testFreeScoutNamesAreAliases()
    {
        $commands = $this->app->make(Kernel::class)->all();

        foreach (self::FREESCOUT_COMMANDS as $name) {
            $this->assertArrayHasKey('tallport:'.$name, $commands);
            $this->assertArrayHasKey('freescout:'.$name, $commands);
            $this->assertSame($commands['tallport:'.$name], $commands['freescout:'.$name], $name);
        }
    }
}
