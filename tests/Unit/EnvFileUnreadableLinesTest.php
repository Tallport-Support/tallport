<?php

namespace Tests\Unit;

use App\Misc\EnvFile;
use Tests\TestCase;

/**
 * EnvFile::standardize() leaves lines it can't read as they are, and a
 * missing .env file alone.
 */
class EnvFileUnreadableLinesTest extends TestCase
{
    protected $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'tallport-env');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->path.'*'));
        parent::tearDown();
    }

    public function testMissingFile()
    {
        unlink($this->path);

        $this->assertSame(0, EnvFile::standardize($this->path));
        $this->assertFileDoesNotExist($this->path);
    }

    public function testUnreadableLinesAreKept()
    {
        $contents = "1BAD=value # note\nAPP NAME=x\nTWO=two words\nOK=value # note\n";
        file_put_contents($this->path, $contents);

        $this->assertSame(1, EnvFile::standardize($this->path));

        $this->assertSame("1BAD=value # note\nAPP NAME=x\nTWO=two words\nOK=value\n", file_get_contents($this->path));
        $this->assertNull(EnvFile::parseLegacyValue('two words'));
    }
}
