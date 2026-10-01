<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Tests\TestCase;

/**
 * public/install.php, the first step of the web installer, runs before the
 * application exists: it creates .env with an APP_KEY. It runs here on a copy
 * in a temporary directory, so the real .env is never touched.
 */
class InstallScriptTest extends TestCase
{
    protected $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/tallport-install-'.uniqid();
        mkdir($this->root.'/public', 0777, true);
        copy(base_path('public/install.php'), $this->root.'/public/install.php');
        file_put_contents($this->root.'/.env.example', "APP_URL=\nAPP_KEY=\nDB_HOST=localhost\n");
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->root);
        parent::tearDown();
    }

    protected function runInstallScript()
    {
        $code = '$_SERVER["REQUEST_URI"] = "/install.php"; include '.var_export($this->root.'/public/install.php', true).';';
        exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code).' 2>&1', $output, $exit_code);

        $this->assertSame(0, $exit_code, implode("\n", $output));
    }

    protected function appKey()
    {
        preg_match('/^APP_KEY=(.*)$/m', file_get_contents($this->root.'/.env'), $m);

        return $m[1];
    }

    public function testCreatesEnvWithAppKey()
    {
        $this->runInstallScript();

        $this->assertMatchesRegularExpression('/^base64:[A-Za-z0-9+\/]{43}=$/', $this->appKey());
        $this->assertStringContainsString("DB_HOST=localhost\n", file_get_contents($this->root.'/.env'));
    }

    public function testKeepsExistingAppKey()
    {
        $this->runInstallScript();
        $key = $this->appKey();

        $this->runInstallScript();

        $this->assertSame($key, $this->appKey());
    }
}
