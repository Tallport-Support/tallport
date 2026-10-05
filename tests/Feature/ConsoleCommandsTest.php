<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\Folder;
use App\Option;
use App\SendLog;
use App\User;
use Carbon\Carbon;
use Tests\FeatureTestCase;

/**
 * The artisan commands the scheduler runs (monitors, cleanups, counters)
 * and the ones admins run by hand, plus the jobs they dispatch.
 *
 * Commands that write outside the database or use the network are either
 * stubbed (see FeatureTestCase), given scratch paths, or left out:
 * clean-tmp (deletes in the system temp dir), module-build, module-update.
 */
class ConsoleCommandsTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Option::$cache = [];
    }

    protected function runCommand($name, array $parameters = [])
    {
        \Artisan::call($name, $parameters);
        Option::$cache = [];

        return \Artisan::output();
    }

    protected function setOption($name, $value)
    {
        Option::set($name, $value);
        Option::$cache = [];
    }

    // Cleanups.

    public function testCleanSendLogDeletesRecordsOlderThanSixMonths()
    {
        $old = SendLog::log(null, 'old@example.org', 'old@example.org', SendLog::MAIL_TYPE_TEST, SendLog::STATUS_ACCEPTED);
        $recent = SendLog::log(null, 'new@example.org', 'new@example.org', SendLog::MAIL_TYPE_TEST, SendLog::STATUS_ACCEPTED);
        \DB::table('send_logs')->where('email', 'old@example.org')->update(['created_at' => Carbon::now()->subMonths(7)]);
        \DB::table('send_logs')->where('email', 'new@example.org')->update(['created_at' => Carbon::now()->subMonths(5)]);

        $output = $this->runCommand('tallport:clean-send-log');

        $this->assertStringContainsString('Deleted send logs', $output);
        $this->assertSame(['new@example.org'], SendLog::whereIn('email', ['old@example.org', 'new@example.org'])->pluck('email')->all());
    }

    public function testCleanNotificationsDeletesOnlyOldReadOnes()
    {
        $user = $this->createUser();
        $insert = function ($id, $read_at, $created_at) use ($user) {
            \DB::table('notifications')->insert([
                'id' => $id, 'type' => 'App\\Notifications\\WebsiteNotification', 'notifiable_id' => $user->id,
                'notifiable_type' => User::class, 'data' => '{}', 'read_at' => $read_at, 'created_at' => $created_at, 'updated_at' => $created_at,
            ]);
        };
        $insert('00000000-0000-0000-0000-000000000001', Carbon::now()->subMonths(7), Carbon::now()->subMonths(7));
        $insert('00000000-0000-0000-0000-000000000002', null, Carbon::now()->subMonths(7));
        $insert('00000000-0000-0000-0000-000000000003', Carbon::now()->subMonth(), Carbon::now()->subMonth());

        $this->runCommand('tallport:clean-notifications-table');

        $this->assertEquals(
            ['00000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000003'],
            \DB::table('notifications')->where('notifiable_id', $user->id)->orderBy('id')->pluck('id')->all()
        );
    }

    // Monitors.

    public function testSendMonitorFlagsStuckReplies()
    {
        $job = [
            'queue'        => 'emails',
            'payload'      => json_encode(['uuid' => '6f1c0d2e-0000-4000-8000-000000000000', 'displayName' => 'App\\Jobs\\SendReplyToCustomer']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 13 * 3600,
            'created_at'   => time() - 13 * 3600,
        ];
        $job_id = \DB::table('jobs')->insertGetId($job);

        $this->assertStringContainsString('There are problems with emails queue processing', $this->runCommand('tallport:send-monitor'));
        $this->assertSame('1', Option::where('name', 'send_emails_problem')->value('value'));

        \DB::table('jobs')->where('id', $job_id)->delete();
        $this->assertStringContainsString('Emails queue processing is working', $this->runCommand('tallport:send-monitor'));
        $this->assertNull(Option::where('name', 'send_emails_problem')->value('value'));
    }

    public function testFetchMonitorAlertsOnceAndReportsRecovery()
    {
        $admin = $this->createAdmin(['email' => 'boss@example.org']);
        $this->setOption('alert_fetch', true);
        $this->setOption('alert_recipients', 'oncall@example.org');

        $this->assertStringContainsString('Fetching has not been configured yet', $this->runCommand('tallport:fetch-monitor'));

        // Last successful fetch an hour ago: over the 15 minute alert period.
        $this->setOption('fetch_emails_last_successful_run', time() - 3600);
        $this->assertStringContainsString('There are some problems fetching emails', $this->runCommand('tallport:fetch-monitor'));
        foreach (['boss@example.org', 'oncall@example.org'] as $recipient) {
            $alerts = $this->sentEmailsTo($recipient);
            $this->assertCount(1, $alerts, "$recipient should be alerted.");
            $this->assertSame('[Tallport] Fetching Problems - tallport.test', $alerts[0]->getSubject());
        }
        $this->assertEquals(SendLog::MAIL_TYPE_ALERT, SendLog::where('email', 'oncall@example.org')->value('mail_type'));

        // Still failing: no second alert.
        $this->captured_mail->flush();
        $this->runCommand('tallport:fetch-monitor');
        $this->assertCount(0, $this->sentEmails());

        // Working again: one "recovered" message.
        $this->setOption('fetch_emails_last_successful_run', time() - 60);
        $this->assertStringContainsString('Fetching is working', $this->runCommand('tallport:fetch-monitor'));
        $this->assertSame('[Tallport] Fetching Recovered - tallport.test', $this->sentEmailsTo('boss@example.org')[0]->getSubject());
    }

    public function testFetchMonitorDoesNotAlertWhenAlertsAreOff()
    {
        $this->createAdmin();
        $this->setOption('fetch_emails_last_successful_run', time() - 3600);

        $this->assertStringContainsString('There are some problems fetching emails', $this->runCommand('tallport:fetch-monitor'));
        $this->assertCount(0, $this->sentEmails());
    }

    public function testLogsMonitorEmailsNewLogRecords()
    {
        $this->createAdmin(['email' => 'boss@example.org']);

        $this->assertStringContainsString('No logs to monitor selected', $this->runCommand('tallport:logs-monitor'));

        $this->setOption('alert_logs_names', [ActivityLog::NAME_EMAILS_SENDING]);
        $this->setOption('alert_logs_period', 'hour');
        activity()->useLog(ActivityLog::NAME_EMAILS_SENDING)->withProperties(['error' => 'SMTP connection refused'])->log('error_sending_email_to_customer');
        activity()->useLog(ActivityLog::NAME_USER)->log('login');
        // The monitor only reports records from before "now".
        \DB::table('activity_logs')->where('created_at', '>=', Carbon::now()->subMinute())->update(['created_at' => Carbon::now()->subMinute()]);

        $output = $this->runCommand('tallport:logs-monitor');

        $this->assertStringContainsString('Monitoring finished', $output);
        $alert = $this->sentEmailsTo('boss@example.org');
        $this->assertCount(1, $alert);
        $this->assertSame('[Tallport] Logs Monitoring - tallport.test', $alert[0]->getSubject());
        $this->assertStringContainsString('SMTP connection refused', $alert[0]->getBody());
        $this->assertStringNotContainsString('login', strip_tags($alert[0]->getBody()), 'Only the selected logs.');
    }

    // Counters and viewers.

    public function testUpdateFolderCountersRepairsCounts()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $unassigned = Folder::where('mailbox_id', $mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();
        $unassigned->active_count = 42;
        $unassigned->total_count = 42;
        $unassigned->save();

        $this->assertStringContainsString('Updating finished', $this->runCommand('tallport:update-folder-counters'));

        $unassigned->refresh();
        $this->assertEquals(1, $unassigned->active_count);
        $this->assertEquals(1, $unassigned->total_count);
    }

    public function testCheckConvViewersRemovesStaleViewers()
    {
        // Viewers are dropped after 25 seconds without a heartbeat.
        $ago = function ($seconds) {
            return Carbon::now()->subSeconds($seconds)->format('Y-m-d H:i:s');
        };
        $fresh = ['t' => $ago(5), 'r' => 1];
        \Cache::put('conv_view', [
            7 => [1 => ['t' => $ago(60), 'r' => 0], 2 => $fresh],
            8 => [3 => ['t' => $ago(120), 'r' => 0]],
        ], 20);

        $this->runCommand('tallport:check-conv-viewers');

        $this->assertEquals([7 => [2 => $fresh]], \Cache::get('conv_view'));
    }

    // Admin commands.

    public function testCreateUser()
    {
        $output = $this->runCommand('tallport:create-user', [
            '--role' => 'admin', '--firstName' => 'Cli', '--lastName' => 'Admin',
            '--email' => 'cli-admin@example.org', '--password' => 'cli-password', '--no-interaction' => true,
        ]);

        $user = User::where('email', 'cli-admin@example.org')->first();
        $this->assertStringContainsString('User created with id: '.$user->id, $output);
        $this->assertEquals(User::ROLE_ADMIN, $user->role);
        $this->assertEquals(User::INVITE_STATE_ACTIVATED, $user->invite_state);
        $this->assertTrue(\Hash::check('cli-password', $user->password));
    }

    /**
     * Answering "no" creates nothing and says so (S14).
     */
    public function testCreateUserDeclined()
    {
        $command = $this->app->make(\App\Console\Commands\CreateUser::class);
        $command->setLaravel($this->app);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $tester->setInputs(['no']);

        $tester->execute([
            '--role' => 'user', '--firstName' => 'Cli', '--lastName' => 'User',
            '--email' => 'declined@example.org', '--password' => 'cli-password',
        ]);

        $this->assertStringContainsString('User not created', $tester->getDisplay());
        $this->assertStringNotContainsString('User created', $tester->getDisplay());
        $this->assertNull(User::where('email', 'declined@example.org')->first());
    }

    public function testCreateUserValidation()
    {
        $options = ['--firstName' => 'Cli', '--lastName' => 'User', '--password' => 'cli-password', '--no-interaction' => true];

        $this->assertStringContainsString('Invalid role', $this->runCommand('tallport:create-user', $options + ['--role' => 'owner', '--email' => 'a@example.org']));
        $this->assertStringContainsString('Invalid email address', $this->runCommand('tallport:create-user', $options + ['--role' => 'user', '--email' => 'not-an-email']));

        $existing = $this->createUser();
        $this->assertStringContainsString('User already exists', $this->runCommand('tallport:create-user', $options + ['--role' => 'user', '--email' => $existing->email]));
        $this->assertSame(0, User::whereIn('email', ['a@example.org', 'not-an-email'])->count());
    }

    public function testParseEml()
    {
        $storage = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        mkdir($storage.'/logs', 0777, true);
        copy(base_path('tests/Messages/message-6.eml'), $storage.'/logs/email.eml');
        $this->app->useStoragePath($storage);

        try {
            $output = $this->runCommand('tallport:parse-eml');
        } finally {
            unlink($storage.'/logs/email.eml');
            rmdir($storage.'/logs');
            rmdir($storage);
        }

        $this->assertStringContainsString('Subject:', $output);
        $this->assertStringContainsString('7f9b1a39-536b-47de-a7c3-22aab4aa0964@example.com', $output);

        $output = $this->runCommand('tallport:parse-eml', ['file' => base_path('tests/Messages/webklex/plain.eml')]);
        $this->assertStringContainsString('"mail":"from@someone.com"', $output);
    }

    public function testAfterAppUpdateAndBuildRunTheirSteps()
    {
        $this->runCommand('tallport:after-app-update');
        $this->assertCommandCalled('tallport:clear-cache');
        $this->assertCommandCalled('migrate');

        $this->runCommand('tallport:build');
        $this->assertCommandCalled('tallport:generate-vars');
        $this->assertCommandCalled('laroute:generate');
    }

    public function testUpdateCommandWhenUpToDate()
    {
        $memory_limit = ini_get('memory_limit');
        \Updater::shouldReceive('isNewVersionAvailable')->andReturn(false);
        ini_set('memory_limit', '512M');

        try {
            $output = $this->runCommand('tallport:update', ['--force' => true]);
            $limit_after = ini_get('memory_limit');
        } finally {
            ini_set('memory_limit', $memory_limit);
        }

        $this->assertSame('512M', $limit_after, 'The memory limit is only ever raised (S15).');
        $this->assertStringContainsString('You have the latest version installed: '.config('app.version'), $output);
    }

    /**
     * After updating, tallport:after-app-update runs in a new process (this
     * one still has the old code's autoloader); not when updating failed.
     */
    public function testUpdateCommandRunsAfterAppUpdateSeparately()
    {
        $command = new class extends \App\Console\Commands\Update {
            public static $runs = 0;

            protected function afterAppUpdate()
            {
                static::$runs++;

                return 0;
            }
        };
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($command);
        \Updater::shouldReceive('isNewVersionAvailable')->andReturn(true);
        \Updater::shouldReceive('update')->once()->andReturn(true);

        $this->runCommand('tallport:update', ['--force' => true]);
        $this->assertSame(1, $command::$runs);

        \Updater::shouldReceive('update')->andThrow(new \Exception('download failed'));
        $output = $this->runCommand('tallport:update', ['--force' => true]);
        $this->assertStringContainsString('Error occurred: download failed', $output);
        $this->assertSame(1, $command::$runs);
    }

    public function testUpdateCommandRefusedWhenUpdatingIsDisabled()
    {
        config(['app.disable_updating' => true]);
        \Updater::shouldReceive('isNewVersionAvailable')->never();
        \Updater::shouldReceive('update')->never();

        $output = $this->runCommand('tallport:update', ['--force' => true]);

        $this->assertStringContainsString('Updating is disabled', $output);
    }

    /**
     * module-update asks only the modules' own latestVersionUrl, never FreeScout's
     * directory: with none to ask, all modules are up to date.
     */
    public function testModuleUpdateAsksOnlyTheModules()
    {
        $dir = sys_get_temp_dir().'/tallport-modules-'.uniqid();
        mkdir($dir);
        $this->app->instance('modules', new \App\Modules\Repository($this->app, $dir));

        // It runs cache:clear itself, which replaces Artisan::output().
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        \Artisan::call('tallport:module-update', [], $output);
        $this->assertStringContainsString('All modules are up-to-date', $output->fetch());

        \Artisan::call('tallport:module-update', ['module_alias' => 'nosuchmodule'], $output);
        $this->assertStringContainsString('Module with the following alias not found: nosuchmodule', $output->fetch());
        rmdir($dir);
    }

    /**
     * clean-tmp may only remove FreeScout's own temp files and SwiftMailer's
     * cache directories, never other programs' files (S11).
     */
    public function testCleanTmpRemovesOnlyItsOwnFiles()
    {
        $dir = sys_get_temp_dir().'/tallport-tmp-'.uniqid();
        mkdir($dir);
        $old = time() - 8 * 86400;
        $make = function ($path, $mtime = null, $is_dir = false) use ($dir) {
            $full = $dir.'/'.$path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0777, true);
            }
            $is_dir ? mkdir($full) : file_put_contents($full, 'x');
            touch($full, $mtime ?: time());
        };
        $hex = function ($n) {
            return str_repeat(dechex($n), 32);
        };
        $prefix = \Helper::getTempFilePrefix();

        $make($prefix.'old', $old);                        // FreeScout's, old: remove
        $make($prefix.'new');                              // FreeScout's, new: keep
        $make('other-program-file', $old);                 // not ours: keep
        $make($hex(1).'/body', $old);                      // SwiftMailer cache, old: remove
        touch($dir.'/'.$hex(1), $old);
        $make($hex(2), $old, true);                        // empty SwiftMailer-like dir, old: remove
        $make($hex(3).'/body');                            // SwiftMailer cache, recent: keep
        $make($hex(4).'/database.sqlite', $old);           // other contents: keep
        touch($dir.'/'.$hex(4), $old);
        $make('project/'.$hex(5).'/body', $old);           // nested: keep
        touch($dir.'/project/'.$hex(5), $old);

        try {
            (new \App\Console\Commands\CleanTmp())->cleanDirectory($dir);
            $left = [];
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
                $left[] = substr($file->getPathname(), strlen($dir) + 1);
            }
            sort($left);
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }

        $this->assertSame([
            $hex(3), $hex(3).'/body',
            $hex(4), $hex(4).'/database.sqlite',
            $prefix.'new',
            'other-program-file',
            'project', 'project/'.$hex(5), 'project/'.$hex(5).'/body',
        ], $left);
    }

    public function testCheckRequirements()
    {
        $output = $this->runCommand('tallport:check-requirements');

        $this->assertStringContainsString('PHP Version', $output);
        $this->assertStringContainsString('PHP Extensions', $output);
    }

    /**
     * Replace a stubbed command by the real one for this test.
     */
    protected function useRealCommand($class)
    {
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand($this->app->make($class));
    }

    public function testFetchEmailsWithoutConfiguredMailboxes()
    {
        $this->useRealCommand(\App\Console\Commands\FetchEmails::class);
        $this->createMailbox();

        $output = $this->runCommand('tallport:fetch-emails');

        $this->assertStringContainsString('Fetching finished', $output);
        $this->assertNotEmpty(Option::where('name', 'fetch_emails_last_run')->value('value'));
    }

    public function testGenerateVars()
    {
        $this->useRealCommand(\App\Console\Commands\GenerateVars::class);
        $public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($public.'/js/builds', 0777, true);
        $this->app->usePublicPath($public);
        \Storage::fake('local');

        try {
            $output = $this->runCommand('tallport:generate-vars');
            $vars = file_get_contents($public.'/js/builds/vars.js');
        } finally {
            @unlink($public.'/js/builds/vars.js');
            @rmdir($public.'/js/builds');
            @rmdir($public.'/js');
            @rmdir($public);
        }

        $this->assertStringContainsString('Created', $output);
        $this->assertStringContainsString('Vars', $vars);
    }

    public function testLogoutUsersDeletesSessions()
    {
        $this->useRealCommand(\App\Console\Commands\LogoutUsers::class);
        $storage = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        mkdir($storage.'/framework/sessions', 0777, true);
        touch($storage.'/framework/sessions/session-one');
        touch($storage.'/framework/sessions/session-two');
        $this->app->useStoragePath($storage);

        try {
            $output = $this->runCommand('tallport:logout-users');
            $left = glob($storage.'/framework/sessions/*');
        } finally {
            array_map('unlink', glob($storage.'/framework/sessions/*'));
            @rmdir($storage.'/framework/sessions');
            @rmdir($storage.'/framework');
            @rmdir($storage);
        }

        $this->assertStringContainsString('Deleted sessions: 2', $output);
        $this->assertSame([], $left);
    }

    public function testClearCacheRunsAllSteps()
    {
        $this->useRealCommand(\App\Console\Commands\ClearCache::class);
        // Its own steps stay stubbed; view:clear would empty the dev app's compiled views.
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new \Tests\Support\StubCommand('view:clear'));
        // The build cleanup runs in a scratch public directory.
        $public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($public.'/js/builds', 0777, true);
        mkdir($public.'/css/builds', 0777, true);
        file_put_contents($public.'/js/builds/old.js', '');
        file_put_contents($public.'/js/builds/vars.js', '');
        file_put_contents($public.'/css/builds/old.css', '');
        $this->app->usePublicPath($public);
        // It also deletes bootstrap/cache/services.php and packages.php. Laravel
        // rebuilds them, but put them back as they were.
        $cached = [];
        foreach ([$this->app->getCachedServicesPath(), $this->app->getCachedPackagesPath()] as $file) {
            if (file_exists($file)) {
                $cached[$file] = file_get_contents($file);
            }
        }

        try {
            $output = $this->runCommand('tallport:clear-cache');
            $left = array_map('basename', array_merge(glob($public.'/js/builds/*'), glob($public.'/css/builds/*')));
        } finally {
            foreach ($cached as $file => $contents) {
                file_put_contents($file, $contents);
            }
            array_map('unlink', array_merge(glob($public.'/js/builds/*'), glob($public.'/css/builds/*')));
            @rmdir($public.'/js/builds');
            @rmdir($public.'/css/builds');
            @rmdir($public.'/js');
            @rmdir($public.'/css');
            @rmdir($public);
        }

        $this->assertStringContainsString('Cleared: JS and CSS builds', $output);
        $this->assertSame(['vars.js'], $left, 'Builds are removed, vars.js is kept.');
        foreach (['clear-compiled', 'view:clear', 'config:cache', 'tallport:generate-vars'] as $step) {
            $this->assertCommandCalled($step);
        }
    }

    public function testFolderCountersInBackground()
    {
        config(['app.update_folder_counters_in_background' => true]);
        $mailbox = $this->createMailbox();
        $unassigned = Folder::where('mailbox_id', $mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();
        $unassigned->active_count = 42;
        $unassigned->save();

        $this->runCommand('tallport:update-folder-counters');

        $this->assertEquals(0, $unassigned->fresh()->active_count, 'The job ran (sync queue) and fixed the count.');
        $this->assertFalse(\Cache::has('folder_update_lock_'.$unassigned->id), 'The lock is released.');
    }

    // Jobs.

    public function testBackgroundActionFiresHook()
    {
        $received = [];
        \Eventy::addAction('tests.background_action', function ($a, $b) use (&$received) {
            $received = [$a, $b];
        }, 20, 2);

        \Helper::backgroundAction('tests.background_action', ['first', 'second']);

        $this->assertSame(['first', 'second'], $received);
    }

    /**
     * A failure for one recipient must not be forgotten when a later one
     * succeeds (S10): the job fails and the failure is logged.
     */
    public function testAlertFailureForOneRecipientFailsTheJob()
    {
        $this->setOption('alert_recipients', 'broken@example.org,ok@example.org');
        // A transport that refuses one address and captures the rest.
        $failing = new class extends \Illuminate\Mail\Transport\ArrayTransport {
            public function send(\Symfony\Component\Mime\RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                if (array_key_exists('broken@example.org', (new \Tests\Support\CapturedEmail($message))->getTo())) {
                    throw new \Exception('Mail server refused broken@example.org');
                }

                return parent::send($message, $envelope);
            }
        };
        $this->captured_mail = $failing;
        // Extenders of a service that's already built apply to that instance
        // only; forget it so this one also applies when FreeScout rebuilds it.
        $this->app->forgetInstance('mail.manager');
        $this->app->extend('mail.manager', function ($manager) use ($failing) {
            return static::captureAllMailDrivers($manager, $failing);
        });
        \MailHelper::$last_mail_config_hash = '';

        try {
            (new \App\Jobs\SendAlert('Something happened', 'Test alert'))->handle();
            $this->fail('The job should fail when a recipient could not be alerted.');
        } catch (\Exception $e) {
            $this->assertSame('Mail server refused broken@example.org', $e->getMessage());
        }

        $this->assertCount(1, $this->sentEmailsTo('ok@example.org'));
        $this->assertEquals(SendLog::STATUS_SEND_ERROR, SendLog::where('email', 'broken@example.org')->value('status'));
        $this->assertEquals(SendLog::STATUS_ACCEPTED, SendLog::where('email', 'ok@example.org')->value('status'));
    }

    public function testAlertWithoutRecipientsIsHarmless()
    {
        \MailHelper::sendAlertMail('Something happened', 'Test alert');

        $this->assertCount(0, $this->sentEmails());
    }
}
