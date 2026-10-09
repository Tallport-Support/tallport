<?php

namespace Tests\Unit;

use Codedge\Updater\SourceRepositoryTypes\GithubRepositoryType;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

class SelfUpdaterTest extends TestCase
{
    protected $download_path;
    protected $history = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->download_path = sys_get_temp_dir().'/tallport-updater-test-'.uniqid();
        mkdir($this->download_path);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->download_path);

        parent::tearDown();
    }

    protected function makeRepository(array $responses)
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GithubRepositoryType(new Client(['handler' => $stack]), [
            'repository_vendor' => 'Tallport-Support',
            'repository_name'   => 'tallport',
            'download_path'     => $this->download_path,
        ]);
    }

    public function testLatestReleaseIsTheAvailableVersion()
    {
        $repository = $this->makeRepository([
            new Response(200, [], json_encode(['tag_name' => 'v1.8.243.2', 'name' => 'Tallport 1.8.243.2'])),
        ]);

        $this->assertSame('1.8.243.2', $repository->getVersionAvailable());
        $this->assertSame(
            'https://api.github.com/repos/Tallport-Support/tallport/releases/latest',
            (string)$this->history[0]['request']->getUri()
        );
    }

    public function testNoPublishedReleaseMeansNoUpdate()
    {
        $repository = $this->makeRepository([
            new Response(404, [], json_encode(['message' => 'Not Found'])),
            new Response(404, [], json_encode(['message' => 'Not Found'])),
        ]);

        $this->assertSame(config('app.version'), $repository->getVersionAvailable());
        $this->assertFalse($repository->isNewVersionAvailable(config('app.version')));
    }

    public function testNewerReleaseIsDetected()
    {
        $repository = $this->makeRepository([
            new Response(200, [], json_encode(['tag_name' => '1.8.243.1'])),
        ]);

        $this->assertTrue($repository->isNewVersionAvailable('1.8.243'));
    }

    public function testTagThatIsNotAVersionIsRejected()
    {
        $repository = $this->makeRepository([
            new Response(200, [], json_encode(['tag_name' => '../../etc'])),
        ]);

        $this->expectExceptionMessage('Invalid release version');
        $repository->getVersionAvailable();
    }

    public function testFetchDownloadsTheTagZipballAndUnpacksIt()
    {
        // GitHub zipballs contain a single "<owner>-<repo>-<sha>/" top-level folder.
        $zip_file = $this->download_path.'/../'.uniqid('zipball-').'.zip';
        $zip = new \ZipArchive();
        $zip->open($zip_file, \ZipArchive::CREATE);
        $zip->addFromString('Tallport-Support-tallport-abc1234/app/Marker.php', '<?php // marker');
        $zip->addFromString('Tallport-Support-tallport-abc1234/artisan', '#!/usr/bin/env php');
        $zip->close();
        $zip_body = file_get_contents($zip_file);
        unlink($zip_file);

        $repository = $this->makeRepository([
            new Response(200, ['Content-Type' => 'application/zip'], $zip_body),
        ]);

        $repository->fetch('1.8.243.1');

        $this->assertSame(
            'https://api.github.com/repos/Tallport-Support/tallport/zipball/refs/tags/1.8.243.1',
            (string)$this->history[0]['request']->getUri()
        );
        $this->assertArrayNotHasKey('proxy', $this->history[0]['options']);
        $this->assertFileExists($this->download_path.'/1.8.243.1/app/Marker.php');
        $this->assertFileExists($this->download_path.'/1.8.243.1/artisan');
    }
}
