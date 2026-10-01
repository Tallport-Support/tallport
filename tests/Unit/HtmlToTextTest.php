<?php

namespace Tests\Unit;

use Tests\TestCase;

class HtmlToTextTest extends TestCase
{
    public function testConvertsHtml()
    {
        $this->assertSame("Hello\n\nWorld & more\n", \Helper::htmlToText('<p>Hello</p><p>World &amp; more</p>'));
    }

    /**
     * Very large HTML can exceed PCRE's backtrack limit; the text must not be
     * lost then.
     */
    public function testTextSurvivesRegexLimit()
    {
        $limit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        // An unclosed <script> makes the main expressions backtrack through the
        // rest of the message.
        $html = '<p>Hello <b>World</b></p><script>'.str_repeat('a ', 20000);
        ini_set('pcre.backtrack_limit', '10000');
        ini_set('pcre.jit', '0');
        try {
            $text = \Helper::htmlToText($html);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
            ini_set('pcre.jit', $jit);
        }

        $this->assertStringStartsWith('Hello WORLD', $text);
    }
}
