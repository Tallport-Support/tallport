<?php

namespace Tests\Unit;

use App\Misc\MessageImages;
use Tests\TestCase;

/**
 * Pictures in a message as the agent sees it: thumbnails that open the full picture.
 */
class MessageImagesTest extends TestCase
{
    public function testPicturesOpenTheFullPicture()
    {
        $html = MessageImages::thumbnails('<p>See <img src="https://help.example.com/storage/attachment/1/2/screen%20shot.png?id=3&amp;token=x" style="width: 900px; height:600px; border: 0"> and <img src="/storage/x.png" alt="Chart"></p>');

        // Opened in the attachment viewer: an attachment's own ID, so it's shown once.
        $this->assertStringContainsString('<a class="f-message__image attachment-link" href="https://help.example.com/storage/attachment/1/2/screen%20shot.png?id=3&amp;token=x" target="_blank" rel="noopener" data-attachment-id="3" data-mime="image/*" data-file-name="screen shot.png" aria-label="screen shot.png"><img src="https://help.example.com/storage/attachment/1/2/screen%20shot.png?id=3&amp;token=x" style="border: 0"></a>', $html);
        // Named by its alt; an ID of its own.
        $this->assertStringContainsString('<a class="f-message__image attachment-link" href="/storage/x.png" target="_blank" rel="noopener" data-attachment-id="image-'.md5('/storage/x.png').'" data-mime="image/*" data-file-name="Chart"><img src="/storage/x.png" alt="Chart"></a>', $html);
    }

    public function testLinksSmallPicturesAndTextStayAsTheyAre()
    {
        $linked = '<a href="https://example.com"><span><img src="https://example.com/banner.png"></span></a>';
        $this->assertSame($linked, MessageImages::thumbnails($linked));
        $pixel = '<img src="https://example.com/open.gif" width="1" height="1">';
        $this->assertSame($pixel, MessageImages::thumbnails($pixel));
        $this->assertSame('<p>No pictures, ünïcode</p>', MessageImages::thumbnails('<p>No pictures, ünïcode</p>'));
        $this->assertStringContainsString('ünïcode', MessageImages::thumbnails('<p>ünïcode <img src="/a.png"></p>'));
    }
}
