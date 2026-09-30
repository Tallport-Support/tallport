<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Helpers used when reading incoming mail: MIME-encoded subjects,
 * auto-responder detection, Message-ID hashes and markers, dates in the
 * formats mail servers actually send, and small formatting utilities.
 */
class MailParsingHelpersTest extends TestCase
{
    /**
     * @dataProvider encodedSubjects
     */
    public function testDecodeSubject($encoded, $decoded)
    {
        $this->assertSame($decoded, \MailHelper::decodeSubject($encoded));
    }

    public function encodedSubjects()
    {
        return [
            'plain'             => ['Question about my order', 'Question about my order'],
            'base64 utf-8'      => ['=?UTF-8?B?R3LDvMOfZSBhdXMgTcO8bmNoZW4=?=', 'Grüße aus München'],
            'quoted-printable'  => ['=?UTF-8?Q?Caf=C3=A9_order?=', 'Café order'],
            'split over parts'  => ['=?UTF-8?B?R3LDvMOf?= =?UTF-8?B?ZQ==?=', 'Grüße'],
            'folded'            => ["=?UTF-8?Q?Caf=C3=A9?=\r\n =?UTF-8?Q?_order?=", 'Café order'],
            'mixed with plain'  => ['Re: =?UTF-8?Q?Caf=C3=A9?= order', 'Re: Café order'],
            'iso-8859-1'        => ['=?ISO-8859-1?Q?Gr=FC=DFe?=', 'Grüße'],
        ];
    }

    /**
     * @dataProvider autoResponderHeaders
     */
    public function testIsAutoResponder($headers, $expected)
    {
        $this->assertSame($expected, \MailHelper::isAutoResponder($headers));
    }

    public function autoResponderHeaders()
    {
        return [
            'normal email'         => ["From: a@example.org\nSubject: Hi", false],
            'auto-submitted'       => ["Auto-Submitted: auto-replied\nSubject: Out of office", true],
            'x-autoreply'          => ["X-Autoreply: yes", true],
            'precedence bulk'      => ["Precedence: bulk", true],
            'precedence list'      => ["Precedence: list", true],
            'precedence first-class' => ["Precedence: first-class", false],
            'case insensitive name' => ["AUTO-SUBMITTED: auto-generated", true],
        ];
    }

    public function testMessageIdHashIsStableAndKeyed()
    {
        $hash = \MailHelper::getMessageIdHash(42);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $hash);
        $this->assertSame($hash, \MailHelper::getMessageIdHash(42));
        $this->assertNotSame($hash, \MailHelper::getMessageIdHash(43));

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $this->assertNotSame($hash, \MailHelper::getMessageIdHash(42), 'Depends on the app key, so it cannot be forged.');
    }

    public function testGetHeaderFromRawHeaders()
    {
        $headers = "From: Casey <casey@customer.example.org>\r\nMessage-ID: <abc@customer.example.org>\r\nX-Custom: one\r\n";

        $this->assertSame('abc@customer.example.org', \MailHelper::getHeader($headers, 'Message-ID'));
        $this->assertSame('', \MailHelper::getHeader($headers, 'In-Reply-To'));
    }

    /**
     * @dataProvider mailDates
     */
    public function testParseDateToCarbon($date, $expected_utc)
    {
        $this->assertSame($expected_utc, \Helper::parseDateToCarbon($date)->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function mailDates()
    {
        return [
            'RFC 2822'                  => ['Tue, 08 Sep 2026 15:46:52 +0700', '2026-09-08 08:46:52'],
            'with comment'              => ['Tue, 08 Sep 2026 15:46:52 +0700 (ICT)', '2026-09-08 08:46:52'],
            'UT instead of UTC'         => ['8 Sep 2026 08:46:52 UT', '2026-09-08 08:46:52'],
            'invalid +0580 India'       => ['Tue, 08 Sep 2026 15:46:52 +0580', '2026-09-08 10:16:52'],
            'angle brackets'            => ['<Tue, 08 Sep 2026 15:46:52 +0000>', '2026-09-08 15:46:52'],
        ];
    }

    public function testInvalidDateFallsBackToNow()
    {
        $parsed = \Helper::parseDateToCarbon('not a date at all');

        $this->assertLessThan(5, abs($parsed->diffInSeconds(\Carbon\Carbon::now())));
    }

    public function testHumanFileSize()
    {
        $this->assertSame('1.00KB', \Helper::humanFileSize(1024));
        $this->assertSame('1.50MB', \Helper::humanFileSize(1.5 * 1024 * 1024));
        $this->assertSame('2.00GB', \Helper::humanFileSize(2 * 1024 * 1024 * 1024));
    }

    public function testSqlEscapeLike()
    {
        $this->assertSame('100\\%\\_off\\\\', \Helper::sqlEscapeLike('100%_off\\'));
    }
}
