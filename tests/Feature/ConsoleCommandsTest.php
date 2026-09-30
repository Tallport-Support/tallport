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

        $output = $this->runCommand('freescout:clean-send-log');

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

        $this->runCommand('freescout:clean-notifications-table');

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
            'payload'      => json_encode(['displayName' => 'App\\Jobs\\SendReplyToCustomer']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time() - 13 * 3600,
            'created_at'   => time() - 13 * 3600,
        ];
        $job_id = \DB::table('jobs')->insertGetId($job);

        $this->assertStringContainsString('There are problems with emails queue processing', $this->runCommand('freescout:send-monitor'));
        $this->assertSame('1', Option::where('name', 'send_emails_problem')->value('value'));

        \DB::table('jobs')->where('id', $job_id)->delete();
        $this->assertStringContainsString('Emails queue processing is working', $this->runCommand('freescout:send-monitor'));
        $this->assertNull(Option::where('name', 'send_emails_problem')->value('value'));
    }

    public function testFetchMonitorAlertsOnceAndReportsRecovery()
    {
        $admin = $this->createAdmin(['email' => 'boss@example.org']);
        $this->setOption('alert_fetch', true);
        $this->setOption('alert_recipients', 'oncall@example.org');

        $this->assertStringContainsString('Fetching has not been configured yet', $this->runCommand('freescout:fetch-monitor'));

        // Last successful fetch an hour ago: over the 15 minute alert period.
        $this->setOption('fetch_emails_last_successful_run', time() - 3600);
        $this->assertStringContainsString('There are some problems fetching emails', $this->runCommand('freescout:fetch-monitor'));
        foreach (['boss@example.org', 'oncall@example.org'] as $recipient) {
            $alerts = $this->sentEmailsTo($recipient);
            $this->assertCount(1, $alerts, "$recipient should be alerted.");
            $this->assertSame('[Tallport] Fetching Problems - tallport.test', $alerts[0]->getSubject());
        }
        $this->assertEquals(SendLog::MAIL_TYPE_ALERT, SendLog::where('email', 'oncall@example.org')->value('mail_type'));

        // Still failing: no second alert.
        $this->captured_mail->flush();
        $this->runCommand('freescout:fetch-monitor');
        $this->assertCount(0, $this->sentEmails());

        // Working again: one "recovered" message.
        $this->setOption('fetch_emails_last_successful_run', time() - 60);
        $this->assertStringContainsString('Fetching is working', $this->runCommand('freescout:fetch-monitor'));
        $this->assertSame('[Tallport] Fetching Recovered - tallport.test', $this->sentEmailsTo('boss@example.org')[0]->getSubject());
    }

    public function testFetchMonitorDoesNotAlertWhenAlertsAreOff()
    {
        $this->createAdmin();
        $this->setOption('fetch_emails_last_successful_run', time() - 3600);

        $this->assertStringContainsString('There are some problems fetching emails', $this->runCommand('freescout:fetch-monitor'));
        $this->assertCount(0, $this->sentEmails());
    }

    public function testLogsMonitorEmailsNewLogRecords()
    {
        $this->createAdmin(['email' => 'boss@example.org']);

        $this->assertStringContainsString('No logs to monitor selected', $this->runCommand('freescout:logs-monitor'));

        $this->setOption('alert_logs_names', [ActivityLog::NAME_EMAILS_SENDING]);
        $this->setOption('alert_logs_period', 'hour');
        activity()->useLog(ActivityLog::NAME_EMAILS_SENDING)->withProperties(['error' => 'SMTP connection refused'])->log('error_sending_email_to_customer');
        activity()->useLog(ActivityLog::NAME_USER)->log('login');
        // The monitor only reports records from before "now".
        \DB::table('activity_logs')->where('created_at', '>=', Carbon::now()->subMinute())->update(['created_at' => Carbon::now()->subMinute()]);

        $output = $this->runCommand('freescout:logs-monitor');

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

        $this->assertStringContainsString('Updating finished', $this->runCommand('freescout:update-folder-counters'));

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

        $this->runCommand('freescout:check-conv-viewers');

        $this->assertEquals([7 => [2 => $fresh]], \Cache::get('conv_view'));
    }

    // Admin commands.

    public function testCreateUser()
    {
        $output = $this->runCommand('freescout:create-user', [
            '--role' => 'admin', '--firstName' => 'Cli', '--lastName' => 'Admin',
            '--email' => 'cli-admin@example.org', '--password' => 'cli-password', '--no-interaction' => true,
        ]);

        $user = User::where('email', 'cli-admin@example.org')->first();
        $this->assertStringContainsString('User created with id: '.$user->id, $output);
        $this->assertEquals(User::ROLE_ADMIN, $user->role);
        $this->assertEquals(User::INVITE_STATE_ACTIVATED, $user->invite_state);
        $this->assertTrue(\Hash::check('cli-password', $user->password));
    }

    public function testCreateUserValidation()
    {
        $options = ['--firstName' => 'Cli', '--lastName' => 'User', '--password' => 'cli-password', '--no-interaction' => true];

        $this->assertStringContainsString('Invalid role', $this->runCommand('freescout:create-user', $options + ['--role' => 'owner', '--email' => 'a@example.org']));
        $this->assertStringContainsString('Invalid email address', $this->runCommand('freescout:create-user', $options + ['--role' => 'user', '--email' => 'not-an-email']));

        $existing = $this->createUser();
        $this->assertStringContainsString('User already exists', $this->runCommand('freescout:create-user', $options + ['--role' => 'user', '--email' => $existing->email]));
        $this->assertSame(0, User::whereIn('email', ['a@example.org', 'not-an-email'])->count());
    }

    public function testParseEml()
    {
        $storage = sys_get_temp_dir().'/tallport-storage-'.uniqid();
        mkdir($storage.'/logs', 0777, true);
        copy(base_path('tests/Messages/message-6.eml'), $storage.'/logs/email.eml');
        $this->app->useStoragePath($storage);

        try {
            $output = $this->runCommand('freescout:parse-eml');
        } finally {
            unlink($storage.'/logs/email.eml');
            rmdir($storage.'/logs');
            rmdir($storage);
            // parse-eml resets Webklex's options; restore them for later tests.
            new \Webklex\PHPIMAP\ClientManager(config('imap'));
        }

        $this->assertStringContainsString('Subject:', $output);
        $this->assertStringContainsString('7f9b1a39-536b-47de-a7c3-22aab4aa0964@example.com', $output);
    }

    public function testAfterAppUpdateAndBuildRunTheirSteps()
    {
        $this->runCommand('freescout:after-app-update');
        $this->assertCommandCalled('freescout:clear-cache');
        $this->assertCommandCalled('migrate');

        $this->runCommand('freescout:build');
        $this->assertCommandCalled('freescout:generate-vars');
        $this->assertCommandCalled('laroute:generate');
    }

    public function testUpdateCommandWhenUpToDate()
    {
        $memory_limit = ini_get('memory_limit');
        \Updater::shouldReceive('isNewVersionAvailable')->andReturn(false);

        try {
            $output = $this->runCommand('freescout:update', ['--force' => true]);
        } finally {
            ini_set('memory_limit', $memory_limit);
        }

        $this->assertStringContainsString('You have the latest version installed: '.config('app.version'), $output);
    }

    public function testModuleLicenseCheckWithoutNetwork()
    {
        $this->assertStringContainsString('Checking licenses finished', $this->runCommand('freescout:module-check-licenses'));
    }

    public function testCheckRequirements()
    {
        $output = $this->runCommand('freescout:check-requirements');

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

        $output = $this->runCommand('freescout:fetch-emails');

        $this->assertStringContainsString('Fetching finished', $output);
        $this->assertNotEmpty(Option::where('name', 'fetch_emails_last_run')->value('value'));
    }

    public function testGenerateVars()
    {
        $this->useRealCommand(\App\Console\Commands\GenerateVars::class);
        $public = sys_get_temp_dir().'/tallport-public-'.uniqid();
        mkdir($public.'/js/builds', 0777, true);
        $this->app->instance('path.public', $public);
        \Storage::fake('local');

        try {
            $output = $this->runCommand('freescout:generate-vars');
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
            $output = $this->runCommand('freescout:logout-users');
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
        $this->app->instance('path.public', $public);
        // It also deletes bootstrap/cache/services.php and packages.php. Laravel
        // rebuilds them, but put them back as they were.
        $cached = [];
        foreach ([$this->app->getCachedServicesPath(), $this->app->getCachedPackagesPath()] as $file) {
            if (file_exists($file)) {
                $cached[$file] = file_get_contents($file);
            }
        }

        try {
            $output = $this->runCommand('freescout:clear-cache');
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
        foreach (['clear-compiled', 'view:clear', 'config:cache', 'freescout:generate-vars'] as $step) {
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

        $this->runCommand('freescout:update-folder-counters');

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

    public function testAlertWithoutRecipientsIsHarmless()
    {
        $this->knownBug('S9');

        \MailHelper::sendAlertMail('Something happened', 'Test alert');

        $this->assertCount(0, $this->sentEmails());
    }
}
