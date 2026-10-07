<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Folder;
use App\Mailbox;
use App\SendLog;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Tests\FeatureTestCase;

/**
 * The User model: mailbox access and settings, permissions, dates in the
 * user's format, invitation and password emails when sending fails, photos,
 * and finding users.
 */
class UserModelTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Make every mail transport throw (as ReplySendingTest does).
     */
    protected function failSending(\Throwable $exception)
    {
        $transport = new class($exception) extends \Symfony\Component\Mailer\Transport\AbstractTransport {
            protected $exception;

            public function __construct($exception)
            {
                parent::__construct();
                $this->exception = $exception;
            }

            protected function doSend(\Symfony\Component\Mailer\SentMessage $message): void
            {
                throw $this->exception;
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
        \MailHelper::$last_mail_config_hash = '';
        $this->app->forgetInstance('mail.manager');
        $this->app->extend('mail.manager', function ($manager) use ($transport) {
            return static::captureAllMailDrivers($manager, $transport);
        });
    }

    protected function archive(Mailbox $mailbox)
    {
        $mailbox->state = Mailbox::STATE_ARCHIVED;
        $mailbox->save();
    }

    protected function setAccess(Mailbox $mailbox, User $user, array $access)
    {
        $mailbox->users()->updateExistingPivot($user->id, ['access' => json_encode($access)]);
    }

    public function testRoleName()
    {
        $this->assertSame('admin', $this->createAdmin()->getRoleName());
        $this->assertSame('Admin', $this->createAdmin()->getRoleName(true));
        $this->assertSame('User', $this->createUser()->getRoleName(true));
    }

    public function testMailboxesWithSettingsFromTheCache()
    {
        $user = $this->createUser();
        $support = $this->createMailbox([$user], ['name' => 'Support']);
        $archived = $this->createMailbox([$user], ['name' => 'Old']);
        $other = $this->createMailbox([], ['name' => 'Other']);
        $this->archive($archived);
        $support->users()->updateExistingPivot($user->id, ['mute' => true]);

        $mailboxes = $user->mailboxesCanViewWithSettings(true);
        $this->assertSame([$support->id], $mailboxes->pluck('id')->all(), 'A member sees own mailboxes, archived ones left out.');
        $this->assertEquals(1, $mailboxes->first()->mute);

        $admin = $this->createAdmin();
        $ids = $admin->mailboxesCanViewWithSettings(true)->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$support->id, $archived->id, $other->id], $ids, 'An admin sees every mailbox.');
    }

    public function testMailboxesSettingsWithoutTheCache()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);

        $this->assertSame([$mailbox->id], $user->mailboxesSettings(false)->pluck('mailbox_id')->all());

        $mailbox->users()->detach($user->id);
        $this->assertCount(0, $user->mailboxesSettings(false), 'Read from the database each time.');
    }

    public function testHasAccessToMailbox()
    {
        $user = $this->createUser();
        $own = $this->createMailbox([$user]);
        $other = $this->createMailbox();

        $this->assertTrue($user->hasAccessToMailbox($own->id));
        $this->assertFalse($user->hasAccessToMailbox($other->id));
        $this->assertTrue($this->createAdmin()->hasAccessToMailbox($other->id));
    }

    public function testCanManageMailbox()
    {
        $user = $this->createUser();
        $managed = $this->createMailbox([$user]);
        $archived = $this->createMailbox([$user]);
        $this->setAccess($managed, $user, [Mailbox::ACCESS_PERM_EDIT]);
        $this->setAccess($archived, $user, [Mailbox::ACCESS_PERM_EDIT]);
        $this->archive($archived);

        $this->assertTrue($user->canManageMailbox($managed));
        $this->assertFalse($user->canManageMailbox($archived), 'Not an archived mailbox, whatever the access.');
        $this->assertTrue($this->createAdmin()->canManageMailbox($archived));
    }

    public function testAdminHasEveryManagePermissionButNotOnlyAssigned()
    {
        $admin = $this->createAdmin();
        $mailbox = $this->createMailbox();

        $this->assertTrue($admin->hasManageMailboxPermission($mailbox->id, Mailbox::ACCESS_PERM_EDIT));
        $this->assertTrue($admin->hasManageMailboxPermission($mailbox->id, [Mailbox::ACCESS_PERM_SIGNATURE]));
        $this->assertFalse($admin->hasManageMailboxPermission($mailbox->id, Mailbox::ACCESS_PERM_ASSIGNED));
    }

    public function testSyncPersonalFoldersForAdmin()
    {
        $first = $this->createMailbox();
        $second = $this->createMailbox();
        $admin = $this->createAdmin();
        Folder::where('user_id', $admin->id)->where('mailbox_id', $first->id)->delete();

        $admin->syncPersonalFolders(null);

        foreach ([$first, $second] as $mailbox) {
            $types = Folder::where('user_id', $admin->id)->where('mailbox_id', $mailbox->id)->orderBy('type')->pluck('type')->all();
            $this->assertSame(Folder::$personal_types, $types, 'Mine and Starred in every mailbox, once.');
        }
    }

    public function testSyncPersonalFoldersForTheUsersOwnMailboxes()
    {
        $user = $this->createUser();
        $own = $this->createMailbox();
        $other = $this->createMailbox();
        $own->users()->attach($user->id);

        $user->syncPersonalFolders(null);

        $this->assertSame(Folder::$personal_types, Folder::where('user_id', $user->id)->where('mailbox_id', $own->id)->orderBy('type')->pluck('type')->all());
        $this->assertSame(0, Folder::where('user_id', $user->id)->where('mailbox_id', $other->id)->count());
    }

    public function testDateFormat()
    {
        $this->assertSame('', User::dateFormat('not a date at all'));
        $this->assertSame('', User::dateFormat(null));
        $this->assertSame('Mar 5, 2024 14:30', User::dateFormat(Carbon::parse('2024-03-05 14:30:00'), ''), 'The default format when none is given.');

        $user = $this->createUser(['time_format' => User::TIME_FORMAT_12, 'timezone' => 'UTC']);
        $this->assertSame('Mar 5, 2024 02:30pm', User::dateFormat(Carbon::parse('2024-03-05 14:30:00'), 'M j, Y H:i', $user));
    }

    public function testDateDiffForHumans()
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->assertSame('', User::dateDiffForHumans(null));
        $this->assertSame('Just now', User::dateDiffForHumans('2026-06-15 11:59:30'));
        $this->assertSame('2 hours ago', User::dateDiffForHumans('2026-06-15 10:00:00'));
        $this->assertSame('Jan 2', User::dateDiffForHumans('2026-01-02 10:00:00'), 'Over a week ago: the date.');
        $this->assertSame('Mar 5, 2020', User::dateDiffForHumans('2020-03-05 10:00:00'), 'In another year: with the year.');

        $this->assertSame('2 hours ago @ 10:00', User::dateDiffForHumansWithHours('2026-06-15 10:00:00'));
        $this->assertSame('Just now', User::dateDiffForHumansWithHours('2026-06-15 11:59:30'));
        $this->assertSame('', User::dateDiffForHumansWithHours(null));
    }

    public function testPermissionNames()
    {
        $this->assertSame('Users are allowed to manage tags', User::getUserPermissionName(User::PERM_EDIT_TAGS));
        $this->assertSame('', User::getUserPermissionName(1001));

        \Eventy::addFilter('user_permissions.name', function ($name, $permission) {
            return $permission == 1001 ? 'Users are allowed to manage widgets' : $name;
        }, 20, 2);
        $this->assertSame('Users are allowed to manage widgets', User::getUserPermissionName(1001), 'A module names its own permissions.');
    }

    public function testInviteStateName()
    {
        $user = $this->createUser();
        $this->assertSame('Active', $user->getInviteStateName());

        $user->invite_state = User::INVITE_STATE_SENT;
        $this->assertSame('Invited', $user->getInviteStateName());

        $user->invite_state = User::INVITE_STATE_NOT_INVITED;
        $this->assertSame('Not Invited', $user->getInviteStateName());

        $user->invite_state = 99;
        $this->assertSame('Active', $user->getInviteStateName());
    }

    public function testNoInviteForAnActivatedUser()
    {
        $user = $this->createUser();

        $this->assertFalse($user->sendInvite());
        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(0, SendLog::where('user_id', $user->id)->count());
    }

    public function testInviteThatCannotBeSentIsLogged()
    {
        $user = $this->createUser();
        $user->invite_state = User::INVITE_STATE_NOT_INVITED;
        $user->save();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection could not be established with host "smtp.example.org:587"'));

        $this->assertFalse($user->sendInvite());

        $user->refresh();
        $this->assertSame(User::INVITE_STATE_NOT_INVITED, (int) $user->invite_state, 'Not marked as invited.');
        $this->assertNotEmpty($user->invite_hash, 'The invite link is made before sending.');
        $this->assertSame([SendLog::STATUS_SEND_ERROR], SendLog::where('user_id', $user->id)->where('mail_type', SendLog::MAIL_TYPE_INVITE)->pluck('status')->all());
        $activity = \DB::table('activity_logs')->where('log_name', ActivityLog::NAME_EMAILS_SENDING)
            ->where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_INVITE)->first();
        $this->assertNotNull($activity);
        $this->assertSame($user->id, (int) $activity->causer_id);
        $this->assertStringContainsString('Connection could not be established', $activity->properties);
    }

    public function testInviteThatCannotBeSentThrowsWhenAsked()
    {
        $user = $this->createUser();
        $user->invite_state = User::INVITE_STATE_NOT_INVITED;
        $user->save();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));

        try {
            $user->sendInvite(true);
            $this->fail('The send error is thrown.');
        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            $this->assertSame('Connection refused', $e->getMessage());
        }
        $this->assertSame([SendLog::STATUS_SEND_ERROR], SendLog::where('user_id', $user->id)->pluck('status')->all());
    }

    public function testPasswordChangedEmailThatCannotBeSentIsLogged()
    {
        $user = $this->createUser();
        $this->failSending(new \Symfony\Component\Mailer\Exception\TransportException('Connection refused'));

        $this->assertFalse($user->sendPasswordChanged());

        $this->assertSame([SendLog::STATUS_SEND_ERROR], SendLog::where('user_id', $user->id)->where('mail_type', SendLog::MAIL_TYPE_PASSWORD_CHANGED)->pluck('status')->all());
        $this->assertSame(1, \DB::table('activity_logs')->where('log_name', ActivityLog::NAME_EMAILS_SENDING)
            ->where('description', ActivityLog::DESCRIPTION_EMAILS_SENDING_ERROR_PASSWORD_CHANGED)->where('causer_id', $user->id)->count());
    }

    public function testSavePhotoResizesAndReplacesThePreviousOne()
    {
        $user = $this->createUser();
        $disk = \Storage::disk('local');
        $disk->put('users/previous.jpg', 'old photo');
        $user->photo_url = 'previous.jpg';

        $file_name = $user->savePhoto(UploadedFile::fake()->image('me.png', 300, 200));

        $this->assertMatchesRegularExpression('#^[0-9a-f]{32}\.jpg$#', $file_name);
        $this->assertTrue($disk->exists('users/'.$file_name));
        $this->assertFalse($disk->exists('users/previous.jpg'), 'The previous photo is removed.');
        $size = getimagesize($disk->path('users/'.$file_name));
        $this->assertSame([config('app.user_photo_size'), config('app.user_photo_size'), IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);

        $user->photo_url = $file_name;
        $this->assertSame(config('app.url').'/storage/users/'.$file_name, $user->getPhotoUrl());
    }

    public function testSavePhotoFromAPathAndModulesCanChangeTheName()
    {
        $user = $this->createUser();
        $image = UploadedFile::fake()->image('me.jpg', 80, 80);
        $path = $image->getRealPath();
        \Eventy::addFilter('user.save_photo', function ($file_name) {
            return 'module-'.$file_name;
        });

        $file_name = $user->savePhoto($path, 'image/jpeg');

        $this->assertStringStartsWith('module-', $file_name);
        $this->assertTrue(\Storage::disk('local')->exists('users/'.substr($file_name, strlen('module-'))));
    }

    /**
     * A file that is not an image is refused (false), e.g. an API photoUrl
     * pointing at a web page.
     */
    public function testSavePhotoRefusesWhatIsNotAnImage()
    {

        $user = $this->createUser();
        $file = UploadedFile::fake()->createWithContent('me.png', 'not an image');

        $this->assertFalse($user->savePhoto($file->getRealPath(), 'image/png'));
        $this->assertSame([], \Storage::disk('local')->allFiles('users'));
    }

    public function testAddAndRemovePermissionSave()
    {
        $user = $this->createUser();

        $user->addPermission(User::PERM_EDIT_TAGS);
        $this->assertTrue($user->fresh()->hasPermission(User::PERM_EDIT_TAGS));

        $user->removePermission(User::PERM_EDIT_TAGS);
        $this->assertFalse($user->fresh()->hasPermission(User::PERM_EDIT_TAGS));

        $user->addPermission(User::PERM_EDIT_TAGS, false);
        $this->assertTrue($user->hasPermission(User::PERM_EDIT_TAGS));
        $this->assertFalse($user->fresh()->hasPermission(User::PERM_EDIT_TAGS), 'Not saved when asked not to.');
    }

    public function testGlobalPermissionsApplyUnlessTheUserHasOwn()
    {
        config(['app.user_permissions' => base64_encode(json_encode([User::PERM_EDIT_TAGS, User::PERM_ACCESS_REPORTS]))]);
        $this->assertSame([User::PERM_EDIT_TAGS, User::PERM_ACCESS_REPORTS], User::getGlobalUserPermissions());

        $user = $this->createUser();
        $this->assertTrue($user->hasPermission(User::PERM_EDIT_TAGS));
        $this->assertFalse($user->hasPermission(User::PERM_EDIT_USERS));

        $restricted = $this->createUser(['permissions' => [User::PERM_EDIT_TAGS => 0]]);
        $this->assertFalse($restricted->hasPermission(User::PERM_EDIT_TAGS), 'The user\'s own setting wins.');
        $this->assertTrue($restricted->hasPermission(User::PERM_EDIT_TAGS, false));

        config(['app.user_permissions' => base64_encode('not json')]);
        $this->assertSame([], User::getGlobalUserPermissions());
    }

    public function testCreate()
    {
        $this->assertNull(User::create(['email' => 'nopassword@example.org']));
        $this->assertNull(User::create(['password' => 'secret123']));

        $user = User::create([
            'email'      => ' New.User@Example.org ',
            'password'   => 'secret123',
            'first_name' => '<b>New</b>',
            'last_name'  => 'User',
            'role'       => User::ROLE_ADMIN,
        ]);
        $this->assertTrue($user->exists);
        $this->assertSame('new.user@example.org', $user->email);
        $this->assertSame('New', $user->first_name);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertTrue(\Hash::check('secret123', $user->password));
    }

    public function testCreateWithAnEmailInUseReturnsNull()
    {
        $existing = $this->createUser();
        \Log::shouldReceive('error')->once();

        $this->assertNull(User::create(['email' => $existing->email, 'password' => 'secret123']));
        $this->assertSame(1, User::where('email', $existing->email)->count());
    }

    public function testCheckRole()
    {
        $this->assertFalse(User::checkRole(User::ROLE_USER), 'Nobody logged in.');

        $this->be($this->createUser());
        $this->assertTrue(User::checkRole(User::ROLE_USER));
        $this->assertFalse(User::checkRole(User::ROLE_ADMIN));

        $this->be($this->createAdmin());
        $this->assertTrue(User::checkRole(User::ROLE_ADMIN));
    }

    public function testLocale()
    {
        $user = $this->createUser(['locale' => 'nl']);
        $this->assertSame('nl', $user->getLocale());

        $user->locale = null;
        $this->assertSame(\Helper::getRealAppLocale(), $user->getLocale());
    }

    public function testWhichUsersCanView()
    {
        $user = $this->createUser(['first_name' => 'Bea', 'last_name' => 'Member']);
        $colleague = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Colleague']);
        $stranger = $this->createUser(['first_name' => 'Sam', 'last_name' => 'Stranger']);
        $gone = $this->createUser(['first_name' => 'Gone', 'status' => User::STATUS_DELETED]);
        $this->createMailbox([$user, $colleague, $gone]);
        $this->createMailbox([$stranger]);

        $this->assertSame([$colleague->id, $user->id], $user->whichUsersCanView()->pluck('id')->values()->all(), 'Users sharing a mailbox, by name, deleted ones left out.');

        $admin = $this->createAdmin();
        $ids = $admin->whichUsersCanView()->pluck('id')->all();
        $this->assertEqualsCanonicalizing(User::nonDeleted()->pluck('id')->all(), $ids);
        $this->assertContains($stranger->id, $ids);
        $this->assertNotContains($gone->id, $ids);
    }

    public function testInitials()
    {
        $user = $this->createUser(['first_name' => 'alex', 'last_name' => 'martin']);

        $this->assertSame('AM', $user->getInitials());
        $this->assertSame('A', $user->getInitials(1));
    }

    /**
     * Initials are upper case in any alphabet, as in the avatar of "élodie martin".
     */
    public function testInitialsOfNonAsciiNames()
    {

        $user = $this->createUser(['first_name' => 'élodie', 'last_name' => 'ødegaard']);

        $this->assertSame('ÉØ', $user->getInitials());
        $this->assertSame('É', $user->getInitials(1));
    }

    public function testAssigneeFilterIncludesTheUser()
    {
        $user = $this->createUser(['first_name' => 'Zed']);
        $colleague = $this->createUser(['first_name' => 'Amy']);
        $mailbox = $this->createMailbox([$user, $colleague]);
        $mailbox->users()->updateExistingPivot($user->id, ['hide' => true]);

        $users = User::assigneeFilterUsers($user, $mailbox);

        $this->assertSame([$colleague->id, $user->id], $users->pluck('id')->values()->all(), 'The user is listed even when hidden in the mailbox.');
    }

    public function testAlternateEmails()
    {
        $user = $this->createUser(['emails' => 'first.alt@example.org, Second.Alt@Example.org']);
        $this->createUser(['emails' => 'unrelated@example.org']);

        $this->assertTrue($user->hasEmail('second.alt@example.org'));
        $this->assertFalse($user->hasEmail('third.alt@example.org'));
        $this->assertSame($user->id, User::findByAlternateEmail('SECOND.alt@example.org')->id);
        $this->assertNull(User::findByAlternateEmail('alt@example.org'), 'A part of an address doesn\'t match.');
    }

    public function testFollowingTwiceKeepsOneFollower()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([$user]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation = $mailbox->conversations()->first();

        $user->followConversation($conversation->id);
        $user->followConversation($conversation->id);

        $this->assertSame(1, \App\Follower::where('conversation_id', $conversation->id)->where('user_id', $user->id)->count());
    }
}
