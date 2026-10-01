<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Tests\FeatureTestCase;

/**
 * Manage » Logs » App Logs (App\Http\Controllers\AppLogsController): only
 * files in the log folder can be read, downloaded or deleted.
 */
class AppLogsTest extends FeatureTestCase
{
    protected $dir;
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-logs-'.uniqid();
        mkdir($this->dir.'/logs', 0777, true);
        file_put_contents($this->dir.'/logs/laravel.log', "[2026-10-01 10:00:00] production.ERROR: Something broke\n");
        file_put_contents($this->dir.'/logs/other.log', "[2026-10-01 11:00:00] production.INFO: Other entry\n");
        file_put_contents($this->dir.'/secret.txt', 'secret');
        config(['logviewer.storage_path' => $this->dir.'/logs']);
        $this->admin = $this->createAdmin();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function logsPage(array $query)
    {
        return $this->actingAs($this->admin)->get('/app-logs/app?'.http_build_query($query));
    }

    public function testViewAndDownloadLog()
    {
        $this->logsPage(['l' => Crypt::encrypt('laravel.log')])->assertStatus(200)->assertSee('Something broke');

        $response = $this->logsPage(['dl' => Crypt::encrypt('other.log')]);
        $response->assertStatus(200);
        $this->assertSame($this->dir.'/logs/other.log', $response->baseResponse->getFile()->getPathname());
    }

    public function testOnlyFilesInTheLogFolder()
    {
        foreach (['../secret.txt', $this->dir.'/secret.txt', 'missing.log'] as $name) {
            $this->logsPage(['dl' => Crypt::encrypt($name)])->assertStatus(404);
            $this->logsPage(['del' => Crypt::encrypt($name)])->assertStatus(404);
        }
        $this->logsPage(['dl' => 'not-encrypted'])->assertStatus(404);

        $this->assertFileExists($this->dir.'/secret.txt');
    }

    public function testDeleteAndDeleteAll()
    {
        $this->logsPage(['del' => Crypt::encrypt('other.log')])->assertRedirect();
        $this->assertFileDoesNotExist($this->dir.'/logs/other.log');

        // Deleting all needs the CSRF token.
        $this->logsPage(['delall' => 'true', '_token' => 'wrong'])->assertStatus(200);
        $this->assertFileExists($this->dir.'/logs/laravel.log');

        \Session::start();
        $this->logsPage(['delall' => 'true', '_token' => csrf_token()])->assertRedirect();
        $this->assertFileDoesNotExist($this->dir.'/logs/laravel.log');
        $this->assertFileExists($this->dir.'/secret.txt');
    }
}
