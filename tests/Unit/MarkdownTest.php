<?php

namespace Tests\Unit;

use App\Misc\Markdown;
use Tests\TestCase;

/**
 * Markdown from the editor, rendered where it is shown or sent.
 */
class MarkdownTest extends TestCase
{
    public function testLineBreaksVariablesAndEverythingAllowed()
    {
        $this->assertSame("<p>Hello {%customer.firstName%},<br />\nthanks for <strong>writing</strong>.</p>\n<h2>Steps</h2>\n<ul>\n<li>Open <a href=\"https://example.com/a_b\">the app</a></li>\n</ul>",
            Markdown::toHtml("Hello {%customer.firstName%},\nthanks for **writing**.\n\n## Steps\n\n- Open [the app](https://example.com/a_b)"));
        $this->assertSame('', Markdown::toHtml(" \n"));
    }

    public function testRawHtmlAndUnsafeLinksAreLeftOut()
    {
        $html = Markdown::toHtml("<script>alert(1)</script>\n\nA <b onclick=\"x\">tag</b> and [link](javascript:alert(1)) <img src=x onerror=alert(1)>");
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function testOnlyTheFormatsGivenAreRendered()
    {
        $markdown = "# Title\n\n**Bold** and *italic*, `code` and [a link](https://example.com) ![logo](https://example.com/a.png)\n\n- one\n- two\n\n3. three\n\n> quoted\n\n---";

        $this->assertSame("<p>Title</p>\n<p><strong>Bold</strong> and italic, code and <a href=\"https://example.com\">a link</a> logo</p>\n<p>- one</p>\n<p>- two</p>\n<p>3. three</p>\n<p>quoted</p>",
            Markdown::toHtml($markdown, ['bold', 'link']));
        $this->assertSame("<p>Title</p>\n<p>Bold and italic, code and a link logo</p>\n<p>- one</p>\n<p>- two</p>\n<p>3. three</p>\n<p>quoted</p>",
            Markdown::toHtml($markdown, []));
        $this->assertSame("<ul>\n<li>one</li>\n</ul>\n<blockquote>\n<p>quoted</p>\n</blockquote>",
            Markdown::toHtml("- one\n\n> quoted", ['bulletList', 'blockquote']));
        $this->assertSame("<p>line one<br />\nline two</p>", Markdown::toHtml("```\nline one\nline two\n```", []));
    }

}
