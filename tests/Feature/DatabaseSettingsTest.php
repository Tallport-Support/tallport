<?php

namespace Tests\Feature;

use App\Misc\DatabaseSettings;
use App\Option;
use Tests\FeatureTestCase;

/**
 * Settings FreeScout kept in .env (timezone, locale, user permissions...)
 * are options that App\Misc\DatabaseSettings puts into config; a variable
 * set in the environment wins and locks the setting.
 */
class DatabaseSettingsTest extends FeatureTestCase
{
    protected $admin;
    protected $timezone;
    protected $env_file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        Option::$cache = [];
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
        if ($this->env_file) {
            @chmod($this->env_file, 0644);
            @unlink($this->env_file);
        }

        parent::tearDown();
    }

    protected function postForm($uri, array $data)
    {
        \Session::start();

        return $this->actingAs($this->admin)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    public function testSavedSettingsAreConfig()
    {
        Option::set('timezone', 'Europe/Amsterdam');
        Option::set('locale', 'nl');
        Option::set('custom_number', 'true');
        Option::set('email_conv_history', 'full');
        Option::set('user_permissions', ['1']);
        Option::set('api.cors_hosts', 'https://a.example.org, https://b.example.org');

        DatabaseSettings::apply();

        $this->assertSame('Europe/Amsterdam', config('app.timezone'));
        $this->assertSame('Europe/Amsterdam', date_default_timezone_get());
        $this->assertSame('nl', config('app.real_locale'));
        $this->assertSame('nl', app()->getLocale());
        $this->assertTrue(config('app.custom_number'), 'As env() read "true".');
        $this->assertSame('full', config('app.email_conv_history'));
        $this->assertEquals([\App\User::PERM_DELETE_CONVERSATIONS], \App\User::getGlobalUserPermissions());
        $this->assertSame('https://a.example.org, https://b.example.org', config('api.cors_hosts'));
        $this->assertSame(['https://a.example.org', 'https://b.example.org'], config('cors.allowed_origins'));
        $this->assertSame(1, (int) config('app.fetch_schedule'), 'Not saved: the default.');
    }

    public function testEnvironmentWins()
    {
        config(['app.settings_in_env' => ['APP_TIMEZONE'], 'app.timezone' => 'Asia/Tokyo']);
        Option::set('timezone', 'Europe/Amsterdam');
        Option::set('email_conv_history', 'last');

        DatabaseSettings::apply();

        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $this->assertSame('last', config('app.email_conv_history'));
        $this->assertTrue(DatabaseSettings::lockedByEnv('timezone'));
        $this->assertFalse(DatabaseSettings::lockedByEnv('locale'));

        // Shown locked, and not saved.
        $this->actingAs($this->admin)->get(route('settings', ['section' => 'general']))->assertOk()
            ->assertSee('Set by APP_TIMEZONE in the .env file')
            ->assertSee('name="settings[timezone]" required="required" disabled="disabled"', false)
            ->assertDontSee('Set by APP_LOCALE in the .env file');
        $this->postForm('/app-settings/general', ['settings' => ['company_name' => 'Tallport', 'timezone' => 'America/Chicago', 'locale' => 'de']])
            ->assertRedirect(route('settings', ['section' => 'general']));
        Option::$cache = [];
        $this->assertSame('Europe/Amsterdam', Option::get('timezone'));
        $this->assertSame('de', Option::get('locale'));

        // Every such setting can be shown locked.
        config(['app.settings_in_env' => array_column(DatabaseSettings::SETTINGS, 'env')]);
        foreach (['general' => 'APP_USER_PERMISSIONS', 'emails' => 'APP_FETCH_SCHEDULE', 'alerts' => 'APP_ALERT_LOGS_PERIOD', 'api' => 'APIWEBHOOKS_CORS_HOSTS'] as $section => $variable) {
            $this->actingAs($this->admin)->get(route('settings', ['section' => $section]))->assertOk()->assertSee('Set by '.$variable.' in the .env file');
        }
    }

    public function testVariablesSetInTheEnvironment()
    {
        $this->assertSame([], DatabaseSettings::setInEnvironment());
        putenv('APP_FETCH_SCHEDULE=5');
        try {
            $this->assertSame(['APP_FETCH_SCHEDULE'], DatabaseSettings::setInEnvironment());
        } finally {
            putenv('APP_FETCH_SCHEDULE');
        }
    }

    /**
     * The migration saves what .env has as options and removes the lines.
     */
    public function testMigrationMovesTheEnvironmentFile()
    {
        require_once base_path('database/migrations/2026_10_30_010101_move_env_settings_to_options.php');
        $this->env_file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->env_file, "APP_URL=https://help.example.org\n\n"
            ."# Timezones: https://github.com/freescout-helpdesk/freescout/wiki/PHP-Timezones\n# Comment it to use default timezone from php.ini\nAPP_TIMEZONE=Europe/Amsterdam\n\n"
            ."# Default language\nAPP_LOCALE=nl\n"
            .'APP_USER_PERMISSIONS='.base64_encode(json_encode(['1']))."\n"
            ."APP_ALERT_LOGS_PERIOD=\n"
            ."APIWEBHOOKS_CORS_HOSTS=\"https://a.example.org,https://b.example.org\"\n"
            ."DB_DATABASE=tallport\n");
        // FreeScout moved user_permissions to .env and kept the option: .env is newer.
        Option::set('user_permissions', []);

        $this->assertTrue(\MoveEnvSettingsToOptions::moveToOptions($this->env_file));

        Option::$cache = [];
        $this->assertSame('Europe/Amsterdam', Option::get('timezone'));
        $this->assertSame('nl', Option::get('locale'));
        $this->assertEquals([\App\User::PERM_DELETE_CONVERSATIONS], Option::get('user_permissions'));
        $this->assertSame('', Option::get('alert_logs_period', 'missing'));
        $this->assertSame('https://a.example.org,https://b.example.org', Option::get('api.cors_hosts'));
        $this->assertSame('missing', Option::get('fetch_schedule', 'missing'), 'Not in .env: not saved.');
        $this->assertSame("APP_URL=https://help.example.org\n\n\nDB_DATABASE=tallport\n", file_get_contents($this->env_file));
    }

    /**
     * When .env can't be written, its lines stay and keep overriding the options.
     */
    public function testMigrationLeavesAReadOnlyEnvironmentFile()
    {
        require_once base_path('database/migrations/2026_10_30_010101_move_env_settings_to_options.php');
        $this->env_file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->env_file, "APP_TIMEZONE=Europe/Amsterdam\n");
        chmod($this->env_file, 0444);
        if (is_writable($this->env_file)) {
            $this->markTestSkipped('Running as root: files can always be written.');
        }

        $this->assertFalse(\MoveEnvSettingsToOptions::moveToOptions($this->env_file));

        $this->assertSame('Europe/Amsterdam', Option::get('timezone'));
        $this->assertSame("APP_TIMEZONE=Europe/Amsterdam\n", file_get_contents($this->env_file));
    }
}
