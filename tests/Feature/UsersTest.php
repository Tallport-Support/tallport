<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\SendLog;
use App\Subscription;
use App\Thread;
use App\User;
use Tests\FeatureTestCase;

/**
 * Managing users: the list, creating users and inviting them, profiles,
 * roles, mailbox access and permissions, notification settings, passwords
 * and deleting users.
 *
 * User::sendInvite() and User::sendPasswordChanged() declare a global
 * function each time they run, so a second call in one process fails with
 * "Cannot redeclare". Tests that trigger them run in their own process.
 */
class UsersTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([], ['name' => 'Support']);
    }

    /**
     * POST a regular (non-ajax) form.
     */
    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    // Listing and creating.

    public function testOnlyAdminsAndUserManagersSeeTheUserList()
    {
        $agent = $this->createUser(['first_name' => 'Listed']);

        $this->actingAs($this->admin)->get('/users')->assertStatus(200)->assertSee('Listed');
        $this->actingAs($agent)->get('/users')->assertStatus(403);

        $manager = $this->createUser(['permissions' => [User::PERM_EDIT_USERS => true]]);
        $this->actingAs($manager)->get('/users')->assertStatus(200);
    }

    public function testAdminCreatesUserWithMailboxAccess()
    {
        $response = $this->postForm($this->admin, '/users/wizard', [
            'first_name' => 'New',
            'last_name'  => 'Agent',
            'email'      => 'New.Agent@Example.org',
            'role'       => User::ROLE_USER,
            'password'   => 'initial-password',
            'mailboxes'  => [$this->mailbox->id],
        ]);

        $user = User::where('email', 'new.agent@example.org')->first();
        $this->assertNotNull($user, 'The user was not created (or the email not lowercased).');
        $response->assertRedirect(route('users.profile', ['id' => $user->id]));
        $this->assertEquals(User::ROLE_USER, $user->role);
        $this->assertEquals(User::STATUS_ACTIVE, $user->status);
        $this->assertTrue(\Hash::check('initial-password', $user->password));
        $this->assertEquals([$this->mailbox->id], $user->mailboxes()->pluck('mailboxes.id')->all());
        $this->assertSame(2, Folder::where('user_id', $user->id)->where('mailbox_id', $this->mailbox->id)->count(), 'Mine and Starred folders.');
        $this->assertSame(8, Subscription::where('user_id', $user->id)->count(), 'Default notification subscriptions.');
    }

    public function testCreateUserValidation()
    {
        $existing = $this->createUser();

        $this->postForm($this->admin, '/users/wizard', [
            'first_name' => 'Dup', 'email' => $existing->email, 'role' => User::ROLE_USER, 'password' => 'x',
        ])->assertSessionHasErrors('email');

        $this->postForm($this->admin, '/users/wizard', [
            'first_name' => 'Clash', 'email' => $this->mailbox->email, 'role' => User::ROLE_USER, 'password' => 'x',
        ])->assertSessionHasErrors('email');

        $this->postForm($this->admin, '/users/wizard', [
            'email' => 'nameless@example.org', 'role' => User::ROLE_USER, 'password' => 'x',
        ])->assertSessionHasErrors('first_name');

        $this->assertNull(User::where('email', 'nameless@example.org')->first());
    }

    public function testUserManagerCanOnlyCreatePlainUsers()
    {
        $manager = $this->createUser(['permissions' => [User::PERM_EDIT_USERS => true]]);

        $this->postForm($manager, '/users/wizard', [
            'first_name' => 'Would-be',
            'email'      => 'would-be-admin@example.org',
            'role'       => User::ROLE_ADMIN,
            'password'   => 'initial-password',
        ]);

        $this->assertEquals(User::ROLE_USER, User::where('email', 'would-be-admin@example.org')->value('role'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testInviteNewUser()
    {
        $this->postForm($this->admin, '/users/wizard', [
            'first_name'  => 'Invited',
            'email'       => 'invited@example.org',
            'role'        => User::ROLE_USER,
            'send_invite' => 1,
        ]);

        $user = User::where('email', 'invited@example.org')->first();
        $this->assertEquals(User::INVITE_STATE_SENT, $user->invite_state);
        $this->assertSame(60, strlen($user->invite_hash));

        $emails = $this->sentEmailsTo('invited@example.org');
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('/user-setup/'.$user->invite_hash.'/', $emails[0]->getBody());

        $log = SendLog::where('user_id', $user->id)->first();
        $this->assertEquals(SendLog::MAIL_TYPE_INVITE, $log->mail_type);
        $this->assertEquals(SendLog::STATUS_ACCEPTED, $log->status);
    }

    public function testInvitedUserSetsUpAccount()
    {
        $user = $this->createUser(['email' => 'invited@example.org']);
        $user->invite_state = User::INVITE_STATE_SENT;
        $user->invite_hash = \Str::random(60);
        $user->save();
        $setup_url = $user->urlSetup();

        $this->get($setup_url)->assertStatus(200)->assertSee('invited@example.org');

        \Session::start();
        $response = $this->post($setup_url, [
            '_token'                => csrf_token(),
            'email'                 => 'invited@example.org',
            'password'              => 'chosen-password',
            'password_confirmation' => 'chosen-password',
            'timezone'              => 'Europe/Amsterdam',
            'time_format'           => User::TIME_FORMAT_24,
        ]);

        $response->assertRedirect(route('dashboard'));
        $user->refresh();
        $this->assertTrue(\Hash::check('chosen-password', $user->password));
        $this->assertEquals(User::INVITE_STATE_ACTIVATED, $user->invite_state);
        $this->assertSame('', (string)$user->invite_hash);
        $this->assertSame('Europe/Amsterdam', $user->timezone);
        $this->assertAuthenticatedAs($user);
    }

    public function testExpiredOrInvalidInviteLinkIsRefused()
    {
        $user = $this->createUser();
        $user->invite_state = User::INVITE_STATE_SENT;
        $user->invite_hash = \Str::random(60);
        $user->save();
        $expired = route('user_setup', [
            'hash'           => $user->invite_hash,
            'invite_sent_at' => \Helper::encrypt((string)(time() - 8 * 86400), $user->password),
        ]);
        $fields = ['email' => $user->email, 'password' => 'chosen-password', 'password_confirmation' => 'chosen-password', 'timezone' => 'UTC', 'time_format' => User::TIME_FORMAT_24];

        \Session::start();
        $this->post($expired, $fields + ['_token' => csrf_token()])->assertStatus(403);
        $this->post('/user-setup/'.str_repeat('x', 60).'/whatever', $fields + ['_token' => csrf_token()])->assertStatus(404);
        $this->assertFalse(\Hash::check('chosen-password', $user->fresh()->password));
    }

    // Profiles and roles.

    public function testUserEditsOwnProfileButNotRoleOrEmail()
    {
        $agent = $this->createUser(['email' => 'agent@example.org']);

        $response = $this->postForm($agent, '/users/profile/'.$agent->id, [
            'first_name'  => 'Renamed',
            'last_name'   => 'Agent',
            'email'       => 'changed@example.org',
            'job_title'   => 'Support lead',
            'timezone'    => 'Europe/Amsterdam',
            'time_format' => User::TIME_FORMAT_24,
            'role'        => User::ROLE_ADMIN,
        ]);

        $response->assertRedirect(route('users.profile', ['id' => $agent->id]));
        $agent->refresh();
        $this->assertSame('Renamed', $agent->first_name);
        $this->assertSame('Support lead', $agent->job_title);
        $this->assertSame('agent@example.org', $agent->email, 'Users cannot change their own email.');
        $this->assertEquals(User::ROLE_USER, $agent->role, 'Users cannot make themselves admin.');
    }

    public function testUserCannotEditSomeoneElse()
    {
        $agent = $this->createUser();
        $other = $this->createUser();

        $this->actingAs($agent)->get('/users/profile/'.$other->id)->assertStatus(403);
        $this->postForm($agent, '/users/profile/'.$other->id, [
            'first_name' => 'Hacked', 'email' => $other->email, 'timezone' => 'UTC', 'time_format' => User::TIME_FORMAT_24,
        ])->assertStatus(403);
        $this->assertNotSame('Hacked', $other->fresh()->first_name);
    }

    public function testAdminPromotesAndDisablesUser()
    {
        $agent = $this->createUser();
        $profile = ['first_name' => $agent->first_name, 'email' => $agent->email, 'timezone' => 'UTC', 'time_format' => User::TIME_FORMAT_24];

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $profile + ['role' => User::ROLE_ADMIN]);
        $this->assertEquals(User::ROLE_ADMIN, $agent->fresh()->role);

        $this->postForm($this->admin, '/users/profile/'.$agent->id, $profile + ['role' => User::ROLE_ADMIN, 'disabled' => 1]);
        $this->assertEquals(User::STATUS_DISABLED, $agent->fresh()->status);

        // A disabled user is logged out on their next request.
        $this->actingAs($agent->fresh())->get('/')->assertRedirect(route('login'));
    }

    public function testOnlyAdminCannotBeDemoted()
    {
        $response = $this->postForm($this->admin, '/users/profile/'.$this->admin->id, [
            'first_name' => $this->admin->first_name, 'email' => $this->admin->email,
            'timezone'   => 'UTC', 'time_format' => User::TIME_FORMAT_24, 'role' => User::ROLE_USER,
        ]);

        $response->assertSessionHasErrors('role');
        $this->assertEquals(User::ROLE_ADMIN, $this->admin->fresh()->role);
    }

    /**
     * Disabled or deleted admins don't count: the last active admin can be
     * neither demoted nor disabled (U2).
     */
    public function testLastActiveAdminCannotBeDemotedOrDisabled()
    {
        $this->createAdmin(['status' => User::STATUS_DISABLED]);
        $profile = [
            'first_name' => $this->admin->first_name, 'email' => $this->admin->email,
            'timezone'   => 'UTC', 'time_format' => User::TIME_FORMAT_24,
        ];

        $this->postForm($this->admin, '/users/profile/'.$this->admin->id, $profile + ['role' => User::ROLE_USER])
            ->assertSessionHasErrors('role');
        $this->postForm($this->admin, '/users/profile/'.$this->admin->id, $profile + ['role' => User::ROLE_ADMIN, 'disabled' => 1])
            ->assertSessionHasErrors('disabled');

        $admin = $this->admin->fresh();
        $this->assertEquals(User::ROLE_ADMIN, $admin->role);
        $this->assertEquals(User::STATUS_ACTIVE, $admin->status);
    }

    public function testAdminCanBeDisabledWhileAnotherIsActive()
    {
        $other = $this->createAdmin();

        $this->postForm($this->admin, '/users/profile/'.$other->id, [
            'first_name' => $other->first_name, 'email' => $other->email, 'timezone' => 'UTC',
            'time_format' => User::TIME_FORMAT_24, 'role' => User::ROLE_ADMIN, 'disabled' => 1,
        ]);

        $this->assertNull(session('errors'));

        $this->assertEquals(User::STATUS_DISABLED, $other->fresh()->status);
    }

    // Access and settings.

    public function testAdminSetsMailboxAccessAndPermissions()
    {
        $agent = $this->createUser();

        $response = $this->postForm($this->admin, '/users/permissions/'.$agent->id, [
            'mailboxes'        => [$this->mailbox->id],
            'user_permissions' => [User::PERM_DELETE_CONVERSATIONS],
        ]);

        $response->assertRedirect(route('users.permissions', ['id' => $agent->id]));
        $agent->refresh();
        $this->assertEquals([$this->mailbox->id], $agent->mailboxes()->pluck('mailboxes.id')->all());
        $this->assertTrue($agent->hasPermission(User::PERM_DELETE_CONVERSATIONS));
        $this->assertFalse($agent->hasPermission(User::PERM_EDIT_USERS));

        $this->actingAs($agent)->get('/users/permissions/'.$agent->id)->assertStatus(403);
    }

    /**
     * "Only assigned tickets" is set on the profile page; saving the
     * permissions page must not clear it (U3).
     */
    public function testSavingPermissionsKeepsOnlyAssignedRestriction()
    {
        $agent = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => 1]]);
        $this->mailbox->users()->attach($agent->id);
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email]));
        $unassigned = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->actingAs($agent)->get('/conversation/'.$unassigned->id.'?folder_id='.$unassigned->folder_id)->assertStatus(403);

        $this->postForm($this->admin, '/users/permissions/'.$agent->id, [
            'mailboxes'        => [$this->mailbox->id],
            'user_permissions' => [User::PERM_DELETE_CONVERSATIONS],
        ]);

        $agent = $agent->fresh();
        $this->assertTrue($agent->hasPermission(User::PERM_DELETE_CONVERSATIONS));
        $this->assertTrue($agent->hasPermission(User::PERM_ONLY_ASSIGNED_TICKETS), 'The restriction was lost.');
        \Cache::flush();
        $this->actingAs($agent)->get('/conversation/'.$unassigned->id.'?folder_id='.$unassigned->folder_id)->assertStatus(403);
    }

    public function testNotificationSettingsReplaceSubscriptions()
    {
        $agent = $this->createUser();

        $this->postForm($agent, '/users/notifications/'.$agent->id, [
            'subscriptions' => [Subscription::MEDIUM_EMAIL => [Subscription::EVENT_TYPE_NEW]],
        ])->assertRedirect(route('users.notifications', ['id' => $agent->id]));

        $this->assertEquals(
            [[Subscription::MEDIUM_EMAIL, Subscription::EVENT_TYPE_NEW]],
            Subscription::where('user_id', $agent->id)->get()->map(function ($s) {
                return [$s->medium, $s->event];
            })->all()
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testChangeOwnPassword()
    {
        $agent = $this->createUser(['password' => \Hash::make('old-password')]);

        $this->postForm($agent, '/users/password/'.$agent->id, [
            'password_current' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('password_current');
        $this->assertTrue(\Hash::check('old-password', $agent->fresh()->password));

        $this->postForm($agent, '/users/password/'.$agent->id, [
            'password_current' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertRedirect(route('users.profile', ['id' => $agent->id]));
        $this->assertTrue(\Hash::check('new-password', $agent->fresh()->password));

        $emails = $this->sentEmailsTo($agent->email);
        $this->assertCount(1, $emails);
        $this->assertSame('Password Changed', $emails[0]->getSubject());
    }

    public function testNobodyChangesAnotherUsersPassword()
    {
        $agent = $this->createUser();

        $this->postForm($this->admin, '/users/password/'.$agent->id, [
            'password_current' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertStatus(403);
    }

    public function testAdminSendsPasswordResetLink()
    {
        $agent = $this->createUser();

        $response = $this->postAjax($this->admin, '/users/ajax', ['action' => 'reset_password', 'user_id' => $agent->id]);

        $this->assertSame('success', $response->json()['status']);
        $this->assertTrue(\DB::table('password_resets')->where('email', $agent->email)->exists());
        $emails = $this->sentEmailsTo($agent->email);
        $this->assertCount(1, $emails);
        $this->assertStringContainsString('/password/reset/', $emails[0]->getBody());
    }

    // Deleting.

    public function testDeleteUserUnassignsTheirConversations()
    {
        $agent = $this->createUser(['first_name' => 'Leaving']);
        $this->mailbox->users()->attach($agent->id);
        $this->mailbox->syncPersonalFolders([$agent->id]);
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->postAjax($agent, '/conversation/ajax', ['action' => 'conversation_change_user', 'conversation_id' => $conversation->id, 'user_id' => $agent->id]);
        $email = $agent->email;

        $response = $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $agent->id]);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $agent->refresh();
        $this->assertEquals(User::STATUS_DELETED, $agent->status);
        $this->assertStringStartsWith($email.'_deleted', $agent->email, 'The address is freed for reuse.');
        $this->assertSame(0, $agent->mailboxes()->count());
        $conversation->refresh();
        $this->assertNull($conversation->user_id);
        $this->assertEquals(Folder::TYPE_UNASSIGNED, Folder::find($conversation->folder_id)->type);
        $this->assertEquals(Thread::ACTION_TYPE_USER_CHANGED, $conversation->threads()->orderBy('id', 'desc')->first()->action_type);
    }

    public function testCannotDeleteYourselfAndOnlyAdminsDelete()
    {
        $agent = $this->createUser();

        $this->assertSame('Not enough permissions', $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $this->admin->id])->json()['msg']);
        $this->assertSame('Not enough permissions', $this->postAjax($agent, '/users/ajax', ['action' => 'delete_user', 'user_id' => $this->admin->id])->json()['msg']);

        $this->assertEquals(User::STATUS_ACTIVE, $this->admin->fresh()->status);
    }
}
