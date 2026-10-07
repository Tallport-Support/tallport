<?php

namespace Tests\Feature;

use App\Folder;
use App\Mailbox;
use App\User;
use Tests\FeatureTestCase;

/**
 * The Mailbox model: stored passwords, state, the dashboard's folders, who
 * has access and can be assigned, the From of outgoing email, aliases and
 * the OAuth connection settings.
 */
class MailboxModelTest extends FeatureTestCase
{
    public function testPasswordsAreStoredEncrypted()
    {
        $mailbox = $this->createMailbox([], ['in_password' => 'in-secret', 'out_password' => 'out-secret']);

        $raw = \DB::table('mailboxes')->where('id', $mailbox->id)->first();
        $this->assertNotSame('in-secret', $raw->in_password);
        $this->assertNotSame('out-secret', $raw->out_password);
        $mailbox = Mailbox::find($mailbox->id);
        $this->assertSame('in-secret', $mailbox->in_password);
        $this->assertSame('out-secret', $mailbox->out_password);

        $mailbox->in_password = '';
        $mailbox->out_password = '';
        $mailbox->save();
        $raw = \DB::table('mailboxes')->where('id', $mailbox->id)->first();
        $this->assertSame(['', ''], [$raw->in_password, $raw->out_password]);
    }

    public function testPasswordsThatCannotBeDecryptedReadAsEmpty()
    {
        $mailbox = $this->createMailbox();
        \DB::table('mailboxes')->where('id', $mailbox->id)->update(['in_password' => 'garbage', 'out_password' => 'garbage']);

        $mailbox = Mailbox::find($mailbox->id);
        $this->assertSame('', $mailbox->in_password);
        $this->assertSame('', $mailbox->out_password);
    }

    public function testUnknownStateMeansActive()
    {
        $mailbox = $this->createMailbox();

        $mailbox->state = Mailbox::STATE_ARCHIVED;
        $this->assertTrue($mailbox->isArchived());

        $mailbox->state = 7;
        $this->assertTrue($mailbox->isActive());
        $this->assertSame(Mailbox::STATE_ACTIVE, $mailbox->getMeta('st'));
    }

    public function testDashboardShowsTheMainFoldersOfConnectedMailboxes()
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $mailbox = $this->createMailbox([$user, $other], ['name' => 'Support', 'in_protocol' => Mailbox::IN_PROTOCOL_MAIL_SERVER]);
        $this->receiveEmail($mailbox, $this->makeEmail([
            'from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'date' => date('r', time() - 3 * 3600),
        ]));
        $mailbox->conversations()->update(['last_reply_at' => date('Y-m-d H:i:s', time() - 3 * 3600)]);
        $mailbox->updateFoldersCounters();

        $this->be($user);
        $folders = $mailbox->getMainFolders();
        $this->assertSame([Folder::TYPE_UNASSIGNED, Folder::TYPE_MINE, Folder::TYPE_STARRED, Folder::TYPE_DRAFTS, Folder::TYPE_ASSIGNED], $folders->pluck('type')->all());
        $this->assertSame([$user->id, $user->id], $folders->whereIn('type', Folder::$personal_types)->pluck('user_id')->values()->all(), 'Only the user\'s own Mine and Starred.');

        $response = $this->actingAs($user)->get('/')->assertStatus(200);
        $response->assertSeeInOrder(['Support', 'Unassigned', 'Mine', 'Starred', 'Drafts', 'Assigned']);
        $response->assertSee('<span class="waiting-since">3 hours ago</span>', false);
    }

    public function testModulesCanChooseTheMainFolders()
    {
        $mailbox = $this->createMailbox();
        $closed = $mailbox->folders()->where('type', Folder::TYPE_CLOSED)->get();
        \Eventy::addFilter('mailbox.main_folders', function ($folders, $filtered_mailbox) use ($closed, $mailbox) {
            return $filtered_mailbox->id == $mailbox->id ? $closed : $folders;
        }, 20, 2);

        $this->assertSame($closed, $mailbox->getMainFolders());
    }

    public function testNextAccentOnceAllAreTaken()
    {
        foreach (\FruitUI\Fruit::ACCENTS as $accent) {
            $this->createMailbox([], ['accent' => $accent]);
        }

        $this->assertSame(\FruitUI\Fruit::ACCENTS[0], Mailbox::nextAccent());
        $this->createMailbox();
        $this->assertSame(\FruitUI\Fruit::ACCENTS[1], Mailbox::nextAccent(), 'Then the next, in turn.');
    }

    public function testSmtpNeedsAServerToSend()
    {
        $mailbox = $this->createMailbox([], ['out_method' => Mailbox::OUT_METHOD_SMTP, 'in_protocol' => Mailbox::IN_PROTOCOL_MAIL_SERVER]);
        $this->assertFalse($mailbox->isOutActive());
        $this->assertFalse($mailbox->isConnected());

        $mailbox->out_server = 'smtp.example.org';
        $this->assertTrue($mailbox->isOutActive());
        $this->assertTrue($mailbox->isConnected());
    }

    public function testUsersHavingAccessLeaveOutInactiveUsers()
    {
        $active = $this->createUser(['first_name' => 'Bea']);
        $disabled = $this->createUser(['status' => User::STATUS_DISABLED]);
        $admin = $this->createAdmin(['first_name' => 'Ann']);
        $mailbox = $this->createMailbox([$active, $disabled]);

        $this->assertSame([$admin->id, $active->id], $mailbox->usersHavingAccess()->pluck('id')->values()->all());
        $this->assertEqualsCanonicalizing([$admin->id, $active->id], $mailbox->userIdsHavingAccess());
    }

    public function testAssignableUsers()
    {
        $visible = $this->createUser(['first_name' => 'Bea']);
        $hidden = $this->createUser(['first_name' => 'Cal']);
        $disabled = $this->createUser(['first_name' => 'Dee', 'status' => User::STATUS_DISABLED]);
        $admin = $this->createAdmin(['first_name' => 'Ann']);
        $mailbox = $this->createMailbox([$visible, $hidden, $disabled]);
        $mailbox->users()->updateExistingPivot($hidden->id, ['hide' => true]);

        $this->assertSame([$admin->id, $visible->id], $mailbox->usersAssignable(false)->pluck('id')->values()->all());
        $this->assertSame([$admin->id, $visible->id, $hidden->id], $mailbox->usersAssignable(false, false)->pluck('id')->values()->all(), 'Hidden users when asked for.');
    }

    public function testUserHasAccess()
    {
        $member = $this->createUser();
        $stranger = $this->createUser();
        $mailbox = $this->createMailbox([$member]);

        $this->assertTrue($mailbox->userHasAccess($member));
        $this->assertTrue($mailbox->userHasAccess($member->id));
        $this->assertFalse($mailbox->userHasAccess($stranger->id));
        $this->assertFalse($mailbox->userHasAccess(999999), 'No such user.');

        \Eventy::addFilter('mailbox.user_has_access', function ($access, $filtered_mailbox, $user) use ($member) {
            return $user->id == $member->id ? false : $access;
        }, 20, 3);
        $this->assertFalse($mailbox->userHasAccess($member->id), 'A module can deny access.');
        \Eventy::removeAllFilters('mailbox.user_has_access');

        $this->createAdmin();
        $mailbox->state = Mailbox::STATE_ARCHIVED;
        $this->assertFalse($mailbox->userHasAccess($member), 'Members lose access to an archived mailbox.');
    }

    /**
     * A module grants access by returning true from mailbox.user_has_access
     * (-1 means it has no say).
     */
    public function testModuleCanGrantAccess()
    {

        $stranger = $this->createUser();
        $mailbox = $this->createMailbox();
        \Eventy::addFilter('mailbox.user_has_access', function ($access, $filtered_mailbox, $user) use ($stranger) {
            return $user->id == $stranger->id ? true : $access;
        }, 20, 3);

        $this->assertTrue($mailbox->userHasAccess($stranger->id));
    }

    public function testMailFromName()
    {
        $user = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $mailbox = $this->createMailbox([$user], ['name' => 'Support', 'email' => 'support@example.org']);

        $this->assertSame(['address' => 'support@example.org', 'name' => 'Support'], $mailbox->getMailFrom($user));

        $mailbox->from_name = Mailbox::FROM_NAME_USER;
        $this->assertSame('Alex Agent', $mailbox->getMailFrom($user)['name']);
        $this->assertSame('Support', $mailbox->getMailFrom()['name'], 'The mailbox name without a user.');

        $mailbox->from_name = Mailbox::FROM_NAME_CUSTOM;
        $mailbox->from_name_custom = '{%user.firstName%} at {%mailbox.name%}{%customer.fullName%}';
        $this->assertSame('Alex at Support', $mailbox->getMailFrom($user)['name']);

        $this->be($this->createUser(['first_name' => 'Kim']));
        $this->assertSame('Kim at Support', $mailbox->getMailFrom()['name'], 'The logged-in user by default.');
    }

    public function testMailDriverAndEncryptionNames()
    {
        $mailbox = $this->createMailbox();

        $expected = [
            Mailbox::OUT_METHOD_PHP_MAIL => 'mail',
            Mailbox::OUT_METHOD_SENDMAIL => 'sendmail',
            Mailbox::OUT_METHOD_SMTP     => 'smtp',
            99                           => 'mail',
        ];
        foreach ($expected as $method => $driver) {
            $mailbox->out_method = $method;
            $this->assertSame($driver, $mailbox->getMailDriverName());
        }

        $mailbox->out_encryption = Mailbox::OUT_ENCRYPTION_TLS;
        $this->assertSame('tls', $mailbox->getOutEncryptionName());
        $mailbox->out_encryption = Mailbox::OUT_ENCRYPTION_NONE;
        $this->assertSame('', $mailbox->getOutEncryptionName());
    }

    public function testAliases()
    {
        $mailbox = $this->createMailbox([], [
            'name'    => 'Support',
            'email'   => 'support@example.org',
            'aliases' => 'Help@Example.org (Help Desk), sales@example.org, not an email',
        ]);

        $this->assertSame([
            'support@example.org' => 'Support',
            'help@example.org'    => 'Help Desk',
            'sales@example.org'   => '',
        ], $mailbox->getAliases());
        $this->assertSame(['help@example.org' => 'Help Desk', 'sales@example.org' => ''], $mailbox->getAliases(false));
        $this->assertSame([], $mailbox->getAliases(true, true), 'None for replying unless allowed.');

        $mailbox->aliases_reply = true;
        $this->assertCount(3, $mailbox->getAliases(true, true));
    }

    public function testRemoveMailboxEmailsFromList()
    {
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org', 'aliases' => 'help@example.org']);

        $this->assertSame([1 => 'casey@customer.example.org'], $mailbox->removeMailboxEmailsFromList(['support@example.org', 'casey@customer.example.org', 'help@example.org']));
        $this->assertSame([], $mailbox->removeMailboxEmailsFromList(null));
    }

    public function testImapFolders()
    {
        $mailbox = $this->createMailbox();
        $this->assertSame(['INBOX'], $mailbox->getInImapFolders());

        $mailbox->setInImapFolders(['INBOX', 'Support/Urgent']);
        $this->assertSame(['INBOX', 'Support/Urgent'], $mailbox->getInImapFolders());
    }

    public function testOauthTokensAreStoredEncrypted()
    {
        $mailbox = $this->createMailbox();

        $mailbox->setMetaParam('oauth', ['provider' => 'ms', 'a_token' => 'access-123', 'r_token' => 'refresh-456'], true);

        $stored = Mailbox::find($mailbox->id);
        $this->assertNotSame('access-123', $stored->meta['oauth']['a_token']);
        $this->assertNotSame('refresh-456', $stored->meta['oauth']['r_token']);
        $this->assertSame('access-123', $stored->oauthGetParam('a_token'));
        $this->assertSame('refresh-456', $stored->oauthGetParam('r_token'));
        $this->assertSame('ms', $stored->oauthGetParam('provider'));
        $this->assertTrue($stored->oauthEnabled());

        $stored->removeMetaParam('oauth', true);
        $this->assertFalse(Mailbox::find($mailbox->id)->oauthEnabled());
    }

    public function testOauthUsernamesAndClientIds()
    {
        $mailbox = $this->createMailbox([], ['email' => 'support@example.org']);

        $mailbox->in_username = 'imap@example.org:in-client-id';
        $mailbox->out_username = 'smtp@example.org:out-client-id';
        $this->assertSame('imap@example.org', $mailbox->getInOauthUsername());
        $this->assertSame('in-client-id', $mailbox->getInOauthClientId());
        $this->assertSame('smtp@example.org', $mailbox->getOutOauthUsername());
        $this->assertSame('out-client-id', $mailbox->getOutOauthClientId());
        $this->assertTrue($mailbox->isOutUsernameOauth());

        $mailbox->in_username = 'in-client-id';
        $mailbox->out_username = 'out-client-id';
        $this->assertSame('support@example.org', $mailbox->getInOauthUsername(), 'The mailbox address with only a client ID.');
        $this->assertSame('support@example.org', $mailbox->getOutOauthUsername());
        $this->assertTrue($mailbox->isOutUsernameOauth());

        $mailbox->out_username = 'smtp@example.org';
        $this->assertFalse($mailbox->isOutUsernameOauth(), 'A plain address is a password login.');
    }

    public function testOutgoingOauthNeedsMicrosoftOrGoogleSmtp()
    {
        $mailbox = $this->createMailbox();
        $mailbox->setMetaParam('oauth', ['provider' => 'ms']);
        $mailbox->out_username = 'smtp@example.org:client-id';

        $mailbox->out_server = ' smtp.office365.com ';
        $this->assertTrue($mailbox->isOutServerOauth());
        $this->assertTrue($mailbox->outOauthEnabled());

        $mailbox->out_server = 'smtp.gmail.com';
        $this->assertTrue($mailbox->isOutServerOauth());

        $mailbox->out_server = 'smtp.example.org';
        $this->assertFalse($mailbox->isOutServerOauth());
        $this->assertFalse($mailbox->outOauthEnabled());
    }
}
