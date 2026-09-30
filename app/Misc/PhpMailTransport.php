<?php

namespace App\Misc;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends email with PHP's mail() function ("PHP mail()" send method), as
 * SwiftMailer's MailTransport did. Symfony Mailer has no such transport.
 */
class PhpMailTransport extends AbstractTransport
{
    public function __toString(): string
    {
        return 'mail://default';
    }

    protected function doSend(SentMessage $message): void
    {
        $raw = $message->toString();

        // Separate headers from body.
        if (false !== $end = strpos($raw, "\r\n\r\n")) {
            $header_block = substr($raw, 0, $end);
            $body = substr($raw, $end + 4);
        } else {
            $header_block = $raw;
            $body = '';
        }

        // mail() adds To and Subject itself, so take them out of the headers.
        // Headers are split into logical lines, keeping folded continuations.
        $to = '';
        $subject = '';
        $headers = [];
        foreach (preg_split("/\r\n(?![ \t])/", $header_block) as $line) {
            $name = strtolower(trim(explode(':', $line, 2)[0]));
            // Unfold (RFC 5322 2.2.3): mail() takes To and Subject as one line.
            if ($name === 'to') {
                $to = preg_replace("/\r\n(?=[ \t])/", '', ltrim(substr($line, 3)));
            } elseif ($name === 'subject') {
                $subject = preg_replace("/\r\n(?=[ \t])/", '', ltrim(substr($line, 8)));
            } else {
                $headers[] = $line;
            }
        }

        // The prepared message has no Bcc header; mail() delivers to the
        // addresses in a Bcc header, as with SwiftMailer's MailTransport.
        $email = $message->getOriginalMessage();
        if ($email instanceof Email && $email->getBcc()) {
            $headers[] = 'Bcc: '.implode(', ', array_map(function (Address $address) {
                return $address->toString();
            }, $email->getBcc()));
        }
        $headers = implode("\r\n", $headers)."\r\n";

        if ("\r\n" != PHP_EOL) {
            // Do NOT convert CRLF to LF in $headers: mail() emits the To: and
            // Subject: headers using CRLF, and a mix of line endings makes
            // MTAs such as Exim fold headers together, so the message loses
            // its MIME type.
            // https://github.com/freescout-help-desk/freescout/pull/5615
            $subject = str_replace("\r\n", PHP_EOL, $subject);
            $body = str_replace("\r\n", PHP_EOL, $body);
        } else {
            // Windows, using SMTP.
            $headers = str_replace("\r\n.", "\r\n..", $headers);
            $subject = str_replace("\r\n.", "\r\n..", $subject);
            $body = str_replace("\r\n.", "\r\n..", $body);
        }

        // Envelope sender (Return-Path), unless it could break the command line.
        $reverse_path = $message->getEnvelope()->getSender()->getAddress();
        $params = null;
        if ($reverse_path && preg_match('/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~@-]+$/', $reverse_path)) {
            $params = '-f'.$reverse_path;
        }

        $sent = $params !== null
            ? mail($to, $subject, $body, $headers, $params)
            : mail($to, $subject, $body, $headers);

        if (!$sent) {
            throw new TransportException('Unable to send an email: mail() returned false.');
        }
    }
}
