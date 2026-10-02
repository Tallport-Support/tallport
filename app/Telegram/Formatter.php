<?php

namespace App\Telegram;

/**
 * A reply's HTML as Telegram's HTML subset: bold, italic, underline,
 * strikethrough, links, code, quotes; lists become bullets, headings bold,
 * other markup plain text. Images are sent as files instead.
 */
class Formatter
{
    /**
     * Telegram's limit for a message's text.
     */
    const MAX_LENGTH = 4096;

    const INLINE_TAGS = [
        'b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 'ins' => 'u',
        's' => 's', 'strike' => 's', 'del' => 's', 'code' => 'code',
    ];

    const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'table', 'tr', 'hr'];

    public static function toTelegramHtml($html)
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        // Spaces around line breaks and blank lines from nested blocks are
        // left out, except in code blocks.
        $parts = preg_split('#(<pre>.*?</pre>)#s', self::children($body, []), -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($i % 2 == 0) {
                $parts[$i] = preg_replace(["/[ \t]*\n[ \t]*/", "/\n{3,}/"], ["\n", "\n\n"], $part);
            }
        }

        return trim(implode('', $parts));
    }

    /**
     * Texts of at most MAX_LENGTH characters: the formatted text, or if
     * that is too long, the plain text in parts.
     * Returns [[text, is_html], ...].
     */
    public static function messages($html)
    {
        $formatted = self::toTelegramHtml($html);
        if ($formatted === '') {
            return [];
        }
        if (mb_strlen($formatted) <= self::MAX_LENGTH) {
            return [[$formatted, true]];
        }

        return array_map(function ($part) {
            return [$part, false];
        }, self::split(self::plain($formatted)));
    }

    /**
     * Telegram HTML as plain text.
     */
    public static function plain($telegram_html)
    {
        return html_entity_decode(strip_tags($telegram_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Plain text in parts of at most MAX_LENGTH characters, at line ends where
     * possible.
     */
    public static function split($text)
    {
        $parts = [];
        while (mb_strlen($text) > self::MAX_LENGTH) {
            $part = mb_substr($text, 0, self::MAX_LENGTH);
            $cut = mb_strrpos($part, "\n");
            if ($cut === false || $cut < self::MAX_LENGTH / 2) {
                $cut = self::MAX_LENGTH;
            }
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        if (trim($text) !== '') {
            $parts[] = $text;
        }

        return $parts;
    }

    protected static function children(\DOMNode $node, array $context)
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::node($child, $context);
        }

        return $text;
    }

    protected static function node(\DOMNode $node, array $context)
    {
        if ($node instanceof \DOMText) {
            $value = $node->nodeValue;
            if (!in_array('pre', $context)) {
                $value = preg_replace('/\s+/u', ' ', $value);
            }

            return htmlspecialchars($value, ENT_NOQUOTES, 'UTF-8');
        }
        if (!$node instanceof \DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        switch ($tag) {
            case 'br':
                return "\n";
            case 'img':
            case 'script':
            case 'style':
            case 'head':
                return '';
            case 'a':
                $inner = self::children($node, $context);
                $href = trim($node->getAttribute('href'));
                if (!preg_match('#^(https?:|mailto:|tg:)#i', $href) || trim(strip_tags($inner)) === '') {
                    return $inner;
                }

                return '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'">'.$inner.'</a>';
            case 'li':
                $parent = $node->parentNode ? strtolower($node->parentNode->nodeName) : '';
                $marker = '• ';
                if ($parent == 'ol') {
                    $number = 1;
                    for ($sibling = $node->previousSibling; $sibling; $sibling = $sibling->previousSibling) {
                        if ($sibling instanceof \DOMElement && strtolower($sibling->tagName) == 'li') {
                            $number++;
                        }
                    }
                    $marker = $number.'. ';
                }

                return "\n".$marker.trim(self::children($node, $context));
            case 'blockquote':
                return "\n<blockquote>".trim(self::children($node, $context))."</blockquote>\n";
            case 'pre':
                return "\n<pre>".trim(self::children($node, array_merge($context, ['pre'])), "\r\n")."</pre>\n";
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                return "\n<b>".trim(self::children($node, $context))."</b>\n";
            case 'td':
            case 'th':
                return self::children($node, $context).' ';
        }

        $inner = self::children($node, $context);
        if (isset(self::INLINE_TAGS[$tag])) {
            $telegram_tag = self::INLINE_TAGS[$tag];
            // Inside pre, code is plain; empty formatting is left out.
            if (trim($inner) === '' || ($telegram_tag == 'code' && in_array('pre', $context))) {
                return $inner;
            }

            return '<'.$telegram_tag.'>'.$inner.'</'.$telegram_tag.'>';
        }
        if (in_array($tag, self::BLOCK_TAGS)) {
            return "\n".$inner."\n";
        }

        return $inner;
    }
}
