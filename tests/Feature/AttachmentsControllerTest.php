<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use Tests\FeatureTestCase;

/**
 * Attachments (AttachmentsController): the zip of a message with files of
 * the same name or missing, attached emails with inline images, and what
 * isn't there.
 */
class AttachmentsControllerTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    /**
     * The customer message of a new conversation, with these files
     * ([name, mime, content] each).
     */
    protected function threadWithFiles(array $files)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Files']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        foreach ($files as [$name, $mime, $content]) {
            Attachment::create($name, $mime, null, $content, null, false, $thread->id);
        }

        return $thread;
    }

    protected function zipEntries($response)
    {
        $zip = new \ZipArchive();
        $zip->open($response->baseResponse->getFile()->getPathname());
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entries[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();
        ksort($entries);

        return $entries;
    }

    public function testZipNamesFilesOfTheSameNameAndSkipsMissingOnes()
    {
        $thread = $this->threadWithFiles([
            ['report.pdf', 'application/pdf', 'first'],
            ['Report.pdf', 'application/pdf', 'second'],
            ['lost.txt', 'text/plain', 'gone'],
        ]);
        $lost = $thread->attachments()->where('file_name', 'lost.txt')->first();
        Attachment::getDisk()->delete($lost->getStorageFilePath());

        $response = $this->actingAs($this->agent)->get(route('attachments.download_all', ['thread_id' => $thread->id]))->assertOk();

        $this->assertSame(['2_Report.pdf' => 'second', 'report.pdf' => 'first'], $this->zipEntries($response));
    }

    public function testNothingToGet()
    {
        $thread = $this->threadWithFiles([['notes.txt', 'text/plain', 'Notes']]);
        $bare = $this->threadWithFiles([]);

        $this->actingAs($this->agent)->get(route('attachments.download_all', ['thread_id' => $bare->id]))->assertNotFound();
        $this->postAjax($this->agent, route('attachments.delete', ['id' => 999999]), [])
            ->assertJson(['status' => 'error', 'msg' => 'Attachment not found']);

        // Not an email: no email page.
        $this->get(route('attachments.email', ['id' => $thread->attachments()->first()->id]))->assertNotFound();
    }

    public function testAttachedEmailWithAnInlineImage()
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $eml = "From: Robin <robin@shop.example>\r\nTo: casey@customer.example.org\r\nSubject: Logo\r\n"
            ."MIME-Version: 1.0\r\nContent-Type: multipart/related; boundary=\"b\"\r\n\r\n"
            ."--b\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Our logo: <img src=\"cid:logo@shop\"></p>\r\n"
            ."--b\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-ID: <logo@shop>\r\nContent-Disposition: inline; filename=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            .chunk_split(base64_encode($png))."--b--\r\n";
        $thread = $this->threadWithFiles([['logo.eml', 'message/rfc822', $eml]]);
        $attachment = $thread->attachments()->first();

        $page = $this->actingAs($this->agent)->get(route('attachments.email', ['id' => $attachment->id]))->assertOk();

        $page->assertSee('src="data:image/png;base64,'.base64_encode($png).'"', false)->assertDontSee('cid:logo@shop', false);
        $this->get(route('attachments.email', ['id' => $attachment->id, 'part' => 5]))->assertNotFound();
    }

    /**
     * Web servers often answer URLs ending in a file extension themselves
     * (production's nginx gave 404 for original.eml): the zip's URL has
     * none, its name is in Content-Disposition.
     */
    public function testZipUrlHasNoFileExtension()
    {
        $this->knownBug('C23');
        $thread = $this->threadWithFiles([['notes.txt', 'text/plain', 'Notes']]);
        $url = route('attachments.download_all', ['thread_id' => $thread->id]);

        $this->assertDoesNotMatchRegularExpression('#\.[a-z0-9]+$#i', parse_url($url, PHP_URL_PATH));
        $this->assertStringContainsString('attachments-'.$thread->id.'.zip', $this->actingAs($this->agent)->get($url)->headers->get('Content-Disposition'));
    }
}
