<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use Dotenv\Loader\Parser;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\RepositoryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * .env values read the same as with FreeScout's patched phpdotenv 2
 * (overrides/vlucas/phpdotenv): Helper::setEnvFileVar() writes values such
 * as "ab\cd" that stock phpdotenv 3 rejects, and passwords may contain "#".
 */
class DotenvParsingTest extends TestCase
{
    /**
     * @dataProvider values
     */
    public function testValue($raw, $expected)
    {
        [$name, $value] = Parser::parse('KEY='.$raw);

        $this->assertSame('KEY', $name);
        $this->assertSame($expected, $value->getChars());
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
        $adapter = new ArrayAdapter();
        $repository = RepositoryBuilder::create()->withReaders([$adapter])->withWriters([$adapter])->make();

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
        Parser::parse('KEY=two words');
    }
}
