<?php

namespace Tests\Feature;

use App\Modules\Repository;
use Illuminate\Filesystem\Filesystem;
use Tests\FeatureTestCase;

/**
 * FreeScout's module system (App\Modules) on a module in a temporary folder.
 */
class ModuleSystemTest extends FeatureTestCase
{
    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-modules-'.uniqid();
        mkdir($this->dir.'/TpModule', 0777, true);
        Repository::$active_cache = [];
        \App\Module::$modules = null;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->dir);
        Repository::$active_cache = [];
        \App\Module::$modules = null;
        parent::tearDown();
    }

    protected function makeModule(array $files = [])
    {
        file_put_contents($this->dir.'/TpModule/module.json', json_encode([
            'name' => 'TpModule', 'alias' => 'tpmodule', 'description' => 'Test module', 'version' => '1.0.0',
            'active' => 0, 'order' => 0, 'providers' => [], 'aliases' => new \stdClass(), 'files' => $files, 'requires' => [],
        ]));

        return new Repository($this->app, $this->dir);
    }

    public function testAppUsesTallportRepository()
    {
        $this->assertInstanceOf(Repository::class, $this->app->make('modules'));
    }

    public function testActiveFlagComesFromDatabase()
    {
        config(['modules.cache.enabled' => false]);
        $modules = $this->makeModule();

        $this->assertFalse($modules->isActive('tpmodule'));

        \App\Module::setActive('tpmodule', true);
        \App\Module::$modules = null;

        $module = $modules->findByAlias('tpmodule');
        $this->assertInstanceOf(\App\Modules\Module::class, $module);
        $this->assertTrue($module->active());
        $this->assertTrue($modules->isActive('tpmodule', false));
        $this->assertSame($this->dir.'/TpModule/', $modules->getModulePathByAlias('tpmodule'));
        $this->assertSame('', $modules->getModulePathByAlias('nosuchmodule'));
    }

    /**
     * A module that fails to load is deactivated instead of breaking every
     * page (modules.register_error, AppServiceProvider).
     */
    public function testBrokenModuleIsDeactivated()
    {
        config(['modules.cache.enabled' => false]);
        \App\Module::setActive('tpmodule', true);
        \App\Module::$modules = null;
        $module = $this->makeModule(['missing-file.php'])->findByAlias('tpmodule');

        $module->register();

        \App\Module::$modules = null;
        $this->assertFalse((bool) \App\Module::isActive('tpmodule'));
    }

    public function testCacheKeepsScannedPath()
    {
        config(['modules.cache.enabled' => true, 'modules.cache.key' => 'tallport-test-modules']);
        $this->makeModule();

        $cached = (new Repository($this->app, $this->dir))->getCached();
        $module = (new Repository($this->app, $this->dir))->find('TpModule');

        $this->assertSame($this->dir.'/TpModule', $cached['TpModule']['scanned_path']);
        $this->assertSame($this->dir.'/TpModule', $module->getScannedPath());
        $this->app['cache']->forget('tallport-test-modules');
    }

    /**
     * The Modules page lists the installed modules, not FreeScout's directory or
     * marketplace; their updates (from their own latestVersionUrl) are checked once
     * it's open, without asking FreeScout's directory.
     */
    public function testModulesPageShowsInstalledModulesOnly()
    {
        config(['modules.cache.enabled' => false]);
        $modules = $this->makeModule();
        $json = json_decode(file_get_contents($this->dir.'/TpModule/module.json'), true);
        file_put_contents($this->dir.'/TpModule/module.json', json_encode($json + ['authorUrl' => 'https://example.org', 'latestVersionUrl' => 'https://example.org/tpmodule/version']));
        $this->app->instance('modules', new Repository($this->app, $this->dir));
        \Cache::put('module_latest_version.'.config('app.version').'.'.md5('https://example.org/tpmodule/version'), '2.0.0', now()->addMinutes(5));
        \Cache::put('modules_directory', [['alias' => 'othermodule', 'name' => 'Other Official Module', 'version' => '1.0.0']], now()->addMinutes(5));

        $admin = $this->createAdmin();
        $this->actingAs($admin)->get('/modules/list')
            ->assertStatus(200)
            ->assertSee('TpModule')
            ->assertSee('module-updates')
            ->assertDontSee('There are updates available')
            ->assertDontSee('Modules Directory')
            ->assertDontSee('Marketplace')
            ->assertDontSee('Other Official Module');

        \Livewire\Livewire::actingAs($admin)->withoutLazyLoading()->test(\App\Livewire\ModuleUpdates::class)
            ->assertSee('There are updates available')->assertSee('TpModule (2.0.0)')->assertDontSee('Other Official Module')
            ->assertDispatched('module-updates', versions: ['tpmodule' => '2.0.0']);
        \Livewire\Livewire::actingAs($this->createUser())->withoutLazyLoading()->test(\App\Livewire\ModuleUpdates::class)->assertForbidden();
    }

    /**
     * A module whose update check fails says so, instead of looking up to date.
     */
    public function testFailedUpdateCheckIsShown()
    {
        config(['modules.cache.enabled' => false]);
        $this->makeModule();
        $json = json_decode(file_get_contents($this->dir.'/TpModule/module.json'), true);
        file_put_contents($this->dir.'/TpModule/module.json', json_encode($json + ['authorUrl' => 'https://example.org', 'latestVersionUrl' => 'http://127.0.0.1:1/version']));
        $this->app->instance('modules', new Repository($this->app, $this->dir));

        \Livewire\Livewire::actingAs($this->createAdmin())->withoutLazyLoading()->test(\App\Livewire\ModuleUpdates::class)
            ->assertSee("TpModule couldn't be checked for updates:")
            ->assertDontSee('There are updates available');
    }

    /**
     * What modules call on the repository: active modules (cached), their
     * options with defaults from their config, their public path.
     */
    public function testRepositoryHelpersForModules()
    {
        config(['modules.cache.enabled' => false, 'tpmodule.options' => ['color' => ['default' => 'blue'], 'size' => []]]);
        $modules = $this->makeModule();

        $this->assertSame([], $modules->getActive());
        $this->assertFalse($modules->isActive('tpmodule'));
        \App\Module::setActive('tpmodule', true);
        \App\Module::$modules = null;
        $this->assertFalse($modules->isActive('tpmodule'), 'Remembered for the request.');
        $this->assertTrue($modules->isActive('tpmodule', false));
        $this->assertSame(['TpModule'], array_keys($modules->getActive()));

        $this->assertSame('blue', $modules->getOption('TpModule', 'color'));
        $this->assertFalse($modules->getOption('TpModule', 'size'));
        $this->assertSame('red', $modules->getOption('TpModule', 'color', 'red'), 'A default given wins over the config.');
        $modules->setOption('TpModule', 'color', 'green');
        $this->assertSame('green', $modules->getOption('tpmodule', 'color'));
        $this->assertSame('/modules/tpmodule', $modules->getPublicPath('tpmodule'));
    }

    public function testModuleActivationAndOfficialAuthors()
    {
        config(['modules.cache.enabled' => false, 'app.freescout_url' => 'https://freescout.net']);
        $module = $this->makeModule()->findByAlias('tpmodule');

        $module->setActive(true);
        \App\Module::$modules = null;
        $this->assertTrue((bool) \App\Module::isActive('tpmodule'));

        $this->assertFalse($module->isOfficial());
        $module->json()->set('authorUrl', 'https://freescout.net/modules');
        $this->assertTrue($module->isOfficial());
    }

    /**
     * A module whose provider fails: the error is handed to modules.register_error,
     * which keeps it from breaking the page; one it doesn't handle is thrown.
     */
    public function testProviderErrors()
    {
        $module = $this->moduleWithProvider('ThrowingProvider', 'throw new \\RuntimeException(\'Provider failed\');');
        $handled = [];
        \Eventy::addFilter('modules.register_error', function ($exception, $module) use (&$handled) {
            $handled[] = [$module->getAlias(), $exception->getMessage()];

            return $exception;
        }, 5, 2);

        $module->registerProviders();
        $this->assertSame([['tpmodule', 'Provider failed']], $handled);

        $post = $_POST;
        $_POST = ['action' => 'save'];
        try {
            $this->expectExceptionMessage('Provider failed');
            $module->registerProviders();
        } finally {
            $_POST = $post;
        }
    }

    /**
     * A provider class that isn't there (a module half updated) is a failed
     * registration too, not a broken page.
     */
    public function testMissingProviderClass()
    {
        config(['modules.cache.enabled' => false]);
        $this->makeModuleJson(['Modules\\TpModule\\Providers\\MissingProvider']);
        $module = (new Repository($this->app, $this->dir))->findByAlias('tpmodule');
        $handled = [];
        \Eventy::addFilter('modules.register_error', function ($exception, $module) use (&$handled) {
            $handled[] = $module->getAlias();

            return $exception;
        }, 5, 2);

        $module->registerProviders();

        $this->assertSame(['tpmodule'], $handled);
    }

    protected function makeModuleJson(array $providers)
    {
        file_put_contents($this->dir.'/TpModule/module.json', json_encode([
            'name' => 'TpModule', 'alias' => 'tpmodule', 'version' => '1.0.0', 'active' => 1, 'order' => 0,
            'providers' => $providers, 'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ]));
    }

    /**
     * The module with a provider whose register() runs $code (a class unique to this run).
     */
    protected function moduleWithProvider($name, $code)
    {
        config(['modules.cache.enabled' => false]);
        $class = $name.uniqid();
        $file = $this->dir.'/TpModule/'.$class.'.php';
        file_put_contents($file, "<?php\nnamespace Modules\\TpModule\\Providers;\nclass $class extends \\Illuminate\\Support\\ServiceProvider\n{\n    public function register()\n    {\n        $code\n    }\n}\n");
        require $file;
        $this->makeModuleJson(['Modules\\TpModule\\Providers\\'.$class]);

        return (new Repository($this->app, $this->dir))->findByAlias('tpmodule');
    }
}
