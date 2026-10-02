<?php

namespace Tests\Unit;

use App\Telegram\Formatter;
use Tests\TestCase;

/**
 * Replies as Telegram's HTML subset, and long ones in parts.
 */
class TelegramFormatterTest extends TestCase
{
    /**
     * @dataProvider replies
     */
    public function testToTelegramHtml($html, $expected)
    {
        $this->assertSame($expected, Formatter::toTelegramHtml($html));
    }

    public static function replies()
    {
        return [
            'paragraphs and breaks' => ['<div>Hi Casey,</div><div><br></div><p>Line 1<br>Line 2</p>', "Hi Casey,\n\nLine 1\nLine 2"],
            'inline formatting'     => ['<p><strong>bold</strong> <em>it</em> <u>under</u> <strike>gone</strike> <code>x</code></p>', '<b>bold</b> <i>it</i> <u>under</u> <s>gone</s> <code>x</code>'],
            'text is escaped'       => ['<p>a &lt;b&gt; &amp; "c"</p>', 'a &lt;b&gt; &amp; "c"'],
            'links'                 => ['<a href="https://x.org/?a=1&amp;b=2">site</a> <a href="javascript:alert(1)">bad</a>', '<a href="https://x.org/?a=1&amp;b=2">site</a> bad'],
            'lists'                 => ['<p>Steps:</p><ol><li>One</li><li>Two</li></ol><ul><li>Dot</li></ul>', "Steps:\n\n1. One\n2. Two\n\n• Dot"],
            'quote and code'        => ["<blockquote>Said</blockquote><pre>  if (x) {\n    y();\n  }</pre>", "<blockquote>Said</blockquote>\n\n<pre>  if (x) {\n    y();\n  }</pre>"],
            'headings and others'   => ['<h2>Title</h2><span style="color:red">red</span><img src="a.png"><table><tr><td>a</td><td>b</td></tr></table>', "<b>Title</b>\nred\n\na b"],
            'empty'                 => ['<p> </p>', ''],
        ];
    }

    public function testLongRepliesAreSentAsPlainTextInParts()
    {
        $this->assertSame([['<b>Short</b>', true]], Formatter::messages('<b>Short</b>'));

        $paragraph = str_repeat('word ', 300);
        $messages = Formatter::messages('<p><b>'.$paragraph.'</b></p><p>'.$paragraph.'</p><p>'.$paragraph.'</p>');

        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertFalse($message[1]);
            $this->assertLessThanOrEqual(Formatter::MAX_LENGTH, mb_strlen($message[0]));
            $this->assertStringNotContainsString('<b>', $message[0]);
        }
    }
}
