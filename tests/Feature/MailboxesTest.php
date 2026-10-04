<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\SendLog;
use App\User;
use Tests\FeatureTestCase;

/**
 * Creating and configuring mailboxes: settings, who has access and who
 * manages what, auto reply, connection settings, test emails, muting and
 * deleting.
 */
class MailboxesTest extends FeatureTestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['password' => \Hash::make('admin-password')]);
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function settingsFields(Mailbox $mailbox, array $changes = [])
    {
        return array_merge([
            'name'            => $mailbox->name,
            'email'           => $mailbox->email,
            'from_name'       => Mailbox::FROM_NAME_MAILBOX,
            'ticket_status'   => Conversation::STATUS_ACTIVE,
            'ticket_assignee' => Mailbox::TICKET_ASSIGNEE_ANYONE,
        ], $changes);
    }

    // Creating.

    public function testAdminCreatesMailbox()
    {
        $agent = $this->createUser();

        $response = $this->postForm($this->admin, '/mailbox/new', [
            'name'  => 'Billing',
            'email' => 'Billing@Example.org',
            'users' => [$agent->id],
        ]);

        $mailbox = Mailbox::where('email', 'billing@example.org')->first();
        $this->assertNotNull($mailbox, 'The mailbox was not created (or the email not lowercased).');
        $response->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]));
        $this->assertSame('Billing', $mailbox->name);
        $this->assertEquals([$agent->id], $mailbox->users()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing(
            [Folder::TYPE_UNASSIGNED, Folder::TYPE_DRAFTS, Folder::TYPE_ASSIGNED, Folder::TYPE_CLOSED, Folder::TYPE_SPAM, Folder::TYPE_DELETED],
            Folder::where('mailbox_id', $mailbox->id)->whereNull('user_id')->pluck('type')->all()
        );
        $this->assertEqualsCanonicalizing(
            [Folder::TYPE_MINE, Folder::TYPE_STARRED],
            Folder::where('mailbox_id', $mailbox->id)->where('user_id', $agent->id)->pluck('type')->all()
        );
    }

    public function testMailboxEmailMustBeUniqueAndNotAUsersEmail()
    {
        $existing = $this->createMailbox();
        $user = $this->createUser();

        $this->postForm($this->admin, '/mailbox/new', ['name' => 'Dup', 'email' => $existing->email])->assertSessionHasErrors('email');
        $this->postForm($this->admin, '/mailbox/new', ['name' => 'Clash', 'email' => $user->email])->assertSessionHasErrors('email');
        $this->postForm($this->admin, '/mailbox/new', ['email' => 'nameless@example.org'])->assertSessionHasErrors('name');

        $this->assertSame(0, Mailbox::whereIn('email', [$user->email, 'nameless@example.org'])->count());
    }

    public function testOnlyAdminsCreateMailboxes()
    {
        $agent = $this->createUser();

        $this->actingAs($agent)->get('/mailbox/new')->assertStatus(403);
        $this->postForm($agent, '/mailbox/new', ['name' => 'Mine', 'email' => 'mine@example.org'])->assertStatus(403);
    }

    // Settings.

    public function testAdminSavesSettings()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);

        $response = $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, [
            'name'             => 'Customer Support',
            'aliases'          => 'help@example.org',
            'from_name'        => Mailbox::FROM_NAME_CUSTOM,
            'from_name_custom' => 'The Support Team',
            'signature'        => '<p>Kind regards</p>',
        ]));

        $response->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]));
        $mailbox->refresh();
        $this->assertSame('Customer Support', $mailbox->name);
        $this->assertSame('help@example.org', $mailbox->aliases);
        $this->assertEquals(Mailbox::FROM_NAME_CUSTOM, $mailbox->from_name);
        $this->assertSame('The Support Team', $mailbox->from_name_custom);
        $this->assertStringContainsString('Kind regards', $mailbox->signature);
    }

    /**
     * The sidebar's Mailbox Settings: name and signature in a dialog. An agent with the
     * signature permission changes only the signature; others may not open it.
     */
    public function testQuickSettings()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);
        $agent = $this->createUser();
        $mailbox->users()->attach($agent->id);

        $this->actingAs($this->admin)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertSee(route('mailboxes.quick_settings', ['id' => $mailbox->id]), false);
        $this->actingAs($this->admin)->get(route('mailboxes.quick_settings', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('name="name"', false)->assertSee('All Settings');
        \Session::start();
        $this->actingAs($this->admin)->post(route('mailboxes.quick_settings.save', ['id' => $mailbox->id]), ['_token' => csrf_token(), 'name' => 'Help Desk', 'signature' => '<p>Kind regards</p><script>x</script>'])
            ->assertJson(['status' => 'success']);
        $mailbox->refresh();
        $this->assertSame('Help Desk', $mailbox->name);
        $this->assertStringContainsString('Kind regards', $mailbox->signature);
        $this->assertStringNotContainsString('<script', $mailbox->signature);
        $this->actingAs($this->admin)->post(route('mailboxes.quick_settings.save', ['id' => $mailbox->id]), ['_token' => csrf_token(), 'name' => ''])
            ->assertJson(['status' => 'error']);

        $this->actingAs($agent)->get(route('mailboxes.quick_settings', ['id' => $mailbox->id]))->assertForbidden();
        $signer = $this->createUser();
        $mailbox->users()->attach($signer->id, ['access' => json_encode([Mailbox::ACCESS_PERM_SIGNATURE])]);
        $this->actingAs($signer)->get(route('mailboxes.quick_settings', ['id' => $mailbox->id]))->assertOk()->assertDontSee('name="name"', false);
        $this->actingAs($signer)->post(route('mailboxes.quick_settings.save', ['id' => $mailbox->id]), ['_token' => csrf_token(), 'name' => 'Hijacked', 'signature' => '<p>Agent</p>'])
            ->assertJson(['status' => 'success']);
        $this->assertSame('Help Desk', $mailbox->fresh()->name);
        $this->assertStringContainsString('Agent', $mailbox->fresh()->signature);
    }

    /**
     * Signature errors and empty signatures give a page, not a 500 (M2, M3).
     */
    public function testSignatureEdgeCases()
    {
        $mailbox = $this->createMailbox([], ['signature' => '<p>Old</p>']);

        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, ['signature' => ['not', 'a', 'string']]))
            ->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]))
            ->assertSessionHasErrors('signature');

        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, ['signature' => '']))
            ->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]));
        $this->assertSame('', (string)$mailbox->fresh()->signature);
    }

    public function testMemberWithoutManagePermissionCannotChangeSettings()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Support']);

        $this->actingAs($agent)->get('/mailbox/settings/'.$mailbox->id)->assertStatus(403);
        $this->postForm($agent, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, ['name' => 'Hijacked']))->assertStatus(403);
        $this->assertSame('Support', $mailbox->fresh()->name);
    }

    public function testManagerCanEditSettingsButNotNameOrEmail()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Support']);
        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->postForm($agent, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, [
            'name'    => 'Renamed by manager',
            'email'   => 'other@example.org',
            'aliases' => 'help@example.org',
        ]))->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]));

        $mailbox->refresh();
        $this->assertSame('Support', $mailbox->name);
        $this->assertNotSame('other@example.org', $mailbox->email);
        $this->assertSame('help@example.org', $mailbox->aliases);
    }

    public function testArchivedMailboxIsHiddenFromMembers()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Old mailbox']);

        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id, $this->settingsFields($mailbox, ['state' => 1]));

        $this->assertEquals(Mailbox::STATE_ARCHIVED, $mailbox->fresh()->state);
        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id)->assertStatus(403);
        $this->actingAs($agent)->get('/')->assertDontSee('Old mailbox');
    }

    // Access.

    public function testPermissionsGrantAndRevokeAccess()
    {
        $staying = $this->createUser();
        $leaving = $this->createUser();
        $mailbox = $this->createMailbox([$leaving]);

        $response = $this->postForm($this->admin, '/mailbox/permissions/'.$mailbox->id, [
            'users'    => [$staying->id],
            'managers' => [$staying->id => ['access' => [Mailbox::ACCESS_PERM_AUTO_REPLIES => Mailbox::ACCESS_PERM_AUTO_REPLIES]]],
        ]);

        $response->assertRedirect(route('mailboxes.permissions', ['id' => $mailbox->id]));
        $this->assertTrue($mailbox->userHasAccess($staying->id));
        $this->assertFalse($mailbox->userHasAccess($leaving->id));
        $this->assertTrue($staying->fresh()->hasManageMailboxPermission($mailbox->id, Mailbox::ACCESS_PERM_AUTO_REPLIES));
        $this->assertFalse($staying->fresh()->hasManageMailboxPermission($mailbox->id, Mailbox::ACCESS_PERM_EDIT));
        $this->assertTrue($mailbox->users()->where('users.id', $this->admin->id)->exists(), 'Admins are always attached.');
    }

    public function testOnlyAdminsAndPermissionManagersEditAccess()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);

        $this->actingAs($agent)->get('/mailbox/permissions/'.$mailbox->id)->assertStatus(403);

        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_PERMISSIONS])]);
        \Cache::flush();
        $this->actingAs($agent)->get('/mailbox/permissions/'.$mailbox->id)->assertStatus(200);
    }

    // Auto reply.

    public function testAutoReplySettings()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id.'/auto-reply', [
            'auto_reply_enabled' => 1,
            'auto_reply_message' => '<p>Thanks!</p>',
        ])->assertSessionHasErrors('auto_reply_subject');
        $this->assertFalse((bool)$mailbox->fresh()->auto_reply_enabled);

        // Without a message: a validation error, not a 500 (M5).
        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id.'/auto-reply', [
            'auto_reply_enabled' => 1,
            'auto_reply_subject' => 'We got it',
        ])->assertSessionHasErrors('auto_reply_message');

        $this->postForm($this->admin, '/mailbox/settings/'.$mailbox->id.'/auto-reply', [
            'auto_reply_enabled' => 1,
            'auto_reply_subject' => 'We got it',
            'auto_reply_message' => '<p>Thanks!</p>',
        ])->assertRedirect(route('mailboxes.auto_reply', ['id' => $mailbox->id]));

        $mailbox->refresh();
        $this->assertTrue((bool)$mailbox->auto_reply_enabled);
        $this->assertSame('We got it', $mailbox->auto_reply_subject);
        $this->assertStringContainsString('Thanks!', $mailbox->auto_reply_message);
    }

    // Connection settings.

    public function testIncomingConnectionSettingsStorePasswordEncrypted()
    {
        config(['app.remote_host_white_list' => '127.0.0.1']);
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/connection-settings/'.$mailbox->id.'/incoming', [
            'in_server'        => '127.0.0.1',
            'in_port'          => 993,
            'in_username'      => 'support@example.org',
            'in_password'      => 'imap-secret',
            'in_protocol'      => Mailbox::IN_PROTOCOL_IMAP,
            'in_encryption'    => Mailbox::IN_ENCRYPTION_SSL,
            'in_validate_cert' => 1,
        ])->assertRedirect(route('mailboxes.connection.incoming', ['id' => $mailbox->id]));

        $mailbox->refresh();
        $this->assertSame('127.0.0.1', $mailbox->in_server);
        $this->assertEquals(993, $mailbox->in_port);
        $this->assertSame('imap-secret', $mailbox->in_password);
        $this->assertNotSame('imap-secret', \DB::table('mailboxes')->where('id', $mailbox->id)->value('in_password'), 'Stored encrypted.');

        // Saving the masked password again keeps the real one.
        $this->postForm($this->admin, '/mailbox/connection-settings/'.$mailbox->id.'/incoming', [
            'in_server' => '127.0.0.1', 'in_port' => 993, 'in_username' => 'support@example.org',
            'in_password' => '*****', 'in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_encryption' => Mailbox::IN_ENCRYPTION_SSL,
        ]);
        $this->assertSame('imap-secret', $mailbox->fresh()->in_password);
    }

    public function testIncomingServerMustNotBeInternal()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/connection-settings/'.$mailbox->id.'/incoming', [
            'in_server' => '10.0.0.1', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p',
        ])->assertSessionHasErrors('in_server');

        $this->assertNull($mailbox->fresh()->in_server);
    }

    public function testOutgoingConnectionSettings()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/connection-settings/'.$mailbox->id.'/outgoing', [
            'out_method' => Mailbox::OUT_METHOD_SENDMAIL,
        ])->assertRedirect(route('mailboxes.connection', ['id' => $mailbox->id]));

        $this->assertEquals(Mailbox::OUT_METHOD_SENDMAIL, $mailbox->fresh()->out_method);
    }

    public function testConnectionSettingsAreAdminOnly()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->actingAs($agent)->get('/mailbox/connection-settings/'.$mailbox->id.'/outgoing')->assertStatus(403);
        $this->actingAs($agent)->get('/mailbox/connection-settings/'.$mailbox->id.'/incoming')->assertStatus(403);
    }

    // Ajax actions.

    public function testSendTestEmail()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);

        $response = $this->postAjax($this->admin, '/mailbox/ajax', ['action' => 'send_test', 'mailbox_id' => $mailbox->id, 'to' => 'me@example.org']);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $emails = $this->sentEmailsTo('me@example.org');
        $this->assertCount(1, $emails);
        $this->assertSame('test.mailbox', $emails[0]->getHeaders()->get('X-FreeScout-Mail-Type')->getFieldBody());
        $this->assertSame([$mailbox->email], array_keys($emails[0]->getFrom()));
        $this->assertEquals(SendLog::MAIL_TYPE_TEST, SendLog::where('email', 'me@example.org')->value('mail_type'));
    }

    public function testMuteMailbox()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);

        $this->postAjax($agent, '/mailbox/ajax', ['action' => 'mute', 'mailbox_id' => $mailbox->id, 'mute' => 1]);

        $this->assertEquals(1, \DB::table('mailbox_user')->where(['mailbox_id' => $mailbox->id, 'user_id' => $agent->id])->value('mute'));
    }

    public function testDeleteMailboxNeedsPasswordAndRemovesEverything()
    {
        $mailbox = $this->createMailbox();
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email]));
        $conversation_id = Conversation::where('mailbox_id', $mailbox->id)->value('id');

        $wrong = $this->postAjax($this->admin, '/mailbox/ajax', ['action' => 'delete_mailbox', 'mailbox_id' => $mailbox->id, 'password' => 'wrong']);
        $this->assertSame('Please double check your password, and try again', $wrong->json()['msg']);
        $this->assertNotNull(Mailbox::find($mailbox->id));

        $this->assertSame('success', $this->postAjax($this->admin, '/mailbox/ajax', [
            'action' => 'delete_mailbox', 'mailbox_id' => $mailbox->id, 'password' => 'admin-password',
        ])->json()['status']);

        $this->assertNull(Mailbox::find($mailbox->id));
        $this->assertNull(Conversation::find($conversation_id));
        $this->assertSame(0, Folder::where('mailbox_id', $mailbox->id)->count());
    }

    public function testMailboxAjaxIsAdminOnly()
    {
        $agent = $this->createUser(['password' => \Hash::make('agent-password')]);
        $mailbox = $this->createMailbox([$agent]);

        $this->assertSame('Not enough permissions', $this->postAjax($agent, '/mailbox/ajax', [
            'action' => 'delete_mailbox', 'mailbox_id' => $mailbox->id, 'password' => 'agent-password',
        ])->json()['msg']);
        $this->assertNotNull(Mailbox::find($mailbox->id));
    }
}
