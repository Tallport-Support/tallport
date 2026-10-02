<?php

namespace App\Incoming;

/**
 * Reads an incoming email: with webklex/php-imap 6 and Tallport's own code
 * (Webklex6Message). If that fails, with App\LegacyImap as before, so an
 * email is never lost while the old parser is being retired.
 */
class Parser
{
    /**
     * Read an email.
     *
     * @param  string  $raw  The email source.
     * @param  LegacyImapMessage|null  $legacy  The fetched message, if any (the fallback).
     */
    public static function parse($raw, ?LegacyImapMessage $legacy = null): IncomingMessage
    {
        try {
            $message = new Webklex6Message($raw);
            // Parse everything now, so a failure falls back here.
            ParserComparison::values($message);

            return $message;
        } catch (\Throwable $e) {
            \Helper::logException($e, '[Incoming] Could not read an email with webklex 6, used the legacy parser: ');

            return $legacy ?: ParserComparison::legacy($raw);
        }
    }
}
