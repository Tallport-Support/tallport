<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\SendLog;
use App\Subscription;
use App\Thread;
use App\Livewire\UserPermissions;
use App\Livewire\UserProfile;
use App\User;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Managing users: the list, creating users and inviting them, profiles,
 * roles, mailbox access and permissions, notification settings, passwords
 * and deleting users.
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

    /**
     * The email is normalised and can't be a mailbox address, as when an
     * admin edits the profile (U10).
     */
    public function testInviteSetupNormalisesAndChecksEmail()
    {
        $user = $this->createUser(['email' => 'invited@example.org']);
        $user->invite_state = User::INVITE_STATE_SENT;
        $user->invite_hash = \Str::random(60);
        $user->save();
        $setup_url = $user->urlSetup();
        $fields = ['password' => 'chosen-password', 'password_confirmation' => 'chosen-password', 'timezone' => 'UTC', 'time_format' => User::TIME_FORMAT_24];

        \Session::start();
        $this->post($setup_url, $fields + ['_token' => csrf_token(), 'email' => $this->mailbox->email])->assertSessionHasErrors('email');
        $this->assertSame('invited@example.org', $user->fresh()->email);

        $this->post($setup_url, $fields + ['_token' => csrf_token(), 'email' => 'Invited.Person@Example.org']);
        $this->assertSame('invited.person@example.org', $user->fresh()->email);
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

    public function testNewUserPasswordNeedsEightCharacters()
    {
        $this->postForm($this->admin, '/users/wizard', [
            'first_name' => 'Short', 'email' => 'short-password@example.org', 'role' => User::ROLE_USER, 'password' => 'seven77',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'short-password@example.org')->first(), 'Minimum password length is 8 everywhere (U5).');
    }

    /**
     * A user manager only grants their own mailboxes; the new user's
     * personal folders must match (U6).
     */
    public function testUserManagerCreatesFoldersOnlyForGrantedMailboxes()
    {
        $manager = $this->createUser(['permissions' => [User::PERM_EDIT_USERS => true]]);
        $this->mailbox->users()->attach($manager->id);
        $other_mailbox = $this->createMailbox([], ['name' => 'Not the manager\'s']);

        $this->postForm($manager, '/users/wizard', [
            'first_name' => 'Managed', 'email' => 'managed@example.org', 'password' => 'initial-password',
            'mailboxes'  => [$this->mailbox->id, $other_mailbox->id],
        ]);

        $user = User::where('email', 'managed@example.org')->first();
        $this->assertEquals([$this->mailbox->id], $user->mailboxes()->pluck('mailboxes.id')->all());
        $this->assertSame(0, Folder::where('user_id', $user->id)->where('mailbox_id', $other_mailbox->id)->count());
    }

    public function testNoInvitesOrResetLinksForDisabledUsers()
    {
        $disabled = $this->createUser(['status' => User::STATUS_DISABLED]);
        $disabled->invite_state = User::INVITE_STATE_SENT;
        $disabled->save();

        $this->assertSame('error', $this->postAjax($this->admin, '/users/ajax', ['action' => 'send_invite', 'user_id' => $disabled->id])->json()['status']);
        $this->assertSame('error', $this->postAjax($this->admin, '/users/ajax', ['action' => 'reset_password', 'user_id' => $disabled->id])->json()['status']);
        $this->assertCount(0, $this->sentEmailsTo($disabled->email), 'Disabled users get no invites or reset links (U8).');
    }

    public function testDeletedUsersPermissionsCannotBeSaved()
    {
        $agent = $this->createUser();
        $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $agent->id]);

        Livewire::actingAs($this->admin)->test(UserPermissions::class, ['user' => $agent])->assertStatus(404);
        Livewire::actingAs($this->admin)->test(UserProfile::class, ['user' => $agent])->assertStatus(404);
        $this->assertSame(0, $agent->fresh()->mailboxes()->count(), 'Like the permissions page, which is 404 for deleted users (U9).');
    }

    // Profiles and roles.

    public function testUserEditsOwnProfileButNotRoleOrEmail()
    {
        $agent = $this->createUser(['email' => 'agent@example.org']);

        // One's own Profile has no email or role to change.
        Livewire::actingAs($agent)->test(UserProfile::class, ['user' => $agent, 'mode' => 'own'])
            ->set('first_name', 'Renamed')->set('job_title', 'Support lead')
            ->set('email', 'changed@example.org')->set('role', (string) User::ROLE_ADMIN)
            ->assertSet('email', 'agent@example.org');
        // Nor anyone else's, without managing users.
        Livewire::actingAs($agent)->test(UserProfile::class, ['user' => $agent, 'mode' => 'own'])->assertSeeHtml('id="email"');

        $agent->refresh();
        $this->assertSame('Renamed', $agent->first_name);
        $this->assertSame('Support lead', $agent->job_title);
        $this->assertSame('agent@example.org', $agent->email, 'Users cannot change their own email.');
        $this->assertEquals(User::ROLE_USER, $agent->role, 'Users cannot make themselves admin.');
    }

    /**
     * One's own settings are the same for everyone, at addresses without a user id; managing
     * someone (oneself too) is in Settings › Users. Old addresses of one's own pages lead there.
     */
    public function testOwnSettingsAndManagingUsersAreApart()
    {
        $agent = $this->createUser();

        $this->actingAs($this->admin)->get(route('own.preferences'))->assertOk()
            ->assertSee('>Preferences</h2>', false)->assertSee(route('own.security'), false)->assertDontSee('New User');
        $this->actingAs($this->admin)->get(route('own.profile'))->assertOk()
            ->assertSee('>Profile</h2>', false)->assertDontSee('>Users</h2>', false)->assertSee(route('own.password'), false)
            ->assertDontSee('id="role"', false);
        foreach (['users.profile' => 'own.profile', 'users.preferences' => 'own.preferences', 'users.notifications' => 'own.notifications', 'users.password' => 'own.password'] as $old => $own) {
            $this->actingAs($agent)->get(route($old, ['id' => $agent->id]))->assertRedirect(route($own));
        }

        // Someone else: Users, then the person, managed; no notifications of theirs to set.
        $this->actingAs($this->admin)->get(route('users.profile', ['id' => $agent->id]))->assertOk()
            ->assertSeeInOrder(['>Users</h2>', '>'.e($agent->getFullName()).'</h2>', 'Account', 'id="role"'], false)
            ->assertSee(route('users.permissions', ['id' => $agent->id]), false)->assertSee(route('users.security', ['id' => $agent->id]), false)
            ->assertDontSee(route('users.notifications', ['id' => $agent->id]), false)->assertDontSee('id="job_title"', false);
        $this->actingAs($this->admin)->get(route('users.notifications', ['id' => $agent->id]))->assertForbidden();

        // Oneself in Users: like anyone, marked, with what would lock one out turned off.
        $this->actingAs($this->admin)->get(route('users'))->assertOk()->assertSee('(You)');
        $current = fn ($href, $value) => '#<a (?=[^>]*href="'.preg_quote($href, '#').'")[^>]*aria-current="'.$value.'"#';
        $this->assertMatchesRegularExpression($current(route('users'), 'true'), $this->actingAs($this->admin)->get(route('users.profile', ['id' => $this->admin->id]))->getContent());
        // Profile › Change Your Password: Profile leads there.
        $this->assertMatchesRegularExpression($current(route('own.profile'), 'true'), $this->actingAs($this->admin)->get(route('own.password'))->getContent());
        $this->assertMatchesRegularExpression($current(route('own.profile'), 'page'), $this->actingAs($this->admin)->get(route('own.profile'))->getContent());
        $this->actingAs($this->admin)->get(route('users.profile', ['id' => $this->admin->id]))->assertOk()
            ->assertSee('>Users</h2>', false)->assertSee("You can't change your own role.")->assertSee("You can't turn off your own sign-in.")
            ->assertSee("You can't remove yourself.")->assertSee(route('own.profile'), false)
            ->assertDontSee(route('users.security', ['id' => $this->admin->id]), false)->assertDontSee('Reset Password');
    }

    public function testUserCannotEditSomeoneElse()
    {
        $agent = $this->createUser();
        $other = $this->createUser();

        $this->actingAs($agent)->get('/users/profile/'.$other->id)->assertStatus(403);
        Livewire::actingAs($agent)->test(UserProfile::class, ['user' => $other])->assertStatus(403);
        $this->assertNotSame('Hacked', $other->fresh()->first_name);
    }

    public function testAdminPromotesAndDisablesUser()
    {
        $agent = $this->createUser();

        $profile = Livewire::actingAs($this->admin)->test(UserProfile::class, ['user' => $agent])->set('role', (string) User::ROLE_ADMIN);
        $this->assertEquals(User::ROLE_ADMIN, $agent->fresh()->role);

        $profile->set('disabled', true);
        $this->assertEquals(User::STATUS_DISABLED, $agent->fresh()->status);

        // A disabled user is logged out on their next request.
        $this->actingAs($agent->fresh())->get('/')->assertRedirect(route('login'));
    }

    /**
     * Nobody demotes, disables or deletes themselves, so an active admin always remains (U2).
     */
    public function testAdminCannotLockThemselvesOut()
    {
        $this->createAdmin(['status' => User::STATUS_DISABLED]);

        Livewire::actingAs($this->admin)->test(UserProfile::class, ['user' => $this->admin])
            ->set('role', (string) User::ROLE_USER)->assertHasErrors(['role' => "You can't change your own role."])->assertSet('role', (string) User::ROLE_ADMIN)
            ->set('disabled', true)->assertHasErrors('disabled')->assertSet('disabled', false);
        $this->assertFalse($this->admin->can('delete', $this->admin));

        $admin = $this->admin->fresh();
        $this->assertEquals(User::ROLE_ADMIN, $admin->role);
        $this->assertEquals(User::STATUS_ACTIVE, $admin->status);
    }

    public function testAdminCanBeDisabledWhileAnotherIsActive()
    {
        $other = $this->createAdmin();

        Livewire::actingAs($this->admin)->test(UserProfile::class, ['user' => $other])->set('disabled', true)->assertHasNoErrors();

        $this->assertEquals(User::STATUS_DISABLED, $other->fresh()->status);
    }

    /**
     * Modules see a saved profile as the form used to send it (user.save_profile).
     */
    public function testModulesSeeTheSavedProfile()
    {
        $agent = $this->createUser();
        $seen = null;
        \Eventy::addFilter('user.save_profile', function ($user, $request) use (&$seen) {
            $seen = [$request->first_name, $request->job_title];
            $user->job_title = 'From a module';

            return $user;
        }, 20, 2);

        Livewire::actingAs($this->admin)->test(UserProfile::class, ['user' => $agent])->set('first_name', 'Robin');

        $this->assertSame(['Robin', ''], $seen);
        $this->assertSame('From a module', $agent->fresh()->job_title);
    }

    // Access and settings.

    /**
     * Settings › Users › the person › Permissions: each change saved as it's made.
     */
    public function testAdminSetsMailboxAccessAndPermissions()
    {
        $agent = $this->createUser();
        $sales = $this->createMailbox([], ['name' => 'Sales']);

        $component = Livewire::actingAs($this->admin)->test(UserPermissions::class, ['user' => $agent])
            ->set('mailboxes', [(string) $this->mailbox->id])
            ->set('permissions.'.User::PERM_DELETE_CONVERSATIONS, '1');

        $agent->refresh();
        $this->assertEquals([$this->mailbox->id], $agent->mailboxes()->pluck('mailboxes.id')->all());
        $this->assertTrue(Folder::where('user_id', $agent->id)->where('mailbox_id', $this->mailbox->id)->exists(), 'Its personal folders.');
        $this->assertTrue($agent->hasPermission(User::PERM_DELETE_CONVERSATIONS));
        $this->assertFalse($agent->hasPermission(User::PERM_EDIT_USERS));

        // A mailbox added keeps the other's settings for the user; All and None.
        $agent->mailboxes()->updateExistingPivot($this->mailbox->id, ['hide' => true]);
        $component->set('mailboxes', [(string) $this->mailbox->id, (string) $sales->id]);
        $this->assertTrue((bool) $agent->mailboxesWithSettings()->where('mailboxes.id', $this->mailbox->id)->first()->settings->hide);
        $component->call('selectAllMailboxes', false);
        $this->assertSame(0, $agent->mailboxes()->count());
        $component->call('selectAllMailboxes', true)->assertSet('mailboxes', \App\Mailbox::pluck('id')->map(fn ($id) => (string) $id)->all());

        // Only known permissions and values.
        $component->set('permissions.'.User::PERM_EDIT_USERS, 'yes')->assertSet('permissions.'.User::PERM_EDIT_USERS, '');
        $this->assertFalse($agent->fresh()->hasPermission(User::PERM_EDIT_USERS));

        Livewire::actingAs($agent)->test(UserPermissions::class, ['user' => $agent])->assertStatus(403);
        $this->actingAs($agent)->get('/users/permissions/'.$agent->id)->assertStatus(403);
    }

    /**
     * The page: Users, the person and Permissions as levels, the open rows current; no Save.
     */
    public function testPermissionsLevels()
    {
        $agent = $this->createUser(['first_name' => 'Robin', 'last_name' => 'Ray']);
        $current = fn ($href, $value) => '#<a (?=[^>]*href="'.preg_quote($href, '#').'")[^>]*aria-current="'.$value.'"#';

        $html = $this->actingAs($this->admin)->get('/users/permissions/'.$agent->id)->assertOk()
            ->assertSee('f-drilldown', false)->assertSeeInOrder(['>Users</h2>', '>Robin Ray</h2>', '>Permissions</h2>'], false)
            ->assertDontSee('Save Permissions')->getContent();
        $this->assertMatchesRegularExpression($current(route('users'), 'true'), $html);
        $this->assertMatchesRegularExpression($current(route('users.profile', ['id' => $agent->id]), 'true'), $html);
        $this->assertMatchesRegularExpression($current(route('users.permissions', ['id' => $agent->id]), 'page'), $html);
        $this->assertStringContainsString('0 mailboxes', $html);
    }

    /**
     * Allowed and Not Allowed stay the user's own, whatever the default (Settings » General)
     * was or becomes; Default follows it.
     */
    public function testOwnPermissionsOutlastTheDefault()
    {
        $agent = $this->createUser();
        $defaults = fn (...$permissions) => config(['app.user_permissions' => base64_encode(json_encode($permissions))]);
        $defaults(User::PERM_EDIT_KB, User::PERM_DELETE_CONVERSATIONS);

        $this->actingAs($this->admin)->get('/users/permissions/'.$agent->id)->assertOk()
            ->assertSee('Default (Allowed)')->assertSee('Default (Not Allowed)');

        // Allowed though it's the default too; Not Allowed against it; Default.
        $component = Livewire::actingAs($this->admin)->test(UserPermissions::class, ['user' => $agent])
            ->set('permissions.'.User::PERM_EDIT_KB, '1')
            ->set('permissions.'.User::PERM_DELETE_CONVERSATIONS, '0')
            ->set('permissions.'.User::PERM_EDIT_SAVED_REPLIES, '');
        $permissions = $agent->fresh()->permissions;
        ksort($permissions);
        $this->assertSame([User::PERM_DELETE_CONVERSATIONS => 0, User::PERM_EDIT_KB => 1], $permissions);
        $agent->refresh();

        $defaults(User::PERM_EDIT_SAVED_REPLIES);
        $this->assertTrue($agent->hasPermission(User::PERM_EDIT_KB));
        $this->assertFalse($agent->hasPermission(User::PERM_DELETE_CONVERSATIONS));
        $this->assertTrue($agent->hasPermission(User::PERM_EDIT_SAVED_REPLIES));

        // Shown as chosen.
        Livewire::actingAs($this->admin)->test(UserPermissions::class, ['user' => $agent->fresh()])
            ->assertSet('permissions.'.User::PERM_EDIT_KB, '1')->assertSet('permissions.'.User::PERM_DELETE_CONVERSATIONS, '0')
            ->assertSet('permissions.'.User::PERM_EDIT_SAVED_REPLIES, '');

        // Back to Default.
        $component->set('permissions.'.User::PERM_EDIT_KB, '');
        $this->assertFalse($agent->fresh()->hasPermission(User::PERM_EDIT_KB));
    }

    /**
     * "Only assigned tickets" is set on the profile page; changing permissions
     * must not clear it (U3).
     */
    public function testSavingPermissionsKeepsOnlyAssignedRestriction()
    {
        $agent = $this->createUser(['permissions' => [User::PERM_ONLY_ASSIGNED_TICKETS => 1]]);
        $this->mailbox->users()->attach($agent->id);
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email]));
        $unassigned = \App\Conversation::where('mailbox_id', $this->mailbox->id)->first();
        $this->actingAs($agent)->get('/conversation/'.$unassigned->id.'?folder_id='.$unassigned->folder_id)->assertStatus(403);

        Livewire::actingAs($this->admin)->test(UserPermissions::class, ['user' => $agent])
            ->set('permissions.'.User::PERM_DELETE_CONVERSATIONS, '1');

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
        ])->assertRedirect(route('own.notifications'));

        $this->assertEquals(
            [[Subscription::MEDIUM_EMAIL, Subscription::EVENT_TYPE_NEW]],
            Subscription::where('user_id', $agent->id)->get()->map(function ($s) {
                return [$s->medium, $s->event];
            })->all()
        );
        // Saved as it changes: the whole form, in the background.
        $this->assertSame('success', $this->postAjax($agent, '/users/notifications/'.$agent->id, [
            'subscriptions' => [Subscription::MEDIUM_BROWSER => [Subscription::EVENT_TYPE_NEW]],
        ])->json('status'));
        $this->assertSame([Subscription::MEDIUM_BROWSER], Subscription::where('user_id', $agent->id)->pluck('medium')->all());
        $this->actingAs($agent)->get(route('own.notifications'))->assertOk()
            ->assertSee('Tallport.autoSave($el)', false)->assertDontSee('Save Notifications')->assertDontSee('>Users</h2>', false);

        // Each checkbox named by its column and row, as a screen reader reads it.
        $this->actingAs($agent)->get(route('own.notifications'))
            ->assertSee('<span id="subs-medium-email">Email</span>', false)
            ->assertSee('<th scope="row" id="subs-event-'.Subscription::EVENT_NEW_CONVERSATION.'">There is a new conversation</th>', false)
            ->assertSee('aria-labelledby="subs-medium-browser subs-event-'.Subscription::EVENT_NEW_CONVERSATION.'"', false);

        // Nobody sets another person's.
        $this->actingAs($this->admin)->get('/users/notifications/'.$agent->id)->assertForbidden();
        $this->postForm($this->admin, '/users/notifications/'.$agent->id, ['subscriptions' => []])->assertForbidden();
    }

    public function testChangeOwnPassword()
    {
        $agent = $this->createUser(['password' => \Hash::make('old-password')]);

        $this->postForm($agent, '/users/password/'.$agent->id, [
            'password_current' => 'wrong-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasErrors('password_current');
        $this->assertTrue(\Hash::check('old-password', $agent->fresh()->password));

        $this->postForm($agent, '/users/password/'.$agent->id, [
            'password_current' => 'old-password', 'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertRedirect(route('own.profile'));
        $this->assertTrue(\Hash::check('new-password', $agent->fresh()->password));

        $emails = $this->sentEmailsTo($agent->email);
        $this->assertCount(1, $emails);
        $this->assertSame('Password Changed', $emails[0]->getSubject());
    }

    /**
     * These used to declare global functions, so a second call in the same
     * process (e.g. a queue worker) failed with "Cannot redeclare" (U11).
     */
    public function testUserEmailsAndLogsPageWorkRepeatedly()
    {
        $first = $this->createUser();
        $second = $this->createUser();
        foreach ([$first, $second] as $user) {
            // Invites go only to users who haven't set up their account.
            $user->invite_state = User::INVITE_STATE_NOT_INVITED;
            $user->save();
        }

        $first->sendInvite();
        $second->sendInvite();
        $first->sendPasswordChanged();
        $second->sendPasswordChanged();

        $this->assertEquals(
            [\App\SendLog::MAIL_TYPE_INVITE, \App\SendLog::MAIL_TYPE_PASSWORD_CHANGED],
            \App\SendLog::where('email', $first->email)->orderBy('id')->pluck('mail_type')->all()
        );
        $this->actingAs($this->admin)->get('/app-logs/out_emails')->assertStatus(200);
        $this->actingAs($this->admin)->get('/app-logs/out_emails')->assertStatus(200);
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
        \App\Misc\ConversationActions::changeUser($conversation, $agent->id, $agent, request());
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

    /**
     * Conversations of a deleted user go to the chosen colleague, but only
     * if that colleague can access the mailbox; otherwise they're unassigned (U4).
     */
    public function testDeleteUserReassignsOnlyToUsersWithAccess()
    {
        $leaving = $this->createUser();
        $colleague = $this->createUser();
        $outsider = $this->createUser();
        $this->mailbox->users()->attach([$leaving->id, $colleague->id]);
        $this->mailbox->syncPersonalFolders([$leaving->id, $colleague->id]);
        $conversations = [];
        foreach (['first', 'second'] as $n) {
            $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $n.'@customer.example.org', 'to' => $this->mailbox->email, 'subject' => $n]));
            $conversation = \App\Conversation::where('mailbox_id', $this->mailbox->id)->where('subject', $n)->first();
            \App\Misc\ConversationActions::changeUser($conversation, $leaving->id, $leaving, request());
            $conversations[] = $conversation;
        }

        $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $leaving->id, 'assign_user' => [$this->mailbox->id => $outsider->id]]);
        $this->assertNull($conversations[0]->fresh()->user_id, 'Someone without access to the mailbox must not get its conversations.');
        $this->assertNull($conversations[1]->fresh()->user_id);

        $staying = $this->createUser();
        $this->mailbox->users()->attach($staying->id);
        \App\Misc\ConversationActions::changeUser($conversations[0]->fresh(), $staying->id, $staying, request());
        $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $staying->id, 'assign_user' => [$this->mailbox->id => $colleague->id]]);
        $this->assertEquals($colleague->id, $conversations[0]->fresh()->user_id);
    }

    public function testCannotDeleteYourselfAndOnlyAdminsDelete()
    {
        $agent = $this->createUser();

        $this->assertSame('Not enough permissions', $this->postAjax($this->admin, '/users/ajax', ['action' => 'delete_user', 'user_id' => $this->admin->id])->json()['msg']);
        $this->assertSame('Not enough permissions', $this->postAjax($agent, '/users/ajax', ['action' => 'delete_user', 'user_id' => $this->admin->id])->json()['msg']);

        $this->assertEquals(User::STATUS_ACTIVE, $this->admin->fresh()->status);
    }
}
