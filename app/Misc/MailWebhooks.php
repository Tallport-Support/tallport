<?php

namespace App\Misc;

use App\Incoming\DeliveryReport;
use App\Mailbox;
use Illuminate\Http\Request;
use Symfony\Component\Mailer\Bridge\Mailgun\RemoteEvent\MailgunPayloadConverter;
use Symfony\Component\Mailer\Bridge\Mailgun\Webhook\MailgunRequestParser;
use Symfony\Component\Mailer\Bridge\Postmark\RemoteEvent\PostmarkPayloadConverter;
use Symfony\Component\Mailer\Bridge\Postmark\Webhook\PostmarkRequestParser;
use Symfony\Component\Mailer\Bridge\Resend\RemoteEvent\ResendPayloadConverter;
use Symfony\Component\Mailer\Bridge\Resend\Webhook\ResendRequestParser;
use Symfony\Component\RemoteEvent\Event\Mailer\AbstractMailerEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerEngagementEvent;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Sending services' delivery events (bounces, complaints, suppressions) for a
 * mailbox's sent replies, from their webhooks: read and checked as each
 * service signs them (Symfony's request parsers for Mailgun, Postmark and
 * Resend; Amazon SNS's signature for Amazon SES), then recorded as report
 * emails are (DeliveryReports::recordFromService()), once.
 */
class MailWebhooks
{
    /**
     * Postmark's bounce types: what each means here (others are ignored).
     */
    const POSTMARK_BOUNCE_TYPES = [
        'HardBounce'          => DeliveryReport::BOUNCE,
        'BadEmailAddress'     => DeliveryReport::BOUNCE,
        'Blocked'             => DeliveryReport::BOUNCE,
        'DnsError'            => DeliveryReport::BOUNCE,
        'DMARCPolicy'         => DeliveryReport::BOUNCE,
        'Unknown'             => DeliveryReport::BOUNCE,
        'Transient'           => DeliveryReport::DELAYED,
        'SoftBounce'          => DeliveryReport::DELAYED,
        'ManuallyDeactivated' => DeliveryReport::SUPPRESSED,
        'SpamNotification'    => DeliveryReport::COMPLAINT,
        'SpamComplaint'       => DeliveryReport::COMPLAINT,
    ];

    /**
     * Handle a webhook request for the mailbox: [HTTP status, text].
     */
    public static function handle(Request $request, Mailbox $mailbox, $provider)
    {
        try {
            if ($provider == MailProviders::SES) {
                return self::handleSns($request);
            }
            $event = self::parse($request, $mailbox, $provider);
        } catch (RejectWebhookException $e) {
            return [$e->getStatusCode(), $e->getMessage()];
        }

        if ($event) {
            self::record($event);
        }

        return [200, 'OK'];
    }

    /**
     * A Mailgun, Postmark or Resend request as a report:
     * ['report' => DeliveryReport::toArray() shape, 'message_ids' => IDs of the sent email], or null to ignore.
     */
    protected static function parse(Request $request, Mailbox $mailbox, $provider)
    {
        $settings = MailProviders::mailboxSettings($mailbox);
        $secret = (string) ($settings[MailProviders::WEBHOOK_SETTINGS[$provider] ?? ''] ?? '');
        if ($secret === '' && isset(MailProviders::WEBHOOK_SETTINGS[$provider])) {
            throw new RejectWebhookException(403, 'Webhook signing key not set.');
        }
        switch ($provider) {
            case MailProviders::MAILGUN:
                $parser = new MailgunRequestParser(new MailgunPayloadConverter());
                break;
            case MailProviders::POSTMARK:
                // Postmark doesn't sign: the secret in the URL (MailWebhooksController) is the check,
                // from any address (its addresses change, and proxies hide them).
                $parser = new PostmarkRequestParser(new PostmarkPayloadConverter(), ['0.0.0.0/0', '::/0']);
                break;
            case MailProviders::RESEND:
                $parser = new ResendRequestParser(new ResendPayloadConverter());
                break;
            default:
                return null;
        }

        try {
            $event = $parser->parse($request, $secret);
        } catch (RejectWebhookException $e) {
            // An event of a kind Symfony doesn't read is not an error (Mailgun's "stored", Postmark's "Inbound").
            if ($e->getPrevious() instanceof \Symfony\Component\RemoteEvent\Exception\ParseException && str_starts_with($e->getMessage(), 'Unsupported event')) {
                return null;
            }
            throw $e;
        }
        if (!$event instanceof AbstractMailerEvent) {
            return null;
        }

        return self::fromMailerEvent($event, $provider);
    }

    /**
     * A Symfony mailer event as a report, or null.
     */
    protected static function fromMailerEvent(AbstractMailerEvent $event, $provider)
    {
        $payload = $event->getPayload();
        $kind = null;
        $status = '';
        $diagnostic = $event instanceof MailerDeliveryEvent ? $event->getReason() : '';
        $recipients = [$event->getRecipientEmail()];
        $message_ids = [$event->getId()];
        $original = [];

        if ($event instanceof MailerEngagementEvent) {
            $kind = $event->getName() == MailerEngagementEvent::SPAM ? DeliveryReport::COMPLAINT : null;
        } elseif ($event->getName() == MailerDeliveryEvent::BOUNCE) {
            $kind = DeliveryReport::BOUNCE;
        } elseif ($event->getName() == MailerDeliveryEvent::DROPPED) {
            $kind = DeliveryReport::SUPPRESSED;
        } elseif ($event->getName() == MailerDeliveryEvent::DEFERRED) {
            $kind = DeliveryReport::DELAYED;
        }

        switch ($provider) {
            case MailProviders::MAILGUN:
                // The event's id is the event's: the email is named by its Message-ID, which Mailgun keeps.
                $message_ids = [(string) ($payload['message']['headers']['message-id'] ?? '')];
                $status = (string) ($payload['delivery-status']['enhanced-code'] ?? '');
                $original['subject'] = (string) ($payload['message']['headers']['subject'] ?? '');
                break;
            case MailProviders::POSTMARK:
                if (($payload['RecordType'] ?? '') == 'Bounce') {
                    $kind = self::POSTMARK_BOUNCE_TYPES[$payload['Type'] ?? ''] ?? null;
                    $diagnostic = trim((string) ($payload['Details'] ?? '')) ?: $diagnostic;
                }
                $original['subject'] = (string) ($payload['Subject'] ?? '');
                break;
            case MailProviders::RESEND:
                $recipients = (array) ($payload['data']['to'] ?? []);
                if ($kind == DeliveryReport::BOUNCE) {
                    if (($payload['data']['bounce']['type'] ?? '') == 'Transient') {
                        $kind = DeliveryReport::DELAYED;
                    }
                    $diagnostic = (string) ($payload['data']['bounce']['message'] ?? '');
                } elseif (($payload['type'] ?? '') == 'email.failed') {
                    // Not sent (the account's limits, for example): nothing about the address.
                    $kind = null;
                }
                $original['subject'] = (string) ($payload['data']['subject'] ?? '');
                break;
        }

        if (!$kind) {
            return null;
        }

        return [
            'report'      => self::report($kind, $recipients, $status, $diagnostic, MailProviders::NAMES[$provider], $payload, array_filter($original)),
            'message_ids' => $message_ids,
        ];
    }

    /**
     * Amazon SES's events, which Amazon SNS sends: the subscription is
     * confirmed, notifications are read; each only with SNS's signature.
     */
    protected static function handleSns(Request $request)
    {
        $message = json_decode($request->getContent(), true);
        if (!is_array($message) || empty($message['Type'])) {
            return [406, 'Payload is malformed.'];
        }
        if (!self::verifySns($message)) {
            return [406, 'Signature is wrong.'];
        }

        switch ($message['Type']) {
            case 'SubscriptionConfirmation':
                if (!self::isSnsUrl($message['SubscribeURL'] ?? '')) {
                    return [406, 'Payload is malformed.'];
                }
                try {
                    \Illuminate\Support\Facades\Http::withOptions(\Helper::setGuzzleDefaultOptions())->get($message['SubscribeURL'])->throw();
                } catch (\Throwable $e) {
                    return [500, 'Could not confirm the subscription.'];
                }
                return [200, 'OK'];
            case 'Notification':
                $event = json_decode((string) ($message['Message'] ?? ''), true);
                if (is_array($event) && ($parsed = self::fromSesEvent($event))) {
                    self::record($parsed);
                }
                return [200, 'OK'];
        }

        return [200, 'OK'];
    }

    /**
     * An Amazon SES event (a notification or an event destination's) as a report, or null.
     */
    protected static function fromSesEvent(array $event)
    {
        $type = $event['notificationType'] ?? $event['eventType'] ?? '';
        $status = '';
        $diagnostic = '';
        $recipients = [];
        if ($type == 'Bounce') {
            $bounce = (array) ($event['bounce'] ?? []);
            if (($bounce['bounceType'] ?? '') == 'Transient') {
                $kind = DeliveryReport::DELAYED;
            } elseif (in_array($bounce['bounceSubType'] ?? '', ['Suppressed', 'OnAccountSuppressionList'])) {
                $kind = DeliveryReport::SUPPRESSED;
            } else {
                $kind = DeliveryReport::BOUNCE;
            }
            foreach ((array) ($bounce['bouncedRecipients'] ?? []) as $recipient) {
                $recipients[] = (string) ($recipient['emailAddress'] ?? '');
                $status = $status ?: (string) ($recipient['status'] ?? '');
                $diagnostic = $diagnostic ?: (string) ($recipient['diagnosticCode'] ?? '');
            }
        } elseif ($type == 'Complaint') {
            $kind = DeliveryReport::COMPLAINT;
            foreach ((array) ($event['complaint']['complainedRecipients'] ?? []) as $recipient) {
                $recipients[] = (string) ($recipient['emailAddress'] ?? '');
            }
        } else {
            return null;
        }

        $mail = (array) ($event['mail'] ?? []);
        $headers = (array) ($mail['commonHeaders'] ?? []);

        return [
            'report'      => self::report($kind, $recipients, $status, $diagnostic, MailProviders::NAMES[MailProviders::SES], $event, array_filter([
                'subject'    => (string) ($headers['subject'] ?? ''),
                'message_id' => trim((string) ($headers['messageId'] ?? ''), '<>'),
            ])),
            // SES's own ID, and the Message-ID Tallport gave it.
            'message_ids' => [(string) ($mail['messageId'] ?? ''), (string) ($headers['messageId'] ?? '')],
        ];
    }

    /**
     * Whether an Amazon SNS message is signed by Amazon SNS (signature versions 1 and 2).
     */
    public static function verifySns(array $message)
    {
        $cert_url = (string) ($message['SigningCertURL'] ?? '');
        $signature = base64_decode((string) ($message['Signature'] ?? ''), true);
        if (!self::isSnsUrl($cert_url) || !str_ends_with((string) parse_url($cert_url, PHP_URL_PATH), '.pem') || !$signature) {
            return false;
        }

        if ($message['Type'] == 'Notification') {
            $fields = ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'];
        } else {
            $fields = ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        }
        $string = '';
        foreach ($fields as $field) {
            if (isset($message[$field])) {
                $string .= $field."\n".$message[$field]."\n";
            }
        }

        $cache_key = 'sns_cert_'.md5($cert_url);
        $certificate = \Cache::get($cache_key);
        if (!$certificate) {
            try {
                $certificate = \Illuminate\Support\Facades\Http::withOptions(\Helper::setGuzzleDefaultOptions())->get($cert_url)->throw()->body();
            } catch (\Throwable $e) {
                return false;
            }
            \Cache::put($cache_key, $certificate, now()->addDay());
        }
        $key = openssl_pkey_get_public($certificate);
        if (!$key) {
            return false;
        }

        return openssl_verify($string, $signature, $key, ($message['SignatureVersion'] ?? '1') == '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * Whether a URL is Amazon SNS's own (https://sns.<region>.amazonaws.com/...).
     */
    protected static function isSnsUrl($url)
    {
        $parts = parse_url((string) $url);

        return ($parts['scheme'] ?? '') === 'https' && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/', $parts['host'] ?? '');
    }

    /**
     * A report in the shape of DeliveryReport::toArray().
     */
    protected static function report($kind, array $recipients, $status, $diagnostic, $reporter, array $payload, array $original)
    {
        $recipients = array_values(array_unique(array_filter(array_map(function ($recipient) {
            return \App\Email::sanitizeEmail(trim((string) $recipient));
        }, $recipients))));
        $status = preg_match('/^[245]\.\d{1,3}\.\d{1,3}$/', (string) $status) ? $status : '';

        return [
            'kind'       => $kind,
            'recipients' => $recipients,
            'status'     => $status,
            'diagnostic' => mb_substr((string) $diagnostic, 0, 1000),
            'reason'     => DeliveryReport::reason($kind, $status, $diagnostic),
            'reporter'   => $reporter,
            'details'    => mb_substr((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 4000),
            'original'   => $original,
        ];
    }

    /**
     * Record a report on the sent reply its IDs name; one about an email
     * Tallport didn't send is ignored.
     */
    protected static function record(array $parsed)
    {
        foreach ($parsed['message_ids'] as $message_id) {
            $reply = DeliveryReports::sentThread($message_id);
            if ($reply) {
                DeliveryReports::recordFromService($reply, $parsed['report']);
                return;
            }
        }
    }
}
