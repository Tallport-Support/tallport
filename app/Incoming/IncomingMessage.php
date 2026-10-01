<?php

namespace App\Incoming;

/**
 * An email Tallport receives, independent of how it arrived (IMAP or POP3
 * today). FetchEmails reads incoming mail only through this.
 */
interface IncomingMessage
{
    /**
     * Message-ID header, as found (may be empty).
     */
    public function messageId(): string;

    /**
     * Sender(s) from the From header.
     *
     * @return Address[]
     */
    public function from(): array;

    /**
     * Addresses from the Reply-To header.
     *
     * @return Address[]
     */
    public function replyTo(): array;

    /**
     * Recipients from the To header.
     *
     * @return Address[]
     */
    public function to(): array;

    /**
     * Recipients from the Cc header.
     *
     * @return Address[]
     */
    public function cc(): array;

    /**
     * Recipients from the Bcc header.
     *
     * @return Address[]
     */
    public function bcc(): array;

    public function subject(): string;

    /**
     * The Date header, or null if there is none.
     */
    public function date(): ?\Carbon\Carbon;

    /**
     * In-Reply-To header, as found.
     */
    public function inReplyTo(): string;

    /**
     * References header, as found.
     */
    public function references(): string;

    /**
     * All headers, as in the message.
     */
    public function headers(): string;

    public function htmlBody(): string;

    public function textBody(): ?string;

    /**
     * The attachments, inline images included.
     *
     * @return Attachment[]
     */
    public function attachments(): array;

    /**
     * The message body as in the message source (without headers).
     */
    public function rawBody(): string;

    /**
     * The whole message as received (headers and body, CRLF line endings).
     */
    public function rawSource(): string;
}
