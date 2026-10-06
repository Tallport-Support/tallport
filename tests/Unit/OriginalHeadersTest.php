<?php

namespace Tests\Unit;

use App\Incoming\OriginalHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Show Original's headers: unfolded and in order, a summary decoded for people, the
 * receiving server's authentication results and the hop the email came in by.
 */
class OriginalHeadersTest extends TestCase
{
    const RAW = "Received: from mail-ed1-f50.google.com (mail-ed1-f50.google.com [209.85.208.50])\r\n"
        ."\tby help.example.org (Postfix) with ESMTPS id 4F1\r\n"
        ."\tfor <support@example.org>; Mon, 5 Oct 2026 10:00:00 +0000\r\n"
        ."Received: from relay.example.net by mail-ed1-f50.google.com; Mon, 5 Oct 2026 09:59:59 +0000\r\n"
        ."Authentication-Results: help.example.org;\r\n"
        ."\tspf=pass smtp.mailfrom=gmail.com;\r\n"
        ."\tdkim=pass header.d=gmail.com;\r\n"
        ."\tdmarc=none\r\n"
        ."DKIM-Signature: v=1; a=rsa-sha256; b=AAAAAAAAAAAAAAAAAAAAAAAAAAAA\r\n"
        ." BBBBBBBBBBBBBBBBBBBB\r\n"
        ."From: =?UTF-8?B?Q2FzZXkgTMOpZQ==?= <casey@example.com>\r\n"
        ."To: support@example.org\r\n"
        ."Subject: =?UTF-8?Q?Caf=C3=A9_order?=\r\n"
        ."Message-ID: <abc@example.com>\r\n";

    public function testEveryHeaderUnfoldedInOrder()
    {
        $all = OriginalHeaders::all(self::RAW);

        $this->assertSame(['Received', 'Received', 'Authentication-Results', 'DKIM-Signature', 'From', 'To', 'Subject', 'Message-ID'], array_column($all, 0));
        $this->assertSame('v=1; a=rsa-sha256; b=AAAAAAAAAAAAAAAAAAAAAAAAAAAA BBBBBBBBBBBBBBBBBBBB', $all[3][1]);
    }

    public function testSummaryAuthenticationAndHop()
    {
        $this->assertSame([
            'From'       => 'Casey Lée <casey@example.com>',
            'To'         => 'support@example.org',
            'Subject'    => 'Café order',
            'Message-ID' => '<abc@example.com>',
        ], OriginalHeaders::summary(self::RAW));
        $this->assertSame(['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'none'], OriginalHeaders::authentication(self::RAW));
        $this->assertSame('mail-ed1-f50.google.com → help.example.org', OriginalHeaders::deliveredVia(self::RAW));

        $this->assertNull(OriginalHeaders::authentication("From: a@example.com\r\n"));
        $this->assertNull(OriginalHeaders::deliveredVia("From: a@example.com\r\n"));
        $this->assertSame(['success', 'danger', 'warning', 'neutral'], array_map([OriginalHeaders::class, 'tone'], ['pass', 'fail', 'softfail', 'none']));
    }
}
