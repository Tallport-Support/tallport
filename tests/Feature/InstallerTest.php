<?php

namespace Tests\Feature;

use App\Install\EnvironmentManager;
use App\Install\PermissionsChecker;
use App\Install\RequirementsChecker;
use Illuminate\Filesystem\Filesystem;
use Tests\FeatureTestCase;

/**
 * The web installer (/install): requirements, the .env it writes, the
 * database step and the last step that creates the admin. It runs in a
 * scratch folder: its storage (the .installed marker) and its .env are
 * not the application's.
 */
class InstallerTest extends FeatureTestCase
{
    protected $dir;
    protected $storage;
    protected $timezone;

    protected function setUp(): void
    {
        parent::setUp();

        // The last step sets PHP's timezone to the one chosen.
        $this->timezone = date_default_timezone_get();
        $this->dir = sys_get_temp_dir().'/tallport-installer-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/storage', 0777, true);
        $this->storage = $this->app->storagePath();
        $this->app->useStoragePath($this->dir.'/storage');

        // The .env of the scratch folder.
        $base = $this->app->basePath();
        $this->app->setBasePath($this->dir);
        $manager = new EnvironmentManager();
        $this->app->setBasePath($base);
        $this->app->useStoragePath($this->dir.'/storage');
        $this->app->instance(EnvironmentManager::class, $manager);

        // Not configured yet: the installer is open.
        config(['database.connections.mysql.password' => '']);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->storage);
        (new Filesystem())->deleteDirectory($this->dir);
        date_default_timezone_set($this->timezone);

        parent::tearDown();
    }

    protected function wizard(array $data = [])
    {
        return array_merge([
            'app_url' => 'http://help.example.org', 'app_force_https' => 'true', 'app_timezone' => 'Europe/Amsterdam', 'app_locale' => 'nl',
            'database_connection' => 'sqlite', 'database_hostname' => 'localhost', 'database_port' => '3306', 'database_name' => ':memory:',
            'database_username' => 'tallport', 'database_password' => 'pa ss#word',
            'admin_email' => 'owner@example.org', 'admin_first_name' => 'Olivia', 'admin_last_name' => 'Owner', 'admin_password' => 'correct horse',
        ], $data);
    }

    public function testPagesBeforeTheDatabase()
    {
        $this->get('/install')->assertOk()->assertSee('/install/requirements');
        $this->get('/install/requirements')->assertOk()->assertSee('OpenSSL')->assertSee('PCRE JIT')->assertSee(PHP_VERSION);
        $this->get('/install/environment')->assertOk();

        // The first look creates an empty .env.
        $this->assertFileDoesNotExist($this->dir.'/.env');
        $this->get('/install/environment/wizard')->assertOk()->assertSee('app_url');
        $this->assertSame('', file_get_contents($this->dir.'/.env'));

        $this->post('/install/environment/saveClassic', ['envConfig' => "APP_URL=https://help.example.org\n"])
            ->assertRedirect(route('LaravelInstaller::environmentClassic'))->assertSessionHas('message');
        $this->assertSame('APP_URL=https://help.example.org', file_get_contents($this->dir.'/.env'));
        $this->get('/install/environment/classic')->assertOk()->assertSee('APP_URL=https://help.example.org');
    }

    public function testEnvironmentIsCheckedAndWritten()
    {
        $this->post('/install/environment/saveWizard', $this->wizard(['app_url' => 'not a url', 'admin_email' => '']))
            ->assertOk()->assertSee('The app url format is invalid.')->assertSee('error-block', false);
        $this->assertSame('', file_get_contents($this->dir.'/.env'));

        // A database that doesn't answer.
        $this->post('/install/environment/saveWizard', $this->wizard(['database_connection' => 'mysql', 'database_hostname' => '127.0.0.1', 'database_port' => '1', 'database_name' => 'tallport']))
            ->assertOk()->assertSee('Could not establish database connection')->assertSee('Database Host: Please check entered value.');
        $this->assertSame('', file_get_contents($this->dir.'/.env'));

        $this->post('/install/environment/saveWizard', $this->wizard())->assertRedirect(route('LaravelInstaller::database'));

        $env = file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString("APP_URL=https://help.example.org\n", $env);
        $this->assertStringContainsString("SESSION_SECURE_COOKIE=true\n", $env);
        // The timezone and language are saved in the database at the last step.
        $this->assertStringNotContainsString('APP_TIMEZONE', $env);
        $this->assertStringNotContainsString('APP_LOCALE', $env);
        $this->assertStringContainsString("DB_CONNECTION=sqlite\nDB_HOST=localhost\nDB_PORT=3306\nDB_DATABASE=:memory:\nDB_USERNAME=tallport\nDB_PASSWORD=\"pa ss#word\"\n", $env);
        $this->assertStringContainsString('APP_KEY='.config('app.key')."\n", $env);
        $this->assertStringNotContainsString('DB_CHARSET', $env);
        $this->assertCommandCalled('tallport:clear-cache');
        // What was entered is kept for the last step.
        $this->assertSame('owner@example.org', session('_old_input.admin_email'));
        $this->assertSame('Europe/Amsterdam', session('_old_input.app_timezone'));
    }

    public function testDatabaseStepMigrates()
    {
        $default = config('database.default');
        config([
            'database.default' => 'installer',
            'database.connections.installer' => ['driver' => 'sqlite', 'database' => $this->dir.'/database.sqlite', 'prefix' => ''],
        ]);
        try {
            $response = $this->get('/install/database');
        } finally {
            config(['database.default' => $default]);
            \DB::purge('installer');
        }

        $response->assertRedirect(route('LaravelInstaller::final'));
        $this->assertCommandCalled('migrate');
        $message = session('message');
        $this->assertSame('success', $message['status']);
        $this->assertStringContainsString('Using SqlLite database: '.$this->dir.'/database.sqlite', $message['dbOutputLog']);
        $this->assertFileExists($this->dir.'/database.sqlite');
    }

    public function testLastStepCreatesTheAdminAndClosesTheInstaller()
    {
        \App\User::where('role', \App\User::ROLE_ADMIN)->delete();
        file_put_contents($this->dir.'/.env', "APP_URL=https://help.example.org\n");

        $this->withSession(['_old_input' => ['admin_email' => 'owner@example.org', 'admin_password' => 'correct horse', 'admin_first_name' => 'Olivia', 'admin_last_name' => 'Owner',
            'app_timezone' => 'Europe/Amsterdam', 'app_locale' => 'nl']])
            ->get('/install/final')->assertOk()->assertSee('owner@example.org')->assertSee('Tallport Installer was succesvol GEÏNSTALLEERD op', false);

        // The timezone and language from the environment step are settings, also the admin's.
        \Option::$cache = [];
        $this->assertSame('Europe/Amsterdam', \Option::get('timezone'));
        $this->assertSame('nl', \Option::get('locale'));
        $this->assertSame('Europe/Amsterdam', config('app.timezone'));
        $this->assertSame('nl', config('app.real_locale'));
        $this->assertStringNotContainsString('APP_TIMEZONE', file_get_contents($this->dir.'/.env'));

        $admin = \App\User::where('email', 'owner@example.org')->first();
        $this->assertSame('Europe/Amsterdam', $admin->timezone);
        $this->assertSame(\App\User::ROLE_ADMIN, (int) $admin->role);
        $this->assertSame('Olivia', $admin->first_name);
        $this->assertTrue(\Hash::check('correct horse', $admin->password));
        $this->assertCommandCalled('storage:link');
        $this->assertCommandCalled('tallport:clear-cache');
        $this->assertStringContainsString(date('Y/m/d'), file_get_contents($this->dir.'/storage/.installed'));

        // Installed: the installer is closed, and running the last step again only notes it.
        $this->get('/install')->assertRedirect(route('dashboard'));
        $this->assertSame(1, substr_count(file_get_contents($this->dir.'/storage/.installed'), "\n"));
        (new \App\Install\InstalledFileManager())->update();
        $this->assertSame(2, substr_count(file_get_contents($this->dir.'/storage/.installed'), "\n"));
        $this->assertSame(1, \App\User::where('role', \App\User::ROLE_ADMIN)->count());
    }

    public function testInstalledInstallerCanAlsoBeA404()
    {
        touch($this->dir.'/storage/.installed');

        foreach (['abort', '404', ''] as $action) {
            config(['installer.installedAlreadyAction' => $action]);
            $this->get('/install/requirements')->assertNotFound();
        }
    }

    public function testRequirementsChecker()
    {
        $checker = new RequirementsChecker();

        $result = $checker->check(['php' => ['openssl', 'no_such_extension'], 'apache' => ['mod_rewrite']]);
        $this->assertSame(['openssl' => true, 'no_such_extension' => false], $result['requirements']['php']);
        $this->assertTrue($result['errors']);
        // Without Apache's functions its modules can't be checked.
        $this->assertArrayNotHasKey('apache', $result['requirements']);
        $this->assertSame(['requirements' => ['php' => ['openssl' => true]]], $checker->check(['php' => ['openssl']]));

        $this->assertTrue($checker->checkPHPversion('8.5.0')['supported']);
        $this->assertSame(PHP_VERSION, $checker->checkPHPversion('8.5.0')['full']);
        $this->assertFalse($checker->checkPHPversion('99.0.0')['supported']);
        $this->assertSame('7.0.0', $checker->checkPHPversion()['minimum']);
        config(['installer.core.maxPhpVersion' => '8.0.0']);
        $this->assertFalse($checker->checkPHPversion('7.0.0')['supported']);
    }

    public function testPermissionsChecker()
    {
        mkdir($this->dir.'/writable');
        mkdir($this->dir.'/readonly');
        chmod($this->dir.'/readonly', 0555);
        $base = $this->app->basePath();
        $this->app->setBasePath($this->dir);
        try {
            $result = (new PermissionsChecker())->check(['writable/' => '775', 'missing/' => '775', 'readonly/' => '775']);
        } finally {
            $this->app->setBasePath($base);
            $this->app->useStoragePath($this->dir.'/storage');
            chmod($this->dir.'/readonly', 0755);
        }

        $this->assertSame([
            ['folder' => 'writable/', 'permission' => '775', 'isSet' => true],
            ['folder' => 'missing/', 'permission' => '775', 'isSet' => true],
            ['folder' => 'readonly/', 'permission' => '775', 'isSet' => function_exists('posix_geteuid') && posix_geteuid() === 0],
        ], $result['permissions']);
        $this->assertTrue($result['errors']);
        // A missing folder is created; the test file is removed.
        $this->assertDirectoryExists($this->dir.'/missing');
        $this->assertSame([], glob($this->dir.'/{writable,missing}/.installer_test', GLOB_BRACE));
    }
}
