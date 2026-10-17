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

    /**
     * The line a mail app adds to every message goes; the customer's words stay.
     */
    public function testMailAppLinesGo()
    {
        $bodies = [
            // Outlook's, marked, in the app's language.
            '<div>Thanks, I was using a wrong URL.</div><div><br></div><div id="ms-outlook-mobile-signature" dir="auto">获取 <a href="https://aka.ms/o0ukef">Outlook for iOS</a></div>',
            '<div>Thanks, I was using a wrong URL.</div><div id="ms-outlook-android-signature">Get <a href="https://aka.ms/ghei36">Outlook for Android</a></div>',
            // Apple's, Yahoo's and Samsung's.
            '<div>Thanks, I was using a wrong URL.</div><div id="AppleMailSignature" dir="ltr">Sent from my iPhone</div>',
            '<div>Thanks, I was using a wrong URL.</div><div class="yahoo-signature"><a href="https://mail.onelink.me/107872968?pid=nativeplacement">Sent from Yahoo Mail on Android</a></div>',
            '<div>Thanks, I was using a wrong URL.</div><div id="composer_signature"><div>Sent from my Galaxy</div></div>',
            // Unmarked: the last line.
            "Thanks, I was using a wrong URL.<br />\n<br />\nVon meinem iPhone gesendet",
            '<p>Thanks, I was using a wrong URL.</p><p>获取<a href="https://aka.ms/o0ukef">Outlook for Android</a></p>',
            '<div>Thanks, I was using a wrong URL.<br>Envoyé de mon iPad</div>',
            '<div>Thanks, I was using a wrong URL.</div><div>iPhoneから送信</div>',
        ];
        foreach ($bodies as $body) {
            $html = MessageBody::withoutAppLines($body);
            $this->assertStringContainsString('Thanks, I was using a wrong URL.', $html, $body);
            $this->assertDoesNotMatchRegularExpression('/Outlook|iPhone|iPad|Yahoo|Galaxy/', $html, $body);
        }

        $kept = [
            // The customer's own words.
            '<p>Sent from my iPhone, sorry for the typos: the app crashes.</p>',
            '<p>Hi</p><p>My iPhone shows an error.</p>',
            '<p>Which is better, Outlook for iOS or Gmail?</p><p>Thanks</p>',
            // Only that line: left as it is.
            '<div id="AppleMailSignature">Sent from my iPhone</div>',
            // In a quoted message.
            '<div>Hi</div><blockquote><div>Old</div><div id="ms-outlook-mobile-signature">Get Outlook for iOS</div></blockquote>',
        ];
        foreach ($kept as $body) {
            $this->assertSame($body, MessageBody::withoutAppLines($body));
        }

        // A real signature in Apple's element stays (to be folded); only the app's line goes.
        $this->assertSame('<div>Hi</div><div id="AppleMailSignature">Jordan Lee<br>Example Ltd<br></div>',
            MessageBody::withoutAppLines('<div>Hi</div><div id="AppleMailSignature">Jordan Lee<br>Example Ltd<br>Sent from my iPhone</div>'));
    }
}
