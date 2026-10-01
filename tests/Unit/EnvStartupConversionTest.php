<?php

namespace Tests\Unit;

use App\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * An .env written with FreeScout's old rules is converted before it's read
 * the first time, e.g. after switching from FreeScout.
 */
class EnvStartupConversionTest extends TestCase
{
    protected $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/tallport-app-'.uniqid();
        mkdir($this->dir.'/storage', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function convert()
    {
        $app = new Application($this->dir);
        $method = new \ReflectionMethod($app, 'standardizeEnvFileOnce');

        // The test environment itself is skipped.
        $env = $_ENV['APP_ENV'];
        $_ENV['APP_ENV'] = 'production';
        try {
            $method->invoke($app);
        } finally {
            $_ENV['APP_ENV'] = $env;
        }
    }

    public function testOldEnvIsConvertedOnce()
    {
        file_put_contents($this->dir.'/.env', "DB_PASSWORD=\"trail\\\"\n");

        $this->convert();

        $this->assertSame("DB_PASSWORD=\"trail\\\\\"\n", file_get_contents($this->dir.'/.env'));
        $this->assertFileExists($this->dir.'/storage/.env-standard');

        // With the marker, the file isn't looked at again.
        file_put_contents($this->dir.'/.env', "A='x'\n");
        $this->convert();
        $this->assertSame("A='x'\n", file_get_contents($this->dir.'/.env'));
    }

    public function testNoEnvYet()
    {
        $this->convert();

        $this->assertFileDoesNotExist($this->dir.'/storage/.env-standard');
    }
}
