<?php

namespace Tests\Unit;

use App\Incoming\Webklex6Message;
use Tests\TestCase;

/**
 * What Tallport does around webklex/php-imap 6 so it reads what
 * FreeScout's patched webklex read: boundaries split into RFC 2231 continuations
 * (FreeScout issue 4567), invalid dates, null bytes.
 */
class Webklex6MessageTest extends TestCase
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

    public function testNullBytesAreIgnored()
    {
        $html = quoted_printable_encode(mb_convert_encoding('<p>Привет, мир</p>', 'KOI8-R', 'UTF-8'));
        $raw = "From: a@example.org\r\nSubject: Hi\r\nContent-Type: text/html; charset=\"koi8-r\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            .substr($html, 0, 5)."\0\0".substr($html, 5);

        $message = new Webklex6Message($raw);

        $this->assertSame('<p>Привет, мир</p>', $message->htmlBody());
        $this->assertStringContainsString("\0", $message->rawSource(), 'The source is kept as received.');
    }

    /**
     * FreeScout's patched webklex added such an HTML part to the plain text.
     */
    public function testHtmlPartWithEmptyCharsetIsHtml()
    {
        $message = new Webklex6Message(file_get_contents(__DIR__.'/../Messages/webklex/without_charset_simple_multipart.eml'));

        $this->assertSame('MyHtml', $message->htmlBody());
        $this->assertSame('MyPlain', $message->textBody());
    }

    public function testInvalidDateIsTheTimeOfReceiving()
    {
        $message = new Webklex6Message("From: a@example.org\r\nDate: %date_raw_header%\r\nSubject: Hi\r\n\r\nBody");

        $this->assertEqualsWithDelta(time(), $message->date()->timestamp, 5);
    }
}
