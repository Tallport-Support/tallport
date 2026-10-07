<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use Tests\FeatureTestCase;

/**
 * App\Attachment: file names and types when saving, downloads, deleting,
 * copying (also from remote storage) and reading the files.
 */
class AttachmentModelTest extends FeatureTestCase
{
    /**
     * A conversation with two messages, each with one file.
     */
    protected function conversationWithTwoFilesMessages()
    {
        $mailbox = $this->createMailbox([$this->createUser()]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'Casey <casey@customer.example.org>', 'to' => $mailbox->email, 'subject' => 'Files']));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $first = $conversation->threads()->first();
        $second = $first->replicate();
        $second->message_id = 'second@customer.example.org';
        $second->save();
        foreach ([$first, $second] as $thread) {
            Attachment::create('file'.$thread->id.'.txt', 'text/plain', null, 'file', null, false, $thread->id);
            $thread->has_attachments = true;
            $thread->save();
        }
        $conversation->has_attachments = true;
        $conversation->save();

        return [$conversation, $first, $second];
    }

    public function testCreateNeedsContent()
    {
        $this->assertFalse(Attachment::create('empty.txt', 'text/plain', null, '', null));
        $this->assertSame(0, Attachment::count());
    }

    /**
     * A mime type that repeats itself (FreeScout #3048) is cut to one.
     */
    public function testCreateFixesRepeatedMimeType()
    {
        $docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

        $attachment = Attachment::create('report.docx', 'application/msword'.$docx, null, 'DOCX', null);

        $this->assertSame($docx, $attachment->mime_type);
        $this->assertSame(Attachment::TYPE_APPLICATION, $attachment->type);
    }

    /**
     * A file without a name gets a unique one, with the extension from the mime type.
     */
    public function testCreateNamesUnnamedFile()
    {
        $attachment = Attachment::create('', 'image/png', null, 'PNG', null);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{13}\.png$/', $attachment->file_name);
        $this->assertSame(Attachment::TYPE_IMAGE, $attachment->type);
        $this->assertSame('PNG', $attachment->getFileContents());

        $this->assertMatchesRegularExpression('/^[0-9a-f]{13}$/', Attachment::create('', 'nomimetype', null, 'X', null)->file_name);
    }

    /**
     * "undefined" attached emails and calendar invitations (from webklex) get real names.
     */
    public function testCreateNamesUndefinedEmailsAndInvitations()
    {
        $this->assertSame('RFC822.eml', Attachment::create('undefined', 'message/rfc822', null, 'Subject: Hi', null)->file_name);
        $this->assertSame('calendar.ics', Attachment::create('undefined', 'text/calendar', null, 'BEGIN:VCALENDAR', null)->file_name);
    }

    /**
     * A name longer than 255 bytes is shortened to 125 characters, keeping the extension.
     */
    public function testCreateShortensLongName()
    {
        $attachment = Attachment::create(str_repeat('ä', 200).'.pdf', 'application/pdf', null, '%PDF', null);

        $this->assertSame(str_repeat('ä', 121).'.pdf', $attachment->file_name);
        $this->assertTrue($attachment->fileExists());
    }

    /**
     * A file that can't be stored is logged; the attachment's path is still returned.
     */
    public function testSaveFileToDiskLogsStorageError()
    {
        \Log::spy();
        $uploaded_file = \Mockery::mock(\Illuminate\Http\UploadedFile::class);
        $uploaded_file->shouldReceive('storeAs')->andThrow(new \Exception('Disk full'));
        $attachment = new Attachment();
        $attachment->id = 3;

        $file_info = Attachment::saveFileToDisk($attachment, 'a.txt', '', $uploaded_file);

        $this->assertSame(Attachment::generatePath(3).'1/', $file_info['file_dir']);
        $this->assertSame('attachment/'.Attachment::generatePath(3).'1/a.txt', $file_info['file_path']);
        \Log::shouldHaveReceived('error')->withArgs(function ($message) {
            return str_contains($message, '[Attachment::saveFileToDisk()]') && str_contains($message, 'Disk full');
        });
    }

    public function testDetectType()
    {
        $this->assertSame(Attachment::TYPE_VIDEO, Attachment::detectType('application/octet-stream', 'MP4'));
        $this->assertSame(Attachment::TYPE_APPLICATION, Attachment::detectType('application/octet-stream', 'bin'));
        $this->assertSame(Attachment::TYPE_AUDIO, Attachment::detectType('audio/mpeg'));
        $this->assertSame(Attachment::TYPE_VIDEO, Attachment::detectType('video/mp4'));
        $this->assertSame(Attachment::TYPE_MODEL, Attachment::detectType('model/gltf+json'));
        $this->assertSame(Attachment::TYPE_OTHER, Attachment::detectType('x-world/x-vrml'));
    }

    public function testTypeNameToInt()
    {
        $this->assertSame(Attachment::TYPE_IMAGE, Attachment::typeNameToInt('image'));
        $this->assertSame(Attachment::TYPE_OTHER, Attachment::typeNameToInt('unknown'));
    }

    /**
     * "text" is TYPE_TEXT (0), which empty() takes for a missing type.
     */
    public function testTypeNameToIntText()
    {
        $this->knownBug('C24');

        $this->assertSame(Attachment::TYPE_TEXT, Attachment::typeNameToInt('text'));
    }

    /**
     * Viewed in the browser: no attachment Content-Disposition; an attached email
     * named "RFC822" downloads as RFC822.eml; cached for a month.
     */
    public function testDownload()
    {
        $attachment = Attachment::create('RFC822', 'message/rfc822', null, 'Subject: Hi', null);

        $download = $attachment->download();
        $this->assertStringContainsString('attachment; filename=RFC822.eml', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('max-age=2592000', $download->headers->get('Cache-Control'));

        $view = $attachment->download(true);
        $this->assertEmpty($view->headers->get('Content-Disposition'));
    }

    public function testLocalFilePathAndSizes()
    {
        $attachment = Attachment::create('notes.txt', 'text/plain', null, str_repeat('a', 2048), null);

        $this->assertSame('/storage/app/attachment/'.$attachment->file_dir.'notes.txt', $attachment->getLocalFilePath(false));
        $this->assertSame('2 KB', $attachment->getSizeName());
        $this->assertSame(0, Attachment::formatBytes(0));
        $this->assertSame(0, Attachment::formatBytes(null));
    }

    public function testDeleteByIds()
    {
        $keep = Attachment::create('keep.txt', 'text/plain', null, 'keep', null);
        $delete = Attachment::create('delete.txt', 'text/plain', null, 'delete', null);

        Attachment::deleteByIds([]);
        $this->assertSame(2, Attachment::count());

        Attachment::deleteByIds([$delete->id]);

        $this->assertNull(Attachment::find($delete->id));
        $this->assertFalse($delete->fileExists());
        $this->assertNotNull(Attachment::find($keep->id));
        $this->assertTrue($keep->fileExists());
    }

    /**
     * Deleting a message's only file: the message has no files any more, the
     * conversation still has them while another message has.
     */
    public function testDeleteAttachmentsKeepsConversationFlagWhileAnotherMessageHasFiles()
    {
        [$conversation, $first, $second] = $this->conversationWithTwoFilesMessages();

        Attachment::deleteAttachments([$first->attachments()->first()]);

        $this->assertSame(0, $first->attachments()->count());
        $this->assertFalse((bool) $first->fresh()->has_attachments);
        $this->assertTrue((bool) $second->fresh()->has_attachments);
        $this->assertTrue((bool) $conversation->fresh()->has_attachments);
    }

    /**
     * Deleting the only files of two messages at once clears both messages' flag
     * and the conversation's. The loop stops (break 2) at the first message while
     * the second still has its flag, so the second keeps it.
     */
    public function testDeleteAttachmentsOfSeveralMessages()
    {
        $this->knownBug('C25');

        [$conversation, $first, $second] = $this->conversationWithTwoFilesMessages();

        Attachment::deleteAttachments(Attachment::whereIn('thread_id', [$first->id, $second->id])->get());

        $this->assertSame(0, Attachment::count());
        $this->assertFalse((bool) $first->fresh()->has_attachments);
        $this->assertFalse((bool) $second->fresh()->has_attachments);
        $this->assertFalse((bool) $conversation->fresh()->has_attachments);
    }

    public function testDuplicateMissingFile()
    {
        $attachment = Attachment::create('gone.txt', 'text/plain', null, 'gone', null);
        Attachment::getDisk()->delete($attachment->getStorageFilePath());

        $this->assertNull($attachment->duplicate());
        $this->assertSame(1, Attachment::count());
    }

    /**
     * With remote storage (the default disk isn't "local"), files are on that disk
     * and a copy is made from a stream of the file.
     */
    public function testDuplicateInRemoteStorage()
    {
        config(['filesystems.default' => 'tallport_remote']);
        \Storage::fake('tallport_remote');
        $attachment = Attachment::create('remote.txt', 'text/plain', null, 'remote file', null);

        $copy = $attachment->duplicate(77);

        $this->assertSame('tallport_remote', Attachment::getDiskName());
        $this->assertTrue(\Storage::disk('tallport_remote')->exists($attachment->getStorageFilePath()));
        $this->assertNotSame($attachment->id, $copy->id);
        $this->assertSame(77, $copy->thread_id);
        $this->assertSame('remote.txt', $copy->file_name);
        $this->assertSame('remote file', $copy->getFileContents());
        $this->assertSame('remote file', stream_get_contents($copy->getFileStream()));
    }

    /**
     * A missing file reads as null (callers check for it), and gives no stream.
     */
    public function testReadingMissingFile()
    {
        $attachment = Attachment::create('gone.txt', 'text/plain', null, 'gone', null);
        Attachment::getDisk()->delete($attachment->getStorageFilePath());

        $this->assertNull($attachment->getFileContents());
        $this->assertNull($attachment->getFileStream());
    }
}
