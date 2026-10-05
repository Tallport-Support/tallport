<?php

namespace Tests\Feature;

use App\Option;
use App\SendLog;
use Tests\FeatureTestCase;

/**
 * App settings (stored in the options table and .env), the system pages and
 * web cron, and the module actions that don't need freescout.net.
 *
 * Settings saves write .env and clear the cache: the env file is a
 * temporary copy and the cache commands are stubs (see FeatureTestCase).
 */
class SettingsAndSystemTest extends FeatureTestCase
{
    protected $admin;
    protected $env_dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        Option::$cache = [];

        // Settings write to the environment file (.env.testing, see
        // CreatesApplication): give them a temporary one.
        $this->env_dir = sys_get_temp_dir().'/tallport-env-'.uniqid();
        mkdir($this->env_dir);
        file_put_contents($this->env_dir.'/.env.testing', "APP_TIMEZONE=UTC\nAPP_LOCALE=en\n");
        $this->app->useEnvironmentPath($this->env_dir);
    }

    protected function tearDown(): void
    {
        // parent::tearDown() rolls back the test's transaction, so it must run
        // even if cleaning up fails.
        try {
            @unlink($this->env_dir.'/.env.testing');
            @rmdir($this->env_dir);
        } finally {
            parent::tearDown();
        }
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function envFile()
    {
        return file_get_contents($this->env_dir.'/.env.testing');
    }

    // Settings.

    public function testSettingsPagesRender()
    {
        foreach (['general', 'emails', 'alerts'] as $section) {
            $this->actingAs($this->admin)->get('/app-settings/'.$section)->assertStatus(200);
        }
        $this->actingAs($this->admin)->get('/app-settings/no-such-section')->assertStatus(404);
    }

    public function testSaveGeneralSettings()
    {
        $response = $this->postForm($this->admin, '/app-settings/general', ['settings' => [
            'company_name'     => 'Tallport Inc.',
            'email_branding'   => '',
            'open_tracking'    => 1,
            'time_format'      => 1,
            'timezone'         => 'Europe/Amsterdam',
            'locale'           => 'nl',
            'max_message_size' => 20,
        ]]);

        $response->assertRedirect(route('settings', ['section' => 'general']));
        $this->assertSame('Tallport Inc.', Option::where('name', 'company_name')->value('value'));
        // Standard .env syntax: unquoted where that's unambiguous (App\Misc\EnvFile).
        $this->assertStringContainsString("APP_TIMEZONE=Europe/Amsterdam\n", $this->envFile());
        $this->assertStringContainsString('APP_LOCALE=nl', $this->envFile());
        $this->assertStringContainsString('APP_MAX_MESSAGE_SIZE=20', $this->envFile());
        $this->assertCommandCalled('tallport:clear-cache');
    }

    public function testSaveMailSettings()
    {
        $this->postForm($this->admin, '/app-settings/emails', ['settings' => [
            'mail_from' => 'not-an-email', 'mail_driver' => 'mail', 'mail_password' => '',
        ]])->assertSessionHasErrors('settings.mail_from');

        $this->postForm($this->admin, '/app-settings/emails', ['settings' => [
            'mail_from'     => 'helpdesk@example.org',
            'mail_driver'   => 'smtp',
            'mail_host'     => '',
            'mail_port'     => 587,
            'mail_username' => 'helpdesk',
            'mail_password' => 'smtp-secret',
        ]])->assertRedirect(route('settings', ['section' => 'emails']));

        $this->assertSame('helpdesk@example.org', Option::where('name', 'mail_from')->value('value'));
        $this->assertSame('smtp', Option::where('name', 'mail_driver')->value('value'));
        $stored_password = Option::where('name', 'mail_password')->value('value');
        $this->assertNotSame('smtp-secret', $stored_password, 'Stored encrypted.');
        $this->assertSame('smtp-secret', decrypt($stored_password));
    }

    /**
     * Leaving out the password keeps it, like the masked value the form
     * sends; it used to give a 500 (S1).
     */
    public function testMailSettingsWithoutPasswordKeepIt()
    {
        $this->postForm($this->admin, '/app-settings/emails', ['settings' => [
            'mail_from' => 'helpdesk@example.org', 'mail_driver' => 'smtp', 'mail_password' => 'smtp-secret',
        ]]);
        Option::$cache = [];

        $this->postForm($this->admin, '/app-settings/emails', ['settings' => [
            'mail_from' => 'support@example.org', 'mail_driver' => 'smtp',
        ]])->assertRedirect(route('settings', ['section' => 'emails']));

        $this->assertSame('support@example.org', Option::where('name', 'mail_from')->value('value'));
        $this->assertSame('smtp-secret', decrypt(Option::where('name', 'mail_password')->value('value')));
    }

    public function testSystemTestEmail()
    {
        $response = $this->postAjax($this->admin, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'me@example.org']);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $emails = $this->sentEmailsTo('me@example.org');
        $this->assertCount(1, $emails);
        $this->assertSame('test.mailbox', $emails[0]->getHeaders()->get('X-FreeScout-Mail-Type')->getFieldBody());
        $this->assertEquals(SendLog::MAIL_TYPE_TEST, SendLog::where('email', 'me@example.org')->value('mail_type'));
    }

    public function testSettingsAreAdminOnly()
    {
        $agent = $this->createUser();

        $this->postForm($agent, '/app-settings/general', ['settings' => ['company_name' => 'Hijacked']])->assertStatus(403);
        $this->assertSame(403, $this->postAjax($agent, '/app-settings/ajax', ['action' => 'send_test', 'to' => 'me@example.org'])->status());
        $this->assertNull(Option::where('name', 'company_name')->value('value'));
    }

    // System.

    public function testWebCron()
    {
        $this->get('/system/cron/wrong-hash')->assertStatus(404);

        $response = $this->get('/system/cron/'.\Helper::getWebCronHash());

        $response->assertStatus(200);
        $this->assertStringContainsString('commands executed', $response->getContent());
        $this->assertCommandCalled('schedule:run');
    }

    public function testFailedJobsCanBeInspectedAndDeleted()
    {
        $job_id = \DB::table('failed_jobs')->insertGetId([
            'connection' => 'database',
            'queue'      => 'emails',
            'payload'    => json_encode(['displayName' => 'App\\Jobs\\SendReplyToCustomer', 'data' => ['command' => serialize('placeholder')]]),
            'exception'  => 'Connection refused',
            'failed_at'  => now(),
        ]);

        // The status page calls it out at the top; its details open in a dialog.
        $this->actingAs($this->admin)->get(route('system'))->assertOk()
            ->assertSeeInOrder(['need attention', '1 job failed', 'Failed Jobs'])
            ->assertSee('data-fruit-dialog-url', false)
            ->assertSee(route('system.ajax_html', ['action' => 'job_details', 'param' => $job_id]), false);

        $this->actingAs($this->admin)->get('/system/ajax-html/job_details/'.$job_id)
            ->assertStatus(200)->assertSee('Connection refused');

        $this->postForm($this->admin, '/system/action', ['action' => 'delete_failed_jobs', 'failed_queue' => 'emails'])
            ->assertRedirect(route('system'));
        $this->assertFalse(\DB::table('failed_jobs')->where('id', $job_id)->exists());
    }

    public function testSystemToolsRunCommands()
    {
        $this->postForm($this->admin, '/system/tools', ['action' => 'clear_cache'])->assertRedirect(route('system.tools'));

        $this->assertCommandCalled('tallport:clear-cache');
    }

    // Modules.

    public function testModuleActionsWithoutNetwork()
    {
        $unknown = $this->postAjax($this->admin, '/modules/ajax', ['action' => 'activate', 'alias' => 'nosuchmodule'])->json();
        $this->assertSame('Module not found: nosuchmodule', $unknown['msg']);

        $no_license = $this->postAjax($this->admin, '/modules/ajax', ['action' => 'install', 'alias' => 'nosuchmodule', 'license' => ''])->json();
        $this->assertSame('Empty license key', $no_license['msg']);

        $this->assertSame(403, $this->postAjax($this->createUser(), '/modules/ajax', ['action' => 'activate', 'alias' => 'x'])->status());
    }
}
