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
}
