<?php

namespace Tests\Unit;

use App\Misc\EnvFile;
use Dotenv\Parser\Parser;
use Tests\TestCase;

/**
 * .env is written in the standard dotenv syntax, read the same by FreeScout's
 * old rules and by standard phpdotenv.
 */
class EnvFileTest extends TestCase
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

    /**
     * Values as the application's dotenv parser reads them back.
     */
    protected function read()
    {
        $values = [];
        foreach ((new Parser())->parse(file_get_contents($this->path)) as $entry) {
            $values[$entry->getName()] = $entry->getValue()->get()->getChars();
        }

        return $values;
    }

    public static function values()
    {
        return [
            'plain'             => ['abc123', 'abc123'],
            'url'               => ['https://example.org/support', 'https://example.org/support'],
            'empty'             => ['', ''],
            'spaces'            => ['two words', '"two words"'],
            'double quote'      => ['pa"ss', '"pa\\"ss"'],
            'trailing backslash'=> ['abc\\', '"abc\\\\"'],
            'hash'              => ['a #b', '"a #b"'],
            'reference'         => ['${OTHER}', '"${OTHER}"'],
        ];
    }

    /**
     * @dataProvider values
     */
    public function testValuesAreWrittenAndReadBack($value, $written)
    {
        $this->assertSame($written, EnvFile::formatValue($value));

        EnvFile::setVar($this->path, 'TP_KEY', $value);

        $this->assertSame($value, $this->read()['TP_KEY']);
        $this->assertSame($value, EnvFile::parseLegacyValue($written));
    }

    public function testSetVarReplacesOnlyThatKey()
    {
        file_put_contents($this->path, "APP_URL=https://a.example\nURL=old\n# comment\n");

        EnvFile::setVar($this->path, 'URL', 'new value');
        EnvFile::setVar($this->path, 'ADDED', 'x');

        $this->assertSame("APP_URL=https://a.example\nURL=\"new value\"\n# comment\nADDED=x\n", file_get_contents($this->path));
    }

    public function testStandardizeKeepsMeaning()
    {
        $legacy = "# Settings\nAPP_URL=https://a.example\nPASS=\"pa\\\"ss\"\nBACKSLASH=\"abc\\\"\nCOMMENTED=value # note\nSINGLE='x \${A} y'\nLAST=\"a\"b\"\n";
        file_put_contents($this->path, $legacy);
        $expected = [
            'APP_URL'   => 'https://a.example',
            'PASS'      => 'pa"ss',
            'BACKSLASH' => 'abc\\',
            'COMMENTED' => 'value',
            'SINGLE'    => 'x ${A} y',
            'LAST'      => 'a"b',
        ];

        // PASS is already standard; the other four change.
        $this->assertSame(4, EnvFile::standardize($this->path));

        $this->assertSame($expected, $this->read());
        $this->assertStringStartsWith("# Settings\n", file_get_contents($this->path));
        $this->assertCount(1, glob($this->path.'.backup-*'));
        $this->assertSame($legacy, file_get_contents(glob($this->path.'.backup-*')[0]));

        // Already standard: nothing changes, no new backup.
        $this->assertSame(0, EnvFile::standardize($this->path));
        $this->assertCount(1, glob($this->path.'.backup-*'));
    }
}
