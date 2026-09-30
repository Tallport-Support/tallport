<?php

namespace Tests\Support;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;

/**
 * Records which routes, ajax actions, artisan commands and queued jobs the
 * tests exercise, for the inventory check (tests/inventory.php).
 *
 * Active when TALLPORT_EXERCISED_LOG names a file (./test.sh sets it for
 * full runs). Lines are appended straight to the file, so tests running in
 * a separate process are counted too.
 */
class ExercisedRecorder
{
    public static function register($app)
    {
        $log = getenv('TALLPORT_EXERCISED_LOG');
        if (!$log) {
            return;
        }

        $record = function ($line) use ($log) {
            file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
        };

        $events = $app['events'];

        $events->listen(RouteMatched::class, function (RouteMatched $event) use ($record) {
            $route = $event->route;
            $method = $event->request->getMethod();
            $record('route '.($method === 'HEAD' ? 'GET' : $method).' '.$route->uri());

            // Ajax endpoints dispatch on an action: record it as well.
            $action = $route->getActionName();
            if (preg_match('/\\\\([A-Za-z]+Controller)@(ajax[A-Za-z]*)$/', $action, $m)) {
                $name = $event->request->input('action') ?: $route->parameter('action');
                if ($m[2] === 'ajaxSearch') {
                    $name = $event->request->input('show_fields');
                }
                if ($name && is_string($name)) {
                    $record('ajax '.$m[1].'@'.$m[2].' '.$name);
                }
            }
        });

        // Since Laravel 10 artisan only fires CommandStarting outside unit
        // tests, unless asked to.
        $kernel = $app[\Illuminate\Contracts\Console\Kernel::class];
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($record, $app) {
            // A stubbed command (see FeatureTestCase) didn't really run.
            $commands = $app[\Illuminate\Contracts\Console\Kernel::class]->all();
            if ($event->command && !(($commands[$event->command] ?? null) instanceof StubCommand)) {
                $record('command '.$event->command);
            }
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($record) {
            $record('job '.$event->job->resolveName());
        });
    }
}
