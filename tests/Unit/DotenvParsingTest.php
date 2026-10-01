<?php

namespace Tests\Unit;

use App\Misc\EnvFile;
use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * .env is read by standard phpdotenv. Files FreeScout wrote with its own
 * rules (a patched phpdotenv 2) are converted once (App\Misc\EnvFile), which
 * reads them with those rules.
 */
class DotenvParsingTest extends TestCase
{
    /**
     * FreeScout's rules, as the conversion reads old files.
     *
     * @dataProvider values
     */
    public function testLegacyValue($raw, $expected)
    {
        $this->assertSame($expected, EnvFile::parseLegacyValue($raw));
    }

    public function values()
    {
        return [
            'plain'                     => ['plain', 'plain'],
            'hash without space'        => ['abc#123', 'abc#123'],
            'comment after space'       => ['abc #comment', 'abc'],
            'quoted with space'         => ['"quoted value"', 'quoted value'],
            'quote inside quotes'       => ['"pa"ss"', 'pa"ss'],
            'unknown escape kept'       => ['"ab\cd"', 'ab\cd'],
            'escaped quote'             => ['"a\"b"', 'a"b'],
            'escaped backslash'         => ['"a\\\\b"', 'a\b'],
            'comment after quotes'      => ['"x" #c', 'x'],
            'single quotes'             => ["'single q'", 'single q'],
            'trailing backslash'        => ['"trail\"', 'trail\\'],
            'comment only'              => ['# only comment', ''],
            'empty'                     => ['', ''],
        ];
    }

    public function testNestedVariablesAreResolved()
    {
        $dir = sys_get_temp_dir().'/tallport-dotenv-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/.env', 'BASE=https://example.org'."\n".'URL="${BASE}/help"'."\n".'RAW=${BASE}'."\n");
        $adapter = ArrayAdapter::create()->get();
        $repository = RepositoryBuilder::createWithNoAdapters()->addReader($adapter)->addWriter($adapter)->make();

        try {
            $values = Dotenv::create($repository, $dir)->load();
        } finally {
            unlink($dir.'/.env');
            rmdir($dir);
        }

        $this->assertSame('https://example.org/help', $values['URL']);
        $this->assertSame('https://example.org', $values['RAW']);
    }

    public function testUnquotedSpacesAreRejected()
    {
        $this->expectException(\Dotenv\Exception\InvalidFileException::class);
        (new Parser())->parse('KEY=two words');
    }
}
