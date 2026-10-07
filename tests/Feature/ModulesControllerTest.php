<?php

namespace Tests\Feature;

use App\Modules\Repository;
use Illuminate\Filesystem\Filesystem;
use Tests\FeatureTestCase;

/**
 * Activating, deactivating and updating modules (ModulesController) on a module in a
 * temporary folder; updates download from a local web server.
 */
class ModulesControllerTest extends FeatureTestCase
{
    protected $admin;
    protected $dir;

    /**
     * Local web servers started by a test, stopped in tearDown().
     */
    protected $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->dir = sys_get_temp_dir().'/tallport-modules-'.uniqid();
        mkdir($this->dir);
        config(['modules.cache.enabled' => false]);
        Repository::$active_cache = [];
        \App\Module::$modules = null;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->servers as $server) {
                proc_terminate($server);
                proc_close($server);
            }
            @chmod($this->dir, 0777);
            (new Filesystem())->deleteDirectory($this->dir);
            Repository::$active_cache = [];
            \App\Module::$modules = null;
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Module TpModule (alias tpmodule) in $folder, used as the installation's modules.
     */
    protected function makeModule($folder = 'TpModule', array $json = [])
    {
        mkdir($this->dir.'/'.$folder);
        file_put_contents($this->dir.'/'.$folder.'/module.json', json_encode(array_merge([
            'name' => 'TpModule', 'alias' => 'tpmodule', 'description' => 'Test module', 'version' => '1.0.0',
            'active' => 0, 'order' => 0, 'providers' => ['Modules\\TpModule\\Providers\\TpModuleServiceProvider'],
            'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ], $json)));
        $this->useModules();
    }

    protected function useModules()
    {
        $this->app->instance('modules', new Repository($this->app, $this->dir));
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('modules');
        \App\Module::$modules = null;
    }

    protected function modulesAjax(array $data)
    {
        return $this->postAjax($this->admin, route('modules.ajax'), $data)->json();
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

    protected function isActive($alias)
    {
        return (bool) \App\Module::where('alias', $alias)->value('active');
    }

    protected function calledCommands()
    {
        return array_column(\Tests\Support\StubCommand::$calls, 'name');
    }

    /**
     * A web server on 127.0.0.1 serving $files ([path => contents]). Returns its address.
     */
    protected function startWebServer(array $files)
    {
        $root = $this->dir.'/.www';
        foreach ($files as $path => $contents) {
            @mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, $contents);
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);
        $this->servers[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(20000);
        }
        config(['app.remote_host_white_list' => '127.0.0.1']);
        // Tests send requests through a proxy that doesn't exist; the only URLs this test
        // requests are the local server's.
        config(['app.proxy' => '']);

        return 'http://127.0.0.1:'.$port;
    }

    /**
     * TpModule 2.0.0 as a ZIP archive.
     */
    protected function moduleZip()
    {
        $zip_path = $this->dir.'/build.zip';
        $zip = new \ZipArchive();
        $zip->open($zip_path, \ZipArchive::CREATE);
        $zip->addFromString('TpModule/module.json', json_encode([
            'name' => 'TpModule', 'alias' => 'tpmodule', 'description' => 'Test module', 'version' => '2.0.0',
            'active' => 0, 'order' => 0, 'providers' => [], 'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ]));
        $zip->close();
        $contents = file_get_contents($zip_path);
        unlink($zip_path);

        return $contents;
    }

    // The page.

    /**
     * What the last action reported is shown once on the Modules page: one message, or
     * one per module after updating them all.
     */
    public function testActionResultsAreShownOnce()
    {
        \Cache::forever('modules_flash', ['text' => '<strong>Activated it</strong>', 'unescaped' => true, 'type' => 'success']);
        $this->actingAs($this->admin)->get(route('modules'))->assertOk()->assertSee('<strong>Activated it</strong>', false);
        $this->assertNull(\Cache::get('modules_flash'));
        $this->actingAs($this->admin)->get(route('modules'))->assertDontSee('Activated it');

        \Cache::forever('modules_flash', [
            ['text' => 'First module updated', 'type' => 'success'],
            ['text' => 'Second module failed', 'type' => 'danger'],
        ]);
        $this->actingAs($this->admin)->get(route('modules'))->assertOk()
            ->assertSeeInOrder(['First module updated', 'Second module failed']);
    }

    // Activating.

    /**
     * Activating renames the module's folder to the name its provider expects, activates
     * it, installs it, migrates, and reports success when the install cached the config.
     */
    public function testActivate()
    {
        mkdir($this->dir.'/Other');
        file_put_contents($this->dir.'/Other/module.json', json_encode(['name' => 'Other', 'alias' => 'other', 'providers' => []]));
        $this->makeModule('tpmodule-main');
        $this->commandPrinting('tallport:module-install', 'Configuration cached successfully.');

        $response = $this->modulesAjax(['action' => 'activate', 'alias' => 'tpmodule']);

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertDirectoryDoesNotExist($this->dir.'/tpmodule-main');
        $this->assertFileExists($this->dir.'/TpModule/module.json');
        $this->assertFileExists($this->dir.'/Other/module.json', 'Other modules stay where they are.');
        $this->assertTrue($this->isActive('tpmodule'));
        $this->assertContains('migrate', $this->calledCommands());
        $flash = \Cache::get('modules_flash');
        $this->assertSame('success', $flash['type']);
        $this->assertStringContainsString('"TpModule" module successfully activated!', $flash['text']);
        $this->assertStringContainsString('Configuration cached successfully.', $flash['text']);
    }

    /**
     * An install that didn't finish leaves the module inactive, with an error. (A module
     * without providers keeps its folder name.)
     */
    public function testActivateThatFailsDeactivatesAgain()
    {
        $this->makeModule('tpmodule-main', ['providers' => []]);

        $this->assertSame('success', $this->modulesAjax(['action' => 'activate', 'alias' => 'tpmodule'])['status']);

        $this->assertFileExists($this->dir.'/tpmodule-main/module.json');
        $this->assertFalse($this->isActive('tpmodule'));
        $this->assertContains('tallport:clear-cache', $this->calledCommands());
        $this->assertNotContains('migrate', $this->calledCommands());
        $flash = \Cache::get('modules_flash');
        $this->assertSame('danger', $flash['type']);
        $this->assertStringContainsString('Error occurred activating "TpModule" module', $flash['text']);
    }

    /**
     * Errors the module reported while loading (modules.register_error) are shown.
     */
    public function testActivateShowsTheModulesErrors()
    {
        $this->makeModule();
        $this->commandPrinting('tallport:module-install', 'Configuration cached successfully.');
        session(['flashes_floating' => [['text' => 'Needs the Telegram module.'], ['text' => 'Run composer.']]]);

        $this->modulesAjax(['action' => 'activate', 'alias' => 'tpmodule']);

        $flash = \Cache::get('modules_flash');
        $this->assertSame('danger', $flash['type']);
        $this->assertStringStartsWith('<strong>Needs the Telegram module. Run composer. </strong>', $flash['text']);
    }

    /**
     * A module with public files needs its link in public/modules; without it, it's
     * deactivated again.
     */
    public function testActivateNeedsThePublicLink()
    {
        $this->makeModule();
        mkdir($this->dir.'/TpModule/Public');
        $this->commandPrinting('tallport:module-install', 'Configuration cached successfully.');

        $this->modulesAjax(['action' => 'activate', 'alias' => 'tpmodule']);

        $flash = \Cache::get('modules_flash');
        $this->assertSame('danger', $flash['type']);
        $this->assertStringContainsString('Error occurred creating a module symlink ('.public_path().'/modules/tpmodule)', $flash['text']);
        $this->assertFalse($this->isActive('tpmodule'));
        $this->assertNotContains('migrate', $this->calledCommands());
    }

    /**
     * A folder that can't be renamed: the admin is told how to rename it, nothing is activated.
     */
    public function testActivateAsksToRenameTheFolder()
    {
        $this->makeModule('tpmodule-main');
        chmod($this->dir, 0555);

        $response = $this->modulesAjax(['action' => 'activate', 'alias' => 'tpmodule']);

        $this->assertSame('error', $response['status']);
        $this->assertSame('Rename "/Modules/tpmodule-main" into "/Modules/TpModule"', $response['msg']);
        $this->assertFalse($this->isActive('tpmodule'));
    }

    /**
     * Deactivating reports success when the cache was rebuilt.
     */
    public function testDeactivate()
    {
        $this->makeModule();
        \App\Module::setActive('tpmodule', true);
        $this->commandPrinting('tallport:clear-cache', 'Configuration cached successfully.');

        $this->assertSame('success', $this->modulesAjax(['action' => 'deactivate', 'alias' => 'tpmodule'])['status']);

        $this->assertFalse($this->isActive('tpmodule'));
        $flash = \Cache::get('modules_flash');
        $this->assertSame('success', $flash['type']);
        $this->assertStringContainsString('"TpModule" module successfully Deactivated!', $flash['text']);
    }

    // Updating.

    /**
     * A module without a download address can't be updated: said so.
     */
    public function testUpdateWithoutADownloadAddress()
    {
        $this->makeModule();

        $this->assertSame('success', $this->modulesAjax(['action' => 'update', 'alias' => 'tpmodule'])['status']);

        $flash = \Cache::get('modules_flash');
        $this->assertSame('danger', $flash['type']);
        $this->assertStringContainsString('Error occurred: module not available for download', $flash['text']);
    }

    /**
     * A download that fails: the page reloads with a link to download it by hand.
     */
    public function testUpdateWhoseDownloadFails()
    {
        $this->makeModule('TpModule', ['latestVersionZipUrl' => 'http://127.0.0.1:1/tpmodule.zip']);

        $response = $this->modulesAjax(['action' => 'update', 'alias' => 'tpmodule']);

        $this->assertTrue($response['reload']);
        $this->assertStringContainsString('Error occurred downloading the module. Please <a href="http://127.0.0.1:1/tpmodule.zip" target="_blank">download</a>', session('flash_error_unescaped'));
        $this->assertFileExists($this->dir.'/TpModule/module.json', 'The installed version is kept.');
    }

    /**
     * After a failed download the page shows that error only, not also an empty message.
     */
    public function testFailedDownloadLeavesNoEmptyMessage()
    {
        $this->makeModule('TpModule', ['latestVersionZipUrl' => 'http://127.0.0.1:1/tpmodule.zip']);

        $this->modulesAjax(['action' => 'update', 'alias' => 'tpmodule']);

        $this->assertNull(\Cache::get('modules_flash'));
    }

    /**
     * Updating downloads the module's ZIP, extracts it over the module and installs it.
     */
    public function testUpdate()
    {
        $url = $this->startWebServer(['tpmodule.zip' => $this->moduleZip()]);
        $this->makeModule('TpModule', ['latestVersionZipUrl' => $url.'/tpmodule.zip']);
        $this->commandPrinting('tallport:module-install', 'Configuration cached successfully.');

        $this->assertSame('success', $this->modulesAjax(['action' => 'update', 'alias' => 'tpmodule'])['status']);

        $this->assertSame('2.0.0', json_decode(file_get_contents($this->dir.'/TpModule/module.json'), true)['version']);
        $flash = \Cache::get('modules_flash');
        $this->assertSame('success', $flash['type']);
        $this->assertStringContainsString('"TpModule" module successfully updated!', $flash['text']);
    }

    /**
     * Update All reports on each module.
     */
    public function testUpdateAll()
    {
        $url = $this->startWebServer(['tpmodule.zip' => $this->moduleZip()]);
        $this->makeModule('TpModule', ['latestVersionZipUrl' => $url.'/tpmodule.zip']);
        mkdir($this->dir.'/Other');
        file_put_contents($this->dir.'/Other/module.json', json_encode([
            'name' => 'Other', 'alias' => 'other', 'description' => 'Other module', 'version' => '1.0.0', 'active' => 0, 'order' => 0,
            'providers' => [], 'aliases' => new \stdClass(), 'files' => [], 'requires' => [], 'latestVersionZipUrl' => 'http://127.0.0.1:1/other.zip',
        ]));
        $this->useModules();
        $this->commandPrinting('tallport:module-install', 'Configuration cached successfully.');

        $this->assertSame('success', $this->modulesAjax(['action' => 'update_all', 'aliases' => ['tpmodule', 'other']])['status']);

        $flashes = \Cache::get('modules_flash');
        $this->assertSame(['success', 'danger'], array_column($flashes, 'type'));
        $this->assertStringStartsWith('<strong>TpModule:</strong> "TpModule" module successfully updated!<pre class="margin-top">Configuration cached successfully.', $flashes[0]['text']);
        $this->assertStringStartsWith('<strong>Other:</strong> <br/>Error occurred downloading the module.', $flashes[1]['text']);
    }

    public function testUnknownAction()
    {
        $this->assertSame('Unknown action', $this->modulesAjax(['action' => 'no_such_action'])['msg']);
    }
}
