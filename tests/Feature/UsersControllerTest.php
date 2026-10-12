<?php

namespace Tests\Feature;

use App\Mailbox;
use App\User;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * UsersController: profile details (photo, admin-only fields),
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

    protected function profileOf(User $as, User $user)
    {
        return \Livewire\Livewire::actingAs($as)->test(\App\Livewire\UserProfile::class, ['user' => $user]);
    }

    public function testPhotoIsResizedAndSaved()
    {
        $agent = $this->createUser();

        $this->profileOf($agent, $agent)->set('photo', UploadedFile::fake()->image('me.jpg', 400, 300))->assertHasNoErrors();

        $photo = $agent->fresh()->photo_url;
        $this->assertStringEndsWith('.jpg', $photo);
        \Storage::disk('local')->assertExists(User::PHOTO_DIRECTORY.'/'.$photo);

        // And removed again.
        $this->profileOf($agent, $agent->fresh())->call('deletePhoto');
        $this->assertSame('', $agent->fresh()->photo_url);
        \Storage::disk('local')->assertMissing(User::PHOTO_DIRECTORY.'/'.$photo);
    }

    public function testBrokenImageIsRejected()
    {
        $agent = $this->createUser();

        $this->profileOf($agent, $agent)->set('photo', UploadedFile::fake()->createWithContent('me.gif', 'GIF89a'))->assertHasErrors('photo');

        $this->assertNull($agent->fresh()->photo_url);
    }

    /**
     * Each field is checked and saved on its own: one that fails shows why and isn't saved, and
     * leaves the rest as they were (U7).
     */
    public function testAnInvalidFieldIsNotSaved()
    {
        $agent = $this->createUser(['first_name' => 'Robin']);
        $profile = $this->profileOf($agent, $agent)->set('photo', UploadedFile::fake()->image('me.jpg', 400, 300));
        $photo = $agent->fresh()->photo_url;

        $profile->set('first_name', '')->assertHasErrors('first_name')
            ->set('job_title', 'Support lead')->assertHasNoErrors('job_title');

        $agent->refresh();
        $this->assertSame('Robin', $agent->first_name);
        $this->assertSame('Support lead', $agent->job_title);
        $this->assertSame($photo, $agent->photo_url);
    }

    public function testEmailOfAMailboxIsRefused()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org']);

        $this->profileOf($this->admin, $agent)->set('email', $mailbox->email)->assertHasErrors('email');

        $this->assertNotSame('support@example.org', $agent->fresh()->email);
    }

    /**
     * Language, timezone and time format are the user's own preferences (users/preferences);
     * password and type aren't the profile's either: it has no such fields.
     */
    public function testOnlyTheProfilesFieldsCanBeSet()
    {
        $other = $this->createUser(['locale' => 'nl']);

        foreach (['locale' => 'de', 'timezone' => 'UTC', 'time_format' => User::TIME_FORMAT_12, 'password' => 'new-password', 'type' => 99] as $field => $value) {
            try {
                $this->profileOf($this->admin, $other)->set($field, $value);
                $this->fail($field.' was set.');
            } catch (\Livewire\Exceptions\PublicPropertyNotFoundException $e) {
                $this->assertStringContainsString($field, $e->getMessage());
            }
        }
        $this->assertSame('nl', $other->fresh()->locale);
    }

    public function testAdminRestrictsUserToAssignedConversations()
    {
        $agent = $this->createUser();

        $this->profileOf($this->admin, $agent)->set('only_assigned', true)->assertHasNoErrors();
        $this->assertTrue($agent->fresh()->canSeeOnlyAssignedConversations());

        // The user can't lift it.
        $this->profileOf($agent->fresh(), $agent->fresh())->set('only_assigned', false)->assertSet('only_assigned', true);
        $this->assertTrue($agent->fresh()->canSeeOnlyAssignedConversations());

        $this->profileOf($this->admin, $agent->fresh())->set('only_assigned', false);
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

        \Livewire\Livewire::actingAs($agent)->test(\App\Livewire\UserPermissions::class, ['user' => $agent])->assertStatus(403);
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

    public function testDeleteOfUnknownUser()
    {
        $response = $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => 999999])->json();
        $this->assertSame(['status' => 'error', 'msg' => 'User not found'], $response);
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

        $this->postForm($this->admin, '/users/preferences/'.$agent->id, ['stay_in_conversation' => 1])->assertStatus(403);
        $this->assertFalse($agent->fresh()->stay_in_conversation);
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
