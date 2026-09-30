<?php

namespace Tests\Unit;

use Dotenv\Parser;
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
        $this->assertSame(['KEY', $expected], Parser::parse('KEY='.$raw));
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

    public function testUnquotedSpacesAreRejected()
    {
        $this->expectException(\Dotenv\Exception\InvalidFileException::class);
        Parser::parse('KEY=two words');
    }
}
