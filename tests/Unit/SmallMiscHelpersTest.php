<?php

namespace Tests\Unit;

use App\Misc\Branding;
use App\Misc\SenderTime;
use App\Thread;
use Tests\TestCase;

/**
 * Edge cases of small helpers: a customer's unreadable Date header
 * (SenderTime), custom CSS with unclosed blocks (Branding), and plain text
 * wrapped at a width (Helper::htmlToText).
 */
class SmallMiscHelpersTest extends TestCase
{
    public function testUnreadableDateHeaderGivesNoSendingTime()
    {
        $thread = new Thread();
        $thread->type = Thread::TYPE_CUSTOMER;
        $thread->headers = "From: casey@customer.example.org\r\nDate: sometime last week\r\n";

        $this->assertNull(SenderTime::sentAt($thread));

        $thread->headers = "From: casey@customer.example.org\r\n";
        $this->assertNull(SenderTime::sentAt($thread), 'No Date header.');
    }

    /**
     * Blocks left open in custom CSS are closed, so the CSS after it in the page still applies.
     */
    public function testCustomCssBlocksAreClosed()
    {
        $this->assertSame('.a { color: red; .b { margin: 0}}', Branding::sanitizeCss('.a { color: red; .b { margin: 0'));
        $this->assertSame('.a { color: red; }', Branding::sanitizeCss('}.a { color: red; }'));
    }

    public function testHtmlToTextWrapsAtTheWidth()
    {
        $this->assertSame(
            "The quick brown fox\njumps over the lazy\ndog.\n",
            \Helper::htmlToText('<p>The quick brown fox jumps over the lazy dog.</p>', false, ['width' => 20])
        );
        $this->assertSame("The quick brown fox jumps over the lazy dog.\n", \Helper::htmlToText('<p>The quick brown fox jumps over the lazy dog.</p>'));
    }
}
