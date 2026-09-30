<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The "PHP mail()" send method: what PHP's mail() hands to sendmail.
 */
class PhpMailTransportTest extends TestCase
{
    public function testMessageHandedToSendmail()
    {
        $capture = tempnam(sys_get_temp_dir(), 'tallport-sendmail');
        $sendmail = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../Support/php-mail/capture-sendmail.php');
        $command = escapeshellarg(PHP_BINARY)
            .' -d '.escapeshellarg('sendmail_path='.$sendmail)
            .' '.escapeshellarg(__DIR__.'/../Support/php-mail/send.php');

        try {
            exec('TALLPORT_SENDMAIL_CAPTURE='.escapeshellarg($capture).' '.$command.' 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));
            $sent = json_decode(file_get_contents($capture), true);
        } finally {
            @unlink($capture);
        }

        // Envelope sender.
        $this->assertContains('-fsupport@example.org', $sent['argv']);

        // Headers end with CRLF (see PhpMailTransport), the body uses PHP_EOL.
        [$headers, $body] = explode("\r\n\r\n", $sent['message'], 2);
        $header_lines = preg_split("/\r\n(?![ \t])/", $headers);
        $names = array_map(function ($line) {
            return strtolower(explode(':', $line, 2)[0]);
        }, $header_lines);

        // To and Subject come from mail() itself, exactly once and unfolded.
        $this->assertSame(1, count(array_keys($names, 'to')));
        $this->assertSame(1, count(array_keys($names, 'subject')));
        $this->assertContains('To: Casey Customer <casey@customer.example.org>', $header_lines);
        $this->assertContains('Subject: Re: =?utf-8?Q?=C3=9Cn=C3=AFcode?= subject that is long enough to be folded by the mime encoder of symfony', $header_lines);
        // Bcc recipients reach sendmail (-t reads them from the headers).
        $this->assertContains('Bcc: audit@example.org', $header_lines);
        $this->assertContains('Message-ID: <fs-reply-1-abc@example.org>', $header_lines);
        $this->assertStringContainsString('Content-Type: multipart/alternative', $headers);
        $this->assertStringContainsString('Your order ships tomorrow.', $body);
    }
}
