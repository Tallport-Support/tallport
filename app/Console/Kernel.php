<?php

namespace App\Console;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Misc\Mail;
use App\Option;

class Kernel extends ConsoleKernel
{
    /**
     * Salt of the AI Assistant queue worker's identifier.
     */
    const AI_WORKER = 'ai-worker';

    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // It is not clear what for this array
        //\App\Console\Commands\CreateUser::class,
    ];

    /**
     * Mail servers bounce an email when tallport:receive exits with anything
     * but 75 (try again later): a failure outside the command, while
     * booting, must not bounce it.
     */
    public function handle($input, $output = null)
    {
        $status = parent::handle($input, $output);

        if ($status && $input->getFirstArgument() === 'tallport:receive'
            && !in_array($status, [Commands\Receive::EX_NOINPUT, Commands\Receive::EX_NOUSER, Commands\Receive::EX_TEMPFAIL])
        ) {
            return Commands\Receive::EX_TEMPFAIL;
        }

        return $status;
    }

    /**
     * Define the application's command schedule.
     * If --no-interaction flag is set the script will not run 'queue:work' daemon.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     *
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // https://github.com/freescout-helpdesk/freescout/issues/3970
        if (!$this->isScheduleRun() && !\Helper::isRoute('system.cron')) {
            return;
        }

        // Remove failed jobs
        $schedule->command('queue:flush')
            ->weekly();

        // Restart processing queued jobs (just in case)
        $schedule->command('queue:restart')
            ->hourly();

        $schedule->command('tallport:fetch-monitor')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('tallport:send-monitor')
            // Every 10 minutes.
            ->cron('*/10 * * * *')
            ->withoutOverlapping();

        // Replies that no job will send: show them as not sent and reopen.
        $schedule->command('tallport:check-outgoing', ['--days=7', '--fix'])
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('tallport:update-folder-counters')
            ->hourly();

        // Nostr: the relay listener, a new process every listener_lifetime
        // seconds (relays keep messages meanwhile), when a mailbox uses Nostr.
        $nostr_listen = $schedule->command('tallport:nostr-listen')
            ->everyMinute()
            ->withoutOverlapping((int) ceil((int) config('nostr.listener_lifetime', 1200) / 60) + 5)
            ->runInBackground()
            ->when(function () {
                try {
                    return \App\Nostr\NostrMailbox::anyActive();
                } catch (\Throwable $e) {
                    return false;
                }
            })
            ->sendOutputTo(storage_path('logs/nostr-listen.log'));
        // A listener that died left its mutex: let a new one start.
        if (function_exists('shell_exec')) {
            try {
                if (\Cache::has($nostr_listen->mutexName()) && !count(\Helper::getRunningProcesses('tallport:nostr-listen'))) {
                    \Cache::forget($nostr_listen->mutexName());
                }
            } catch (\Throwable $e) {
                // Best effort.
            }
        }
        // The mailboxes' Nostr profiles and relay lists.
        $schedule->command('tallport:nostr-announce')->dailyAt('04:30')->withoutOverlapping();

        // AI Assistant documentation: pick up changes to documented pages.
        $schedule->command('tallport:ai-index-documents', ['--fetch'])
            ->dailyAt('03:40')
            ->withoutOverlapping();

        // Check if user finished viewing conversation.
        $schedule->command('tallport:check-conv-viewers')
            ->everyMinute()
            ->withoutOverlapping();

        $schedule->command('tallport:clean-send-log')
            ->monthly();

        $schedule->command('tallport:clean-notifications-table')
            ->weekly();

        $schedule->command('tallport:clean-tmp')
            ->daily();

        $schedule->command('tallport:clean-gravatars')
            ->dailyAt('04:10');

        // Search: index what the requests and jobs didn't (new installations,
        // missed changes), and forget conversations deleted around Tallport.
        $schedule->command('tallport:search-index')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('tallport:search-index', ['--prune', '--seconds=0'])
            ->daily()
            ->withoutOverlapping();

        // Reports: replies of new conversations, and existing ones after installing.
        $schedule->command('tallport:report-replies')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        // Workflows whose conditions depend on time.
        $schedule->command('tallport:workflows')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground();

        // Webhook deliveries finished more than 3 days ago.
        $schedule->command('model:prune', ['--model' => [\App\Api\WebhookLog::class]])
            ->daily();

        // Logs monitoring.
        $alert_logs_period = config('app.alert_logs_period');
        if (config('app.alert_logs') && $alert_logs_period) {
            $logs_cron = '';
            switch ($alert_logs_period) {
                case 'hour':
                    $logs_cron = '0 * * * *';
                    break;
                case 'day':
                    $logs_cron = '0 0 * * *';
                    break;
                case 'week':
                    $logs_cron = '0 0 * * 0';
                    break;
                case 'month':
                    $logs_cron = '0 0 1 * *';
                    break;
            }
            if ($logs_cron) {
                $schedule->command('tallport:logs-monitor')
                    ->cron($logs_cron)
                    ->withoutOverlapping();
            }
        }

        $fetch_unseen = (int)config('app.fetch_unseen');
        // The old command name stays the salt, so fetch processes started before
        // the rename to tallport:fetch-emails are still recognised.
        $fetch_command_identifier = \Helper::getWorkerIdentifier('freescout:fetch-emails');
        $fetch_command_name = 'tallport:fetch-emails'
            . ' --identifier='.$fetch_command_identifier
            . ' --unseen='.$fetch_unseen;

        // Fetch emails from mailboxes
        $fetch_command = $schedule->command($fetch_command_name)
            // withoutOverlapping() option creates a mutex in the cache
            // which by default expires in 24 hours.
            // So we are passing an 'expiresAt' parameter to withoutOverlapping() to
            // prevent fetching from not being executed when fetching command by some reason
            // does not remove the mutex from the cache.
            ->withoutOverlapping($expiresAt = (int)config('app.fetch_max_execution_time') /* minutes */)
            ->sendOutputTo(storage_path().'/logs/fetch-emails.log');

        switch (config('app.fetch_schedule')) {
            case Mail::FETCH_SCHEDULE_EVERY_TWO_MINUTES:
                $fetch_command->cron('*/2 * * * *');
                break;
            case Mail::FETCH_SCHEDULE_EVERY_THREE_MINUTES:
                $fetch_command->cron('*/3 * * * *');
                break;
            case Mail::FETCH_SCHEDULE_EVERY_FIVE_MINUTES:
                $fetch_command->everyFiveMinutes();
                break;
            case Mail::FETCH_SCHEDULE_EVERY_TEN_MINUTES:
                $fetch_command->everyTenMinutes();
                break;
            case Mail::FETCH_SCHEDULE_EVERY_FIFTEEN_MINUTES:
                $fetch_command->everyFifteenMinutes();
                break;
            case Mail::FETCH_SCHEDULE_EVERY_THIRTY_MINUTES:
                $fetch_command->everyThirtyMinutes();
                break;
            case Mail::FETCH_SCHEDULE_HOURLY:
                $fetch_command->Hourly();
                break;
            default:
                $fetch_command->everyMinute();
                break;
        }

        // Fetching that runs too long is stopped; a fetch that ended without
        // releasing its lock (killed, Redis away) doesn't block the next one.
        // The lock is the scheduler's (with Redis a lock, not a cache key).
        if (function_exists('shell_exec')) {
            $fetch_command_pids = \Helper::getRunningProcesses($fetch_command_identifier);
            if (count($fetch_command_pids) > 0 && !$fetch_command->mutex->exists($fetch_command)) {
                // The lock expired after 'fetch_max_execution_time'.
                shell_exec('kill '.implode(' | kill ', $fetch_command_pids));
            } elseif (count($fetch_command_pids) == 0 && count(\Helper::getRunningProcesses('schedule:run'))) {
                $fetch_command->mutex->forget($fetch_command);
            }
        }

        $schedule = \Eventy::filter('schedule', $schedule);

        // If --no-daemonize flag is passed - do not run 'queue:work' daemon.
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if ($arg == '--no-interaction') {
                return;
            }
        }

        // Command runs as subprocess and sets cache mutex. If schedule:run command is killed
        // subprocess does not clear the mutex and it stays in the cache until cache:clear is executed.
        // By default, the lock will expire after 24 hours.

        $queue_work_params = Config('app.queue_work_params');
        // Add identifier to avoid conflicts with other FreeScout instances on the same server.
        $queue_work_params['--queue'] .= ','.\Helper::getWorkerIdentifier();
        $this->scheduleQueueWorker($schedule, $queue_work_params, \Helper::getWorkerIdentifier(), 'queue-jobs.log');

        // A second worker for the AI Assistant's jobs (drafts first), so
        // that slow AI requests don't hold up emails, nor drafts the rest.
        if (\App\Ai\Settings::isConfigured()) {
            $ai_identifier = \Helper::getWorkerIdentifier(self::AI_WORKER);
            $ai_work_params = Config('app.queue_work_ai_params');
            $ai_work_params['--queue'] .= ','.$ai_identifier;
            $this->scheduleQueueWorker($schedule, $ai_work_params, $ai_identifier, 'queue-ai-jobs.log');
        }
    }

    /**
     * Keep one queue:work running with these parameters: the processes are
     * found by the identifier in their --queue parameter.
     */
    protected function scheduleQueueWorker(Schedule $schedule, $queue_work_params, $identifier, $log)
    {
        $worker = $schedule->command('queue:work', $queue_work_params)
            ->everyMinute()
            ->withoutOverlapping()
            ->sendOutputTo(storage_path().'/logs/'.$log);

        // withoutOverlapping() keeps a lock while 'queue:work' runs. When the
        // cache is cleared, a second one starts: then both are stopped, and the
        // next minute one starts again. A worker that ended without releasing
        // its lock (killed, Redis away) would block a new one for 24 hours, so
        // without a worker the lock goes. The lock is the scheduler's (with
        // Redis a lock, not a cache key).
        if (function_exists('shell_exec')) {
            $running_commands = \Helper::getRunningProcesses($identifier);

            if (count($running_commands) > 1) {
                // Stop all queue:work processes.
                // queue:work command is stopped by settings a cache key
                \Helper::queueWorkerRestart();
                // Sometimes processes stuck and just continue running, so we need to kill them.
                // Sleep to let processes stop.
                sleep(1);
                // Check processes again.
                $worker_pids = \Helper::getRunningProcesses($identifier);

                if (count($worker_pids) > 1) {
                    shell_exec('kill '.implode(' | kill ', $worker_pids));
                }
            } elseif (count($running_commands) == 0 && count(\Helper::getRunningProcesses('schedule:run'))) {
                // ('ps' works.)
                $worker->mutex->forget($worker);
            }
        }
    }

    /**
     * This function is needed because every time $schedule->command() is executed
     * the schedule() is executed also.
     */
    public function isScheduleRun()
    {
        if (\Helper::isConsole()) {
            return true;
        } else {
            return !empty($_SERVER['argv']) && in_array('schedule:run', $_SERVER['argv']);
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        // Swiftmailer uses $_SERVER['SERVER_NAME'] in transport_deps.php
        // to set the host for EHLO command, if it is empty it uses [127.0.0.1].
        // G Suite sometimes rejects emails with EHLO [127.0.0.1].
        if (empty($_SERVER['SERVER_NAME'])) {
            $_SERVER['SERVER_NAME'] = parse_url(config('app.url'), PHP_URL_HOST);
        }

        require base_path('routes/console.php');
    }
}
