<?php

namespace App\Incoming;

use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * An IncomingMessage parsed from a raw email by webklex/php-imap 6
 * (unpatched). Not used for fetching yet: tallport:compare-parsers and the
 * tests compare it with LegacyImapMessage, to find which of FreeScout's
 * changes to the library still make a difference.
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
        $this->message = Message::fromString($this->raw, Config::make($config));
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
            $addresses = [];
            foreach ($this->message->get($header)->toArray() as $address) {
                if (is_object($address)) {
                    $addresses[] = new Address($address->mail ?? '', $address->personal ?? '');
                }
            }

            return $addresses;
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
        return $this->addresses('from');
    }

    public function replyTo(): array
    {
        return $this->addresses('reply_to');
    }

    public function to(): array
    {
        return $this->addresses('to');
    }

    public function cc(): array
    {
        return $this->addresses('cc');
    }

    public function bcc(): array
    {
        return $this->addresses('bcc');
    }

    public function subject(): string
    {
        return $this->headerValue('subject');
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
        return (string) ($this->message->getHeader()->raw ?? '');
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
                $attachments[] = new Attachment(
                    $attachment->getName(),
                    $attachment->type,
                    $attachment->content_type,
                    $attachment->content,
                    $attachment->id,
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
