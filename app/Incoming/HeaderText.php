<?php

namespace App\Incoming;

/**
 * Header values from a raw email, as UTF-8 text.
 *
 * Decodes RFC 2047 encoded words ("=?UTF-8?B?...?="), including the ways
 * real senders break the rules: a multibyte character split across two
 * encoded words, charsets PHP doesn't know, 8-bit text that isn't UTF-8.
 */
class HeaderText
{
    /**
     * Charsets that senders name but mean a superset of.
     */
    const CHARSET_SUBSTITUTIONS = [
        'iso-2022-jp'    => 'ISO-2022-JP-MS',
        'gb2312'         => 'GB18030',
        'ks_c_5601-1987' => 'CP949',
        'utf8'           => 'UTF-8',
    ];

    /**
     * The unfolded value of the first header with this name, or null.
     *
     * @param  string  $headers  The raw header section.
     * @param  string  $name  E.g. "Subject".
     */
    public static function value($headers, $name)
    {
        if (!preg_match('/^'.preg_quote($name, '/').'[ \t]*:(.*(?:\r?\n[ \t].*)*)/mi', (string) $headers, $m)) {
            return null;
        }

        return trim(preg_replace('/\r?\n(?=[ \t])/', '', $m[1]));
    }

    /**
     * All headers, unfolded and not decoded: [name => value], names in lower
     * case with "_" for "-" (message_id); the first one of each name.
     *
     * @param  string  $headers  The raw header section.
     */
    public static function all($headers)
    {
        $all = [];
        preg_match_all('/^([^\s:]+)[ \t]*:(.*(?:\r?\n[ \t].*)*)/m', (string) $headers, $m, PREG_SET_ORDER);
        foreach ($m as $header) {
            $name = str_replace('-', '_', strtolower($header[1]));
            if (!array_key_exists($name, $all)) {
                $all[$name] = trim(preg_replace('/\r?\n(?=[ \t])/', '', $header[2]));
            }
        }

        return $all;
    }

    /**
     * Decode a header value to UTF-8 text.
     */
    public static function decode($value)
    {
        $value = preg_replace('/\r?\n(?=[ \t])/', '', (string) $value);

        // Encoded words, with the whitespace between two of them (which is
        // not part of the text, RFC 2047 6.2).
        // Lazy up to "?=": some senders put spaces and a "?" in Q-encoded text.
        $parts = preg_split('/(=\?[^?\s]+\?[BbQq]\?.*?\?=)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);

        $text = '';
        $bytes = '';
        $base64 = '';
        $charset = null;
        foreach ($parts as $i => $part) {
            if ($i % 2 == 0) {
                // Plain text: whitespace between encoded words is dropped.
                if ($part === '' || ($charset !== null && trim($part, " \t") === '' && isset($parts[$i + 1]))) {
                    continue;
                }
                $text .= self::convert($bytes.base64_decode($base64), $charset).self::convert($part, null);
                $bytes = $base64 = '';
                $charset = null;
                continue;
            }

            preg_match('/^=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?(.*)\?=$/s', $part, $m);
            $word_charset = strtolower($m[1]);
            // Adjacent words in one charset are joined as bytes first: senders
            // split a multibyte character across two words, and some split
            // the base64 text itself.
            if ($charset !== null && $word_charset !== $charset) {
                $text .= self::convert($bytes.base64_decode($base64), $charset);
                $bytes = $base64 = '';
            }
            $charset = $word_charset;
            if (strtoupper($m[2]) == 'B') {
                $base64 .= $m[3];
                if (strlen($base64) % 4 == 0) {
                    $bytes .= base64_decode($base64);
                    $base64 = '';
                }
            } else {
                $bytes .= base64_decode($base64).quoted_printable_decode(str_replace('_', ' ', $m[3]));
                $base64 = '';
            }
        }
        $text .= self::convert($bytes.base64_decode($base64), $charset);

        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }

        return $text;
    }

    /**
     * Bytes in a charset as UTF-8. Without a charset, or with one PHP doesn't
     * know: as is if valid UTF-8, otherwise as Windows-1252.
     */
    protected static function convert($bytes, $charset)
    {
        if ($bytes === '') {
            return '';
        }
        if ($charset !== null) {
            $charset = self::CHARSET_SUBSTITUTIONS[$charset] ?? $charset;
            try {
                return mb_scrub(mb_convert_encoding($bytes, 'UTF-8', $charset), 'UTF-8');
            } catch (\ValueError $e) {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
                if ($converted !== false) {
                    return $converted;
                }
            }
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }
}
