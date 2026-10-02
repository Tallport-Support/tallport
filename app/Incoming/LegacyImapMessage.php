<?php

namespace App\Incoming;

/**
 * An IncomingMessage read from App\LegacyImap (FreeScout's patched webklex
 * 4.1.1): IMAP and POP3 fetching, and messages parsed from a string.
 *
 * Values are read from the library when first needed, as before, so a
 * message skipped early (e.g. without a sender) isn't parsed further.
 */
class LegacyImapMessage implements IncomingMessage
{
    /**
     * The library's message.
     *
     * @var \App\LegacyImap\Message
     */
    protected $message;

    protected $cache = [];

    public function __construct(\App\LegacyImap\Message $message)
    {
        $this->message = $message;
    }

    /**
     * The library's message, for setting flags on the server and for
     * modules (fetch_emails.data_to_save).
     */
    public function legacyMessage()
    {
        return $this->message;
    }

    protected function cached($key, \Closure $value)
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $value();
        }

        return $this->cache[$key];
    }

    /**
     * Addresses from an address header attribute.
     *
     * @return Address[]
     */
    protected function addresses($key, $attribute)
    {
        return $this->cached($key, function () use ($attribute) {
            $list = is_object($attribute) && $attribute instanceof \App\LegacyImap\Attribute ? $attribute->get() : $attribute;
            $addresses = [];
            foreach ($list ?: [] as $address) {
                $addresses[] = new Address($address->mail ?? '', $address->personal ?? '');
            }

            return $addresses;
        });
    }

    public function messageId(): string
    {
        return $this->cached('message_id', function () {
            return (string) $this->message->getMessageId();
        });
    }

    public function from(): array
    {
        return $this->addresses('from', $this->message->getFrom());
    }

    public function replyTo(): array
    {
        return $this->addresses('reply_to', $this->message->getReplyTo());
    }

    public function to(): array
    {
        return $this->addresses('to', $this->message->getTo());
    }

    public function cc(): array
    {
        return $this->addresses('cc', $this->message->getCc());
    }

    public function bcc(): array
    {
        return $this->addresses('bcc', $this->message->getBcc());
    }

    public function subject(): string
    {
        return $this->cached('subject', function () {
            return $this->message->getSubject().'';
        });
    }

    public function date(): ?\Carbon\Carbon
    {
        return $this->cached('date', function () {
            $date = $this->message->getDate();
            if ($date instanceof \App\LegacyImap\Attribute) {
                $date = $date->toDate();
            }

            return $date ?: null;
        });
    }

    public function inReplyTo(): string
    {
        return $this->cached('in_reply_to', function () {
            return (string) ($this->message->getInReplyTo() ?? '');
        });
    }

    public function references(): string
    {
        return $this->cached('references', function () {
            return (string) ($this->message->getReferences() ?? '');
        });
    }

    public function headers(): string
    {
        return $this->cached('headers', function () {
            $header = $this->message->getHeader();

            return is_string($header) ? $header : (string) $header->raw;
        });
    }

    public function htmlBody(): string
    {
        return $this->cached('html_body', function () {
            return (string) $this->message->getHTMLBody(false);
        });
    }

    public function textBody(): ?string
    {
        return $this->cached('text_body', function () {
            return $this->message->getTextBody();
        });
    }

    public function attachments(): array
    {
        return $this->cached('attachments', function () {
            $attachments = [];
            foreach ($this->message->getAttachments() as $attachment) {
                // Without a name or Content-ID the library uses its own hash;
                // Tallport names such attachments (Attachment::fallbackName()).
                $hash = $attachment->getHash();
                $attachments[] = new Attachment(
                    $attachment->getName() !== $hash ? $attachment->getName() : null,
                    $attachment->getType(),
                    $attachment->content_type,
                    $attachment->getContent(),
                    $attachment->id !== $hash ? $attachment->id : null,
                    $attachment
                );
            }

            return $attachments;
        });
    }

    public function rawBody(): string
    {
        return $this->cached('raw_body', function () {
            return (string) $this->message->getRawBody();
        });
    }

    public function rawSource(): string
    {
        return rtrim($this->headers(), "\r\n")."\r\n\r\n".$this->rawBody();
    }
}
