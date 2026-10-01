<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use Tests\FeatureTestCase;

/**
 * Pages and endpoints not covered elsewhere: settings pages that only
 * render, the web installer (must be closed once installed), the realtime
 * (polycast) endpoints, logs, and the translation manager's access check.
 */
class PagesAndEndpointsTest extends FeatureTestCase
{
    protected $admin;
    protected $agent;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin();
        $this->agent = $this->createUser();
        $this->mailbox = $this->createMailbox([$this->agent]);
    }

    public function testPagesRender()
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Listed']));
        $customer_id = Conversation::where('mailbox_id', $this->mailbox->id)->value('customer_id');
        $closed = Folder::where('mailbox_id', $this->mailbox->id)->where('type', Folder::TYPE_UNASSIGNED)->first();

        $pages = [
            '/mailboxes',
            '/mailbox/'.$this->mailbox->id.'/'.$closed->id,
            '/mailbox/settings/'.$this->mailbox->id.'/auto-reply',
            '/users/wizard',
            '/users/notifications/'.$this->agent->id,
            '/users/password/'.$this->admin->id,
            '/system/tools',
            '/customers/'.$customer_id.'/merge',
            '/app-logs/app',
        ];
        foreach ($pages as $page) {
            $this->actingAs($this->admin)->get($page)->assertStatus(200);
        }
        $this->actingAs($this->admin)->get('/mailbox/'.$this->mailbox->id.'/'.$closed->id)->assertSee('Listed');
    }

    public function testGuestPages()
    {
        $this->get('/password/reset')->assertStatus(200);
        $this->get('/home')->assertRedirect();
    }

    public function testAgentsCannotOpenAdminPages()
    {
        foreach (['/users/wizard', '/system/tools', '/app-logs/app'] as $page) {
            $this->assertContains($this->actingAs($this->agent)->get($page)->status(), [403, 302], $page);
        }
        $this->actingAs($this->agent)->get('/users/notifications/'.$this->admin->id)->assertStatus(403);
    }

    /**
     * The translation manager was removed: translations ship complete with
     * every release (TranslationsTest).
     */
    public function testTranslationManagerIsGone()
    {
        $this->actingAs($this->admin)->get('/translations')->assertStatus(404);
    }

    public function testClearLog()
    {
        activity()->useLog(\App\ActivityLog::NAME_EMAILS_FETCHING)->log('fetch failed');

        \Session::start();
        $this->actingAs($this->admin)->post('/app-logs/fetch_errors', ['_token' => csrf_token(), 'action' => 'clean'])
            ->assertRedirect();

        $this->assertSame(0, \DB::table('activity_logs')->where('log_name', \App\ActivityLog::NAME_EMAILS_FETCHING)->count());
    }

    public function testLogPagesUseBootstrapThreePagination()
    {
        for ($i = 0; $i < 21; $i++) {
            activity()->useLog(\App\ActivityLog::NAME_EMAILS_FETCHING)->log('fetch failed '.$i);
        }

        $page = $this->actingAs($this->admin)->get('/app-logs/fetch_errors')->assertStatus(200)->getContent();

        $this->assertStringContainsString('<ul class="pagination"', $page);
        $this->assertStringNotContainsString('page-link', $page);
    }

    // Web installer.

    public function testInstallerIsClosedOnceInstalled()
    {
        $marker = storage_path('.installed');
        $created = false;
        if (!file_exists($marker)) {
            file_put_contents($marker, 'installed for tests');
            $created = true;
        }
        $env_before = file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null;

        try {
            foreach (['install', 'install/requirements', 'install/permissions', 'install/environment', 'install/environment/wizard', 'install/environment/classic', 'install/database', 'install/final'] as $page) {
                $this->get('/'.$page)->assertRedirect(route('dashboard'));
            }
            \Session::start();
            foreach (['install/environment/saveWizard', 'install/environment/saveClassic'] as $action) {
                $this->post('/'.$action, ['_token' => csrf_token(), 'envConfig' => "APP_KEY=hijacked\n"])->assertRedirect(route('dashboard'));
            }
        } finally {
            if ($created) {
                unlink($marker);
            }
        }

        $this->assertSame($env_before, file_exists(base_path('.env')) ? md5_file(base_path('.env')) : null, '.env must not change.');
    }

    // Realtime.

    public function testPolycastConnectNeedsLogin()
    {
        \Session::start();
        $this->post('/polycast/connect', ['_token' => csrf_token()])->assertStatus(403);

        $response = $this->actingAs($this->agent)->post('/polycast/connect', ['_token' => csrf_token()]);
        $response->assertStatus(200);
        $this->assertSame('success', $response->json()['status']);
    }

    public function testPolycastReceiveOnlyOwnPrivateChannel()
    {
        \Session::start();
        $receive = function ($channels) {
            return $this->actingAs($this->agent)->post('/polycast/receive', [
                '_token'   => csrf_token(),
                'channels' => $channels,
                'time'     => now()->subMinute()->toDateTimeString(),
            ]);
        };

        $receive([])->assertStatus(403);
        $receive(['private-App.User.'.$this->agent->id => ['App\\Events\\RealtimeBroadcastNotificationCreated']])->assertStatus(200);
        $receive(['private-App.User.'.$this->admin->id => ['App\\Events\\RealtimeBroadcastNotificationCreated']])->assertStatus(403);
    }
}
