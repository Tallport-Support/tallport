<?php

namespace App\Incoming;

use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * An IncomingMessage parsed from a raw email by webklex/php-imap 6
 * (unpatched), with the subject and addresses read by Tallport's own code
 * (HeaderText, Address::parseList()). Fetching and tallport:receive read
 * email with it (through Parser); tallport:compare-parsers compares it with
 * LegacyImapMessage.
 */
class Webklex6Message implements IncomingMessage
{
    /**
     * The library's message.
     *
     * @var Message
     */
    protected $message;

    protected $raw;

    protected $cache = [];

    public function __construct($raw, array $config = [])
    {
        $this->raw = preg_replace("/\r?\n/", "\r\n", $raw);
        // An invalid Date header: the time of receiving (as App\LegacyImap).
        $config = array_replace_recursive(['options' => ['fallback_date' => 'now']], $config);
        // Null bytes (sent by some mail programs) make charset conversion stop
        // there: https://github.com/freescout-help-desk/freescout/issues/5292
        $this->message = Message::fromString(self::joinBoundaryContinuations(str_replace("\0", '', $this->raw)), Config::make($config));
    }

    /**
     * A multipart boundary split into RFC 2231 continuations
     * (boundary*0*=us-ascii''...; boundary*1*=...) as one boundary="..."
     * parameter, the only form webklex reads.
     */
    public static function joinBoundaryContinuations($raw)
    {
        return preg_replace_callback('/^Content-Type:[^\r\n]*(?:\r\n[ \t][^\r\n]*)*/mi', function ($m) {
            $pattern = '/\s*\bboundary\*(\d+)(\*?)=("[^"]*"|[^;\s]*)\s*;?/i';
            if (!preg_match_all($pattern, $m[0], $params, PREG_SET_ORDER)) {
                return $m[0];
            }
            $sections = [];
            foreach ($params as $param) {
                $value = trim($param[3], '"');
                if ($param[2] === '*') {
                    // The first section starts with charset'language'.
                    if ((int) $param[1] === 0 && substr_count($value, "'") >= 2) {
                        $value = explode("'", $value, 3)[2];
                    }
                    $value = rawurldecode($value);
                }
                $sections[(int) $param[1]] = $value;
            }
            ksort($sections);

            return rtrim(preg_replace($pattern, '', $m[0]), " \t;").'; boundary="'.implode('', $sections).'"';
        }, $raw);
    }

    protected function cached($key, \Closure $value)
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $value();
        }

        return $this->cache[$key];
    }

    /**
     * Addresses of an address header.
     *
     * @return Address[]
     */
    protected function addresses($header)
    {
        return $this->cached($header, function () use ($header) {
            return Address::parseList(HeaderText::value($this->headers(), $header));
        });
    }

    protected function headerValue($header)
    {
        return $this->cached($header, function () use ($header) {
            $values = $this->message->get($header)->toArray();

            return implode(' ', array_map('strval', $values));
        });
    }

    public function messageId(): string
    {
        return $this->headerValue('message_id');
    }

    public function from(): array
    {
        return $this->addresses('From');
    }

    public function replyTo(): array
    {
        // As imap (and so App\LegacyImap): From if there's no Reply-To.
        return $this->addresses('Reply-To') ?: $this->from();
    }

    public function to(): array
    {
        return $this->addresses('To');
    }

    public function cc(): array
    {
        return $this->addresses('Cc');
    }

    public function bcc(): array
    {
        return $this->addresses('Bcc');
    }

    public function subject(): string
    {
        return $this->cached('subject', function () {
            return HeaderText::decode(HeaderText::value($this->headers(), 'Subject'));
        });
    }

    public function date(): ?\Carbon\Carbon
    {
        return $this->cached('date', function () {
            $date = $this->message->get('date');

            return $date->count() ? $date->toDate() : null;
        });
    }

    public function inReplyTo(): string
    {
        return $this->headerValue('in_reply_to');
    }

    public function references(): string
    {
        return $this->headerValue('references');
    }

    public function headers(): string
    {
        $end = strpos($this->raw, "\r\n\r\n");

        return str_replace("\0", '', $end === false ? $this->raw : substr($this->raw, 0, $end));
    }

    public function htmlBody(): string
    {
        return $this->message->getHTMLBody();
    }

    public function textBody(): ?string
    {
        return $this->message->getTextBody();
    }

    public function attachments(): array
    {
        return $this->cached('attachments', function () {
            $attachments = [];
            foreach ($this->message->getAttachments() as $attachment) {
                // Without a name or Content-ID the library uses its own hash;
                // Tallport names such attachments (Attachment::fallbackName()).
                $hash = $attachment->hash;
                $attachments[] = new Attachment(
                    $attachment->getName() !== $hash ? $attachment->getName() : null,
                    $attachment->type,
                    $attachment->content_type,
                    $attachment->content,
                    $attachment->id !== $hash ? $attachment->id : null,
                    $attachment
                );
            }

            return $attachments;
        });
    }

    public function rawBody(): string
    {
        return $this->message->getRawBody();
    }

    public function rawSource(): string
    {
        return $this->raw;
    }
}
