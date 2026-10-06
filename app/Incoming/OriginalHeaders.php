<?php

namespace App\Incoming;

/**
 * An email's header section as Show Original presents it: every header in
 * order, unfolded, and what a support agent looks at first (who, when, the
 * receiving server's authentication results, the hop it came in by).
 */
class OriginalHeaders
{
    /**
     * The summary's headers, in this order.
     */
    const SUMMARY = ['From', 'To', 'Cc', 'Reply-To', 'Subject', 'Date', 'Message-ID'];

    /**
     * Every header in order, unfolded (RFC 5322: a line break followed by a space or tab
     * continues the line), not decoded: [[name, value], ...]. Repeated ones are separate.
     */
    public static function all($raw)
    {
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', str_replace("\r\n", "\n", (string) $raw));
        $headers = [];
        foreach (explode("\n", $unfolded) as $line) {
            if (preg_match('/^([^\s:]+)[ \t]*:[ \t]*(.*)$/', $line, $m)) {
                $headers[] = [$m[1], trim($m[2])];
            }
        }

        return $headers;
    }

    /**
     * The summary's headers that are there, decoded: [name => text].
     */
    public static function summary($raw)
    {
        $summary = [];
        foreach (self::SUMMARY as $name) {
            $value = HeaderText::value($raw, $name);
            if ($value !== null && $value !== '') {
                $summary[$name] = $name == 'Message-ID' ? $value : HeaderText::decode($value);
            }
        }

        return $summary;
    }

    /**
     * What the receiving server found (the topmost Authentication-Results, the one it
     * added): ['spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'none'], null for one it
     * didn't report; null when there's no such header.
     */
    public static function authentication($raw)
    {
        $value = HeaderText::value($raw, 'Authentication-Results');
        if ($value === null) {
            return null;
        }
        $results = [];
        foreach (['spf', 'dkim', 'dmarc'] as $method) {
            $results[$method] = preg_match('/\b'.$method.'\s*=\s*([a-z]+)/i', $value, $m) ? strtolower($m[1]) : null;
        }

        return $results;
    }

    /**
     * A result's tone: pass → success, fail → danger, softfail and errors → warning, others neutral.
     */
    public static function tone($result)
    {
        if ($result == 'pass') {
            return 'success';
        }
        if ($result == 'fail') {
            return 'danger';
        }

        return in_array($result, ['softfail', 'temperror', 'permerror']) ? 'warning' : 'neutral';
    }

    /**
     * The hop it came in by (the topmost Received header): "from-host → by-host", or null.
     */
    public static function deliveredVia($raw)
    {
        $value = HeaderText::value($raw, 'Received');
        if ($value === null || !preg_match('/\bfrom\s+([^\s;()]+).*?\bby\s+([^\s;()]+)/is', $value, $m)) {
            return null;
        }

        return $m[1].' → '.$m[2];
    }
}
