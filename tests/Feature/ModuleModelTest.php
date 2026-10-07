<?php

namespace Tests\Feature;

use App\Module;
use App\Modules\Repository;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Tests\FeatureTestCase;

/**
 * App\Module: active flags, required extensions and modules, the public
 * symlinks and updating a module from its download address. Modules and
 * the public folder live in a temporary folder.
 */
class ModuleModelTest extends FeatureTestCase
{
    protected $dir;

    protected $modules_dir;

    protected $public_dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-module-model-'.uniqid();
        $this->modules_dir = $this->dir.'/Modules';
        $this->public_dir = $this->dir.'/public';
        mkdir($this->modules_dir, 0777, true);
        mkdir($this->public_dir.'/modules', 0777, true);
        $this->app->usePublicPath($this->public_dir);
        config(['modules.cache.enabled' => false]);
        Repository::$active_cache = [];
        Module::$modules = null;
    }

    protected function tearDown(): void
    {
        // Folders made read-only by a test can't be deleted otherwise.
        exec('chmod -R u+w '.escapeshellarg($this->dir));
        (new Filesystem())->deleteDirectory($this->dir);
        Repository::$active_cache = [];
        Module::$modules = null;
        parent::tearDown();
    }

    /**
     * Put a module into the temporary modules folder and use that folder.
     */
    protected function makeModule($name, $alias, array $extra = [])
    {
        if (!is_dir($this->modules_dir.'/'.$name)) {
            mkdir($this->modules_dir.'/'.$name, 0777, true);
        }
        file_put_contents($this->modules_dir.'/'.$name.'/module.json', json_encode(array_merge([
            'name' => $name, 'alias' => $alias, 'description' => 'Test module', 'version' => '1.0.0',
            'active' => 0, 'order' => 0, 'providers' => [], 'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ], $extra)));
        $this->app->instance('modules', new Repository($this->app, $this->modules_dir));
        \Module::clearResolvedInstance('modules');
    }

    /**
     * A zip archive with the given files (name => contents).
     */
    protected function makeZip($path, array $files)
    {
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
    }

    /**
     * module.json of a module, as a string.
     */
    protected function moduleJson($name, $alias, $version)
    {
        return json_encode([
            'name' => $name, 'alias' => $alias, 'description' => 'Test module', 'version' => $version,
            'active' => 0, 'order' => 0, 'providers' => [], 'aliases' => new \stdClass(), 'files' => [], 'requires' => [],
        ]);
    }

    /**
     * The module's download address points to the local host, so nothing is
     * downloaded (Helper::downloadRemoteFile() refuses it); an archive put where
     * the download is saved stands in for the downloaded one.
     */
    protected function makeUpdatableModule(array $archive_files = null)
    {
        $this->makeModule('TpModule', 'tpmodule', ['latestVersionZipUrl' => 'http://127.0.0.1/archive/refs/heads/master.zip']);
        Module::setActive('tpmodule', true);
        Module::$modules = null;
        $this->makeZip($this->modules_dir.'/tpmodule.zip', $archive_files ?? [
            'TpModule-master/module.json' => $this->moduleJson('TpModule', 'tpmodule', '2.0.0'),
            'TpModule-master/Public/js/module.js' => 'var updated = true;',
        ]);
    }

    /**
     * Saving an active flag for a module whose row another process has just
     * added (the in-memory list doesn't have it yet) updates that row.
     */
    public function testSetActiveUpdatesARowAddedMeanwhile()
    {
        Module::getCached();
        \DB::table('modules')->insert(['alias' => 'tpmodule', 'active' => false]);

        $this->assertTrue(Module::setActive('tpmodule', true));

        $this->assertSame(1, \DB::table('modules')->where('alias', 'tpmodule')->count());
        $this->assertTrue((bool) \DB::table('modules')->where('alias', 'tpmodule')->value('active'));
    }

    /**
     * Official modules and modules without latestVersionUrl aren't checked for updates.
     */
    public function testAvailableUpdatesSkipsOfficialModulesAndModulesWithoutAnAddress()
    {
        $this->makeModule('NoUrl', 'nourl', ['authorUrl' => 'https://example.org']);
        $this->makeModule('Official', 'official', ['authorUrl' => 'https://freescout.net', 'latestVersionUrl' => 'http://127.0.0.1:1/version']);

        $this->assertSame([], Module::availableUpdates());
        $this->assertSame([], Module::$update_check_errors);
    }

    public function testMissingExtensions()
    {
        $this->assertSame(['tallport_no_such_ext'], Module::getMissingExtensions('json, tallport_no_such_ext,'));
        $this->assertSame([], Module::getMissingExtensions('json'));
        $this->assertSame([], Module::getMissingExtensions(null));
    }

    /**
     * A required module is missing when it isn't installed, isn't active or is too old.
     */
    public function testMissingModules()
    {
        $this->makeModule('Active', 'active', ['version' => '1.2.0']);
        $this->makeModule('Inactive', 'inactive', ['version' => '1.2.0']);
        Module::setActive('active', true);
        Module::$modules = null;

        $this->assertSame(
            ['notinstalled' => '1.0.0', 'inactive' => '1.0.0', 'active' => '1.3.0'],
            Module::getMissingModules(['notinstalled' => '1.0.0', 'inactive' => '1.0.0', 'active' => '1.3.0'])
        );
        $this->assertSame([], Module::getMissingModules(['active' => '1.2.0']));
        $this->assertSame([], Module::getMissingModules(null));
        $this->assertSame(['active' => '1.0.0'], Module::getMissingModules(['active' => '1.0.0'], [(object) ['alias' => 'other']]));
    }

    /**
     * Modules from FreeScout's directory by other authors are marked Third-Party, once.
     */
    public function testFormatModuleDataMarksThirdPartyModules()
    {
        $third_party = ['name' => 'Some Module', 'detailsUrl' => 'https://freescout.net/module/some/', 'author' => 'Someone'];

        $this->assertSame('Some Module [Third-Party]', Module::formatModuleData($third_party)['name']);
        $this->assertSame('Some Module [Beta]', Module::formatModuleData(['name' => 'Some Module [Beta]'] + $third_party)['name']);
        $this->assertSame('Some Module', Module::formatModuleData(['author' => 'FreeScout'] + $third_party)['name']);
        $this->assertSame('Some Module', Module::formatModuleData(['detailsUrl' => 'https://example.org/some'] + $third_party)['name']);
    }

    /**
     * A missing public symlink of an active module is created, pointing to the
     * module's Public folder (made when missing).
     */
    public function testCheckSymlinksCreatesMissingSymlink()
    {
        $this->makeModule('TpModule', 'tpmodule');
        $this->makeModule('Inactive', 'inactive');
        Module::setActive('tpmodule', true);
        Module::$modules = null;

        $this->assertSame([], Module::checkSymlinks());

        $from = $this->public_dir.'/modules/tpmodule';
        $this->assertSame($from, Module::getSymlinkPath('tpmodule'));
        $this->assertTrue(is_link($from));
        $this->assertSame($this->modules_dir.'/TpModule/Public', readlink($from));
        $this->assertDirectoryExists($this->modules_dir.'/TpModule/Public');
        $this->assertFalse(file_exists($this->public_dir.'/modules/inactive'));

        // A valid symlink is left as it is.
        $this->assertSame([], Module::checkSymlinks(['tpmodule']));
    }

    /**
     * A folder in the symlink's place is moved aside; a module's Public folder that is
     * itself a symlink is replaced by a folder.
     */
    public function testCheckSymlinksReplacesFolderAndPublicSymlink()
    {
        $this->makeModule('TpModule', 'tpmodule');
        mkdir($this->public_dir.'/modules/tpmodule');
        file_put_contents($this->public_dir.'/modules/tpmodule/old.js', 'old');
        symlink($this->dir.'/nowhere', $this->modules_dir.'/TpModule/Public');

        $this->assertSame([], Module::checkSymlinks(['tpmodule']));

        $from = $this->public_dir.'/modules/tpmodule';
        $this->assertTrue(is_link($from));
        $this->assertFalse(is_link($this->modules_dir.'/TpModule/Public'));
        $this->assertDirectoryExists($this->modules_dir.'/TpModule/Public');
        $this->assertCount(1, glob($from.'_*/old.js'));
    }

    /**
     * A symlink that can't be created is reported (from => to); unknown modules aren't.
     */
    public function testCheckSymlinksReportsSymlinkThatCantBeCreated()
    {
        $this->makeModule('TpModule', 'tpmodule');
        rmdir($this->public_dir.'/modules');
        \Log::spy();

        $this->assertSame(
            [$this->public_dir.'/modules/tpmodule' => $this->modules_dir.'/TpModule/Public'],
            Module::checkSymlinks(['tpmodule', 'nosuchmodule'])
        );
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_contains($message, 'Error occurred creating ['.$this->public_dir.'/modules/tpmodule');
        });
        $this->assertFalse(Module::createModuleSymlink('nosuchmodule'));
    }

    /**
     * A folder in the symlink's place that can't be moved aside is reported.
     */
    public function testCheckSymlinksReportsFolderThatCantBeMoved()
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root ignores folder permissions.');
        }
        $this->makeModule('TpModule', 'tpmodule');
        mkdir($this->public_dir.'/modules/tpmodule');
        chmod($this->public_dir.'/modules', 0555);

        $this->assertSame(
            [$this->public_dir.'/modules/tpmodule' => $this->modules_dir.'/TpModule/Public'],
            Module::checkSymlinks(['tpmodule'])
        );
        $this->assertFalse(is_link($this->public_dir.'/modules/tpmodule'));
    }

    /**
     * When the module's Public folder can't be made, the symlink leads nowhere and is reported.
     */
    public function testCheckSymlinksReportsMissingPublicFolder()
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Root ignores folder permissions.');
        }
        $this->makeModule('TpModule', 'tpmodule');
        chmod($this->modules_dir.'/TpModule', 0555);

        $this->assertSame(
            [$this->public_dir.'/modules/tpmodule' => $this->modules_dir.'/TpModule/Public'],
            Module::checkSymlinks(['tpmodule'])
        );
        $this->assertDirectoryDoesNotExist($this->modules_dir.'/TpModule/Public');
    }

    public function testUpdateUnknownModule()
    {
        $this->makeModule('TpModule', 'tpmodule');

        $result = Module::updateModule('nosuchmodule');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Module not found: nosuchmodule', $result['msg']);
        $this->assertSame('', $result['module_name']);
        $this->assertSame([], \Tests\Support\StubCommand::$calls);
    }

    public function testUpdateModuleWithoutDownloadAddress()
    {
        $this->makeModule('TpModule', 'tpmodule');

        $result = Module::updateModule('tpmodule');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Error occurred: module not available for download', $result['msg']);
        $this->assertSame('TpModule', $result['module_name']);
        $this->assertSame([], \Tests\Support\StubCommand::$calls);
    }

    /**
     * A failed download says where to get the module and where to extract it.
     */
    public function testUpdateModuleDownloadError()
    {
        $this->makeModule('TpModule', 'tpmodule', ['latestVersionZipUrl' => 'http://127.0.0.1/archive/refs/heads/master.zip']);

        $result = Module::updateModule('tpmodule');

        $this->assertSame('error', $result['status']);
        $this->assertTrue($result['download_error']);
        $this->assertStringContainsString('<a href="http://127.0.0.1/archive/refs/heads/master.zip" target="_blank">', $result['download_msg']);
        $this->assertStringContainsString('<strong>'.$this->modules_dir.'</strong>', $result['download_msg']);
        $this->assertSame([], \Tests\Support\StubCommand::$calls);
    }

    /**
     * The archive (a GitHub one: TpModule-master) replaces the module, its Public
     * symlink is removed first, and the archive is deleted. Without "Configuration
     * cached successfully" from tallport:module-install, the module is deactivated.
     */
    public function testUpdateModuleInstallsArchiveAndDeactivatesOnInstallError()
    {
        $this->makeUpdatableModule();
        symlink($this->modules_dir.'/TpModule', $this->modules_dir.'/TpModule/Public');

        $result = Module::updateModule('tpmodule');

        $this->assertSame('error', $result['status']);
        $this->assertFalse($result['download_error']);
        $this->assertSame('Error occurred activating "TpModule" module', $result['msg']);
        $this->assertSame(' ', $result['output']);
        $this->assertSame('2.0.0', json_decode(file_get_contents($this->modules_dir.'/TpModule/module.json'), true)['version']);
        $this->assertSame('var updated = true;', file_get_contents($this->modules_dir.'/TpModule/Public/js/module.js'));
        $this->assertFileDoesNotExist($this->modules_dir.'/tpmodule.zip');
        $this->assertDirectoryDoesNotExist($this->modules_dir.'/TpModule-master');
        $this->assertCommandCalled('tallport:module-install');
        $this->assertCommandCalled('tallport:clear-cache');
        Module::$modules = null;
        $this->assertFalse((bool) Module::isActive('tpmodule'));
    }

    /**
     * Errors the module reported while being installed are the message.
     */
    public function testUpdateModuleReportsFlashedErrors()
    {
        $this->makeUpdatableModule();
        session(['flashes_floating' => [['text' => 'Module failed.'], ['text' => 'Deactivated.']]]);

        $result = Module::updateModule('tpmodule');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Module failed. Deactivated. ', $result['msg']);
        $this->assertNotContains('tallport:clear-cache', array_column(\Tests\Support\StubCommand::$calls, 'name'));
    }

    public function testUpdateModuleSuccess()
    {
        $this->makeUpdatableModule();
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new class extends Command {
            protected $signature = 'tallport:module-install {module_alias?}';

            public function handle()
            {
                $this->line('Configuration cached successfully!');
            }
        });

        $result = Module::updateModule('tpmodule');

        $this->assertSame('success', $result['status']);
        $this->assertSame('', $result['msg']);
        $this->assertSame('"TpModule" module successfully updated!', $result['msg_success']);
        $this->assertStringContainsString('Configuration cached successfully', $result['output']);
        Module::$modules = null;
        $this->assertTrue((bool) Module::isActive('tpmodule'));
    }

    /**
     * An archive that can't be opened leaves the module as it was.
     */
    public function testUpdateModuleWithBrokenArchive()
    {
        $this->makeUpdatableModule();
        file_put_contents($this->modules_dir.'/tpmodule.zip', 'not a zip');

        $result = Module::updateModule('tpmodule');

        $this->assertSame('error', $result['status']);
        $this->assertSame('Could not open archive '.$this->modules_dir.'/tpmodule.zip', $result['msg']);
        $this->assertFalse($result['download_error']);
        $this->assertFileDoesNotExist($this->modules_dir.'/tpmodule.zip');
        $this->assertSame('1.0.0', json_decode(file_get_contents($this->modules_dir.'/TpModule/module.json'), true)['version']);
        $this->assertSame([], \Tests\Support\StubCommand::$calls);
    }

    /**
     * An archive without the module is a download error.
     */
    public function testUpdateModuleWithArchiveOfAnotherModule()
    {
        $this->makeUpdatableModule(['TpModule/module.json' => $this->moduleJson('TpModule', 'othermodule', '2.0.0')]);

        $result = Module::updateModule('tpmodule');

        $this->assertTrue($result['download_error']);
        $this->assertStringContainsString('Error occurred downloading the module.', $result['download_msg']);
        $this->assertSame([], \Tests\Support\StubCommand::$calls);
    }
}
