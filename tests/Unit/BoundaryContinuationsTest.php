<?php

namespace Tests\Unit;

use App\Incoming\Webklex6Message;
use Tests\TestCase;

/**
 * Multipart boundaries split into RFC 2231 continuations, which webklex
 * doesn't read (FreeScout issue 4567).
 */
class BoundaryContinuationsTest extends TestCase
{
    public function testExtendedContinuationsAreJoined()
    {
        $raw = "Subject: Signed\r\nContent-Type: multipart/signed; protocol=\"application/pgp-signature\";\r\n micalg=\"pgp-sha256\";\r\n boundary*0*=us-ascii''------3f0e;\r\n boundary*1*=4dab%2D9839; charset=\"utf-8\"\r\n\r\nBody";

        $this->assertSame(
            "Subject: Signed\r\nContent-Type: multipart/signed; protocol=\"application/pgp-signature\";\r\n micalg=\"pgp-sha256\"; charset=\"utf-8\"; boundary=\"------3f0e4dab-9839\"\r\n\r\nBody",
            Webklex6Message::joinBoundaryContinuations($raw)
        );
    }

    public function testPlainContinuationsAreJoined()
    {
        $raw = "Content-Type: multipart/mixed;\r\n boundary*0=\"abc\"; boundary*1=\"def\"\r\n\r\n";

        $this->assertSame("Content-Type: multipart/mixed; boundary=\"abcdef\"\r\n\r\n", Webklex6Message::joinBoundaryContinuations($raw));
    }

    public function testOtherHeadersAreLeftAlone()
    {
        $raw = "Content-Type: multipart/mixed; boundary=\"abc\"\r\nX-Note: boundary*0=x\r\n\r\n--abc\r\nContent-Type: text/plain\r\n\r\nHi";

        $this->assertSame($raw, Webklex6Message::joinBoundaryContinuations($raw));
    }

    public function testSignedEmailIsRead()
    {
        $message = new Webklex6Message(file_get_contents(__DIR__.'/../Messages/issue-4567.eml'));

        $this->assertSame(['Hi!'], array_map(function ($attachment) {
            return $attachment->getContent();
        }, $message->attachments()));
    }

    public function testInvalidDateIsTheTimeOfReceiving()
    {
        $message = new Webklex6Message("From: a@example.org\r\nDate: %date_raw_header%\r\nSubject: Hi\r\n\r\nBody");

        $this->assertEqualsWithDelta(time(), $message->date()->timestamp, 5);
    }
}
