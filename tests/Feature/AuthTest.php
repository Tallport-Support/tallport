<?php

namespace Tests\Feature;

use App\Attachment;
use App\Conversation;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Storage;
use Tests\FeatureTestCase;

/**
 * Logging in and out, password resets, the dashboard, admin-only pages, and
 * the public endpoints that work without a session (open tracking,
 * attachment downloads).
 */
class AuthTest extends FeatureTestCase
{
    protected function postForm($uri, array $data)
    {
        \Session::start();

        return $this->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    // Logging in and out.

    public function testLogin()
    {
        $user = $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);

        $this->get('/login')->assertStatus(200);
        $response = $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'secret-password']);

        $response->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function testWrongPasswordIsRejected()
    {
        $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);

        $response = $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'wrong-password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function testLoginIsThrottledAfterFiveFailures()
    {
        $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);
        for ($i = 0; $i < 5; $i++) {
            $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'wrong-password']);
        }

        $response = $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'secret-password']);

        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    /**
     * Disabled users can't log in at all (U1), with the same message as a
     * wrong password so the account's existence isn't revealed.
     */
    public function testDisabledUserCannotLogIn()
    {
        $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password'), 'status' => User::STATUS_DISABLED]);

        $response = $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'secret-password']);

        $response->assertSessionHasErrors('email');
        $this->assertSame('These credentials do not match our records.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function testDeletedUserCannotLogIn()
    {
        $user = $this->createUser(['email' => 'agent@example.org', 'password' => \Hash::make('secret-password')]);
        $user->status = User::STATUS_DELETED;
        $user->save();

        $this->postForm('/login', ['email' => 'agent@example.org', 'password' => 'secret-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function testLogout()
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $this->postForm('/logout', [])->assertRedirect('/');

        $this->assertGuest();
    }

    public function testRegistrationIsDisabled()
    {
        $this->get('/register')->assertRedirect('login');

        $this->postForm('/register', [
            'first_name' => 'Self', 'email' => 'self-registered@example.org',
            'password'   => 'password1', 'password_confirmation' => 'password1',
        ]);

        $this->assertNull(User::where('email', 'self-registered@example.org')->first());
    }

    // Password reset.

    public function testForgotPasswordDoesNotRevealWhetherAccountExists()
    {
        $user = $this->createUser(['email' => 'agent@example.org']);

        $known = $this->postForm('/password/email', ['email' => 'agent@example.org']);
        $unknown = $this->postForm('/password/email', ['email' => 'nobody@example.org']);

        $this->assertSame(session('status'), 'If an account exists for this email, you will receive a password reset link.');
        $known->assertSessionHas('status', 'If an account exists for this email, you will receive a password reset link.');
        $unknown->assertSessionHas('status', 'If an account exists for this email, you will receive a password reset link.');
        $this->assertCount(1, $this->sentEmailsTo('agent@example.org'));
        $this->assertCount(0, $this->sentEmailsTo('nobody@example.org'));
    }

    /**
     * Resetting sends the "password changed" email, which can only run once
     * per process (see UsersTest).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testResetPasswordWithToken()
    {
        $user = $this->createUser(['email' => 'agent@example.org']);
        $token = \Password::broker()->createToken($user);

        $this->get('/password/reset/'.$token)->assertStatus(200);
        $response = $this->postForm('/password/reset', [
            'token' => $token, 'email' => 'agent@example.org',
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(\Hash::check('brand-new-password', $user->fresh()->password));
        $this->assertFalse(\DB::table('password_resets')->where('email', 'agent@example.org')->exists(), 'The token is used up.');
        $this->assertGuest();
        $this->assertSame(['Password Changed'], array_map(function ($email) {
            return $email->getSubject();
        }, $this->sentEmailsTo('agent@example.org')));
    }

    public function testResetPasswordWithInvalidToken()
    {
        $user = $this->createUser(['email' => 'agent@example.org']);

        $this->postForm('/password/reset', [
            'token' => 'not-a-token', 'email' => 'agent@example.org',
            'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(\Hash::check('brand-new-password', $user->fresh()->password));
    }

    // Dashboard and admin-only pages.

    public function testDashboardShowsOnlyAccessibleMailboxes()
    {
        $agent = $this->createUser();
        $this->createMailbox([$agent], ['name' => 'Visible mailbox']);
        $this->createMailbox([], ['name' => 'Hidden mailbox']);

        $response = $this->actingAs($agent)->get('/');

        $response->assertStatus(200);
        $response->assertSee('Visible mailbox');
        $response->assertDontSee('Hidden mailbox');

        $this->actingAs($this->createAdmin())->get('/')->assertSee('Hidden mailbox');
    }

    public function testGuestIsSentToLogin()
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function testAdminOnlyPages()
    {
        $agent = $this->createUser();
        $admin = $this->createAdmin();

        foreach (['/app-settings', '/app-logs', '/system/status', '/modules/list'] as $uri) {
            $this->actingAs($agent)->get($uri)->assertStatus(403);
            $this->actingAs($admin)->get($uri)->assertStatus(200);
        }
    }

    // Public endpoints.

    public function testOpenTrackingPixelMarksReplyAsRead()
    {
        [$conversation, $reply] = $this->conversationWithReply();
        $hash = Thread::getOpenTrackingHash($reply, $conversation, $conversation->mailbox);

        $wrong = $this->get('/thread/read/'.$conversation->id.'/'.$reply->id.'/0000000000000000');
        $wrong->assertStatus(200);
        $this->assertSame('image/gif', $wrong->headers->get('Content-Type'));
        $this->assertNull($reply->fresh()->opened_at, 'A wrong hash must not mark the reply as read.');

        $this->get('/thread/read/'.$conversation->id.'/'.$reply->id.'/'.$hash)->assertStatus(200);
        $this->assertNotNull($reply->fresh()->opened_at);
    }

    public function testAttachmentDownloadNeedsValidToken()
    {
        Storage::fake('local_app');
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $boundary = 'b1';
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from'    => 'casey@customer.example.org',
            'to'      => $mailbox->email,
            'headers' => ['Content-Type' => 'multipart/mixed; boundary="'.$boundary.'"'],
            'body'    => "--$boundary\nContent-Type: text/plain\n\nSee attached.\n"
                ."--$boundary\nContent-Type: application/octet-stream; name=\"data.bin\"\nContent-Disposition: attachment; filename=\"data.bin\"\nContent-Transfer-Encoding: base64\n\n"
                .base64_encode('secret contents')."\n--$boundary--",
        ]));
        $attachment = Attachment::where('file_name', 'data.bin')->first();

        $url = $attachment->url();
        $this->assertStringContainsString('token=', $url);
        $download = $this->get($url);
        $download->assertStatus(200);
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));

        $without_token = preg_replace('/([?&])token=[^&]*/', '$1token=wrong', $url);
        $this->get($without_token)->assertStatus(403);
    }

    protected function conversationWithReply()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $mailbox->id)->first();
        $this->postAjax($agent, '/conversation/ajax', ['action' => 'send_reply', 'mailbox_id' => $mailbox->id, 'conversation_id' => $conversation->id, 'body' => '<p>Hi</p>']);

        return [$conversation, $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->first()];
    }
}
