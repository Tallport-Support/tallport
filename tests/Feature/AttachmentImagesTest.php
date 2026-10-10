<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Livewire\SystemStatus;
use App\Misc\AttachmentImages;
use App\Thread;
use App\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Pictures made from image attachments (App\Misc\AttachmentImages): thumbnails
 * in a message's attachment list, HEIC photos converted to JPEG, who may get
 * them, what has none, and that they go with the attachment.
 */
class AttachmentImagesTest extends FeatureTestCase
{
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
        \Cache::forget(AttachmentImages::CONVERTER_CACHE);
    }

    /**
     * The customer message of a new conversation, with these files (name => [mime, content]).
     */
    protected function threadWithFiles(array $files)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Photos']));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        foreach ($files as $name => [$mime, $content]) {
            Attachment::create($name, $mime, null, $content, null, false, $thread->id);
        }
        $thread->has_attachments = true;
        $thread->save();
        $conversation->has_attachments = true;
        $conversation->save();

        return $thread;
    }

    /**
     * A picture made with GD: the left half red, the right half blue.
     */
    protected function picture($width, $height, $type = 'png')
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 255, 0, 0));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 0, 0, 255));
        ob_start();
        $type == 'png' ? imagepng($image) : imagejpeg($image, null, 95);

        return ob_get_clean();
    }

    /**
     * A JPEG with an EXIF orientation (6: to be turned 90° clockwise).
     */
    protected function jpegWithOrientation($content, $orientation)
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01".pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($content, 2);
    }

    /**
     * A converter that reads HEIC (or not); "converting" writes a 600×800 JPEG.
     */
    protected function fakeConverter($reads_heic = true)
    {
        Process::fake(function (PendingProcess $process) use ($reads_heic) {
            if (in_array('-list', (array) $process->command)) {
                return Process::result($reads_heic ? "   Format  Module    Mode  Description\n     HEIC  HEIC      r--   High Efficiency Image Format\n" : "     PNG  PNG      rw-   Portable Network Graphics\n");
            }
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, 'jpeg:')) {
                    file_put_contents(substr($argument, 5), $this->picture(600, 800, 'jpeg'));
                }
            }

            return Process::result('');
        });
    }

    protected function image($response)
    {
        $content = $response->baseResponse instanceof \Symfony\Component\HttpFoundation\StreamedResponse ? $response->streamedContent() : $response->getContent();

        return imagecreatefromstring($content);
    }

    public function testThumbnailIsMadeOnceForWhoMayGetTheFile()
    {
        $thread = $this->threadWithFiles(['screenshot.png' => ['image/png', $this->picture(800, 600)]]);
        $attachment = $thread->attachments()->first();
        $url = AttachmentImages::thumbnailUrl($attachment);

        $response = $this->get($url)->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('max-age=2592000', $response->headers->get('Cache-Control'));
        $thumb = $this->image($response);
        $this->assertSame([427, 320], [imagesx($thumb), imagesy($thumb)]);

        // Kept: the next request gets the same file.
        $path = AttachmentImages::paths($attachment)[0];
        $this->assertTrue(Attachment::getDisk()->exists($path));
        Attachment::getDisk()->put($path, $this->picture(10, 10, 'jpeg'));
        $this->assertSame(10, imagesx($this->image($this->get($url))));

        // The attachment's token, as for downloading it; no URL with an extension.
        $this->assertStringNotContainsString('.jpg', parse_url($url, PHP_URL_PATH));
        $this->get(route('attachments.thumbnail', ['id' => $attachment->id, 'token' => 'wrong']))->assertForbidden();
        $this->get(route('attachments.thumbnail', ['id' => $attachment->id]))->assertForbidden();
        $this->get(route('attachments.thumbnail', ['id' => 999999, 'token' => $attachment->getToken()]))->assertNotFound();
    }

    public function testCameraPhotosAreTurnedUpright()
    {
        $thread = $this->threadWithFiles(['photo.jpg' => ['image/jpeg', $this->jpegWithOrientation($this->picture(400, 200, 'jpeg'), 6)]]);

        $thumb = $this->image($this->get(AttachmentImages::thumbnailUrl($thread->attachments()->first()))->assertOk());

        // Turned clockwise: the red left half is on top, and it's portrait.
        $this->assertSame([160, 320], [imagesx($thumb), imagesy($thumb)]);
        $top = imagecolorsforindex($thumb, imagecolorat($thumb, 80, 40));
        $bottom = imagecolorsforindex($thumb, imagecolorat($thumb, 80, 280));
        $this->assertGreaterThan(200, $top['red']);
        $this->assertGreaterThan(200, $bottom['blue']);
    }

    public function testFilesWithoutThumbnails()
    {
        // A PNG header claiming 10000×10000 pixels: not decoded.
        $huge = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0DIHDR".pack('NN', 10000, 10000)."\x08\x02\x00\x00\x00\x00\x00\x00\x00";
        $thread = $this->threadWithFiles([
            'huge.png'    => ['image/png', $huge],
            'broken.png'  => ['image/png', 'not a picture'],
            'invoice.pdf' => ['application/pdf', '%PDF-1.4'],
        ]);
        $attachments = $thread->attachments()->get()->keyBy('file_name');

        foreach (['huge.png', 'broken.png'] as $name) {
            $this->get(AttachmentImages::thumbnailUrl($attachments[$name]))->assertNotFound();
            // The failure is remembered.
            $this->assertSame(0, Attachment::getDisk()->size(AttachmentImages::paths($attachments[$name])[0]));
        }
        $this->assertFalse(AttachmentImages::hasThumbnail($attachments['invoice.pdf']));
        $this->get(AttachmentImages::thumbnailUrl($attachments['invoice.pdf']))->assertNotFound();

        // Too large a file isn't even read.
        $large = $attachments['broken.png'];
        $large->size = AttachmentImages::MAX_FILE_SIZE + 1;
        $this->assertFalse(AttachmentImages::hasThumbnail($large));
    }

    public function testMessageListShowsThumbnailsForImages()
    {
        $thread = $this->threadWithFiles([
            'screenshot.png' => ['image/png', $this->picture(80, 60)],
            'invoice.pdf'    => ['application/pdf', '%PDF-1.4'],
        ]);
        $attachments = $thread->attachments()->get()->keyBy('file_name');

        $html = $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$thread->conversation_id)->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'conv-attachment--thumbnail'));
        $this->assertMatchesRegularExpression('#<img\s+src="'.preg_quote(e(AttachmentImages::thumbnailUrl($attachments['screenshot.png'])), '#').'"\s+alt="screenshot.png"#', $html);
        $this->assertStringNotContainsString(e(AttachmentImages::thumbnailUrl($attachments['invoice.pdf'])), $html);
        $this->assertStringContainsString('invoice.pdf', $html);
    }

    public function testDeletingAnAttachmentDeletesItsPictures()
    {
        $this->fakeConverter();
        $thread = $this->threadWithFiles(['IMG_0001.HEIC' => ['application/octet-stream', 'heic photo']]);
        $attachment = $thread->attachments()->first();
        $this->get(AttachmentImages::thumbnailUrl($attachment))->assertOk();
        [$thumbnail, $converted] = AttachmentImages::paths($attachment);
        $this->assertTrue(Attachment::getDisk()->exists($thumbnail));
        $this->assertTrue(Attachment::getDisk()->exists($converted));

        $this->agent->permissions = [User::PERM_DELETE_CONVERSATIONS => true];
        $this->agent->save();
        $this->postAjax($this->agent->fresh(), route('attachments.delete', ['id' => $attachment->id]), [])->assertJsonPath('status', 'success');

        $this->assertFalse(Attachment::getDisk()->exists($thumbnail));
        $this->assertFalse(Attachment::getDisk()->exists($converted));
    }

    public function testHeicPhotosAreConvertedOnTheServer()
    {
        $this->fakeConverter();
        $thread = $this->threadWithFiles([
            'IMG_0001.HEIC' => ['application/octet-stream', 'heic photo'],
            'IMG_0002.heif' => ['image/heif', 'heif photo'],
        ]);
        $attachment = $thread->attachments()->where('file_name', 'IMG_0001.HEIC')->first();
        $this->assertTrue(AttachmentImages::isHeic($attachment));

        // The viewer gets a JPEG; downloading gives the original.
        $converted = $this->image($this->get(AttachmentImages::convertedUrl($attachment))->assertOk());
        $this->assertSame([600, 800], [imagesx($converted), imagesy($converted)]);
        $this->assertSame('heic photo', $this->get($attachment->url())->assertOk()->streamedContent());
        $thumb = $this->image($this->get(AttachmentImages::thumbnailUrl($attachment))->assertOk());
        $this->assertSame([240, 320], [imagesx($thumb), imagesy($thumb)]);

        // Converted once, by ImageMagick with the coder named, without a shell.
        Process::assertRanTimes(function (PendingProcess $process) {
            return is_array($process->command) && in_array('-auto-orient', $process->command)
                && str_starts_with($process->command[1], 'heic:') && $process->command[0] == 'magick';
        }, 1);
        $this->get(route('attachments.converted', ['id' => $attachment->id, 'token' => 'wrong']))->assertForbidden();

        $html = $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$thread->conversation_id)->assertOk()->getContent();
        $this->assertStringContainsString('data-converted-url="'.e(AttachmentImages::convertedUrl($attachment)).'"', $html);
        $this->assertSame(2, substr_count($html, 'conv-attachment--thumbnail'));
    }

    public function testHeicPhotosWithoutAConverterAreLeftToTheBrowser()
    {
        $this->fakeConverter(false);
        $thread = $this->threadWithFiles(['IMG_0001.heic' => ['image/heic', 'heic photo']]);
        $attachment = $thread->attachments()->first();

        $this->assertNull(AttachmentImages::convertedUrl($attachment));
        $this->get(route('attachments.converted', ['id' => $attachment->id, 'token' => $attachment->getToken()]))->assertNotFound();
        $this->get(AttachmentImages::thumbnailUrl($attachment))->assertNotFound();
        // Nothing remembered: a converter installed later is used.
        $this->assertFalse(Attachment::getDisk()->exists(AttachmentImages::paths($attachment)[0]));

        $html = $this->actingAs($this->agent)->followingRedirects()->get('/conversation/'.$thread->conversation_id)->assertOk()->getContent();
        $this->assertStringContainsString('data-heic', $html);
        $this->assertStringNotContainsString('data-converted-url', $html);
        $this->assertMatchesRegularExpression('#<img\s+alt="IMG_0001.heic"#', $html);
    }

    public function testRealHeicConversion()
    {
        if (!AttachmentImages::detectConverter()) {
            $this->markTestSkipped('ImageMagick here can\'t read HEIC.');
        }
        $thread = $this->threadWithFiles(['tiny.heic' => ['image/heic', file_get_contents(base_path('tests/Fixtures/images/tiny.heic'))]]);

        $converted = $this->image($this->get(AttachmentImages::convertedUrl($thread->attachments()->first()))->assertOk());

        $this->assertSame([8, 8], [imagesx($converted), imagesy($converted)]);
        $this->assertGreaterThan(150, imagecolorsforindex($converted, imagecolorat($converted, 4, 4))['red']);
    }

    public function testSystemStatusShowsWhetherHeicIsConverted()
    {
        $admin = $this->createUser(['role' => User::ROLE_ADMIN]);

        $this->fakeConverter();
        Livewire::withoutLazyLoading();
        Livewire::actingAs($admin)->test(SystemStatus::class)->assertSee('HEIC Images')->assertSee('Converted on the server');

        $this->fakeConverter(false);
        Livewire::withoutLazyLoading();
        Livewire::actingAs($admin)->test(SystemStatus::class)->assertSee('HEIC Images')->assertDontSee('Converted on the server');
        $this->assertSame('', AttachmentImages::converter());
    }
}
