<?php

namespace Tests\Unit;

use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
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
        $entry = (new Parser())->parse('KEY='.$raw)[0];

        $this->assertSame('KEY', $entry->getName());
        $this->assertSame($expected, $entry->getValue()->get()->getChars());
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

    public function testEveryLineIsOneEntry()
    {
        $entries = (new Parser())->parse("A=\"ends with backslash\\\"\nB=2");

        $values = [];
        foreach ($entries as $entry) {
            $values[$entry->getName()] = $entry->getValue()->get()->getChars();
        }
        $this->assertSame('ends with backslash\\', $values['A']);
        $this->assertSame('2', $values['B']);
    }

    public function testUnquotedSpacesAreRejected()
    {
        $this->expectException(\Dotenv\Exception\InvalidFileException::class);
        (new Parser())->parse('KEY=two words');
    }
}
