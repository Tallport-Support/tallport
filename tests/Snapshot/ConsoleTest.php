<?php

namespace Tests\Snapshot;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\Concerns\AssertsSnapshots;
use Tests\TestCase;

/**
 * The application's artisan commands and what the scheduler runs. Cron and
 * deployment scripts call these by name, so signatures must not drift.
 */
class ConsoleTest extends TestCase
{
    use AssertsSnapshots;

    public function testCommands()
    {
        $commands = [];
        foreach ($this->app[Kernel::class]->all() as $name => $command) {
            // Laravel's own commands change with the framework version.
            if (strpos(get_class($command), 'App\\') !== 0) {
                continue;
            }
            $definition = $command->getDefinition();
            $commands[$name] = [
                'class'       => get_class($command),
                'description' => $command->getDescription(),
                'arguments'   => array_map(function ($argument) {
                    return [
                        'required' => $argument->isRequired(),
                        'array'    => $argument->isArray(),
                        'default'  => $argument->getDefault(),
                    ];
                }, $definition->getArguments()) ?: new \stdClass(),
                'options'     => array_map(function ($option) {
                    return array_filter([
                        'shortcut' => $option->getShortcut(),
                        'value'    => $option->acceptValue() ? ($option->isValueRequired() ? 'required' : 'optional') : 'none',
                        'array'    => $option->isArray() ?: null,
                        'default'  => $option->getDefault() === false ? null : $option->getDefault(),
                    ], function ($value) {
                        return $value !== null;
                    });
                }, $definition->getOptions()) ?: new \stdClass(),
            ];
        }
        ksort($commands);

        $this->assertMatchesSnapshot('console_commands', $commands);
    }

    public function testSchedule()
    {
        $events = [];
        foreach ($this->app->make(Schedule::class)->events() as $event) {
            $events[] = array_filter([
                'command'              => $this->normalizeCommand($event->command),
                'description'          => $event->description,
                'expression'           => $event->expression,
                'timezone'             => $event->timezone ? (string)$event->timezone : null,
                'without_overlapping'  => $event->withoutOverlapping ?: null,
                'run_in_background'    => $event->runInBackground ?: null,
                'even_in_maintenance'  => $event->evenInMaintenanceMode ?: null,
            ], function ($value) {
                return $value !== null && $value !== '';
            });
        }

        $this->assertMatchesSnapshot('schedule', $events);
    }

    /**
     * Strip the PHP binary and artisan path, which differ per machine.
     */
    protected function normalizeCommand($command)
    {
        if ($command === null) {
            return null;
        }
        $command = str_replace([PHP_BINARY, base_path()], ['php', '.'], $command);
        $command = preg_replace("#^'?php'? +'?(\./)?artisan'? +#", 'artisan ', $command);

        return $command;
    }
}
