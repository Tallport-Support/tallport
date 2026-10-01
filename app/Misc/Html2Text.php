<?php

namespace App\Misc;

/**
 * html2text, but very large HTML no longer becomes an empty text: when the
 * main regular expressions hit PCRE's backtrack limit, the HTML is converted
 * without them.
 */
class Html2Text extends \Html2Text\Html2Text
{
    protected function converter(&$text)
    {
        $this->convertBlockquotes($text);
        $this->convertPre($text);
        $text_copy = $text;
        $text = preg_replace($this->search, $this->replace, $text);
        if ($text === null) {
            $text = $text_copy;
        }
        $text = preg_replace_callback($this->callbackSearch, array($this, 'pregCallback'), $text) ?? '';
        $text = strip_tags($text);
        $text = preg_replace($this->entSearch, $this->entReplace, $text);
        $text = html_entity_decode($text, $this->htmlFuncFlags, self::ENCODING);

        // Remove unknown/unhandled entities (this cannot be done in search-and-replace block)
        $text = preg_replace('/&([a-zA-Z0-9]{2,6}|#[0-9]{2,4});/', '', $text);

        // Convert "|+|amp|+|" into "&", need to be done after handling of unknown entities
        // This properly handles situation of "&amp;quot;" in input string
        $text = str_replace('|+|amp|+|', '&', $text);

        // Normalise empty lines
        $text = preg_replace("/\n\s+\n/", "\n\n", $text);
        $text = preg_replace("/[\n]{3,}/", "\n\n", $text) ?? '';

        // remove leading empty lines (can be produced by eg. P tag on the beginning)
        $text = ltrim($text, "\n");

        if ($this->options['width'] > 0) {
            $text = wordwrap($text, $this->options['width']);
        }
    }
}
