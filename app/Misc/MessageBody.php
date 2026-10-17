<?php

namespace App\Misc;

/**
 * A message's body as the agent sees it (conversations/partials/thread): without the empty
 * space emails pile up (tidy()), and with the sender's signature apart (signature()), shown
 * folded. The email as sent and as stored is untouched.
 */
class MessageBody
{
    /**
     * Signatures that mail programs mark: Gmail, Outlook, Apple Mail, Thunderbird, Yahoo.
     */
    const SIGNATURES = "//div[contains(concat(' ', normalize-space(@class), ' '), ' gmail_signature ')]"
        ."|//*[@id='Signature' or @id='x_Signature' or starts-with(@id, 'AppleMailSignature')]"
        ."|//*[contains(concat(' ', normalize-space(@class), ' '), ' moz-signature ')]"
        ."|//div[contains(concat(' ', normalize-space(@class), ' '), ' signature ')]";

    /**
     * Elements that show something even without text.
     */
    const CONTENT = ['img', 'table', 'hr', 'iframe', 'video', 'audio', 'object', 'input', 'svg'];

    const BLOCKS = ['div', 'p', 'blockquote', 'table', 'ul', 'ol', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'center', 'dl'];

    /**
     * The lines mail apps add to every message ("Get Outlook for iOS", "Sent from my
     * iPhone"): marked by the app, else a short last line saying so (in common languages).
     */
    const APP_LINES = "//*[starts-with(@id, 'ms-outlook-') and contains(@id, 'signature')]"
        ."|//*[starts-with(@id, 'ymail_') and contains(@id, 'signature')]"
        ."|//*[contains(concat(' ', normalize-space(@class), ' '), ' yahoo-signature ')]"
        ."|//*[starts-with(@id, 'AppleMailSignature') or @id='composer_signature']";

    const APP_LINE_TEXT = '/^(.{0,12}Outlook (for|pour|für|para|per|voor|dla|för|på|pro) (iOS|Android).{0,15}'
        .'|(Sent|Envoyé|Enviado|Verzonden|Inviato|Wysłane|Skickat|Sendt|Lähetetty|Odesláno)\\b.{0,25}\\b(iPhone|iPad|Android|Galaxy|Samsung|Huawei|Xiaomi|Yahoo Mail|Mail (for|pour|für) Windows|Outlook|Gmail)'
        .'|Von meinem (iPhone|iPad|Android|Samsung|Galaxy).{0,20}gesendet'
        .'|.{0,8}(iPhone|iPad)(から送信|에서 보냄)'
        .'|(从我的|發自我的|从|發自).{0,8}(iPhone|iPad|Android)(发送|傳送)?)\\.?$/iu';

    /**
     * The thread's body and its signature, each as shown (null without one).
     */
    public static function forThread(\App\Thread $thread, $conversation = null, $mailbox = null)
    {
        [$main, $signature] = self::signature(self::withoutAppLines((string) $thread->body));
        $render = function ($html) {
            return MessageImages::thumbnails(self::tidy(EmailColors::neutral(safe_raw_html($html))));
        };

        return [
            $render(\Eventy::filter('thread.body_output', $thread->getBodyWithFormatedLinks($main), $thread, $conversation, $mailbox)),
            $signature !== null ? $render($thread->getBodyWithFormatedLinks($signature)) : null,
        ];
    }

    /**
     * The body without the mail app's line at its end.
     */
    public static function withoutAppLines($html)
    {
        $html = (string) $html;
        if (!preg_match('/signature|Outlook|iPhone|iPad|Android|Galaxy|Samsung|Huawei|Xiaomi|Yahoo|Windows|Gmail/i', $html)) {
            return $html;
        }
        [$document, $root] = self::load($html);
        if (!$root) {
            return $html;
        }
        $removed = false;
        $xpath = new \DOMXPath($document);
        foreach (iterator_to_array($xpath->query(self::APP_LINES, $root)) as $element) {
            // The app's own line, at the end (Apple's and Samsung's elements may hold a real signature).
            if ($element->parentNode && self::isAppLine($element->textContent) && self::atEnd($element, $root) && !self::inQuote($element)) {
                $element->parentNode->removeChild($element);
                $removed = true;
            }
        }
        // Unmarked (plain text, or the app's markup lost): the last line.
        $line = self::lastLine($root);
        $text = implode('', array_map(fn ($node) => $node->textContent, $line));
        if ($line && self::isAppLine($text) && !self::inQuote($line[0])) {
            foreach ($line as $node) {
                $node->parentNode->removeChild($node);
            }
            $removed = true;
        }
        if (!$removed || self::isBlank($root)) {
            return $html;
        }

        return self::save($document, $root);
    }

    protected static function isAppLine($text)
    {
        $text = trim(preg_replace('/[\s\x{a0}]+/u', ' ', $text));

        return $text !== '' && mb_strlen($text) <= 60 && preg_match(self::APP_LINE_TEXT, $text);
    }

    /**
     * The nodes of the last line that shows: after the last line break, in the innermost
     * block it ends in.
     */
    protected static function lastLine(\DOMElement $root)
    {
        for ($parent = $root;;) {
            $line = [];
            $trailing = true;
            for ($node = $parent->lastChild; $node; $node = $node->previousSibling) {
                $is_break = $node instanceof \DOMElement && strtolower($node->nodeName) == 'br';
                if ($trailing && ($is_break || $node instanceof \DOMComment || self::isBlank($node))) {
                    continue;
                }
                // A block is a line of its own.
                $is_block = $node instanceof \DOMElement && in_array(strtolower($node->nodeName), self::BLOCKS);
                if ($is_break || ($is_block && $line)) {
                    break;
                }
                $trailing = false;
                array_unshift($line, $node);
                if ($is_block) {
                    break;
                }
            }
            if (count($line) == 1 && $line[0] instanceof \DOMElement && in_array(strtolower($line[0]->nodeName), ['div', 'p'])) {
                $parent = $line[0];
                continue;
            }

            return $line;
        }
    }

    /**
     * The body without its signature, and the signature: one its mail program marked, or
     * after a "-- " line, at the very end of the body. [$html, null] when unsure.
     */
    public static function signature($html)
    {
        $html = (string) $html;
        if (!preg_match('/signature|(^|>|\n)-- ?(<|\r?$)/im', $html)) {
            return [$html, null];
        }
        [$document, $root] = self::load($html);
        if (!$root) {
            return [$html, null];
        }

        $xpath = new \DOMXPath($document);
        $found = null;
        foreach ($xpath->query(self::SIGNATURES, $root) as $element) {
            // The outermost one (a signature inside another's).
            $found = $found ?: $element;
        }
        $nodes = $found ? [$found] : self::delimited($root);
        if (!$nodes || !self::atEnd(end($nodes), $root) || self::inQuote(reset($nodes))) {
            return [$html, null];
        }

        $signature = '';
        if (!$found) {
            // The "-- " line itself isn't part of it.
            $delimiter = array_shift($nodes);
            $delimiter->parentNode->removeChild($delimiter);
            if ($nodes && strtolower($nodes[0]->nodeName) == 'br') {
                $nodes[0]->parentNode->removeChild(array_shift($nodes));
            }
        }
        foreach ($nodes as $node) {
            $signature .= $document->saveHTML($node);
            $node->parentNode->removeChild($node);
        }
        $main = self::save($document, $root);
        if (self::isBlank($root) || trim(strip_tags($signature, '<img>')) === '') {
            return [$html, null];
        }

        // What was before it ends without blank lines.
        return [self::tidy($main), $signature];
    }

    /**
     * Empty space collapsed: no blank lines at the start or end, and one blank line where
     * there were more. Single blank lines (paragraph breaks) stay.
     */
    public static function tidy($html)
    {
        $html = (string) $html;
        if (!preg_match('/<br|<p|<div/i', $html)) {
            return $html;
        }
        [$document, $root] = self::load($html);
        if (!$root) {
            return $html;
        }

        self::collapse($root);
        foreach ([true, false] as $from_start) {
            // Blank blocks and line breaks at the edge, also at the edge of the blocks there.
            for ($parent = $root; $parent instanceof \DOMElement;) {
                while (($edge = self::edge($parent, $from_start)) && self::isBlank($edge)) {
                    $parent->removeChild($edge);
                }
                $parent = $edge && in_array(strtolower($edge->nodeName), ['div', 'p', 'blockquote', 'span', 'font', 'center']) ? $edge : null;
            }
        }

        return self::save($document, $root);
    }

    /**
     * Runs of blank blocks and line breaks: one blank line.
     */
    protected static function collapse(\DOMElement $parent)
    {
        $previous_blank = false;
        $breaks = 0;
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof \DOMText && trim($node->textContent, " \t\r\n\u{a0}") === '') {
                continue;
            }
            if ($node instanceof \DOMElement && strtolower($node->nodeName) == 'br') {
                // Two in a row make a blank line; more add nothing.
                if (++$breaks > 2 || $previous_blank) {
                    $parent->removeChild($node);
                }
                continue;
            }
            $breaks = 0;
            $blank = $node instanceof \DOMElement && self::isBlank($node);
            if ($blank && $previous_blank) {
                $parent->removeChild($node);
                continue;
            }
            $previous_blank = $blank;
            if ($node instanceof \DOMElement && !$blank) {
                self::collapse($node);
            }
        }
    }

    /**
     * The first (or last) child that isn't whitespace.
     */
    protected static function edge(\DOMElement $parent, $first)
    {
        for ($node = $first ? $parent->firstChild : $parent->lastChild; $node; $node = $first ? $node->nextSibling : $node->previousSibling) {
            if ($node instanceof \DOMText && trim($node->textContent, " \t\r\n\u{a0}") === '') {
                continue;
            }
            if ($node instanceof \DOMComment) {
                continue;
            }

            return $node;
        }

        return null;
    }

    /**
     * Shows nothing: no text (spaces, &nbsp; and zero-width ones aside) and no picture or table.
     */
    protected static function isBlank(\DOMNode $node)
    {
        if ($node instanceof \DOMText) {
            return preg_replace('/[\s\x{a0}\x{200b}\x{200c}\x{200d}\x{feff}]+/u', '', $node->textContent) === '';
        }
        if (!$node instanceof \DOMElement) {
            return true;
        }
        if (in_array(strtolower($node->nodeName), self::CONTENT)) {
            return false;
        }
        foreach (self::CONTENT as $tag) {
            if ($node->getElementsByTagName($tag)->length) {
                return false;
            }
        }

        return preg_replace('/[\s\x{a0}\x{200b}\x{200c}\x{200d}\x{feff}]+/u', '', $node->textContent) === '';
    }

    /**
     * A plain-text signature: a "-- " line and everything after it in its block.
     */
    protected static function delimited(\DOMElement $root)
    {
        $xpath = new \DOMXPath($root->ownerDocument);
        $delimiter = null;
        foreach ($xpath->query('.//text()', $root) as $text) {
            if (preg_match('/^\r?\n?-- ?\r?$/u', str_replace("\u{a0}", ' ', $text->textContent))
                && (!$text->previousSibling || strtolower($text->previousSibling->nodeName) == 'br')
                && (!$text->nextSibling || strtolower($text->nextSibling->nodeName) == 'br')
            ) {
                $delimiter = $text;
            }
        }
        if (!$delimiter) {
            return [];
        }
        $nodes = [];
        for ($node = $delimiter; $node; $node = $node->nextSibling) {
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * Nothing shows after it.
     */
    protected static function atEnd(\DOMNode $node, \DOMElement $root)
    {
        for (; $node && !$node->isSameNode($root); $node = $node->parentNode) {
            for ($next = $node->nextSibling; $next; $next = $next->nextSibling) {
                if (!self::isBlank($next)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * In a quoted earlier message: that message's signature.
     */
    protected static function inQuote(\DOMNode $node)
    {
        for (; $node; $node = $node->parentNode) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) == 'blockquote') {
                return true;
            }
        }

        return false;
    }

    protected static function load($html)
    {
        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="tallport-message-body">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);

        return [$document, $loaded ? $document->getElementById('tallport-message-body') : null];
    }

    protected static function save(\DOMDocument $document, \DOMElement $root)
    {
        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }

        return $result;
    }
}
