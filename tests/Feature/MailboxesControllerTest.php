<?php

namespace Tests\Feature;

use App\Mailbox;
use App\MailboxAutoReply;
use App\User;
use Tests\FeatureTestCase;

/**
 * MailboxesController beyond MailboxesTest: who sees which mailboxes, the edges of the
 * settings, permissions and connection forms, the connection tests (against a local
 * IMAP server), and connecting a mailbox with oAuth.
 */
class MailboxesControllerTest extends FeatureTestCase
{
    protected $admin;

    /**
     * Local IMAP servers started by a test, stopped in tearDown().
     */
    protected $servers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['password' => \Hash::make('admin-password')]);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->servers as $server) {
                proc_terminate($server);
                proc_close($server);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function postForm($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge(['_token' => csrf_token()], $data));
    }

    protected function mailboxAjax($user, array $data)
    {
        return $this->postAjax($user, '/mailbox/ajax', $data)->json();
    }

    /**
     * A local IMAP server that takes several connections (the connection test opens the
     * port first, then logs in), with INBOX and its subfolder INBOX/Archive; or one that
     * refuses every login. Returns its port.
     */
    protected function startImapServer($refuse_login = false)
    {
        $code = <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0');
echo explode(':', stream_socket_get_name($server, false))[1]."\n";
while ($client = @stream_socket_accept($server, 20)) {
    fwrite($client, "* OK [CAPABILITY IMAP4rev1] Test IMAP ready\r\n");
    while (($line = fgets($client)) !== false) {
        $parts = explode(' ', trim($line));
        $tag = $parts[0];
        $command = strtoupper($parts[1] ?? '');
        if ($command == 'UID') {
            $command .= ' '.strtoupper($parts[2] ?? '');
        }
        if ($command == 'LOGIN' && getenv('IMAP_REFUSE_LOGIN')) {
            fwrite($client, "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
            continue;
        }
        if ($command == 'LOGOUT') {
            fwrite($client, "* BYE Logging out\r\n$tag OK LOGOUT completed\r\n");
            break;
        }
        $responses = [
            'CAPABILITY' => "* CAPABILITY IMAP4rev1\r\n",
            'LIST'       => str_contains($line, 'INBOX/')
                ? "* LIST (\\HasNoChildren) \"/\" \"INBOX/Archive\"\r\n"
                : "* LIST (\\HasChildren) \"/\" \"INBOX\"\r\n",
            'SELECT'     => "* 0 EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n* OK [UIDNEXT 1] Next UID\r\n",
            'EXAMINE'    => "* 0 EXISTS\r\n* 0 RECENT\r\n* OK [UIDVALIDITY 1] UIDs valid\r\n* OK [UIDNEXT 1] Next UID\r\n",
            'SEARCH'     => "* SEARCH\r\n",
            'UID SEARCH' => "* SEARCH\r\n",
        ];
        fwrite($client, ($responses[$command] ?? '')."$tag OK $command completed\r\n");
    }
    fclose($client);
}
PHP;
        $server = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $refuse_login ? ['IMAP_REFUSE_LOGIN' => '1'] : null);
        $this->servers[] = $server;

        return (int) fgets($pipes[1]);
    }

    protected function imapMailbox($port, array $attributes = [])
    {
        config(['app.remote_host_white_list' => '127.0.0.1']);
        $mailbox = $this->createMailbox([], ['name' => 'Fetched']);
        $mailbox->fill(array_merge([
            'in_protocol'   => Mailbox::IN_PROTOCOL_IMAP,
            'in_server'     => '127.0.0.1',
            'in_port'       => $port,
            'in_username'   => 'support',
            'in_password'   => 'imap-secret',
            'in_encryption' => Mailbox::IN_ENCRYPTION_NONE,
        ], $attributes))->save();

        return $mailbox;
    }

    // Lists and pages.

    /**
     * Settings › Mailboxes lists, for someone who isn't an admin, only the mailboxes they
     * manage something of; New Mailbox lists the people (not admins) to give access.
     */
    public function testMailboxListAndNewMailboxForm()
    {
        $agent = $this->createUser(['first_name' => 'Alex', 'last_name' => 'Agent']);
        $managed = $this->createMailbox([$agent], ['name' => 'Managed Box']);
        $this->createMailbox([$agent], ['name' => 'Worked Box']);
        $managed->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->actingAs($agent)->get(route('mailboxes'))->assertOk()
            ->assertSee('Managed Box')->assertDontSee('Worked Box');
        $this->actingAs($this->admin)->get(route('mailboxes'))->assertOk()
            ->assertSee('Managed Box')->assertSee('Worked Box');

        $this->actingAs($this->admin)->get(route('mailboxes.create'))->assertOk()
            ->assertSee('Alex Agent')
            ->assertSee('id="user-'.$agent->id.'"', false)
            ->assertDontSee('id="user-'.$this->admin->id.'"', false);
    }

    /**
     * Someone with no access to a mailbox's settings pages is refused, rather than sent
     * somewhere; someone who manages only its auto reply is sent there.
     */
    public function testSettingsPageForSomeoneWhoMayNotEditIt()
    {
        $outsider = $this->createUser();
        $replier = $this->createUser();
        $mailbox = $this->createMailbox([$replier]);
        $mailbox->users()->updateExistingPivot($replier->id, ['access' => json_encode([Mailbox::ACCESS_PERM_AUTO_REPLIES])]);

        $this->actingAs($outsider)->get(route('mailboxes.update', ['id' => $mailbox->id]))->assertStatus(403);
        $this->actingAs($replier)->get(route('mailboxes.update', ['id' => $mailbox->id]))
            ->assertRedirect(route('mailboxes.auto_reply', ['id' => $mailbox->id]));
    }

    /**
     * The settings form refuses a user's email address, and saves the Chat option
     * (a reply to a closed chat starts a new conversation).
     */
    public function testSettingsRefuseAUsersEmailAndSaveTheChatOption()
    {
        $user = $this->createUser();
        $mailbox = $this->createMailbox([], ['name' => 'Support']);
        $fields = [
            'name'            => 'Support',
            'email'           => $mailbox->email,
            'from_name'       => Mailbox::FROM_NAME_MAILBOX,
            'ticket_assignee' => Mailbox::TICKET_ASSIGNEE_ANYONE,
        ];

        $this->postForm($this->admin, route('mailboxes.update.save', ['id' => $mailbox->id]), array_merge($fields, ['email' => $user->email]))
            ->assertRedirect(route('mailboxes.update', ['id' => $mailbox->id]))
            ->assertSessionHasErrors('email');
        $this->assertSame($mailbox->email, $mailbox->fresh()->email);

        $this->postForm($this->admin, route('mailboxes.update.save', ['id' => $mailbox->id]), array_merge($fields, ['chat_start_new' => 1]))
            ->assertSessionHasNoErrors();
        $this->assertTrue($mailbox->fresh()->meta['chat_start_new'] ?? null);

        $this->postForm($this->admin, route('mailboxes.update.save', ['id' => $mailbox->id]), $fields);
        $this->assertArrayNotHasKey('chat_start_new', (array) $mailbox->fresh()->meta);
    }

    public function testOnlyAdminsSaveAMailboxsAiSettings()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);
        $mailbox->users()->updateExistingPivot($agent->id, ['access' => json_encode([Mailbox::ACCESS_PERM_EDIT])]);

        $this->postForm($agent, route('mailboxes.ai.save', ['id' => $mailbox->id]), ['glossary' => 'Hijacked'])->assertStatus(403);
        $this->assertEmpty(\Option::get('aiassistant.translation_glossary'));
    }

    /**
     * Saving the people who may use a mailbox keeps the admins' own settings for it
     * (muted, after sending), only setting whether it's hidden for them.
     */
    public function testPermissionsKeepAdminsOwnSettings()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$this->admin, $agent]);
        $mailbox->users()->updateExistingPivot($this->admin->id, ['mute' => 1, 'after_send' => \App\MailboxUser::AFTER_SEND_STAY]);

        $this->postForm($this->admin, route('mailboxes.permissions.save', ['id' => $mailbox->id]), [
            'users'    => [$agent->id],
            'managers' => [$this->admin->id => ['hide' => 1]],
        ])->assertRedirect(route('mailboxes.permissions', ['id' => $mailbox->id]));

        $row = \DB::table('mailbox_user')->where(['mailbox_id' => $mailbox->id, 'user_id' => $this->admin->id])->first();
        $this->assertEquals(1, $row->mute);
        $this->assertEquals(\App\MailboxUser::AFTER_SEND_STAY, $row->after_send);
        $this->assertEquals(1, $row->hide);
        $this->assertTrue($mailbox->userHasAccess($agent->id));
    }

    // Connection settings.

    /**
     * Sending by SMTP needs a server, port and encryption; a saved masked password keeps
     * the real one, and the test address is remembered.
     */
    public function testOutgoingSmtpSettings()
    {
        config(['app.remote_host_white_list' => '127.0.0.1']);
        $mailbox = $this->createMailbox();
        $url = route('mailboxes.connection.save', ['id' => $mailbox->id]);

        $this->postForm($this->admin, $url, ['out_method' => Mailbox::OUT_METHOD_SMTP, 'out_port' => 'abc'])
            ->assertRedirect(route('mailboxes.connection', ['id' => $mailbox->id]))
            ->assertSessionHasErrors(['out_server', 'out_port', 'out_encryption']);
        $this->assertEquals(Mailbox::OUT_METHOD_PHP_MAIL, $mailbox->fresh()->out_method);

        $smtp = [
            'out_method'     => Mailbox::OUT_METHOD_SMTP,
            'out_server'     => '127.0.0.1',
            'out_port'       => 587,
            'out_username'   => 'support',
            'out_encryption' => Mailbox::OUT_ENCRYPTION_NONE,
        ];
        $this->postForm($this->admin, $url, $smtp + ['out_password' => 'smtp-secret', 'send_test_to' => 'me@example.org'])
            ->assertSessionHasNoErrors();
        $mailbox->refresh();
        $this->assertSame('127.0.0.1', $mailbox->out_server);
        $this->assertEquals(587, $mailbox->out_port);
        $this->assertSame('smtp-secret', $mailbox->out_password);
        $this->assertSame('me@example.org', \Option::get('send_test_to'));

        $this->postForm($this->admin, $url, $smtp + ['out_password' => '*****']);
        $this->assertSame('smtp-secret', $mailbox->fresh()->out_password);
    }

    /**
     * A mailbox that sends by SMTP without a server says on its pages that it can't send
     * yet, with a way to set it up (not on the sending page itself).
     */
    public function testMailboxThatCantSendSaysSo()
    {
        $mailbox = $this->createMailbox([], ['out_method' => Mailbox::OUT_METHOD_SMTP]);

        $this->actingAs($this->admin)->get(route('mailboxes.update', ['id' => $mailbox->id]))->assertOk()
            ->assertSee('This mailbox can&#039;t send email yet.', false)
            ->assertSee('href="'.route('mailboxes.connection', ['id' => $mailbox->id]).'">Set Up Sending', false);
        $this->actingAs($this->admin)->get(route('mailboxes.connection', ['id' => $mailbox->id]))->assertOk()
            ->assertDontSee('Set Up Sending');
    }

    /**
     * The IMAP folders to fetch are saved in the order given.
     */
    public function testIncomingSettingsSaveTheFoldersToFetch()
    {
        config(['app.remote_host_white_list' => '127.0.0.1']);
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, route('mailboxes.connection.incoming.save', ['id' => $mailbox->id]), [
            'in_protocol'     => Mailbox::IN_PROTOCOL_IMAP,
            'in_server'       => '127.0.0.1',
            'in_port'         => 143,
            'in_username'     => 'support',
            'in_password'     => 'imap-secret',
            'in_encryption'   => Mailbox::IN_ENCRYPTION_NONE,
            'in_imap_folders' => ['INBOX', 'Support/Billing'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['INBOX', 'Support/Billing'], $mailbox->fresh()->getInImapFolders());
    }

    // Team chat.

    public function testTeamChatWithoutAMailboxIsRefused()
    {
        $this->actingAs($this->createUser())->get(route('team_chat'))->assertStatus(403);
    }

    // Auto reply.

    /**
     * Versions in languages Tallport doesn't know are ignored.
     */
    public function testAutoReplyIgnoresUnknownLanguages()
    {
        $mailbox = $this->createMailbox();

        $this->postForm($this->admin, route('mailboxes.auto_reply.save', ['id' => $mailbox->id]), [
            'auto_reply_enabled' => 1,
            'auto_reply_subject' => 'We got it',
            'auto_reply_message' => '<p>Thanks!</p>',
            'versions'           => [
                'tlh' => ['enabled' => 1, 'subject' => 'Qapla', 'message' => 'Qapla'],
                'de'  => ['enabled' => 1, 'subject' => 'Danke', 'message' => '<p>Danke!</p>'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['de'], MailboxAutoReply::where('mailbox_id', $mailbox->id)->pluck('language')->all());
    }

    // Ajax: sending and fetching tests.

    public function testSendTestRefusals()
    {
        $agent = $this->createUser();
        $mailbox = $this->createMailbox([$agent]);

        $this->assertSame('Mailbox not found', $this->mailboxAjax($this->admin, ['action' => 'send_test', 'mailbox_id' => 0, 'to' => 'me@example.org'])['msg']);
        $this->assertSame('Not enough permissions', $this->mailboxAjax($agent, ['action' => 'send_test', 'mailbox_id' => $mailbox->id, 'to' => 'me@example.org'])['msg']);
        $this->assertSame('Please specify recipient of the test email', $this->mailboxAjax($this->admin, ['action' => 'send_test', 'mailbox_id' => $mailbox->id])['msg']);
        $this->assertCount(0, $this->sentEmailsTo('me@example.org'));
    }

    /**
     * Before sending by SMTP the port is checked: an internal server is refused, a closed
     * port named. Nothing is sent; the address is remembered.
     */
    public function testSendTestChecksTheSmtpPort()
    {
        $mailbox = $this->createMailbox([], ['out_method' => Mailbox::OUT_METHOD_SMTP]);
        $mailbox->fill(['out_server' => '127.0.0.1', 'out_port' => 1])->save();

        $internal = $this->mailboxAjax($this->admin, ['action' => 'send_test', 'mailbox_id' => $mailbox->id, 'to' => 'me@example.org']);
        $this->assertSame('error', $internal['status']);
        $this->assertNotEmpty($internal['msg']);
        $this->assertStringNotContainsString('is not available', $internal['msg']);

        config(['app.remote_host_white_list' => '127.0.0.1']);
        $closed = $this->mailboxAjax($this->admin, ['action' => 'send_test', 'mailbox_id' => $mailbox->id, 'to' => 'me@example.org']);
        $this->assertSame('error', $closed['status']);
        $this->assertStringStartsWith('127.0.0.1 is not available on 1 port.', $closed['msg']);

        $this->assertCount(0, $this->sentEmailsTo('me@example.org'));
        $this->assertSame('me@example.org', \Option::get('send_test_to'));
    }

    /**
     * Test Connection logs in to the IMAP server and opens INBOX.
     */
    public function testFetchTestAgainstAServer()
    {
        $mailbox = $this->imapMailbox($this->startImapServer());

        $response = $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => $mailbox->id]);

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertSame('', $response['msg']);
    }

    /**
     * A server that refuses the login: its answer is shown.
     */
    public function testFetchTestShowsTheServersError()
    {
        $mailbox = $this->imapMailbox($this->startImapServer(true));

        $response = $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => $mailbox->id]);

        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('Invalid credentials', $response['msg'].$response['log']);
        $this->assertNotSame('Error occurred connecting to the server', $response['msg']);
    }

    public function testFetchTestRefusesAnInternalServer()
    {
        $mailbox = $this->createMailbox();
        $mailbox->fill(['in_protocol' => Mailbox::IN_PROTOCOL_IMAP, 'in_server' => '10.0.0.1', 'in_port' => 993, 'in_username' => 'u', 'in_password' => 'p'])->save();

        $response = $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => $mailbox->id]);

        $this->assertSame('error', $response['status']);
        $this->assertNotEmpty($response['msg']);
        $this->assertStringNotContainsString('is not available', $response['msg']);
        $this->assertSame('Mailbox not found', $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => 0])['msg']);
    }

    /**
     * A module may test the connection itself (mailbox.fetch_test, e.g. for another
     * protocol): its answer is returned and the server isn't contacted.
     */
    public function testFetchTestByAModule()
    {
        $mailbox = $this->createMailbox();
        $mailbox->fill(['in_server' => '10.0.0.1', 'in_port' => 1])->save();

        \Eventy::addFilter('mailbox.fetch_test', function ($response, $mailbox) {
            return array_merge($response, ['tested' => true, 'status' => 'success', 'msg_success' => 'Tested by the module']);
        }, 20, 2);
        $tested = $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => $mailbox->id]);
        \Eventy::removeAllFilters('mailbox.fetch_test');

        $this->assertSame('success', $tested['status']);
        $this->assertSame('Tested by the module', $tested['msg_success']);

        // A module that tested without saying how it went: an error, never a silent success.
        \Eventy::addFilter('mailbox.fetch_test', function ($response) {
            return array_merge($response, ['tested' => true]);
        }, 20, 1);
        $silent = $this->mailboxAjax($this->admin, ['action' => 'fetch_test', 'mailbox_id' => $mailbox->id]);
        \Eventy::removeAllFilters('mailbox.fetch_test');

        $this->assertSame('error', $silent['status']);
        $this->assertSame('Unknown error occurred', $silent['msg']);
    }

    /**
     * Get IMAP Folders lists the server's folders, subfolders by their full path.
     */
    public function testImapFoldersFromAServer()
    {
        $agent = $this->createUser();
        $mailbox = $this->imapMailbox($this->startImapServer());
        $mailbox->users()->sync([$agent->id]);

        $response = $this->mailboxAjax($this->admin, ['action' => 'imap_folders', 'mailbox_id' => $mailbox->id]);

        $this->assertSame('success', $response['status'], json_encode($response));
        $this->assertSame(['INBOX', 'INBOX/Archive'], $response['folders']);
        $this->assertSame('IMAP folders retrieved: INBOX, INBOX/Archive', $response['msg_success']);

        $this->assertSame('Mailbox not found', $this->mailboxAjax($this->admin, ['action' => 'imap_folders', 'mailbox_id' => 0])['msg']);
        $this->assertSame('Not enough permissions', $this->mailboxAjax($agent, ['action' => 'imap_folders', 'mailbox_id' => $mailbox->id])['msg']);
    }

    /**
     * Folders as either IMAP library gives them: top-level ones by name, subfolders (at
     * any depth) by full name (fullName in the old library, full_name in the new one).
     */
    public function testIterateFolders()
    {
        $folders = [
            (object) ['name' => 'INBOX', 'full_name' => 'INBOX', 'children' => [
                (object) ['name' => 'Sales', 'full_name' => 'INBOX.Sales', 'children' => [
                    (object) ['name' => 'Leads', 'fullName' => 'INBOX.Sales.Leads'],
                ]],
            ]],
            (object) ['name' => 'Sent', 'fullName' => 'Sent'],
        ];

        $response = (new \App\Http\Controllers\MailboxesController())->interateFolders(['folders' => []], $folders);

        $this->assertSame(['INBOX', 'INBOX.Sales.Leads', 'INBOX.Sales', 'INBOX', 'Sent', 'Sent'], $response['folders']);
    }

    // Ajax: muting, deleting, unknown actions.

    public function testMuteAndDeleteEdges()
    {
        $outsider = $this->createUser();
        $mailbox = $this->createMailbox();

        $this->assertSame('Mailbox not found', $this->mailboxAjax($outsider, ['action' => 'mute', 'mailbox_id' => 0])['msg']);
        $this->assertSame('Not enough permissions', $this->mailboxAjax($outsider, ['action' => 'mute', 'mailbox_id' => $mailbox->id, 'mute' => 1])['msg']);

        // An admin not yet connected to the mailbox is connected, muted.
        $this->assertSame('success', $this->mailboxAjax($this->admin, ['action' => 'mute', 'mailbox_id' => $mailbox->id, 'mute' => 1])['status']);
        $this->assertEquals(1, \DB::table('mailbox_user')->where(['mailbox_id' => $mailbox->id, 'user_id' => $this->admin->id])->value('mute'));

        $this->assertSame('Mailbox not found', $this->mailboxAjax($this->admin, ['action' => 'delete_mailbox', 'mailbox_id' => 0, 'password' => 'admin-password'])['msg']);
        $this->assertSame('Unknown action', $this->mailboxAjax($this->admin, ['action' => 'no_such_action'])['msg']);
    }

    // oAuth.

    protected function oauthMailbox()
    {
        $mailbox = $this->createMailbox();
        $mailbox->fill([
            'in_protocol'  => Mailbox::IN_PROTOCOL_IMAP,
            'in_server'    => 'outlook.office365.com',
            'in_port'      => 993,
            'in_username'  => 'support@example.org:in-client-id',
            'in_password'  => 'in-client-secret',
            'out_username' => 'out-client-id',
            'out_password' => 'out-client-secret',
        ])->save();

        return $mailbox;
    }

    /**
     * Connecting starts at the provider's sign-in page with the mailbox's Client ID (its
     * username after a colon) and a state kept in the session.
     */
    public function testOauthStartsAtTheProvider()
    {
        $mailbox = $this->oauthMailbox();

        $response = $this->actingAs($this->admin)->get(route('mailboxes.oauth', ['id' => $mailbox->id, 'in_out' => 'in', 'provider' => \MailHelper::OAUTH_PROVIDER_MICROSOFT]));

        $location = $response->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('in-client-id', $query['client_id']);
        $this->assertSame(route('mailboxes.oauth_callback'), $query['redirect_uri']);
        $state = ['provider' => 'ms', 'mailbox_id' => (string) $mailbox->id, 'in_out' => 'in', 'state' => crc32('in-client-id'.'in-client-secret')];
        $this->assertSame($state, json_decode($query['state'], true));
        $this->assertSame($state, session('mailbox_oauth_ms_'.$mailbox->id));

        // Sending: the outgoing Client ID, at Google.
        $location = $this->actingAs($this->admin)->get(route('mailboxes.oauth', ['id' => $mailbox->id, 'in_out' => 'out', 'provider' => \MailHelper::OAUTH_PROVIDER_GOOGLE]))
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=out-client-id', $location);
    }

    /**
     * A provider Tallport doesn't know: said so, rather than an error page.
     */
    public function testOauthWithAnUnknownProvider()
    {
        $mailbox = $this->oauthMailbox();

        $this->actingAs($this->admin)->get(route('mailboxes.oauth', ['id' => $mailbox->id, 'in_out' => 'in', 'provider' => 'unknown-provider']))
            ->assertOk()->assertSee('Could not generate authorization URL');
        $this->assertNull(session('mailbox_oauth_unknown-provider_'.$mailbox->id));
    }

    public function testOauthNeedsAClientIdAndSecret()
    {
        $mailbox = $this->createMailbox();
        $url = route('mailboxes.oauth', ['id' => $mailbox->id, 'in_out' => 'in', 'provider' => 'ms']);

        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Enter oAuth Client ID as Username and save mailbox settings');
        $mailbox->fill(['in_username' => 'client-id'])->save();
        $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('Enter oAuth Client Secret as Password and save mailbox settings');

        $this->actingAs($this->admin)->get(route('mailboxes.oauth_callback'))->assertOk()->assertSee('Invalid oAuth Provider');
        $this->actingAs($this->admin)->get(route('mailboxes.oauth_callback', ['error' => 'invalid_request', 'error_description' => '<b>Bad request</b>']))
            ->assertOk()->assertSee('&lt;b&gt;Bad request&lt;/b&gt;', false);

        $agent = $this->createUser();
        $mailbox->users()->sync([$agent->id]);
        $this->actingAs($agent)->get($url)->assertStatus(403);
    }

    /**
     * The provider's answer must carry the state the session kept, or it's refused (CSRF);
     * with it, the code is exchanged and the user returns to the connection settings.
     * (Exchanging the code with Microsoft or Google itself goes over the network: not tested.)
     */
    public function testOauthCallbackChecksTheState()
    {
        $mailbox = $this->oauthMailbox();
        $state = ['provider' => 'unknown-provider', 'mailbox_id' => $mailbox->id, 'in_out' => 'in', 'state' => 12345];
        $session_key = 'mailbox_oauth_unknown-provider_'.$mailbox->id;

        $this->actingAs($this->admin)->withSession([$session_key => $state])
            ->get(route('mailboxes.oauth_callback', ['code' => 'auth-code', 'state' => json_encode(['state' => 99999] + $state)]))
            ->assertOk()->assertSee('Invalid oAuth state');
        $this->assertNull(session($session_key));

        $this->actingAs($this->admin)->withSession([$session_key => $state])
            ->get(route('mailboxes.oauth_callback', ['code' => 'auth-code', 'state' => json_encode($state)]))
            ->assertRedirect(route('mailboxes.connection.incoming', ['id' => $mailbox->id]));
        $this->assertFalse($mailbox->fresh()->oauthEnabled());

        // Sending: back to the outgoing settings.
        $state['in_out'] = 'out';
        $this->actingAs($this->admin)->withSession([$session_key => $state])
            ->get(route('mailboxes.oauth_callback', ['code' => 'auth-code', 'state' => json_encode($state)]))
            ->assertRedirect(route('mailboxes.connection', ['id' => $mailbox->id]));
    }

    /**
     * Disconnecting forgets the tokens and signs out at the provider; it needs the CSRF token.
     */
    public function testOauthDisconnect()
    {
        $mailbox = $this->oauthMailbox();
        $mailbox->setMetaParam('oauth', ['provider' => 'ms', 'a_token' => 'access', 'r_token' => 'refresh'], true);
        $url = function ($in_out, $provider, $token) use ($mailbox) {
            return route('mailboxes.oauth_disconnect', ['id' => $mailbox->id, 'in_out' => $in_out, 'provider' => $provider, 'token' => $token]);
        };

        \Session::start();
        $this->actingAs($this->admin)->get($url('in', 'ms', 'wrong-token'))->assertStatus(419);
        $this->assertTrue($mailbox->fresh()->oauthEnabled());

        $this->actingAs($this->admin)->get($url('in', 'ms', csrf_token()))
            ->assertRedirect('https://login.microsoftonline.com/common/oauth2/v2.0/logout?post_logout_redirect_uri='.urlencode(route('mailboxes.connection.incoming', ['id' => $mailbox->id])));
        $this->assertFalse($mailbox->fresh()->oauthEnabled());

        $this->actingAs($this->admin)->get($url('out', 'gw', csrf_token()))
            ->assertRedirect(route('mailboxes.connection', ['id' => $mailbox->id]));
    }
}
