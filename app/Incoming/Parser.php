<?php

namespace App\Incoming;

/**
 * Reads an incoming email: with webklex/php-imap 6 and Tallport's own code
 * (Webklex6Message).
 */
class Parser
{
    /**
     * Read an email.
     *
     * @param  string  $raw  The email source.
     *
     * @throws \Throwable An email that can't be read.
     */
    public static function parse($raw): IncomingMessage
    {
        $message = new Webklex6Message($raw);
        // Read everything now, so an email that can't be read fails here.
        $message->subject();
        $message->from();
        $message->htmlBody();
        $message->textBody();
        $message->attachments();

        return $message;
    }
}
