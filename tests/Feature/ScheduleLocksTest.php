<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\FeatureTestCase;

/**
 * The queue workers and fetching run withoutOverlapping(): a lock left by
 * one that ended without releasing it (killed, Redis away) is removed, so
 * a new one starts. The locks are the scheduler's, which with Redis (and
 * the array cache in tests) are not cache keys.
 */
class ScheduleLocksTest extends FeatureTestCase
{
    /**
     * A process that looks like schedule:run (the scheduler checks that
     * 'ps' works by finding it).
     */
    protected $process;

    protected function setUp(): void
    {
        parent::setUp();

        $this->process = proc_open([PHP_BINARY, '-r', 'sleep(30);', 'schedule:run'], [], $pipes);
        usleep(200000);
    }

    protected function tearDown(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);

        parent::tearDown();
    }

    protected function schedule()
    {
        $schedule = new Schedule();
        $method = new \ReflectionMethod(\App\Console\Kernel::class, 'schedule');
        $method->invoke($this->app->make(\Illuminate\Contracts\Console\Kernel::class), $schedule);

        return collect($schedule->events());
    }

    public function testLeftoverLocksAreRemoved()
    {
        foreach (['queue:work', 'tallport:fetch-emails'] as $command) {
            $event = $this->schedule()->first(fn ($event) => str_contains((string) $event->command, $command));
            $this->assertNotNull($event, $command);
            $event->mutex->create($event);
            $this->assertTrue($event->mutex->exists($event));

            $this->schedule();

            $this->assertFalse($event->mutex->exists($event), $command.': nothing runs, so its lock goes.');
        }
    }
}
