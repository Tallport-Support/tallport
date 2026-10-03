<?php

namespace App\Misc;

/**
 * Addresses that don't read replies (no-reply@...): agents are warned when
 * writing to one, and auto replies aren't sent to them.
 *
 * A pattern without @ is part of the name before the @; with @ it is the
 * whole address. A dash also matches an underscore or nothing (no-reply:
 * noreply, no_reply); an asterisk matches anything.
 */
class Noreply
{
    const DEFAULT_PATTERNS = ['no-reply', 'do-not-reply', 'ne-pas-repondre', 'no-responder', 'auto-responder', 'auto-reply'];

    /**
     * Patterns added in Settings » Mail Settings (one per line).
     */
    const OPTION = 'noreply_emails';

    public static function customPatterns()
    {
        return self::normalize(\Option::get(self::OPTION, ''));
    }

    public static function patterns()
    {
        return array_values(array_unique(array_merge(self::DEFAULT_PATTERNS, self::customPatterns())));
    }

    /**
     * Lines of patterns: lower case, characters email addresses can have.
     */
    public static function normalize($text)
    {
        $patterns = [];
        foreach (preg_split('/[\r\n,]+/', (string) $text) as $line) {
            $line = strtolower(trim((string) filter_var(trim($line), FILTER_SANITIZE_EMAIL)));
            if ($line !== '' && $line !== '*' && !in_array($line, $patterns)) {
                $patterns[] = $line;
            }
        }

        return $patterns;
    }

    /**
     * Regular expressions (without delimiters, case-insensitive), also used
     * in the browser.
     */
    public static function regexes()
    {
        return array_map(function ($pattern) {
            $regex = strtr(preg_quote($pattern, '/'), ['\-' => '[-_]?', '\*' => '.*']);

            return str_contains($pattern, '@') ? '^'.$regex.'$' : '^[^@]*'.$regex.'[^@]*@';
        }, self::patterns());
    }

    public static function isNoreply($email)
    {
        $email = trim((string) $email);
        if ($email === '') {
            return false;
        }
        foreach (self::regexes() as $regex) {
            if (preg_match('/'.$regex.'/i', $email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * No auto replies to no-reply addresses.
     */
    public static function listen()
    {
        \Eventy::addFilter('autoreply.should_send', function ($should_send, $conversation) {
            return $should_send && !self::isNoreply($conversation->customer_email);
        }, 20, 2);
    }
}
