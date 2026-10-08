<?php

namespace Tests\Unit;

use App\Misc\EmailColors;
use Tests\TestCase;

/**
 * Emails' default colours (black text, white backgrounds) are dropped where messages are shown,
 * so dark appearance can read them; other colours stay.
 */
class EmailColorsTest extends TestCase
{
    public function testDefaultColoursAreDropped()
    {
        $this->assertSame('<div style="font-size:14px">Hi</div>', EmailColors::neutral('<div style="color: rgb(0, 0, 0); font-size:14px">Hi</div>'));
        $this->assertSame('<p style="">x</p>', EmailColors::neutral('<p style="color:#000;background-color:#FFFFFF">x</p>'));
        $this->assertSame('<span style="">x</span>', EmailColors::neutral('<span style="color: windowtext">x</span>'));
        $this->assertSame('<font>x</font>', EmailColors::neutral('<font color="black">x</font>'));
        $this->assertSame('<table><tr><td>x</td></tr></table>', EmailColors::neutral('<table bgcolor="#fff"><tr><td>x</td></tr></table>'));
        $this->assertSame('<p style="">x</p>', EmailColors::neutral('<p style="color:#111111 !important">x</p>'), 'Near black.');
    }

    public function testOtherColoursStay()
    {
        $banner = '<td style="background-color:#083866; color:#fff">Banner</td>';
        $this->assertSame($banner, EmailColors::neutral($banner));
        $this->assertSame('<p style="color:#c00">Red</p>', EmailColors::neutral('<p style="color:#c00">Red</p>'));
        $this->assertSame('<p style="color:#555">Grey</p>', EmailColors::neutral('<p style="color:#555">Grey</p>'));
        $this->assertSame('<div style="background:url(x.png) #fff">x</div>', EmailColors::neutral('<div style="background:url(x.png) #fff">x</div>'), 'Backgrounds with images stay.');
        // Text that says color=black isn't markup.
        $this->assertSame('<p>use color=black here</p>', EmailColors::neutral('<p>use color=black here</p>'));
    }
}
