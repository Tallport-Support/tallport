<?php

namespace Tests\Unit;

use App\Incoming\Attachment;
use Tests\TestCase;

/**
 * Attachments of incoming mail reach modules (fetch_emails.data_to_save);
 * what Tallport's class lacks is passed on to the library's attachment.
 */
class IncomingAttachmentTest extends TestCase
{
    public function testOwnValues()
    {
        $attachment = new Attachment('report.pdf', 'application', 'application/pdf', '%PDF-1.4', 'abc@cid');

        $this->assertSame('report.pdf', $attachment->getName());
        $this->assertSame('application', $attachment->getType());
        $this->assertSame('application/pdf', $attachment->content_type);
        $this->assertSame('abc@cid', $attachment->id);
        $this->assertSame('application/pdf', $attachment->getMimeType());
    }

    public function testOtherCallsGoToTheLibraryAttachment()
    {
        $library = new class {
            public $disposition = 'inline';

            public function getExtension()
            {
                return 'pdf';
            }
        };
        $attachment = new Attachment('report.pdf', 'application', 'application/pdf', '%PDF-1.4', null, $library);

        $this->assertSame('pdf', $attachment->getExtension());
        $this->assertSame('inline', $attachment->disposition);
    }

    public function testWithoutLibraryAttachment()
    {
        $attachment = new Attachment('a.txt', 'text', 'text/plain', 'x');

        $this->assertNull($attachment->disposition);
        $this->expectException(\BadMethodCallException::class);
        $attachment->getExtension();
    }
}
