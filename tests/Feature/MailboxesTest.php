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
        $response->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]));
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

        $response = $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, [
            'name'             => 'Customer Support',
            'aliases'          => 'help@example.org',
            'from_name'        => Mailbox::FROM_NAME_CUSTOM,
            'from_name_custom' => 'The Support Team',
            'signature'        => '<p>Kind regards</p>',
        ]));

        $response->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]));
        $mailbox->refresh();
        $this->assertSame('Customer Support', $mailbox->name);
        $this->assertSame('help@example.org', $mailbox->aliases);
        $this->assertEquals(Mailbox::FROM_NAME_CUSTOM, $mailbox->from_name);
        $this->assertSame('The Support Team', $mailbox->from_name_custom);
        $this->assertStringContainsString('Kind regards', $mailbox->signature);
    }

    /**
     * The chat options are in General; the chat channels' pages together in the list beside it.
     */
    public function testChatSettingsAndChannelLinksAreTogether()
    {
        $mailbox = $this->createMailbox();
        $this->postForm($this->admin, route('mailboxes.update.save', ['id' => $mailbox->id]), $this->settingsFields($mailbox, [
            'chat_reopen_days' => '14', 'chat_start_new' => '1',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(14, $mailbox->fresh()->getMeta('chat_reopen_days'));
        $this->assertTrue($mailbox->fresh()->getMeta('chat_start_new'));

        $response = $this->actingAs($this->admin)->get(route('mailboxes.general', ['id' => $mailbox->getSlug()]))->assertOk();
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame('14', $xpath->evaluate('string(//*[@id="chat"]//input[@name="chat_reopen_days"]/@value)'));
        $this->assertSame(1, $xpath->query('//*[@id="chat"]//input[@name="chat_start_new"]')->length);
        $chat = $xpath->query('//*[contains(@class, "mailbox-settings")]//*[contains(@class, "f-form-section")][.//*[normalize-space()="Chat"]]')->item(0);
        foreach (['mailboxes.web_chat', 'mailboxes.telegram', 'mailboxes.matrix', 'mailboxes.nostr'] as $route) {
            $this->assertSame(1, $xpath->query('.//a[@href="'.route($route, ['id' => $mailbox->getSlug()]).'"]', $chat)->length, $route);
        }
    }

    /**
     * @dataProvider invalidChatWindows
     */
    public function testChatWindowMustBeAWholeNumberOfDaysWithinBounds($value)
    {
        $mailbox = $this->createMailbox();
        $mailbox->setMetaParam('chat_reopen_days', 14, true);
        $this->postForm($this->admin, route('mailboxes.update.save', ['id' => $mailbox->id]), $this->settingsFields($mailbox, [
            'chat_reopen_days' => $value,
        ]))->assertSessionHasErrors('chat_reopen_days');
        $this->assertSame(14, $mailbox->fresh()->getMeta('chat_reopen_days'));
    }

    public static function invalidChatWindows()
    {
        return [[''], ['0'], ['3651'], ['1.5'], ['several']];
    }

    /**
     * The sidebar's Mailbox Settings: name and signature in a dialog. An agent with the
     * signature permission changes only the signature; others may not open it.
     */
    /**
     * Each mailbox has a color: a new one the first free one, in turn when all are taken;
     * shown as the bar on its conversations across mailboxes and on its sidebar icon.
     */
    public function testMailboxColors()
    {
        $taken = \App\Mailbox::pluck('accent')->filter()->all();
        $mailbox = $this->createMailbox([$this->admin]);
        $this->assertSame(collect(\FruitUI\Fruit::ACCENTS)->first(fn ($accent) => !in_array($accent, $taken)) ?? \FruitUI\Fruit::ACCENTS[0], $mailbox->fresh()->accent);

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['accent' => 'green']))
            ->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]));
        $this->assertSame('green', $mailbox->fresh()->accent);
        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['accent' => 'chartreuse']))
            ->assertSessionHasErrors('accent');
        $this->assertSame('green', $mailbox->fresh()->accent);

        $this->actingAs($this->admin)->get(route('mailboxes.update', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('Marks this mailbox&#039;s conversations', false)->assertSee('value="green" checked', false);
        $this->actingAs($this->admin)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('data-fruit-mark="green"', false);
    }

    /**
     * The sidebar's mailbox menu leads to the mailbox's settings; someone who may only edit the
     * signature gets there too, and changes only that.
     */
    public function testMailboxSettingsFromTheSidebar()
    {
        $agent = $this->createUser();
        $signer = $this->createUser();
        $mailbox = $this->createMailbox([$agent, $signer], ['name' => 'Support']);
        $mailbox->users()->updateExistingPivot($signer->id, ['access' => json_encode([Mailbox::ACCESS_PERM_SIGNATURE])]);

        $this->actingAs($this->admin)->get(route('mailboxes.view', ['id' => $mailbox->id]))
            ->assertSee('href="'.route('mailboxes.update', ['id' => 'support']).'"', false)->assertSee('Mailbox Settings');
        $this->actingAs($agent)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertOk()->assertDontSee('Mailbox Settings');

        $this->actingAs($signer)->get(route('mailboxes.view', ['id' => $mailbox->id]))->assertOk()->assertSee('Mailbox Settings');
        $this->actingAs($signer)->get(route('mailboxes.update', ['id' => $mailbox->id]))->assertOk()->assertSee('name="signature_markdown"', false)->assertDontSee('id="name"', false)
            ->assertDontSee('name="chat_reopen_days"', false);
        $this->postForm($signer, route('mailboxes.update.save', ['id' => $mailbox->id]), ['name' => 'Hijacked', 'signature' => '<p>Agent</p>', 'chat_reopen_days' => 7, 'chat_start_new' => 1]);
        $this->assertSame('Support', $mailbox->fresh()->name);
        $this->assertStringContainsString('Agent', $mailbox->fresh()->signature);
        $this->assertNull($mailbox->fresh()->getMeta('chat_reopen_days'));
        $this->assertNull($mailbox->fresh()->getMeta('chat_start_new'));
    }

    /**
     * A mailbox's settings are in the mailbox, beside its folders: the list column holds its pages
     * (General, then each further page the user may open with its value, the chat channels together),
     * the open one current, and the page shows beside it under its title. Settings › Mailboxes leads
     * there; an ID in the address works too, and the page says its address by the short name.
     */
    public function testMailboxSettingsInTheMailbox()
    {
        $manager = $this->createUser();
        $mailbox = $this->createMailbox([$manager], ['name' => 'Support']);
        $mailbox->auto_reply_enabled = true;
        $mailbox->save();
        $mailbox->users()->updateExistingPivot($manager->id, ['access' => json_encode([Mailbox::ACCESS_PERM_PERMISSIONS])]);

        $current = fn ($href) => '#<a (?=[^>]*href="'.preg_quote($href, '#').'")[^>]*aria-current="page"#';
        $this->actingAs($this->admin)->get(route('mailboxes'))->assertOk()
            ->assertSee('href="'.route('mailboxes.update', ['id' => 'support']).'"', false);
        $html = $this->actingAs($this->admin)->get('/mailbox/support/settings')->assertOk()
            ->assertSeeInOrder(['>Settings</h1>', 'Support', 'General', 'Connection Settings', 'Not set up', 'Permissions', '2 people', 'Auto Reply', 'On',
                'Chat', 'Website Chat', 'Telegram', 'Matrix', 'Nostr', 'AI', 'Documentation', 'Workflows', 'Saved Replies', '>General</h1>'], false)
            // The inbox sidebar, not Settings'.
            ->assertSee('app-sidebar__mailbox', false)->assertDontSee('id="settings-search"', false)
            // Saved with its button: the signature and From name go to customers.
            ->assertSeeInOrder(['id="mailbox-form"', 'Reopen Window', 'Save'], false)->assertDontSee('Delete Mailbox')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<form id="mailbox-form"[^>]*autoSave/', $html);
        $this->assertMatchesRegularExpression($current(route('mailboxes.general', ['id' => 'support'])), $html);

        // A further page, by its ID too (an older link): its row current, its title, its address.
        $html = $this->actingAs($this->admin)->get(route('mailboxes.auto_reply', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('>Auto Reply</h1>', false)->assertSee('data-page-url="'.route('mailboxes.auto_reply', ['id' => 'support']).'"', false)->getContent();
        $this->assertMatchesRegularExpression($current(route('mailboxes.auto_reply', ['id' => 'support'])), $html);
        $this->actingAs($this->admin)->get('/mailbox/elsewhere/settings/auto-reply')->assertNotFound();

        // In a narrow window the settings start with the list (the page under it, out of sight);
        // a page shows with Back to it.
        $this->withUnencryptedCookie('tallport_narrow', '1');
        $this->actingAs($this->admin)->get('/mailbox/support/settings')->assertOk()->assertDontSee('split-view--open', false);
        $this->actingAs($this->admin)->get('/mailbox/support/settings/general')->assertOk()->assertSee('split-view--open', false)
            ->assertSee('href="'.route('mailboxes.update', ['id' => 'support']).'"', false);
        $this->withUnencryptedCookie('tallport_narrow', '0');

        // Not connected: what it can't do yet, with a way to set it up (not on that very page); the
        // tab that needs it says so in words too.
        $this->actingAs($this->admin)->get(route('mailboxes.update', ['id' => 'support']))
            ->assertSee('This mailbox can&#039;t receive email yet.', false)
            ->assertSee('Set up Fetching Emails so messages sent to '.$mailbox->email.' arrive here.')
            ->assertSee('href="'.route('mailboxes.connection.incoming', ['id' => 'support']).'">Set Up Fetching', false);
        $this->actingAs($this->admin)->get(route('mailboxes.connection.incoming', ['id' => 'support']))->assertDontSee('Set Up Fetching');
        $this->actingAs($this->admin)->get(route('mailboxes.connection', ['id' => 'support']))
            ->assertSee('Fetching Emails<span class="f-sr-only">, needs attention</span>', false);

        // Workflows and Saved Replies: pages of its settings too.
        $this->assertMatchesRegularExpression($current(route('mailboxes.workflows', ['mailbox_id' => 'support'])),
            $this->actingAs($this->admin)->get(route('mailboxes.workflows', ['mailbox_id' => 'support']))->assertOk()->assertSee('>Workflows</h1>', false)->getContent());
        $this->assertMatchesRegularExpression($current(route('mailboxes.saved_replies', ['id' => 'support'])),
            $this->actingAs($this->admin)->get(route('mailboxes.saved_replies', ['id' => 'support']))->assertOk()->assertSee('>Saved Replies</h1>', false)->getContent());

        // Only its permissions: its menu's Mailbox Settings leads there, and the list holds no other row.
        $this->actingAs($manager)->get(route('mailboxes.view', ['id' => 'support']))->assertOk()->assertSee('href="'.route('mailboxes.update', ['id' => 'support']).'"', false);
        $this->actingAs($manager)->get(route('mailboxes.permissions', ['id' => 'support']))->assertOk()
            ->assertSee('>Permissions</h1>', false)->assertDontSee('>General<', false)->assertDontSee('Auto Reply');
        $this->actingAs($manager)->get(route('mailboxes.update', ['id' => 'support']))->assertRedirect();
    }

    /**
     * Modules' pages are in the list too (mailboxes.settings.menu), at its end; an open one marks its
     * own row and gives the title, also when its route starts like one of ours (Nostr's announcements).
     */
    public function testModulePagesInTheList()
    {
        $this->createMailbox([], ['name' => 'Support']);
        \Eventy::addAction('mailboxes.settings.menu', function () {
            echo '<a href="https://tallport.test/mailbox/settings/1/nostr/announcements" aria-current="page">Announcements</a>';
        });

        $html = $this->actingAs($this->admin)->get(route('mailboxes.nostr', ['id' => 'support']))->assertOk()
            ->assertSee('>Announcements</h1>', false)
            ->assertSeeInOrder(['Saved Replies', '<a class="f-form-row f-form-row--link" href="https://tallport.test/mailbox/settings/1/nostr/announcements" aria-current="page">Announcements</a>'], false)->getContent();
        $this->assertDoesNotMatchRegularExpression('#<a [^>]*href="'.preg_quote(route('mailboxes.nostr', ['id' => 'support']), '#').'"[^>]*aria-current#', $html);
    }

    /**
     * The older addresses of a mailbox's settings pages lead to theirs in the mailbox.
     */
    public function testOlderSettingsAddressesLeadToTheMailbox()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);
        $workflow = new \App\Workflow();
        $workflow->id = 7;
        foreach ([
            '/mailbox/settings/'.$mailbox->id                          => '/mailbox/support/settings',
            '/mailbox/connection-settings/'.$mailbox->id.'/outgoing'   => '/mailbox/support/settings/connection',
            '/mailbox/connection-settings/'.$mailbox->id.'/incoming'   => '/mailbox/support/settings/connection/incoming',
            '/mailbox/permissions/'.$mailbox->id                       => '/mailbox/support/settings/permissions',
            '/mailbox/settings/'.$mailbox->id.'/auto-reply'            => '/mailbox/support/settings/auto-reply',
            '/mailbox/settings/'.$mailbox->id.'/website-chat'          => '/mailbox/support/settings/website-chat',
            '/mailbox/'.$mailbox->id.'/telegram'                       => '/mailbox/support/settings/telegram',
            '/mailbox/settings/'.$mailbox->id.'/matrix'                => '/mailbox/support/settings/matrix',
            '/mailbox/settings/'.$mailbox->id.'/nostr'                 => '/mailbox/support/settings/nostr',
            '/mailbox/settings/'.$mailbox->id.'/ai'                    => '/mailbox/support/settings/ai',
            '/mailbox/settings/'.$mailbox->id.'/documentation'         => '/mailbox/support/settings/documentation',
            '/mailbox/workflows/'.$mailbox->id                         => '/mailbox/support/settings/workflows',
            '/mailbox/workflows/'.$mailbox->id.'/new'                  => '/mailbox/support/settings/workflows/new',
            '/mailbox/workflows/'.$mailbox->id.'/7'                    => '/mailbox/support/settings/workflows/7',
            '/mailbox/saved-replies/'.$mailbox->id.'?from=mailbox'     => '/mailbox/support/settings/saved-replies?from=mailbox',
            '/mailbox/saved-replies/'.$mailbox->id.'/new'              => '/mailbox/support/settings/saved-replies/new',
            '/mailbox/saved-replies/'.$mailbox->id.'/12/edit'          => '/mailbox/support/settings/saved-replies/12',
        ] as $old => $new) {
            $this->actingAs($this->admin)->get($old)->assertStatus(301)->assertRedirect(url($new));
        }
    }

    /**
     * A mailbox's settings save as they change, in the background: what's wrong is said (on the
     * field's row), nothing saved then; workers restart only for connection settings that changed.
     */
    public function testSettingsSaveAsTheyChange()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);

        $this->postAjax($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['name' => 'Help']))
            ->assertOk()->assertJson(['status' => 'success']);
        $this->assertSame('Help', $mailbox->fresh()->name);
        $this->postAjax($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox->fresh(), ['email' => 'not an address']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertNotSame('not an address', $mailbox->fresh()->email);
        $this->assertNull(session('flash_success_floating'));

        $connection = ['out_method' => Mailbox::OUT_METHOD_PHP_MAIL, 'out_server' => '', 'out_port' => '', 'out_encryption' => Mailbox::OUT_ENCRYPTION_NONE];
        $this->postAjax($this->admin, route('mailboxes.connection.save', ['id' => $mailbox->id]), $connection)->assertJson(['status' => 'success']);
        \Cache::forget('illuminate:queue:restart');
        $this->postAjax($this->admin, route('mailboxes.connection.save', ['id' => $mailbox->id]), $connection)->assertJson(['status' => 'success']);
        $this->assertFalse(\Cache::has('illuminate:queue:restart'), 'Nothing changed: the workers keep running.');
    }

    /**
     * Signature errors and empty signatures give a page, not a 500 (M2, M3).
     */
    public function testSignatureEdgeCases()
    {
        $mailbox = $this->createMailbox([], ['signature' => '<p>Old</p>']);

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['signature' => ['not', 'a', 'string']]))
            ->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]))
            ->assertSessionHasErrors('signature');

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['signature' => '']))
            ->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]));
        $this->assertSame('', (string)$mailbox->fresh()->signature);
    }

    public function testMemberWithoutManagePermissionCannotChangeSettings()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Support']);

        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id.'/settings/general')->assertStatus(403);
        $this->postForm($agent, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['name' => 'Hijacked']))->assertStatus(403);
        $this->assertSame('Support', $mailbox->fresh()->name);
    }

    public function testManagerCanEditSettingsButNotNameOrEmail()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Support']);
        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->postForm($agent, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, [
            'name'    => 'Renamed by manager',
            'email'   => 'other@example.org',
            'aliases' => 'help@example.org',
        ]))->assertRedirect(route('mailboxes.general', ['id' => $mailbox->fresh()->getSlug()]));

        $mailbox->refresh();
        $this->assertSame('Support', $mailbox->name);
        $this->assertNotSame('other@example.org', $mailbox->email);
        $this->assertSame('help@example.org', $mailbox->aliases);
    }

    public function testArchivedMailboxIsHiddenFromMembers()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent], ['name' => 'Old mailbox']);

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/general', $this->settingsFields($mailbox, ['state' => 1]));

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

        $response = $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/permissions', [
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

        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id.'/settings/permissions')->assertStatus(403);

        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_PERMISSIONS])]);
        \Cache::flush();
        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id.'/settings/permissions')->assertStatus(200);
    }

    // Auto reply.

    public function testAutoReplySettings()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/auto-reply', [
            'auto_reply_enabled' => 1,
            'auto_reply_message' => '<p>Thanks!</p>',
        ])->assertSessionHasErrors('auto_reply_subject');
        $this->assertFalse((bool)$mailbox->fresh()->auto_reply_enabled);

        // Without a message: a validation error, not a 500 (M5).
        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/auto-reply', [
            'auto_reply_enabled' => 1,
            'auto_reply_subject' => 'We got it',
        ])->assertSessionHasErrors('auto_reply_message');

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/auto-reply', [
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

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/connection/incoming', [
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
        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/connection/incoming', [
            'in_server' => '127.0.0.1', 'in_port' => 993, 'in_username' => 'support@example.org',
            'in_password' => '*****', 'in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_encryption' => Mailbox::IN_ENCRYPTION_SSL,
        ]);
        $this->assertSame('imap-secret', $mailbox->fresh()->in_password);
    }

    public function testMailDeliveredByTheMailServerNeedsNoFetching()
    {
        $mailbox = $this->createMailbox();
        $this->assertFalse($mailbox->isInActive());

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/connection/incoming', [
            'in_protocol' => Mailbox::IN_PROTOCOL_MAIL_SERVER,
        ])->assertRedirect(route('mailboxes.connection.incoming', ['id' => $mailbox->id]))->assertSessionHasNoErrors();

        // Receiving is set up: no warnings, and nothing to fetch.
        $mailbox->refresh();
        $this->assertTrue($mailbox->isInActive());
        $this->assertTrue($mailbox->isDeliveredByMailServer());
        $this->actingAs($this->admin)->get(route('mailboxes.connection.incoming', ['id' => $mailbox->id]))->assertOk()
            ->assertSee($mailbox->email.'    tallport:')->assertSee('Mail Server (Direct Delivery)');
        $this->actingAs($this->admin)->get(route('mailboxes.connection', ['id' => $mailbox->id]))
            ->assertDontSee('This mailbox can&#039;t receive email yet.', false);
        $this->artisan('tallport:fetch-emails')->doesntExpectOutputToContain('Mailbox: '.$mailbox->name)->assertSuccessful();
    }

    public function testIncomingServerMustNotBeInternal()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/connection/incoming', [
            'in_server' => '10.0.0.1', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p',
        ])->assertSessionHasErrors('in_server');

        $this->assertNull($mailbox->fresh()->in_server);
    }

    public function testOutgoingConnectionSettings()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, '/mailbox/'.$mailbox->id.'/settings/connection', [
            'out_method' => Mailbox::OUT_METHOD_SENDMAIL,
        ])->assertRedirect(route('mailboxes.connection', ['id' => $mailbox->id]));

        $this->assertEquals(Mailbox::OUT_METHOD_SENDMAIL, $mailbox->fresh()->out_method);
    }

    public function testConnectionSettingsAreAdminOnly()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id.'/settings/connection')->assertStatus(403);
        $this->actingAs($agent)->get('/mailbox/'.$mailbox->id.'/settings/connection/incoming')->assertStatus(403);
    }

    // Ajax actions.

    public function testSendTestEmail()
    {
        $mailbox = $this->createMailbox([], ['name' => 'Support']);

        $response = $this->postAjax($this->admin, '/mailbox/ajax', ['action' => 'send_test', 'mailbox_id' => $mailbox->id, 'to' => 'me@example.org']);

        $this->assertSame('success', $response->json()['status'], json_encode($response->json()));
        $emails = $this->sentEmailsTo('me@example.org');
        $this->assertCount(1, $emails);
        $this->assertSame('test.mailbox', $emails[0]->getHeaders()->get('X-Tallport-Mail-Type')->getFieldBody());
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
