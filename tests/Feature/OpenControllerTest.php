<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * OpenController: pages that work without logging in. The invitation
 * setup page and form, the open tracking pixel, and how attachments are
 * sent (viewed or downloaded, by PHP, Apache or nginx).
 */
class OpenControllerTest extends FeatureTestCase
{
    protected function invitedUser(array $attributes = [])
    {
        $user = $this->createUser(array_merge(['email' => 'invited@example.org'], $attributes));
        $user->invite_state = User::INVITE_STATE_SENT;
        $user->invite_hash = \Str::random(User::INVITE_HASH_LENGTH);
        $user->save();

        return $user;
    }

    protected function setupFields(array $fields = [])
    {
        \Session::start();

        return array_merge([
            '_token'                => csrf_token(),
            'email'                 => 'invited@example.org',
            'password'              => 'chosen-password',
            'password_confirmation' => 'chosen-password',
            'timezone'              => 'UTC',
            'time_format'           => User::TIME_FORMAT_24,
        ], $fields);
    }

    protected function attachment($file_name, $mime_type, $content = 'file contents')
    {
        return Attachment::create($file_name, $mime_type, null, $content, null);
    }

    // Invitations.

    public function testSetupWhileLoggedInGoesToTheDashboard()
    {
        $user = $this->invitedUser();
        $other = $this->createUser();

        $this->actingAs($other)->get($user->urlSetup())->assertRedirect(route('dashboard'));
        $this->actingAs($other)->post($user->urlSetup(), $this->setupFields())->assertRedirect(route('dashboard'));

        $this->assertSame(User::INVITE_STATE_SENT, (int) $user->fresh()->invite_state);
    }

    public function testSetupPageIsInTheUsersLanguage()
    {
        $user = $this->invitedUser(['locale' => 'nl']);

        $this->get($user->urlSetup())->assertStatus(200)->assertSee('invited@example.org');

        $this->assertSame('nl', app()->getLocale());
    }

    public function testExpiredOrForgedLinkShowsNoAccount()
    {
        $user = $this->invitedUser();
        $expired = route('user_setup', [
            'hash'           => $user->invite_hash,
            'invite_sent_at' => \Helper::encrypt((string) (time() - (User::INVITE_TTL_DAYS + 1) * 86400), $user->password),
        ]);
        $this->get($expired)->assertStatus(200)->assertDontSee('invited@example.org');

        // A plain timestamp instead of an encrypted one.
        $forged = route('user_setup', ['hash' => $user->invite_hash, 'invite_sent_at' => time()]);
        $this->get($forged)->assertStatus(200)->assertDontSee('invited@example.org');
        $this->post($forged, $this->setupFields())->assertStatus(403);
        $this->assertSame(User::INVITE_STATE_SENT, (int) $user->fresh()->invite_state);
    }

    public function testSetupWithPhoto()
    {
        $user = $this->invitedUser();

        $this->post($user->urlSetup(), $this->setupFields(['photo_url' => UploadedFile::fake()->image('me.png', 120, 120)]))
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame(User::INVITE_STATE_ACTIVATED, (int) $user->invite_state);
        $this->assertStringEndsWith('.jpg', $user->photo_url);
        \Storage::disk('local')->assertExists(User::PHOTO_DIRECTORY.'/'.$user->photo_url);
    }

    public function testSetupWithBrokenPhotoIsRefused()
    {
        $this->knownBug('U9');

        $user = $this->invitedUser();

        $this->post($user->urlSetup(), $this->setupFields(['photo_url' => UploadedFile::fake()->createWithContent('me.gif', 'GIF89a')]))
            ->assertSessionHasErrors('photo_url');

        $this->assertSame(User::INVITE_STATE_SENT, (int) $user->fresh()->invite_state);
    }

    // Open tracking.

    public function testPixelOfAnotherConversationMarksNothing()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'First']));
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Second']));
        $first = Conversation::where('subject', 'First')->first();
        $second = Conversation::where('subject', 'Second')->first();
        $thread = $first->threads()->first();
        $hash = Thread::getOpenTrackingHash($thread, $first, $mailbox);

        $response = $this->get('/thread/read/'.$second->id.'/'.$thread->id.'/'.$hash);

        $response->assertStatus(200);
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $this->assertNull($thread->fresh()->opened_at);
    }

    // Attachments.

    public function testAttachmentLinkChecks()
    {
        $attachment = $this->attachment('report.zip', 'application/zip');
        $path = '/storage/attachment/'.$attachment->file_dir.'report.zip';

        // By its path and token (links without an id).
        $this->get($path.'?token='.$attachment->getToken())->assertStatus(200);
        $this->get($path.'?token=wrong')->assertStatus(403);
        // An id needs a token.
        $this->get($path.'?id='.$attachment->id)->assertStatus(403);
        // The id and the name must match.
        $other = $this->attachment('other.zip', 'application/zip');
        $this->get($path.'?id='.$other->id.'&token='.$other->getToken())->assertStatus(403);
        $this->get('/storage/attachment/'.$attachment->file_dir.'missing.zip?token=x')->assertStatus(404);
    }

    public function testViewableFilesAreShownInTheBrowser()
    {
        $image = $this->attachment('photo.png', 'image/png', UploadedFile::fake()->image('photo.png', 10, 10)->getContent());

        $response = $this->get($image->url());

        $response->assertStatus(200);
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $response->baseResponse);
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('allow-same-origin', $response->headers->get('Content-Security-Policy'));
        $this->assertStringStartsNotWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function testAudioPlaysInTheBrowser()
    {
        $audio = $this->attachment('voice.mp3', 'audio/mpeg', 'ID3 audio');

        $response = $this->get($audio->url());

        $response->assertStatus(200);
        $this->assertStringContainsString('sandbox allow-same-origin', $response->headers->get('Content-Security-Policy'));
    }

    public function testFilesWithAViewableNameButOtherContentAreDownloaded()
    {
        // HTML or SVG renamed to an image or text: never shown by the browser.
        foreach ([['page.txt', 'text/html'], ['drawing.png', 'image/svg+xml']] as [$file_name, $mime_type]) {
            $attachment = Attachment::create($file_name, 'text/plain', null, 'contents', null);
            $attachment->mime_type = $mime_type;
            $attachment->save();

            $response = $this->get($attachment->url());

            $response->assertStatus(200);
            $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'), $file_name);
            $this->assertNull($response->headers->get('Content-Security-Policy'), $file_name);
        }
    }

    public function testSentByTheWebServer()
    {
        $download = $this->attachment('report.zip', 'application/zip');
        $image = $this->attachment('photo.png', 'image/png', UploadedFile::fake()->image('photo.png', 10, 10)->getContent());

        config(['app.download_attachments_via' => 'apache']);
        $response = $this->get($download->url());
        $response->assertStatus(200)->assertHeader('X-Sendfile', $download->getLocalFilePath());
        $this->assertSame('attachment; filename="report.zip"', $response->headers->get('Content-Disposition'));
        $response = $this->get($image->url());
        $response->assertHeader('X-Sendfile', $image->getLocalFilePath());
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Disposition'));

        config(['app.download_attachments_via' => 'nginx']);
        $response = $this->get($download->url());
        $response->assertStatus(200)->assertHeader('X-Accel-Redirect', '/storage/app/attachment/'.$download->file_dir.'report.zip');
        $this->assertSame('attachment; filename="report.zip"', $response->headers->get('Content-Disposition'));
        $response = $this->get($image->url());
        $response->assertHeader('X-Accel-Redirect', $image->getLocalFilePath(false));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
    }

    public function testTeamChatFileIsDecryptedForDownload()
    {
        $attachment = $this->attachment('archive.zip', 'application/zip', \Crypt::encryptString('PK archive'));
        $attachment->team_message_id = 1;
        $attachment->save();

        $response = $this->get($attachment->url());

        $response->assertStatus(200);
        $this->assertSame('PK archive', $response->getContent());
        $this->assertSame('attachment; filename="archive.zip"', $response->headers->get('Content-Disposition'));
    }
}
