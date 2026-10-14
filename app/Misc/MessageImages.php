<?php

namespace App\Misc;

/**
 * Pictures in a message as the agent sees it (conversations/partials/thread): thumbnails
 * (FruitUI caps them, --f-message-image-height) that open the full picture. Each picture
 * not in a link gets one (f-message__image), named by its alt, else its file's name; an
 * email's inline width and height go, or the picture would be letterboxed. Small ones
 * (icons, tracking pixels) stay as they are. The email as sent is untouched.
 */
class MessageImages
{
    /**
     * Pictures this small (width or height attribute) are left alone.
     */
    const SMALL = 48;

    public static function thumbnails($html)
    {
        $html = (string) $html;
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $document = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div id="tallport-message-images">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $root = $loaded ? $document->getElementById('tallport-message-images') : null;
        if (!$root) {
            return $html;
        }

        foreach (iterator_to_array($root->getElementsByTagName('img')) as $img) {
            if ($img->hasAttribute('style')) {
                $style = trim(preg_replace('/(^|;)\s*(width|height)\s*:[^;]*/i', '$1', $img->getAttribute('style')), " ;");
                $style === '' ? $img->removeAttribute('style') : $img->setAttribute('style', $style);
            }
            $src = $img->getAttribute('src');
            if (!preg_match('#^(https?:)?/#i', $src) || self::isSmall($img) || self::inLink($img)) {
                continue;
            }
            $link = $document->createElement('a');
            $link->setAttribute('class', 'f-message__image');
            $link->setAttribute('href', $src);
            $link->setAttribute('target', '_blank');
            $link->setAttribute('rel', 'noopener');
            if (trim($img->getAttribute('alt')) === '') {
                $name = rawurldecode(basename((string) parse_url($src, PHP_URL_PATH)));
                $link->setAttribute('aria-label', $name !== '' ? $name : __('Image'));
            }
            $img->parentNode->replaceChild($link, $img);
            $link->appendChild($img);
        }

        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $document->saveHTML($node);
        }

        return $result;
    }

    protected static function isSmall(\DOMElement $img)
    {
        foreach (['width', 'height'] as $dimension) {
            $value = $img->getAttribute($dimension);
            if (is_numeric($value) && (int) $value <= self::SMALL) {
                return true;
            }
        }

        return false;
    }

    protected static function inLink(\DOMElement $img)
    {
        for ($node = $img->parentNode; $node; $node = $node->parentNode) {
            if ($node instanceof \DOMElement && strtolower($node->nodeName) == 'a') {
                return true;
            }
        }

        return false;
    }
}
