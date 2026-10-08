<?php

namespace App\Incoming;

/**
 * What a delivery report says: a bounce or delay (RFC 3464 DSN, or a mail
 * server's plain-text notice), a complaint (RFC 5965 ARF feedback report) or a
 * sending service's suppression notice (Amazon SES). Which email failed, to
 * whom, why, and the original email. Only reads; FetchEmails saves it.
 */
class DeliveryReport
{
    const BOUNCE = 'bounce';
    const DELAYED = 'delayed';
    const COMPLAINT = 'complaint';
    const SUPPRESSED = 'suppressed';

    /**
     * Reasons in words (DeliveryReports::reasonText()), from the status code
     * (RFC 3463) or the server's words.
     */
    const REASONS = ['unknown_address', 'unknown_domain', 'mailbox_full', 'mailbox_disabled', 'too_large', 'blocked', 'unreachable', 'delayed', 'complaint', 'suppressed', 'rejected'];

    /**
     * Sending services and mail providers recognised by the report's sender or
     * reporting server: the name shown.
     */
    const SERVICES = [
        'amazonses'            => 'Amazon SES',
        'simple email service' => 'Amazon SES',
        'sendgrid'             => 'SendGrid',
        'mailgun'              => 'Mailgun',
        'postmarkapp'          => 'Postmark',
        'googlemail.com'       => 'Gmail',
        'outlook.com'          => 'Outlook',
        'yahoo'                => 'Yahoo',
    ];

    /**
     * Content types of an attached original: complete, or only its headers.
     */
    const ORIGINAL_TYPES = ['message/rfc822', 'message/global'];
    const ORIGINAL_HEADERS_TYPES = ['text/rfc822-headers', 'message/rfc822-headers', 'message/global-headers'];

    /**
     * Machine-readable report parts.
     */
    const DSN_TYPES = ['message/delivery-status', 'message/global-delivery-status'];
    const ARF_TYPE = 'message/feedback-report';

    /**
     * Lines before the copy of the original in a plain-text bounce
     * (qmail, Exim, and others).
     */
    const COPY_MARKER = '/^[ \t]*-{2,}[^\n]*(copy of the message|original message|header of the original|undelivered message)[^\n]*$/im';

    /**
     * bounce, delayed, complaint or suppressed.
     *
     * @var string
     */
    public $kind;

    /**
     * The addresses it is about, in lower case.
     *
     * @var string[]
     */
    public $recipients = [];

    /**
     * Enhanced status code (5.1.1), or ''.
     *
     * @var string
     */
    public $status = '';

    /**
     * What the receiving server said (Diagnostic-Code), or ''.
     *
     * @var string
     */
    public $diagnostic = '';

    /**
     * One of REASONS.
     *
     * @var string
     */
    public $reason = 'rejected';

    /**
     * Who reports it: a service (Amazon SES) or the reporting mail server.
     *
     * @var string
     */
    public $reporter = '';

    /**
     * The machine-readable part (delivery status or feedback report) as text, or ''.
     *
     * @var string
     */
    public $details = '';

    /**
     * The original email as included (complete, or only its headers), or null.
     *
     * @var string|null
     */
    public $original;

    /**
     * Whether $original is the complete email (not only its headers).
     *
     * @var bool
     */
    public $original_complete = false;

    /**
     * Read a delivery report, or null if the message isn't one (or reports
     * success). A plain-text notice without a report part is only read when
     * the message is known to be a bounce ($is_bounce: from a mail server).
     */
    public static function read(IncomingMessage $message, $is_bounce = false): ?self
    {
        $parts = self::parts($message->rawSource());
        $report = new self();
        $text = (string) $message->textBody();
        if (trim($text) === '') {
            $text = \Helper::htmlToText($message->htmlBody());
        }

        foreach ($parts as $part) {
            if ($report->original === null && in_array($part['type'], array_merge(self::ORIGINAL_TYPES, self::ORIGINAL_HEADERS_TYPES))) {
                $report->original = $part['body'];
                $report->original_complete = in_array($part['type'], self::ORIGINAL_TYPES);
            }
        }

        $dsn = self::findPart($parts, self::DSN_TYPES);
        $arf = self::findPart($parts, [self::ARF_TYPE]);
        if ($dsn !== null) {
            $fields = $report->readDeliveryStatus($dsn);
        } elseif ($arf !== null) {
            $fields = $report->readFeedbackReport($arf);
        } elseif ($is_bounce) {
            $fields = $report->readText($message, $text);
        } else {
            return null;
        }
        if (!$report->kind || !$report->recipients) {
            return null;
        }

        // Amazon SES (and others) tell in words that the address was suppressed.
        if (preg_match('/suppression list|suppressed sending|address is suppressed/i', $text.' '.$report->diagnostic)) {
            $report->kind = self::SUPPRESSED;
        }
        $report->reason = self::reason($report->kind, $report->status, $report->diagnostic);
        $report->reporter = self::reporter($message, $fields);

        return $report;
    }

    /**
     * A DSN: the recipients that failed (or were delayed), their status and the server's words.
     */
    protected function readDeliveryStatus($dsn)
    {
        $this->details = trim($dsn);
        $blocks = preg_split('/\r?\n[ \t]*\r?\n/', trim($dsn));
        $fields = HeaderText::all(array_shift($blocks));
        $failed = $delayed = [];
        foreach ($blocks as $block) {
            $recipient_fields = HeaderText::all($block);
            $recipient = self::address($recipient_fields['final_recipient'] ?? $recipient_fields['original_recipient'] ?? '');
            if (!$recipient) {
                continue;
            }
            $action = strtolower(trim($recipient_fields['action'] ?? ''));
            $status = self::statusCode($recipient_fields['status'] ?? '');
            if ($action === '') {
                $action = str_starts_with($status, '5') ? 'failed' : (str_starts_with($status, '4') ? 'delayed' : '');
            }
            $recipient_fields['address'] = $recipient;
            $recipient_fields['status'] = $status;
            if ($action == 'failed') {
                $failed[] = $recipient_fields;
            } elseif ($action == 'delayed') {
                $delayed[] = $recipient_fields;
            }
        }

        $problems = $failed ?: $delayed;
        if ($problems) {
            $this->kind = $failed ? self::BOUNCE : self::DELAYED;
            $this->recipients = array_values(array_unique(array_column($problems, 'address')));
            $this->status = $problems[0]['status'];
            $this->diagnostic = self::diagnostic($problems[0]['diagnostic_code'] ?? '');
        }

        return $fields;
    }

    /**
     * An ARF feedback report: a complaint (abuse, fraud, virus); other types
     * (auth-failure, not-spam) aren't about a recipient's delivery.
     */
    protected function readFeedbackReport($arf)
    {
        $this->details = trim($arf);
        $fields = HeaderText::all($arf);
        if (!in_array(strtolower(trim($fields['feedback_type'] ?? '')), ['abuse', 'fraud', 'virus', 'other'])) {
            return $fields;
        }
        $this->kind = self::COMPLAINT;
        // Original-Rcpt-To may be repeated; HeaderText::all() keeps the first of each.
        preg_match_all('/^(?:Original-Rcpt-To|Removal-Recipient)[ \t]*:(.*)$/mi', $arf, $m);
        foreach ($m[1] as $value) {
            if ($recipient = self::address($value)) {
                $this->recipients[] = $recipient;
            }
        }
        if (!$this->recipients && $this->original !== null) {
            foreach (Address::parseList(HeaderText::value($this->originalHeaderSection(), 'To')) as $address) {
                $this->recipients[] = strtolower($address->mail);
            }
        }
        $this->recipients = array_values(array_unique(array_filter($this->recipients)));

        return $fields;
    }

    /**
     * A mail server's plain-text notice (best effort): the addresses it names
     * before the copy of the original, its status code and the line with it.
     */
    protected function readText(IncomingMessage $message, $text)
    {
        $notice = $text;
        if (preg_match(self::COPY_MARKER, $text, $m, PREG_OFFSET_CAPTURE)) {
            $notice = substr($text, 0, $m[0][1]);
            if ($this->original === null) {
                $copy = ltrim(substr($text, $m[0][1] + strlen($m[0][0])), "\r\n");
                if (preg_match('/^[A-Za-z][A-Za-z0-9-]*:/', $copy)) {
                    $this->original = $copy;
                    $this->original_complete = (bool) preg_match('/\r?\n[ \t]*\r?\n/', $copy);
                }
            }
        }

        $ignore = [];
        foreach (array_merge($message->from(), $message->to(), Address::parseList(HeaderText::value($this->originalHeaderSection(), 'From'))) as $address) {
            $ignore[] = strtolower($address->mail);
        }
        $named = Address::parseList(HeaderText::value($message->headers(), 'X-Failed-Recipients'));
        if (!$named) {
            preg_match_all('/[A-Za-z0-9._%+\'=-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $notice, $m);
            $named = array_map(fn ($mail) => new Address($mail), $m[0]);
        }
        foreach ($named as $address) {
            $mail = strtolower($address->mail);
            if (!in_array($mail, $ignore) && !preg_match('/^(postmaster|mailer-daemon)@/', $mail)) {
                $this->recipients[] = $mail;
            }
        }
        $this->recipients = array_values(array_unique($this->recipients));

        if (preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $notice, $m)) {
            $this->status = $m[1];
        }
        if (preg_match('/^.*\b[45]\d\d[ -].*$/m', $notice, $m)) {
            $this->diagnostic = self::diagnostic($m[0]);
        }
        $permanent = preg_match('/permanent|given up|giving up|could not be delivered|wasn\'t able to deliver|failed/i', $notice);
        $temporary = preg_match('/delayed|will be retried|will retry|still trying|not yet been delivered|warning only/i', $notice);
        $this->kind = (str_starts_with($this->status, '4') || $temporary) && !$permanent ? self::DELAYED : self::BOUNCE;

        return [];
    }

    /**
     * The original's From, To, Subject, Date (ISO 8601) and Message-ID, those it has.
     */
    public function originalHeaders(): array
    {
        $headers = $this->originalHeaderSection();
        $summary = [];
        foreach (['From' => 'from', 'To' => 'to', 'Subject' => 'subject'] as $name => $key) {
            $value = HeaderText::value($headers, $name);
            if ($value !== null && $value !== '') {
                $summary[$key] = HeaderText::decode($value);
            }
        }
        $date = HeaderText::value($headers, 'Date');
        if ($date) {
            try {
                $summary['date'] = \Carbon\Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $date))->utc()->toIso8601String();
            } catch (\Throwable $e) {
                // An invalid date is left out.
            }
        }
        $message_id = trim((string) HeaderText::value($headers, 'Message-ID'), " <>\t");
        if ($message_id !== '') {
            $summary['message_id'] = $message_id;
        }

        return $summary;
    }

    /**
     * The complete original, read as other incoming email is, or null.
     */
    public function originalMessage(): ?IncomingMessage
    {
        if (!$this->original_complete || $this->original === null) {
            return null;
        }
        try {
            return Parser::parse($this->original);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The recipients as addresses, with their names from the original's To.
     *
     * @return Address[]
     */
    public function recipientAddresses(): array
    {
        $names = [];
        foreach (Address::parseList(HeaderText::value($this->originalHeaderSection(), 'To')) as $address) {
            $names[strtolower($address->mail)] = HeaderText::decode($address->personal);
        }

        return array_map(fn ($recipient) => new Address($recipient, $names[$recipient] ?? ''), $this->recipients);
    }

    /**
     * The files to keep with the report: the original email (as an .eml file)
     * and its attachments, rather than the report's machine-readable parts.
     *
     * @param  Attachment[]  $attachments  The report's own.
     * @return Attachment[]
     */
    public function attachments(array $attachments): array
    {
        $skip = array_merge(self::DSN_TYPES, [self::ARF_TYPE], self::ORIGINAL_TYPES, self::ORIGINAL_HEADERS_TYPES);
        $kept = array_values(array_filter($attachments, function ($attachment) use ($skip) {
            return !in_array(strtolower(trim(explode(';', (string) $attachment->content_type)[0])), $skip);
        }));

        $original = $this->originalMessage();
        if ($original) {
            $name = trim(preg_replace('/[\s\\\\\/:*?"<>|]+/u', ' ', $original->subject()));
            $name = mb_substr($name !== '' ? $name : 'original', 0, 100).'.eml';
            $kept[] = new Attachment($name, 'message', 'message/rfc822', $this->original);
            foreach ($original->attachments() as $attachment) {
                $kept[] = $attachment;
            }
        }

        return $kept;
    }

    /**
     * What is stored with the thread (send_status_data's delivery_report).
     */
    public function toArray(): array
    {
        return [
            'kind'       => $this->kind,
            'recipients' => $this->recipients,
            'status'     => $this->status,
            'diagnostic' => mb_substr($this->diagnostic, 0, 1000),
            'reason'     => $this->reason,
            'reporter'   => $this->reporter,
            'details'    => mb_substr($this->details, 0, 4000),
            'original'   => $this->originalHeaders(),
        ];
    }

    /**
     * The reason in REASONS for a kind, status code and the server's words.
     */
    public static function reason($kind, $status, $diagnostic)
    {
        if ($kind == self::COMPLAINT || $kind == self::SUPPRESSED) {
            return $kind;
        }
        $detail = preg_replace('/^[245]\./', '', (string) $status);
        $by_status = [
            '1.1' => 'unknown_address', '1.3' => 'unknown_address', '1.6' => 'unknown_address', '1.10' => 'unknown_address',
            '1.2' => 'unknown_domain', '4.4' => 'unknown_domain',
            '2.2' => 'mailbox_full',
            '2.1' => 'mailbox_disabled',
            '2.3' => 'too_large', '3.4' => 'too_large',
            '4.1' => 'unreachable', '4.2' => 'unreachable', '4.7' => 'unreachable',
        ];
        if (isset($by_status[$detail])) {
            return $by_status[$detail];
        }
        if (str_starts_with($detail, '7.')) {
            return 'blocked';
        }
        $by_words = [
            'mailbox_full'    => '/mailbox (is )?full|over quota|quota exceeded|insufficient storage/i',
            'unknown_address' => '/user unknown|unknown user|no such user|does ?n[o\']t exist|recipient not found|unknown recipient|recipient unknown|invalid recipient|mailbox not found|mailbox unavailable|address rejected/i',
            'unknown_domain'  => '/host not found|domain not found|no mx|name service error|unrouteable|unroutable/i',
            'blocked'         => '/spam|blocked|blacklist|blocklist|policy/i',
            'unreachable'     => '/timed out|connection refused|could not connect|unreachable/i',
        ];
        foreach ($by_words as $reason => $pattern) {
            if (preg_match($pattern, (string) $diagnostic)) {
                return $reason;
            }
        }

        return $kind == self::DELAYED ? 'delayed' : 'rejected';
    }

    /**
     * The service or mail server that reports it.
     */
    protected static function reporter(IncomingMessage $message, array $fields)
    {
        $from = strtolower($message->from()[0]->mail ?? '');
        $headers = $message->headers();
        $haystack = strtolower(implode(' ', [
            $from,
            $fields['user_agent'] ?? '',
            $fields['reporting_mta'] ?? '',
            HeaderText::value($headers, 'Feedback-ID') ?? '',
            HeaderText::value($headers, 'X-SES-Outgoing') !== null ? 'amazonses' : '',
        ]));
        foreach (self::SERVICES as $needle => $name) {
            if (str_contains($haystack, $needle)) {
                return $name;
            }
        }
        $mta = trim(preg_replace('/^[a-z0-9-]+\s*;\s*/i', '', $fields['reporting_mta'] ?? ''));

        return $mta !== '' ? $mta : (string) substr((string) strrchr($from, '@'), 1);
    }

    /**
     * "rfc822; Name@Example.org", "<...>" or "[...]": the address in lower case, or ''.
     */
    protected static function address($value)
    {
        $value = trim(preg_replace('/^[a-z0-9-]+\s*;/i', '', trim((string) $value)));
        $value = strtolower(trim($value, " \t<>[]\"'"));

        return str_contains($value, '@') && !preg_match('/\s/', $value) ? $value : '';
    }

    /**
     * "5.1.1 (comment)": 5.1.1.
     */
    protected static function statusCode($value)
    {
        return preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', (string) $value, $m) ? $m[1] : '';
    }

    /**
     * The server's words on one line, without the "smtp;" type.
     */
    protected static function diagnostic($value)
    {
        $value = preg_replace('/\s+/', ' ', trim((string) $value));
        $value = trim(preg_replace('/^[a-z0-9-]+\s*;\s*/i', '', $value));
        // A reply of several lines repeats its code on each ("550-5.1.1 ... 550 5.1.1 ...").
        if (preg_match('/^([45]\d\d)[- ]/', $value, $m)) {
            $value = $m[0].preg_replace('/\s'.$m[1].'[- ](?:[245]\.\d{1,3}\.\d{1,3}\s+)?/', ' ', substr($value, strlen($m[0])));
        }

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * The original's header section.
     */
    protected function originalHeaderSection()
    {
        return self::split((string) $this->original)[0];
    }

    protected static function findPart(array $parts, array $types)
    {
        foreach ($parts as $part) {
            if (in_array($part['type'], $types)) {
                return $part['body'];
            }
        }

        return null;
    }

    /**
     * A MIME entity's headers and body.
     */
    protected static function split($raw)
    {
        if (preg_match('/^\r?\n/', $raw)) {
            return ['', preg_replace('/^\r?\n/', '', $raw)];
        }
        $parts = preg_split('/\r?\n\r?\n/', $raw, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * The leaf parts of a MIME entity, decoded: [['type' => ..., 'body' => ...]].
     * An attached email is a leaf.
     */
    protected static function parts($raw, $depth = 0)
    {
        [$headers, $body] = self::split((string) $raw);
        $content_type = (string) HeaderText::value($headers, 'Content-Type');
        $type = strtolower(trim(explode(';', $content_type)[0])) ?: 'text/plain';

        if (str_starts_with($type, 'multipart/') && $depth < 10
            && preg_match('/boundary\s*=\s*(?:"([^"]+)"|([^;\s]+))/i', $content_type, $m)
        ) {
            $delimiter = '--'.($m[1] !== '' ? $m[1] : $m[2]);
            $chunks = [];
            $current = null;
            foreach (preg_split('/\r?\n/', $body) as $line) {
                $trimmed = rtrim($line);
                if ($trimmed === $delimiter || $trimmed === $delimiter.'--') {
                    if ($current !== null) {
                        $chunks[] = implode("\r\n", $current);
                    }
                    $current = $trimmed === $delimiter ? [] : null;
                    if ($current === null) {
                        break;
                    }
                } elseif ($current !== null) {
                    $current[] = $line;
                }
            }
            if ($current !== null) {
                $chunks[] = implode("\r\n", $current);
            }
            $parts = [];
            foreach ($chunks as $chunk) {
                $parts = array_merge($parts, self::parts($chunk, $depth + 1));
            }

            return $parts;
        }

        $encoding = strtolower(trim((string) HeaderText::value($headers, 'Content-Transfer-Encoding')));
        if ($encoding == 'base64') {
            $body = base64_decode(preg_replace('/\s+/', '', $body));
        } elseif ($encoding == 'quoted-printable') {
            $body = quoted_printable_decode($body);
        }

        return [['type' => $type, 'body' => $body]];
    }
}
