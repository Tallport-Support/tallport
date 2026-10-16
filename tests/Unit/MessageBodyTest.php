<?php

namespace Tests\Unit;

use App\Misc\MessageBody;
use Tests\TestCase;

/**
 * A message's body as the agent sees it: empty space collapsed, the signature apart.
 */
class MessageBodyTest extends TestCase
{
    public function testEmptySpaceAtTheEdgesGoes()
    {
        $this->assertSame('<p>Hello</p><p>Thanks</p>', MessageBody::tidy('<p>&nbsp;</p><div><br></div><p>Hello</p><p>Thanks</p><p> </p><br><br>'));
        $this->assertSame('<div><p>Hello</p></div>', MessageBody::tidy('<div><p>Hello</p><p><br></p><br></div><p>&nbsp;</p>'));
        $this->assertSame('Hello', MessageBody::tidy('Hello<br><br><br>'));
    }

    public function testRunsOfEmptySpaceBecomeOneBlankLine()
    {
        $this->assertSame("<p>One</p><p>\u{a0}</p><p>Two</p>", MessageBody::tidy('<p>One</p><p>&nbsp;</p><p><br></p><div>&nbsp;</div><p>Two</p>'));
        $this->assertSame('One<br><br>Two', MessageBody::tidy('One<br><br><br><br>Two'));
        // A paragraph break stays.
        $this->assertSame('One<br><br>Two', MessageBody::tidy('One<br><br>Two'));
        // A picture or a table isn't empty space.
        $this->assertSame('<p>One</p><p><img src="/a.png"></p><table><tr><td></td></tr></table>', MessageBody::tidy('<p>One</p><p><img src="/a.png"></p><table><tr><td></td></tr></table>'));
        $this->assertSame('<p>Ünïcode</p>', MessageBody::tidy('<p>Ünïcode</p><p></p>'));
    }

    public function testMarkedSignaturesAtTheEnd()
    {
        foreach ([
            '<div class="gmail_signature" data-smartmail="gmail_signature">Jordan Lee<br>Example Ltd</div>',
            '<div id="Signature"><p>Jordan Lee</p></div>',
            '<div id="x_Signature"><p>Jordan Lee</p></div>',
            '<div id="AppleMailSignature">Jordan Lee</div>',
            '<pre class="moz-signature" cols="72">Jordan Lee</pre>',
            '<div class="signature">Jordan Lee</div>',
        ] as $signature) {
            [$main, $found] = MessageBody::signature('<div>Yes, that works.</div>'.$signature.'<p>&nbsp;</p>');
            $this->assertSame('<div>Yes, that works.</div>', $main, $signature);
            $this->assertSame($signature, $found);
        }
    }

    public function testPlainTextSignature()
    {
        [$main, $signature] = MessageBody::signature("Yes, that works.<br />\n-- <br />\nJordan Lee<br />\nExample Ltd");
        $this->assertSame('Yes, that works.', $main);
        $this->assertSame("\nJordan Lee<br>\nExample Ltd", $signature);
    }

    public function testUnsureLeavesItAsItIs()
    {
        $bodies = [
            // Text after it.
            '<div>Hi</div><div class="gmail_signature">Jordan</div><div>P.S. one more thing</div>',
            // An earlier message's, in the quote.
            '<div>Hi</div><blockquote><div>Old</div><div class="gmail_signature">Casey</div></blockquote>',
            // Nothing but the signature.
            '<div class="gmail_signature">Jordan</div>',
            // No marker (a name in a table, as some webmail sends it).
            '<div>Yes</div><table><tr><td><img src="/a.png"></td><td>Jordan Lee</td></tr></table>',
            // A dash line that isn't on its own.
            'Prices -- as discussed<br>Jordan',
        ];
        foreach ($bodies as $body) {
            $this->assertSame([$body, null], MessageBody::signature($body));
        }
    }
}
