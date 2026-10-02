<?php

namespace Tests\Unit;

use App\Incoming\Parser;
use App\Incoming\Webklex6Message;
use Tests\TestCase;

/**
 * Incoming email is read with webklex 6 and Tallport's own code.
 */
class IncomingParserTest extends TestCase
{
    public function testEmailIsRead()
    {
        $message = Parser::parse(file_get_contents(__DIR__.'/../Messages/webklex/plain.eml'));

        $this->assertInstanceOf(Webklex6Message::class, $message);
        $this->assertSame('Example', $message->subject());
    }

    public function testUnreadableEmailFailsRightAway()
    {
        $this->expectException(\Exception::class);

        // A multipart email without a boundary.
        Parser::parse("Subject: Broken\r\nContent-Type: multipart/mixed\r\n\r\nHello");
    }
}
