<?php

namespace Tests\Unit;

use App\Incoming\DeliveryReport;
use App\Incoming\Parser;
use Tests\TestCase;

/**
 * Delivery reports are read from real-world and typical samples
 * (tests/Messages/delivery-report-*.eml): kind, recipient, reason, reporter
 * and the original email.
 */
class DeliveryReportTest extends TestCase
{
    protected function report($file, $is_bounce = false)
    {
        return DeliveryReport::read(Parser::parse(file_get_contents(__DIR__.'/../Messages/'.$file)), $is_bounce);
    }

    /**
     * Amazon SES's suppression notice comes as an ARF complaint report, with
     * the original (multipart, with a PDF) attached inline.
     */
    public function testAmazonSesSuppressionNotice()
    {
        $report = $this->report('delivery-report-ses-suppressed.eml');

        $this->assertSame(DeliveryReport::SUPPRESSED, $report->kind);
        $this->assertSame(['jamie.doe@customer.example.org'], $report->recipients);
        $this->assertSame('suppressed', $report->reason);
        $this->assertSame('Amazon SES', $report->reporter);
        $this->assertStringContainsString('Feedback-Type: abuse', $report->details);
        $this->assertSame([
            'from'       => 'Example Shop <support@shop.example.com>',
            'to'         => 'jamie.doe@customer.example.org',
            'subject'    => 'Payment Confirmation for Your Example Shop Service',
            'date'       => '2026-10-08T01:03:59+00:00',
            'message_id' => '010001a11909e1a3-4ce1a1b5-4a65-4876-be19-000000000002-000000@email.amazonses.com',
        ], $report->originalHeaders());

        $original = $report->originalMessage();
        $this->assertStringContainsString('Invoice Number: 100001', $original->htmlBody());

        // The report's files: the original as an .eml file, and its PDF.
        $files = array_map(fn ($attachment) => [$attachment->getName(), $attachment->getMimeType()], $report->attachments([]));
        $this->assertSame([
            ['Payment Confirmation for Your Example Shop Service.eml', 'message/rfc822'],
            ['Invoice-100001.pdf', 'application/pdf'],
        ], $files);
    }

    public function testComplaint()
    {
        $report = $this->report('delivery-report-arf-complaint.eml');

        $this->assertSame(DeliveryReport::COMPLAINT, $report->kind);
        $this->assertSame(['casey.jones@mail.example.org'], $report->recipients);
        $this->assertSame('complaint', $report->reason);
        $this->assertSame('mail.example.org', $report->reporter);
        $this->assertSame('Re: Cancel my account', $report->originalHeaders()['subject']);
    }

    /**
     * An ARF report that isn't a complaint (a DMARC failure report) isn't about delivery.
     */
    public function testFeedbackReportOtherThanComplaintIsNotADeliveryReport()
    {
        $raw = str_replace('Feedback-Type: abuse', 'Feedback-Type: auth-failure', file_get_contents(__DIR__.'/../Messages/delivery-report-arf-complaint.eml'));

        $this->assertNull(DeliveryReport::read(Parser::parse($raw)));
    }

    public function testBounce()
    {
        $report = $this->report('delivery-report-dsn-bounce.eml', true);

        $this->assertSame(DeliveryReport::BOUNCE, $report->kind);
        $this->assertSame(['sam.taylor@customer.example.org'], $report->recipients);
        $this->assertSame('5.1.1', $report->status);
        $this->assertSame('550 5.1.1 <sam.taylor@customer.example.org>: Recipient address rejected: User unknown in virtual mailbox table', $report->diagnostic);
        $this->assertSame('unknown_address', $report->reason);
        $this->assertSame('mx.customer.example.org', $report->reporter);
        $this->assertSame('Sam Taylor', $report->recipientAddresses()[0]->personal);
        $this->assertSame(['Your order 1001 has shipped.eml', 'tracking-1001.txt'], array_map(fn ($attachment) => $attachment->getName(), $report->attachments([])));
    }

    /**
     * A bounce without a delivery-status part (by Content-Type) is read too.
     */
    public function testBounceOfTallportReply()
    {
        $report = $this->report('delivery-report-tallport-reply.eml');

        $this->assertSame(DeliveryReport::BOUNCE, $report->kind);
        $this->assertSame(['robin.lee@gmail.example.org'], $report->recipients);
        $this->assertSame('Gmail', $report->reporter);
        $this->assertSame('TP_reply-123-0123456789abcdef@help.example.net', $report->originalHeaders()['message_id']);
        // The reply's code isn't repeated for each line.
        $this->assertStringStartsWith('550-5.1.1 The email account that you tried to reach does not exist. Please try double-checking', $report->diagnostic);
        $this->assertSame(1, substr_count($report->diagnostic, '5.1.1'));
    }

    /**
     * A delay; the original is only its headers, so there's no .eml file.
     */
    public function testDelay()
    {
        $report = $this->report('delivery-report-dsn-delayed.eml', true);

        $this->assertSame(DeliveryReport::DELAYED, $report->kind);
        $this->assertSame(['alex.morgan@slow.example.org'], $report->recipients);
        $this->assertSame('4.4.1', $report->status);
        $this->assertSame('unreachable', $report->reason);
        $this->assertSame('Re: Invoice question', $report->originalHeaders()['subject']);
        $this->assertNull($report->originalMessage());
        $this->assertSame([], $report->attachments([]));
    }

    /**
     * A mail server's plain-text notice, read when the email is known to be a bounce.
     */
    public function testPlainTextBounce()
    {
        $this->assertNull($this->report('delivery-report-plain-text.eml'));

        $report = $this->report('delivery-report-plain-text.eml', true);

        $this->assertSame(DeliveryReport::BOUNCE, $report->kind);
        $this->assertSame(['lee.smith@customer.example.org'], $report->recipients);
        $this->assertSame('5.2.2', $report->status);
        $this->assertSame('mailbox_full', $report->reason);
        $this->assertSame('Re: Your subscription', $report->originalHeaders()['subject']);
        $this->assertSame('subscription-reply@help.example.net', $report->originalHeaders()['message_id']);
        $this->assertStringContainsString('Your subscription has been renewed.', $report->originalMessage()->textBody());
    }

    /**
     * Exim's report (webklex/php-imap's sample): the machine-readable part and the
     * attached original give way to the original as an .eml file.
     */
    public function testEximBounce()
    {
        $message = Parser::parse(file_get_contents(__DIR__.'/../Messages/webklex/example_bounce.eml'));
        $report = DeliveryReport::read($message, true);

        $this->assertSame(['ding@ding.de'], $report->recipients);
        $this->assertSame('5.0.0', $report->status);
        $this->assertSame('sslproxy01.your-server.de', $report->reporter);
        $this->assertSame(['Test.eml'], array_map(fn ($attachment) => $attachment->getName(), $report->attachments($message->attachments())));
    }

    /**
     * A notice from a mail server that names nobody, and ordinary email, aren't delivery reports.
     */
    public function testNotADeliveryReport()
    {
        $this->assertNull(DeliveryReport::read(Parser::parse(file_get_contents(__DIR__.'/../Messages/webklex/issue-382.eml')), true));
        $this->assertNull(DeliveryReport::read(Parser::parse(file_get_contents(__DIR__.'/../Messages/message-1.eml'))));
    }

    /**
     * A DSN that reports success isn't about a problem.
     */
    public function testSuccessfulDeliveryIsNotAProblem()
    {
        $raw = str_replace('Action: failed', 'Action: delivered', file_get_contents(__DIR__.'/../Messages/delivery-report-dsn-bounce.eml'));

        $this->assertNull(DeliveryReport::read(Parser::parse($raw), true));
    }

    /**
     * @dataProvider reasons
     */
    public function testReason($kind, $status, $diagnostic, $reason)
    {
        $this->assertSame($reason, DeliveryReport::reason($kind, $status, $diagnostic));
    }

    public static function reasons()
    {
        return [
            'unknown address'  => ['bounce', '5.1.1', '', 'unknown_address'],
            'unknown domain'   => ['bounce', '5.1.2', '', 'unknown_domain'],
            'mailbox full'     => ['bounce', '5.2.2', '', 'mailbox_full'],
            'disabled'         => ['bounce', '5.2.1', '', 'mailbox_disabled'],
            'too large'        => ['bounce', '5.3.4', '', 'too_large'],
            'blocked'          => ['bounce', '5.7.1', 'Message rejected', 'blocked'],
            'by words'         => ['bounce', '5.0.0', '550 No such user here', 'unknown_address'],
            'quota by words'   => ['bounce', '', '552 Mailbox is full', 'mailbox_full'],
            'other'            => ['bounce', '5.0.0', '550 Rejected', 'rejected'],
            'delayed'          => ['delayed', '4.0.0', '', 'delayed'],
            'unreachable'      => ['delayed', '4.4.1', '', 'unreachable'],
            'complaint'        => ['complaint', '', '', 'complaint'],
        ];
    }
}
