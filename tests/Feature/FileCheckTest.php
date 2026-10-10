<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Livewire\SystemFileCheck;
use App\Misc\FileCheck;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * System > Status > Files (App\Misc\FileCheck, App\Livewire\SystemFileCheck) on
 * an installation in a temporary folder, with GitHub's tree of the release faked.
 */
class FileCheckTest extends FeatureTestCase
{
    protected $dir;

    protected $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-file-check-'.uniqid();
        $this->outside = $this->dir.'-outside';
        mkdir($this->dir, 0777, true);
        mkdir($this->outside, 0777, true);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->dir);
        (new Filesystem())->deleteDirectory($this->outside);
        parent::tearDown();
    }

    protected function putFile($path, $content, $dir = null)
    {
        $file = ($dir ?: $this->dir).'/'.$path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $content);
    }

    protected static function sha($content)
    {
        return sha1('blob '.strlen($content)."\0".$content);
    }

    /**
     * GitHub's tree of the release, with these files (path => content).
     */
    protected function fakeRelease(array $files, $truncated = false)
    {
        $tree = [['path' => 'public', 'mode' => '040000', 'type' => 'tree', 'sha' => str_repeat('a', 40)]];
        foreach ($files as $path => $content) {
            $tree[] = ['path' => $path, 'mode' => '100644', 'type' => 'blob', 'sha' => self::sha($content), 'size' => strlen($content)];
        }
        Http::fake(['api.github.com/repos/Tallport-Support/tallport/git/trees/*' => Http::response(['sha' => 'x', 'tree' => $tree, 'truncated' => $truncated])]);
    }

    protected function fileCheck(array $removed_files = [])
    {
        return new FileCheck($this->dir, '9.8.7', $removed_files);
    }

    protected function release()
    {
        return [
            '.gitattributes'    => "* text=auto\nCHANGELOG.md export-ignore\n",
            'CHANGELOG.md'      => 'changes',
            'artisan'           => 'artisan',
            'public/index.php'  => 'index',
            'public/style.css'  => 'style',
            'public/script.js'  => 'script',
            'storage/.gitignore' => "*\n",
        ];
    }

    public function testScanReportsUnexpectedChangedAndMissingFiles()
    {
        $this->fakeRelease($this->release());
        foreach (['.gitattributes', 'artisan', 'public/index.php'] as $path) {
            $this->putFile($path, $this->release()[$path]);
        }
        $this->putFile('public/style.css', 'edited');
        $this->putFile('public/extra.txt', 'extra');
        // What each installation makes itself isn't checked.
        $this->putFile('CHANGELOG.md', 'changes, in a git checkout');
        $this->putFile('.env', 'APP_KEY=x');
        $this->putFile('.env.backup-2026-10-10', 'APP_KEY=y');
        $this->putFile('storage/logs/laravel.log', 'log');
        $this->putFile('Modules/Example/module.json', '{}');
        $this->putFile('bootstrap/cache/packages.php', '<?php');
        $this->putFile('public/js/builds/app.js', 'built');
        $this->putFile('public/css/builds/app.css', 'built');

        $result = $this->fileCheck()->scan();

        $this->assertSame(['public/extra.txt'], $result['unexpected']);
        $this->assertSame(['public/style.css'], $result['changed']);
        // CHANGELOG.md isn't in the zipball (export-ignore), storage/ is the installation's own.
        $this->assertSame(['public/script.js'], $result['missing']);
        $this->assertSame([], $result['leftovers']);
        Http::assertSent(fn ($request) => $request->url() == 'https://api.github.com/repos/Tallport-Support/tallport/git/trees/9.8.7?recursive=1');

        // The release's file list is kept: no second request.
        $this->fileCheck()->scan();
        Http::assertSentCount(1);
    }

    public function testModulesCanIgnorePaths()
    {
        $this->fakeRelease(['artisan' => 'artisan']);
        $this->putFile('artisan', 'artisan');
        $this->putFile('custom/data.json', '{}');
        $this->putFile('public/uploads/photo.jpg', 'jpg');

        $this->assertSame(['custom/data.json', 'public/uploads/photo.jpg'], $this->fileCheck()->scan()['unexpected']);

        \Eventy::addFilter('system.file_check.ignored_paths', fn ($paths) => array_merge($paths, ['custom', 'public/uploads/*.jpg']));
        $this->assertSame([], $this->fileCheck()->scan()['unexpected']);
    }

    public function testOnlyUnchangedFilesOfEarlierReleasesAreDeleted()
    {
        $this->fakeRelease($this->release());
        foreach ($this->release() as $path => $content) {
            $this->putFile($path, $content);
        }
        $this->putFile('public/js/old/plugin.js', 'old plugin');
        $this->putFile('public/js/old/plugin.css', 'old css');
        $this->putFile('public/legacy.css', 'legacy, edited');
        $this->putFile('public/mine.txt', 'mine');
        $removed = [
            'public/js/old/plugin.js'  => [self::sha('older plugin'), self::sha('old plugin')],
            'public/js/old/plugin.css' => [self::sha('old css')],
            'public/legacy.css'        => [self::sha('legacy')],
        ];
        $file_check = $this->fileCheck($removed);

        $this->assertSame(['public/js/old/plugin.css', 'public/js/old/plugin.js'], $file_check->scan()['leftovers']);

        $admin = $this->createAdmin();
        $this->assertSame(['public/js/old/plugin.css', 'public/js/old/plugin.js'], $file_check->deleteLeftovers($admin));

        // Their folders went with them; the release's own and changed or unknown files stay.
        $this->assertDirectoryDoesNotExist($this->dir.'/public/js');
        $this->assertFileExists($this->dir.'/public/legacy.css');
        $this->assertFileExists($this->dir.'/public/mine.txt');
        $this->assertFileExists($this->dir.'/public/index.php');
        $this->assertSame(['public/legacy.css', 'public/mine.txt'], $file_check->scan()['unexpected']);

        $log = ActivityLog::where('log_name', ActivityLog::NAME_SYSTEM)->latest('id')->first();
        $this->assertSame(ActivityLog::DESCRIPTION_SYSTEM_FILES_DELETED, $log->description);
        $this->assertSame('Deleted leftover files', $log->getEventDescription());
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('public/js/old/plugin.css, public/js/old/plugin.js', $log->properties['files']);

        // Nothing left to delete: nothing logged.
        $this->assertSame([], $file_check->deleteLeftovers($admin));
        $this->assertSame(1, ActivityLog::where('description', ActivityLog::DESCRIPTION_SYSTEM_FILES_DELETED)->count());
    }

    public function testLeftoversOutsideTheInstallationAreNeverDeleted()
    {
        $this->fakeRelease(['artisan' => 'artisan']);
        $this->putFile('artisan', 'artisan');
        $this->putFile('old.js', 'old', $this->outside);
        $this->putFile('folder/old.js', 'old', $this->outside);
        symlink($this->outside.'/old.js', $this->dir.'/old.js');
        symlink($this->outside.'/folder', $this->dir.'/folder');
        $removed = [
            'old.js'                                  => [self::sha('old')],
            'folder/old.js'                           => [self::sha('old')],
            '../'.basename($this->outside).'/old.js'  => [self::sha('old')],
        ];
        $file_check = $this->fileCheck($removed);

        // Symlinks are reported, not followed.
        $result = $file_check->scan();
        $this->assertSame(['folder', 'old.js'], $result['unexpected']);
        $this->assertSame([], $result['leftovers']);
        $this->assertFalse($file_check->isLeftover('folder/old.js'));
        $this->assertFalse($file_check->isLeftover('../'.basename($this->outside).'/old.js'));

        $this->assertSame([], $file_check->deleteLeftovers());
        $this->assertFileExists($this->outside.'/old.js');
        $this->assertFileExists($this->outside.'/folder/old.js');
        $this->assertTrue(is_link($this->dir.'/old.js'));
    }

    public function testFileChangedSinceTheScanIsKept()
    {
        $this->fakeRelease(['artisan' => 'artisan']);
        $this->putFile('artisan', 'artisan');
        $this->putFile('old.js', 'old');
        $file_check = $this->fileCheck(['old.js' => [self::sha('old')]]);
        $this->assertTrue($file_check->isLeftover('old.js'));

        $this->putFile('old.js', 'edited since');
        $this->assertFalse($file_check->isLeftover('old.js'));
        $this->assertSame([], $file_check->deleteLeftovers());
        $this->assertFileExists($this->dir.'/old.js');
    }

    public function testGitHubErrorsAreExplained()
    {
        $cases = [
            [Http::response(['message' => 'Not Found'], 404), 'Release 9.8.7 wasn\'t found on GitHub.'],
            [Http::response(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0']), 'GitHub\'s limit on requests from this server was reached. Try again later.'],
            [Http::response('Server Error', 500), 'GitHub sent an error (500).'],
            [Http::response(['tree' => [], 'truncated' => true]), 'GitHub sent an incomplete file list.'],
            [fn () => throw new ConnectionException('Could not resolve host: api.github.com'), 'GitHub couldn\'t be reached: Could not resolve host: api.github.com'],
        ];
        $response = null;
        Http::fake(function () use (&$response) {
            return $response instanceof \Closure ? $response() : $response;
        });
        foreach ($cases as [$response, $message]) {
            try {
                $this->fileCheck()->releaseFiles();
                $this->fail('No error for: '.$message);
            } catch (\RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            // Nothing is kept from a failed check.
            $this->assertNull(\Cache::get(FileCheck::CACHE_KEY.'9.8.7'));
        }
    }

    public function testExportIgnoreAndIgnoredPatterns()
    {
        $this->assertTrue(FileCheck::matches('docs/CHANGELOG.md', ['**/CHANGELOG.md']));
        $this->assertTrue(FileCheck::matches('storage/app/file.txt', ['storage']));
        $this->assertTrue(FileCheck::matches('.env.backup-1', ['.env.backup-*']));
        $this->assertFalse(FileCheck::matches('.env.example', ['.env', '.env.backup-*']));
        $this->assertFalse(FileCheck::matches('storage-old/file.txt', ['storage']));
        $this->assertFalse(FileCheck::matches('public/js/a/b.js', ['public/js/*.js']));
    }

    public function testAdminsCheckFilesAndDeleteLeftovers()
    {
        $this->fakeRelease(['artisan' => 'artisan', 'public/index.php' => 'index']);
        $this->putFile('artisan', 'artisan, edited');
        $this->putFile('old.js', 'old');
        $this->putFile('mine.txt', 'mine');
        $this->app->instance(FileCheck::class, $this->fileCheck(['old.js' => [self::sha('old')]]));

        $component = Livewire::actingAs($this->createAdmin())->test(SystemFileCheck::class)
            ->assertSee('Check Files')->assertDontSee('Unexpected Files')
            ->call('check')
            ->assertSee('Unexpected Files · 2')->assertSee('Leftover')->assertSee('Delete Leftovers…')
            ->assertSee('Changed Files · 1')->assertSee('Missing Files · 1')->assertSee('public/index.php');

        $component->call('deleteLeftovers')->assertSee('Leftovers deleted.')
            ->assertSee('Unexpected Files · 1')->assertDontSee('Delete Leftovers…');
        $this->assertFileDoesNotExist($this->dir.'/old.js');
        $this->assertFileExists($this->dir.'/mine.txt');

        // All in order.
        $this->putFile('artisan', 'artisan');
        $this->putFile('public/index.php', 'index');
        unlink($this->dir.'/mine.txt');
        $component->call('check')->assertSee('All files match the release.')->assertDontSee('Leftovers deleted.');
    }

    public function testCheckShowsGitHubErrors()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], 404)]);
        $this->app->instance(FileCheck::class, $this->fileCheck());

        Livewire::actingAs($this->createAdmin())->test(SystemFileCheck::class)
            ->call('check')->assertSee('Release 9.8.7 wasn&#039;t found on GitHub.', false);
    }

    public function testOnlyAdminsCheckFiles()
    {
        $this->app->instance(FileCheck::class, $this->fileCheck());

        Livewire::actingAs($this->createUser())->test(SystemFileCheck::class)->assertForbidden();

        // The status page has the Files section.
        Livewire::withoutLazyLoading();
        $this->actingAs($this->createAdmin())->get(route('system'))->assertOk();
        Livewire::actingAs($this->createAdmin())->test(\App\Livewire\SystemStatus::class)->assertSee('Check Files');
    }
}
