<?php

namespace Tests\Feature;

use App\Misc\Mail;
use Illuminate\Console\Scheduling\Schedule;
use Tests\FeatureTestCase;

/**
 * What the scheduler (schedule:run, every minute from cron) runs and how
 * often: app/Console/Kernel.php.
 */
class ScheduleTest extends FeatureTestCase
{
    protected function schedule()
    {
        $schedule = new Schedule();
        $method = new \ReflectionMethod(\App\Console\Kernel::class, 'schedule');
        $method->invoke($this->app->make(\Illuminate\Contracts\Console\Kernel::class), $schedule);

        return collect($schedule->events());
    }

    /**
     * The event whose command contains $command (the first, or the one with $argument).
     */
    protected function event($command, $argument = null)
    {
        return $this->schedule()->first(function ($event) use ($command, $argument) {
            return str_contains((string) $event->command, $command) && ($argument === null || str_contains((string) $event->command, $argument));
        });
    }

    public function testCommandsAndHowOften()
    {
        $expected = [
            ['queue:flush', null, '0 0 * * 0'],
            ['queue:restart', null, '0 * * * *'],
            ['tallport:fetch-monitor', null, '* * * * *'],
            ['tallport:send-monitor', null, '*/10 * * * *'],
            ['tallport:check-outgoing', '--fix', '*/5 * * * *'],
            ['tallport:update-folder-counters', null, '0 * * * *'],
            ['tallport:nostr-listen', null, '* * * * *'],
            ['tallport:nostr-announce', null, '30 4 * * *'],
            ['tallport:ai-index-documents', '--fetch', '40 3 * * *'],
            ['tallport:check-conv-viewers', null, '* * * * *'],
            ['tallport:retention', null, '20 3 * * *'],
            ['tallport:retention', '--sweep-files', '50 3 * * 0'],
            ['tallport:clean-tmp', null, '0 0 * * *'],
            ['tallport:clean-customer-photos', null, '10 4 * * *'],
            ['tallport:search-index', null, '* * * * *'],
            ['tallport:search-index', '--prune', '0 0 * * *'],
            ['tallport:report-replies', null, '* * * * *'],
            ['tallport:workflows', null, '*/5 * * * *'],
            ['model:prune', 'WebhookLog', '0 0 * * *'],
            ['queue:work', null, '* * * * *'],
        ];
        foreach ($expected as [$command, $argument, $cron]) {
            $event = $this->event($command, $argument);
            $this->assertNotNull($event, $command.' '.$argument);
            $this->assertSame($cron, $event->expression, $command.' '.$argument);
        }
        $this->assertStringContainsString('--days=7', $this->event('tallport:check-outgoing')->command);

        // Long or frequent ones don't run twice at once.
        foreach (['tallport:fetch-monitor', 'tallport:check-outgoing', 'tallport:nostr-listen', 'tallport:search-index', 'tallport:workflows', 'queue:work'] as $command) {
            $this->assertTrue($this->event($command)->withoutOverlapping, $command);
        }
        $this->assertTrue($this->event('tallport:nostr-listen')->runInBackground);
        $this->assertSame((int) ceil(config('nostr.listener_lifetime') / 60) + 5, $this->event('tallport:nostr-listen')->expiresAt);
        $this->assertSame(storage_path('logs/nostr-listen.log'), $this->event('tallport:nostr-listen')->output);
    }

    public function testNostrListenerOnlyRunsForNostrMailboxes()
    {
        $event = $this->event('tallport:nostr-listen');
        $this->assertFalse($event->filtersPass($this->app));

        $cfg = \App\Nostr\NostrMailbox::forMailbox($this->createMailbox()->id);
        $cfg->setPrivateKey(\App\Nostr\Keys::generatePrivateKey());
        $cfg->enabled = true;
        $cfg->save();

        $this->assertTrue($event->filtersPass($this->app));
    }

    public function testSystemProblemsAreCounted()
    {
        \Cache::forget(\App\Http\Controllers\SystemController::PROBLEM_COUNT_CACHE);
        $event = $this->schedule()->first(function ($event) {
            return $event->description === 'system-problem-count';
        });
        $this->assertSame('*/5 * * * *', $event->expression);

        $event->run($this->app);

        $this->assertIsInt(\Cache::get(\App\Http\Controllers\SystemController::PROBLEM_COUNT_CACHE));
    }

    /**
     * How often mail is fetched (Settings » Mail Settings).
     *
     * @dataProvider fetchSchedules
     */
    public function testFetchingFollowsTheSetting($setting, $cron)
    {
        config(['app.fetch_schedule' => $setting, 'app.fetch_unseen' => 1]);

        $event = $this->event('tallport:fetch-emails');

        $this->assertSame($cron, $event->expression);
        $this->assertStringContainsString('--unseen=1', $event->command);
        $this->assertStringContainsString('--identifier='.\Helper::getWorkerIdentifier('freescout:fetch-emails'), $event->command);
        $this->assertSame(storage_path().'/logs/fetch-emails.log', $event->output);
    }

    public static function fetchSchedules()
    {
        return [
            [Mail::FETCH_SCHEDULE_EVERY_MINUTE, '* * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_TWO_MINUTES, '*/2 * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_THREE_MINUTES, '*/3 * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_FIVE_MINUTES, '*/5 * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_TEN_MINUTES, '*/10 * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_FIFTEEN_MINUTES, '*/15 * * * *'],
            [Mail::FETCH_SCHEDULE_EVERY_THIRTY_MINUTES, '*/30 * * * *'],
            [Mail::FETCH_SCHEDULE_HOURLY, '0 * * * *'],
        ];
    }

    /**
     * Logs monitoring (Settings » Alerts) runs once per its period.
     *
     * @dataProvider logsPeriods
     */
    public function testLogsMonitorFollowsItsPeriod($enabled, $period, $cron)
    {
        config(['app.alert_logs' => $enabled, 'app.alert_logs_period' => $period]);

        $event = $this->event('tallport:logs-monitor');

        $this->assertSame($cron, $event ? $event->expression : null);
    }

    public static function logsPeriods()
    {
        return [
            [true, 'hour', '0 * * * *'],
            [true, 'day', '0 0 * * *'],
            [true, 'week', '0 0 * * 0'],
            [true, 'month', '0 0 1 * *'],
            [true, 'year', null],
            [false, 'day', null],
        ];
    }

    public function testModulesCanAddToTheSchedule()
    {
        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('module:example')->dailyAt('02:00');

            return $schedule;
        });

        $this->assertSame('0 2 * * *', $this->event('module:example')->expression);
    }

    public function testAiWorkerOnlyWhenTheAssistantIsSetUp()
    {
        $ai_worker = function () {
            return $this->schedule()->filter(function ($event) {
                return str_contains((string) $event->command, 'queue:work') && str_contains((string) $event->command, \Helper::getWorkerIdentifier(\App\Console\Kernel::AI_WORKER));
            })->count();
        };
        $this->assertSame(0, $ai_worker());

        \Option::set('aiassistant.api_key', encrypt('sk-test'));
        \Option::$cache = [];
        $this->assertSame(1, $ai_worker());
    }

    public function testWorkersUseSeparateQueuesAndReservations()
    {
        config(['queue.default' => 'database']);
        $workers = $this->schedule()->filter(fn ($event) => str_contains((string) $event->command, 'queue:work'));
        $this->assertCount(2, $workers);

        $main = $workers->first(fn ($event) => str_contains($event->command, \Helper::getWorkerIdentifier()));
        $mail = $workers->first(fn ($event) => str_contains($event->command, \Helper::getWorkerIdentifier(\App\Console\Kernel::EMAIL_WORKER)));
        $this->assertStringContainsString("--queue='default,", $main->command);
        $this->assertStringContainsString("queue:work 'database_emails' --queue='emails,", $mail->command);
        $this->assertStringContainsString('--timeout=300', $mail->command);

        foreach (['database', 'beanstalkd', 'redis'] as $driver) {
            $this->assertGreaterThan(3600, config('queue.connections.'.$driver.'.retry_after'));
            $this->assertGreaterThan(300, config('queue.connections.'.$driver.'_emails.retry_after'));
            $this->assertGreaterThan(900, config('queue.connections.'.$driver.'_ai.retry_after'));
        }
    }
}
