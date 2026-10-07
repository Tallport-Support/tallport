<?php

namespace Tests\Feature;

use App\Http\Controllers\SystemController;
use App\Option;
use App\User;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * SystemController beyond SettingsAndSystemTest: what System Status finds (background
 * commands, Redis, the storage link), the problems it lists, its actions and tools, and
 * the update actions' error paths.
 */
class SystemControllerTest extends FeatureTestCase
{
    protected $admin;

    /**
     * Processes started by a test, stopped in tearDown().
     */
    protected $processes = [];

    protected $temp_dirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        Option::$cache = [];
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->processes as $process) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($this->temp_dirs as $dir) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    /**
     * A process whose command line contains $marker, as ps shows it. Returns its PID.
     */
    protected function startProcess($marker)
    {
        $process = proc_open([PHP_BINARY, '-r', 'sleep(30);', $marker], [], $pipes);
        $this->processes[] = $process;
        $pid = proc_get_status($process)['pid'];
        for ($i = 0; $i < 50 && !str_contains((string) shell_exec('ps -o args= -p '.$pid), $marker); $i++) {
            usleep(20000);
        }

        return $pid;
    }

    protected function command(array $data, $name)
    {
        return collect($data['commands'])->firstWhere('name', $name);
    }

    /**
     * Replaces a stubbed command with one that also prints $output.
     */
    protected function commandPrinting($name, $output)
    {
        $command = new class($name, $output) extends \Tests\Support\StubCommand {
            protected $printed;

            public function __construct($name, $printed)
            {
                $this->printed = $printed;
                parent::__construct($name);
            }

            public function handle()
            {
                parent::handle();
                $this->line($this->printed);
            }
        };
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);
    }

    protected function useControllerWithoutBackgroundUpdate()
    {
        $this->app->bind(SystemController::class, function () {
            return new class extends SystemController {
                protected function startBackgroundUpdate()
                {
                    return false;
                }
            };
        });
    }

    protected function fetchedMailbox()
    {
        return $this->createMailbox([], ['in_protocol' => \App\Mailbox::IN_PROTOCOL_IMAP, 'in_server' => 'imap.example.org', 'in_port' => 993, 'in_username' => 'support', 'in_password' => 'secret']);
    }

    // Background commands.

    /**
     * One queue worker running for this installation: Running.
     */
    public function testRunningCommandIsFound()
    {
        $this->startProcess('artisan queue:work --queue=default,'.\Helper::getWorkerIdentifier());

        $queue_work = $this->command(SystemController::statusData(), 'queue:work');

        $this->assertSame('success', $queue_work['status']);
        $this->assertSame('Running', $queue_work['status_text']);
    }

    public function testMailWorkerIsReportedSeparately()
    {
        $this->startProcess('artisan queue:work database_emails --queue=emails,'.\Helper::getWorkerIdentifier(\App\Console\Kernel::EMAIL_WORKER));

        $mail_worker = $this->command(SystemController::statusData(), 'queue:work (Mail)');
        $this->assertSame('success', $mail_worker['status']);
        $this->assertSame('Running', $mail_worker['status_text']);
    }

    /**
     * Two fetch commands at once: the extra one is named, with the command that stops it.
     */
    public function testCommandRunningTwiceIsReported()
    {
        $this->fetchedMailbox();
        $first = $this->startProcess('artisan tallport:fetch-emails');
        $second = $this->startProcess('artisan tallport:fetch-emails');

        $fetch = $this->command(SystemController::statusData(), 'tallport:fetch-emails');

        $this->assertSame('error', $fetch['status']);
        $this->assertStringStartsWith('2 commands are running at the same time.', $fetch['status_text']);
        $kill = substr($fetch['status_text'], strpos($fetch['status_text'], ' kill '));
        $this->assertContains($kill, [' kill '.$first, ' kill '.$second], 'One of them, not both.');
    }

    /**
     * Two queue workers at once: they're told to restart.
     */
    public function testQueueWorkerRunningTwiceIsRestarted()
    {
        $identifier = \Helper::getWorkerIdentifier();
        $this->startProcess('artisan queue:work --queue=default,'.$identifier);
        $this->startProcess('artisan queue:work --queue=default,'.$identifier);
        \Cache::forget('illuminate:queue:restart');

        $queue_work = $this->command(SystemController::statusData(), 'queue:work');

        $this->assertSame('error', $queue_work['status']);
        $this->assertSame('2 commands were running at the same time. Commands have been restarted', $queue_work['status_text']);
        $this->assertNotNull(\Cache::get('illuminate:queue:restart'));
    }

    /**
     * A command not running is described by when it last ran and last ran successfully;
     * the queue worker that never succeeded suggests clearing the cache.
     */
    public function testCommandNotRunningShowsItsLastRun()
    {
        $last_run = Carbon::parse('2026-10-01 09:00:00')->getTimestamp();
        Option::set('queue_work_last_run', $last_run);

        $queue_work = $this->command(SystemController::statusData(), 'queue:work');

        $this->assertSame('error', $queue_work['status']);
        $this->assertStringStartsWith('Last run: '.User::dateFormat(Carbon::createFromTimestamp($last_run)).' Last successful run: ? Try to', $queue_work['status_text']);
        $this->assertStringContainsString('href="'.route('system.tools').'"', $queue_work['status_text']);
        $this->assertEquals($last_run, $queue_work['last_run']->getTimestamp());
        $this->assertNull($queue_work['last_successful_run']);
    }

    /**
     * Last successful run shows when the command last succeeded, also when it never
     * merely ran.
     */
    public function testCommandLastSuccessfulRunShowsItsOwnDate()
    {
        $last_run = Carbon::parse('2026-10-01 09:00:00')->getTimestamp();
        $last_successful_run = Carbon::parse('2026-10-01 09:30:00')->getTimestamp();
        Option::set('queue_work_last_run', $last_run);
        Option::set('queue_work_last_successful_run', $last_successful_run);

        $queue_work = $this->command(SystemController::statusData(), 'queue:work');

        $this->assertSame('success', $queue_work['status']);
        $this->assertSame('Last successful run: '.User::dateFormat(Carbon::createFromTimestamp($last_successful_run)), $queue_work['status_text']);

        Option::$cache = [];
        Option::where('name', 'queue_work_last_run')->delete();
        $queue_work = $this->command(SystemController::statusData(), 'queue:work');
        $this->assertStringContainsString('Last successful run: '.User::dateFormat(Carbon::createFromTimestamp($last_successful_run)), $queue_work['status_text']);
    }

    // Other checks.

    /**
     * Without public/storage, Status tries to create it (storage:link) and says when
     * it's still missing.
     */
    public function testMissingStorageLinkIsRecreatedOrReported()
    {
        $public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($public);
        $this->temp_dirs[] = $public;
        $this->app->usePublicPath($public);

        $data = SystemController::statusData();

        $this->assertCommandCalled('storage:link');
        $this->assertFalse($data['public_symlink_exists']);
        $this->assertContains('symlink', array_column(SystemController::problems($data), 0));
    }

    /**
     * Redis that the sessions use but that can't be reached is a problem, with the error.
     */
    public function testUnreachableRedisIsReported()
    {
        config(['session.driver' => 'redis', 'database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        $this->app->forgetInstance('redis');

        $data = SystemController::statusData();

        $this->assertSame(['session'], $data['redis_uses']);
        $this->assertNotSame('', $data['redis_error']);
        $redis = collect(SystemController::problems($data))->firstWhere(0, 'redis');
        $this->assertSame($data['redis_error'], $redis[3]);
        $this->assertSame('Tallport keeps session in Redis and can\'t connect to it.', $redis[4]);
    }

    public function testWithUpdatingDisabledTheLatestVersionIsThisOne()
    {
        config(['app.disable_updating' => true]);
        \Updater::shouldReceive('getVersionAvailable')->never();

        $data = SystemController::statusData();

        $this->assertSame(config('app.version'), $data['latest_version']);
        $this->assertFalse($data['new_version_available']);
    }

    /**
     * Each kind of problem, in the order Needs Attention lists them: [key, problem, tone,
     * detail, explanation]; optional extensions and running commands aren't problems.
     */
    public function testProblems()
    {
        $failing_command = ['name' => 'queue:work', 'status' => 'error', 'status_text' => 'Last run: ?'];
        $data = [
            'missing_migrations'      => ['2026_10_01_000000_create_things', '2026_10_02_000000_add_stuff'],
            'redis_uses'              => ['cache', 'queue'],
            'redis_error'             => 'Connection refused',
            'php_extensions'          => ['mbstring' => false, 'gmp' => false, 'curl' => true],
            'functions'               => ['proc_open' => false, 'shell_exec' => true],
            'permissions'             => ['storage/app/' => ['status' => false, 'value' => '0555'], 'bootstrap/cache/' => ['status' => true, 'value' => '0775']],
            'non_writable_cache_file' => '/var/www/storage/framework/cache/data/ab/cd/file',
            'env_is_writable'         => false,
            'public_symlink_exists'   => false,
            'invalid_symlinks'        => ['/public/modules/demo' => '/Modules/Demo/Public'],
            'commands'                => [$failing_command, ['name' => 'tallport:fetch-emails', 'status' => 'success', 'status_text' => 'Running']],
            'failed_jobs'             => [],
        ];

        $problems = SystemController::problems($data);

        $this->assertSame(
            ['migrations', 'redis', 'extension', 'function', 'permissions', 'permissions', 'permissions', 'symlink', 'module_symlinks', 'command'],
            array_column($problems, 0)
        );
        $this->assertSame(['migrations', 'Database updates are waiting', 'danger', '2026_10_01_000000_create_things, 2026_10_02_000000_add_stuff'], array_slice($problems[0], 0, 4));
        $this->assertSame(['Redis isn\'t reachable', 'Connection refused', 'Tallport keeps cache, queue in Redis and can\'t connect to it.'], [$problems[1][1], $problems[1][3], $problems[1][4]]);
        $this->assertSame('The mbstring extension is missing', $problems[2][1]);
        $this->assertStringContainsString('php-mbstring', $problems[2][4]);
        $this->assertSame('The proc_open function is missing', $problems[3][1]);
        $this->assertSame('storage/app/ isn\'t writable', $problems[4][1]);
        $this->assertSame(['Some cache files aren\'t writable', '/var/www/storage/framework/cache/data/ab/cd/file'], [$problems[5][1], $problems[5][3]]);
        $this->assertSame('.env isn\'t writable', $problems[6][1]);
        $this->assertSame('The public/storage link is missing', $problems[7][1]);
        $this->assertSame('/public/modules/demo → /Modules/Demo/Public', $problems[8][3]);
        $this->assertSame(['Queue Worker isn\'t running', 'danger', $failing_command], [$problems[9][1], $problems[9][2], $problems[9][3]]);

        $this->assertSame(['Nostr Listener', 'Receives Nostr messages'], SystemController::taskName('tallport:nostr-listen'));
        $this->assertSame(['some:command', ''], SystemController::taskName('some:command'));
    }

    // Actions.

    /**
     * Run Now: a delayed job becomes due now (available_at, a Unix time, is now).
     */
    public function testRetryJobRunsADelayedJobNow()
    {
        $job_id = \DB::table('jobs')->insertGetId([
            'queue' => 'emails', 'payload' => '{"displayName":"App\\\\Jobs\\\\SendReplyToCustomer"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time() + 3600, 'created_at' => time(),
        ]);

        $this->postForm($this->admin, route('system.action'), ['action' => 'retry_job', 'job_id' => $job_id])
            ->assertRedirect(route('system'))->assertSessionHas('flash_success_floating', 'Done');

        $available_at = \DB::table('jobs')->where('id', $job_id)->value('available_at');
        $this->assertTrue(is_numeric($available_at), 'A Unix time, as the queue expects: '.$available_at);
        $this->assertLessThanOrEqual(time(), (int) $available_at);
        $this->assertGreaterThan(time() - 60, (int) $available_at);
    }

    /**
     * Run Now doesn't keep the request waiting for the queue worker (S7).
     */
    public function testRetryJobAnswersWithoutWaiting()
    {
        $job_id = \DB::table('jobs')->insertGetId([
            'queue' => 'emails', 'payload' => '{"displayName":"App\\\\Jobs\\\\SendReplyToCustomer"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time() + 3600, 'created_at' => time(),
        ]);

        $started = microtime(true);
        $this->postForm($this->admin, route('system.action'), ['action' => 'retry_job', 'job_id' => $job_id])
            ->assertRedirect(route('system'));

        $this->assertLessThan(1, microtime(true) - $started);
    }

    /**
     * Retry for one queue's failed jobs leaves the other queues' alone.
     */
    public function testRetryFailedJobsOfOneQueue()
    {
        dispatch(function () {
        })->onConnection('database')->onQueue('emails');
        $job = \DB::table('jobs')->orderBy('id', 'desc')->first();
        \DB::table('jobs')->where('id', $job->id)->delete();
        $failed = function ($queue) use ($job) {
            return \DB::table('failed_jobs')->insertGetId(['connection' => 'database', 'queue' => $queue, 'payload' => $job->payload, 'exception' => 'Connection refused', 'failed_at' => now()]);
        };
        $emails = $failed('emails');
        $default = $failed('default');

        $this->postForm($this->admin, route('system.action'), ['action' => 'retry_failed_jobs', 'failed_queue' => 'emails'])
            ->assertRedirect(route('system'))->assertSessionHas('flash_success_floating', 'Failed jobs restarted');

        $this->assertFalse(\DB::table('failed_jobs')->where('id', $emails)->exists());
        $this->assertTrue(\DB::table('failed_jobs')->where('id', $default)->exists());
    }

    /**
     * Maintenance's tools run their commands; what a command prints is kept for the page.
     */
    public function testToolsRunTheirCommands()
    {
        $this->postForm($this->admin, route('system.tools.action'), ['action' => 'fetch_emails', 'days' => '3', 'unseen' => '1', 'debug' => '0'])
            ->assertRedirect(route('system'));
        $fetch = collect(\Tests\Support\StubCommand::$calls)->firstWhere('name', 'tallport:fetch-emails');
        $this->assertStringContainsString('--days=3', $fetch['arguments']);
        $this->assertStringContainsString('--unseen=1', $fetch['arguments']);
        $this->assertStringContainsString('--debug=0', $fetch['arguments']);

        $this->postForm($this->admin, route('system.tools.action'), ['action' => 'migrate_db']);
        $this->assertStringContainsString('--force', collect(\Tests\Support\StubCommand::$calls)->firstWhere('name', 'migrate')['arguments']);

        $this->postForm($this->admin, route('system.tools.action'), ['action' => 'logout_users']);
        $this->assertCommandCalled('tallport:logout-users');
        $this->assertNull(\Cache::get('tools_execute_output'));

        $this->commandPrinting('tallport:clear-cache', 'Cache cleared');
        $this->postForm($this->admin, route('system.tools.action'), ['action' => 'clear_cache']);
        $this->assertSame('Cache cleared', trim(\Cache::get('tools_execute_output')));
    }

    // Ajax.

    /**
     * Updating in the request (no background processes) reports the updater's error.
     */
    public function testUpdateInTheRequestReportsErrors()
    {
        $this->useControllerWithoutBackgroundUpdate();

        \Updater::shouldReceive('update')->once()->andThrow(new \Exception('Disk full'));
        $failed = $this->postAjax($this->admin, route('system.ajax'), ['action' => 'update'])->json();
        $this->assertSame('error', $failed['status']);
        $this->assertStringStartsWith('Error occurred. Please try again or try another <a href="', $failed['msg']);
        $this->assertStringEndsWith('<br/><br/>Disk full', $failed['msg']);

        // The updater gave up without saying why.
        \Updater::shouldReceive('update')->once()->andReturn(false);
        $this->assertSame('Unknown error occurred', $this->postAjax($this->admin, route('system.ajax'), ['action' => 'update'])->json()['msg']);
    }

    public function testCheckForUpdatesWhenUpToDate()
    {
        \Updater::shouldReceive('getVersionAvailable')->andReturn(config('app.version'));
        \Updater::shouldReceive('isNewVersionAvailable')->andReturn(false);

        $response = $this->postAjax($this->admin, route('system.ajax'), ['action' => 'check_updates'])->json();

        $this->assertSame('success', $response['status']);
        $this->assertFalse($response['new_version_available']);
        $this->assertSame('You have the latest version installed', $response['msg_success']);
    }

    public function testUnknownActions()
    {
        $this->assertSame('Unknown action', $this->postAjax($this->admin, route('system.ajax'), ['action' => 'no_such_action'])->json()['msg']);
        $this->actingAs($this->admin)->get(route('system.ajax_html', ['action' => 'job_details', 'param' => 999999]))->assertStatus(404);
        $this->actingAs($this->admin)->get(route('system.ajax_html', ['action' => 'no_such_action']))->assertStatus(404);
    }
}
