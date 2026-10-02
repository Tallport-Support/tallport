<?php

namespace Tests\Unit;

use App\Incoming\Parser;
use App\Incoming\ParserComparison;
use App\Incoming\Webklex6Message;
use Tests\TestCase;

/**
 * Incoming email is read with webklex 6, falling back to App\LegacyImap.
 */
class IncomingParserTest extends TestCase
{
    public function testEmailIsReadWithWebklex6()
    {
        $message = Parser::parse(file_get_contents(__DIR__.'/../Messages/webklex/plain.eml'));

        $this->assertInstanceOf(Webklex6Message::class, $message);
    }

    public function testFetchedMessageIsTheFallback()
    {
        $legacy = ParserComparison::legacy(file_get_contents(__DIR__.'/../Messages/webklex/plain.eml'));
        \Log::spy();

        // A source webklex 6 can't read.
        $message = Parser::parse("Subject: Broken\r\nContent-Type: multipart/mixed\r\n\r\nHello", $legacy);

        $this->assertSame($legacy, $message);
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_contains($message, 'used the legacy parser');
        })->once();
    }
}
