<?php

namespace Tests\Feature;

use App\Mailbox;
use App\User;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * UsersController: profile details (photo, language, admin-only fields),
 * the permissions and notifications pages of deleted users, invites that
 * can't be sent, and the ajax actions' error answers.
 */
class UsersControllerTest extends FeatureTestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function profile(User $user, array $data = [])
    {
        return array_merge([
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'email'       => $user->email,
            'timezone'    => 'Europe/Amsterdam',
            'time_format' => User::TIME_FORMAT_24,
        ], $data);
    }

    /**
     * Sending any email fails, as when the mail server can't be reached.
     */
    protected function failMailSending()
    {
        $failing = new class extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw new \Symfony\Component\Mailer\Exception\TransportException('Connection to the mail server failed');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
        // Extenders are kept only while there's no instance.
        \App::forgetInstance('mail.manager');
        \App::forgetInstance('mailer');
        $this->app->extend('mail.manager', function ($manager) use ($failing) {
            return static::captureAllMailDrivers($manager, $failing);
        });
        \Mail::swap(app('mail.manager'));
    }

    // Profile.

    public function testPhotoIsResizedAndSaved()
    {
        $agent = $this->createUser();

        $this->postForm($agent, '/users/profile/'.$agent->id, $this->profile($agent, [
            'photo_url' => UploadedFile::fake()->image('me.jpg', 400, 300),
        ]))->assertRedirect(route('users.profile', ['id' => $agent->id]))->assertSessionHasNoErrors();

        $photo = $agent->fresh()->photo_url;
        $this->assertStringEndsWith('.jpg', $photo);
        \Storage::disk('local')->assertExists(User::PHOTO_DIRECTORY.'/'.$photo);

        // And removed again.
        $response = $this->postAjax($agent, '/users/ajax', ['action' => 'delete_photo', 'user_id' => $agent->id])->json();
        $this->assertSame('success', $response['status']);
        $this->assertSame('', $agent->fresh()->photo_url);
        \Storage::disk('local')->assertMissing(User::PHOTO_DIRECTORY.'/'.$photo);
    }

    public function testBrokenImageIsRejected()
    {

        $agent = $this->createUser();

        $this->postForm($agent, '/users/profile/'.$agent->id, $this->profile($agent, [
            'job_title' => 'Changed',
            'photo_url' => UploadedFile::fake()->createWithContent('me.gif', 'GIF89a'),
        ]))->assertRedirect(route('users.profile', ['id' => $agent->id]))->assertSessionHasErrors('photo_url');

        $this->assertNull($agent->fresh()->photo_url);
        $this->assertNull($agent->fresh()->job_title);
    }

    public function testEmailOfAMailboxIsRefused()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org']);

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $this->profile($agent, ['email' => $mailbox->email]))
            ->assertRedirect(route('users.profile', ['id' => $agent->id]))
            ->assertSessionHasErrors('email');

        $this->assertNotSame('support@example.org', $agent->fresh()->email);
    }

    public function testOwnLanguageIsUsedRightAway()
    {
        $agent = $this->createUser();

        $this->postForm($agent, '/users/profile/'.$agent->id, $this->profile($agent, ['locale' => 'nl']))->assertSessionHasNoErrors();

        $this->assertSame('nl', session('user_locale'));
        $this->assertSame('nl', $agent->fresh()->locale);

        // Someone else's language is theirs only.
        $other = $this->createUser();
        \Session::forget('user_locale');
        $this->postForm($this->admin, '/users/profile/'.$other->id, $this->profile($other, ['locale' => 'de']));
        $this->assertSame('nl', session('user_locale'));
        $this->assertSame('de', $other->fresh()->locale);
    }

    public function testPasswordAndTypeCantBeSetThroughTheProfile()
    {
        $agent = $this->createUser(['password' => \Hash::make('old-password')]);
        $type = $agent->fresh()->type;

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $this->profile($agent, [
            'password' => 'new-password',
            'type'     => 99,
        ]))->assertSessionHasNoErrors();

        $agent->refresh();
        $this->assertTrue(\Hash::check('old-password', $agent->password));
        $this->assertSame($type, $agent->type);
    }

    public function testAdminRestrictsUserToAssignedConversations()
    {
        $agent = $this->createUser();

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $this->profile($agent, ['only_assigned_tickets' => 1]))->assertSessionHasNoErrors();
        $this->assertTrue($agent->fresh()->canSeeOnlyAssignedConversations());

        // The user can't lift it.
        $this->postForm($agent->fresh(), '/users/profile/'.$agent->id, $this->profile($agent, ['only_assigned_tickets' => 0]));
        $this->assertTrue($agent->fresh()->canSeeOnlyAssignedConversations());

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $this->profile($agent));
        $this->assertFalse($agent->fresh()->canSeeOnlyAssignedConversations());
    }

    // Deleted users' pages.

    public function testDeletedUsersPagesAreGone()
    {
        $agent = $this->createUser();
        $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $agent->id]);
        $this->assertTrue($agent->fresh()->isDeleted());

        $this->actingAs($this->admin)->get('/users/profile/'.$agent->id)->assertStatus(404);
        $this->actingAs($this->admin)->get('/users/permissions/'.$agent->id)->assertStatus(404);
        $this->actingAs($this->admin)->get('/users/notifications/'.$agent->id)->assertStatus(404);
    }

    public function testPermissionsPage()
    {
        $agent = $this->createUser(['first_name' => 'Robin']);
        $this->createMailbox([$agent], ['name' => 'Billing']);
        $this->createMailbox([], ['name' => 'Sales']);

        $this->actingAs($this->admin)->get('/users/permissions/'.$agent->id)
            ->assertStatus(200)
            ->assertSee('Billing')
            ->assertSee('Sales');

        $this->postForm($agent, '/users/permissions/'.$agent->id, ['mailboxes' => [Mailbox::where('name', 'Sales')->first()->id]])->assertStatus(403);
        $this->assertSame(['Billing'], $agent->mailboxes()->pluck('name')->all());
    }

    // Invites and password resets.

    public function testInviteThatCantBeSentIsReported()
    {
        $this->failMailSending();

        $this->postForm($this->admin, '/users/wizard', [
            'first_name'  => 'Invited',
            'email'       => 'invited@example.org',
            'role'        => User::ROLE_USER,
            'send_invite' => 1,
        ])->assertSessionHas('flash_error_floating', 'Connection to the mail server failed — Check mail settings in "Manage » Settings » Mail Settings"');

        // The user exists; the invite can be sent again later.
        $invited = User::where('email', 'invited@example.org')->first();
        $this->assertNotNull($invited);
        $response = $this->postAjax($this->admin, '/users/ajax', ['action' => 'send_invite', 'user_id' => $invited->id])->json();
        $this->assertSame('error', $response['status']);
        $this->assertSame('Connection to the mail server failed — Check mail settings in "Manage » Settings » Mail Settings"', $response['msg']);
    }

    public function testInviteAndResetErrors()
    {
        $agent = $this->createUser();

        foreach (['send_invite', 'reset_password'] as $action) {
            $this->assertSame('Incorrect user', $this->postAjax($this->admin, '/users/ajax', ['action' => $action])->json()['msg'], $action);
            $this->assertSame('User not found', $this->postAjax($this->admin, '/users/ajax', ['action' => $action, 'user_id' => 999999])->json()['msg'], $action);
        }
        $this->assertSame('Not enough permissions', $this->postAjax($agent, '/users/ajax', ['action' => 'reset_password', 'user_id' => $agent->id])->json()['msg']);
        $this->assertCount(0, $this->sentEmailsTo($agent->email));
    }

    public function testResetLinkThatCantBeSentIsAnError()
    {
        $agent = $this->createUser();
        $this->failMailSending();

        $response = $this->postAjax($this->admin, '/users/ajax', ['action' => 'reset_password', 'user_id' => $agent->id]);

        $response->assertStatus(200);
        $this->assertSame('error', $response->json()['status']);
        $this->assertNotEmpty($response->json()['msg']);
    }

    // Other ajax actions.

    public function testPhotoAndDeleteOfUnknownUser()
    {
        foreach (['delete_photo', 'delete_user'] as $action) {
            $response = $this->postAjax($this->admin, '/users/ajax', ['action' => $action, 'user_id' => 999999])->json();
            $this->assertSame(['status' => 'error', 'msg' => 'User not found'], $response, $action);
        }
    }

    public function testUnknownActionGoesToModules()
    {
        $this->assertSame(['status' => 'error', 'msg' => 'Unknown action'], $this->postAjax($this->admin, '/users/ajax', ['action' => 'module_action'])->json());

        \Eventy::addFilter('users.ajax.response_default', function ($response, $request) {
            if ($request->action == 'module_action') {
                $response = ['status' => 'success', 'msg' => '', 'answer' => 42];
            }

            return $response;
        }, 20, 2);

        $this->assertSame(['status' => 'success', 'msg' => '', 'answer' => 42], $this->postAjax($this->admin, '/users/ajax', ['action' => 'module_action'])->json());
    }

    // Own pages.

    public function testOnlyOwnPreferencesCanBeSaved()
    {
        $agent = $this->createUser();

        $this->postForm($this->admin, '/users/preferences/'.$agent->id, ['after_send' => \App\MailboxUser::AFTER_SEND_NEXT])->assertStatus(403);
        $this->assertNull($agent->fresh()->after_send);
    }

    public function testNewPasswordMustDiffer()
    {
        $agent = $this->createUser(['password' => \Hash::make('old-password')]);

        $this->postForm($agent, '/users/password/'.$agent->id, [
            'password_current' => 'old-password', 'password' => 'old-password', 'password_confirmation' => 'old-password',
        ])->assertRedirect(route('users.password', ['id' => $agent->id]))->assertSessionHasErrors('password');

        $this->assertCount(0, $this->sentEmailsTo($agent->email));
    }
}
