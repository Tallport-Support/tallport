<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\SendLog;
use Tests\FeatureTestCase;

/**
 * SecureController: the Logs pages (activity logs with who did it and their
 * details, outgoing emails of one message) and uploads without a file.
 */
class SecureControllerTest extends FeatureTestCase
{
    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdmin(['first_name' => 'Ada', 'last_name' => 'Admin']);
    }

    public function testActivityLogShowsWhoAndDetails()
    {
        $customer = $this->createCustomer('casey@customer.example.org', ['first_name' => 'Casey', 'last_name' => 'Buyer']);
        activity()->causedBy($this->admin)->withProperties(['ip' => '192.0.2.10'])
            ->useLog(ActivityLog::NAME_USER)->log(ActivityLog::DESCRIPTION_USER_LOGIN);
        activity()->causedBy($customer)->withProperties(['ip' => '192.0.2.20', 'context' => ['attempts' => 3]])
            ->useLog(ActivityLog::NAME_USER)->log('Custom event');

        $response = $this->actingAs($this->admin)->get('/app-logs/'.ActivityLog::NAME_USER);

        $response->assertStatus(200)
            ->assertSee('Logged in')
            ->assertSee('Custom event')
            ->assertSee('Ada Admin')
            ->assertSee('Casey Buyer')
            ->assertSee('192.0.2.10')
            ->assertSee('192.0.2.20')
            ->assertSee('{"attempts":3}');
    }

    public function testOutgoingEmailsOfOneMessage()
    {
        $mailbox = $this->createMailbox([$this->admin]);
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $mailbox->email, 'subject' => 'First']));
        $this->receiveEmail($mailbox, $this->makeEmail(['from' => 'sam@customer.example.org', 'to' => $mailbox->email, 'subject' => 'Second']));
        $first = Conversation::where('subject', 'First')->first();
        $second = Conversation::where('subject', 'Second')->first();
        SendLog::log($first->threads()->first()->id, 'm1@example.org', 'first-recipient@example.org', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_ACCEPTED);
        SendLog::log($second->threads()->first()->id, 'm2@example.org', 'second-recipient@example.org', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_ACCEPTED);

        $all = $this->actingAs($this->admin)->get('/app-logs/out_emails');
        $all->assertStatus(200)->assertSee('first-recipient@example.org')->assertSee('second-recipient@example.org');
        $all->assertSee('#'.$first->number.'</a>', false);

        $this->actingAs($this->admin)->get('/app-logs/out_emails?thread_id='.$first->threads()->first()->id)
            ->assertStatus(200)
            ->assertSee('first-recipient@example.org')
            ->assertDontSee('second-recipient@example.org');
    }

    public function testOutgoingEmailsAreTheDefaultLog()
    {
        SendLog::log(null, 'm1@example.org', 'recipient@example.org', SendLog::MAIL_TYPE_EMAIL_TO_CUSTOMER, SendLog::STATUS_ACCEPTED);
        activity()->causedBy($this->admin)->useLog(ActivityLog::NAME_USER)->log(ActivityLog::DESCRIPTION_USER_LOGIN);

        $this->actingAs($this->admin)->get('/app-logs')
            ->assertStatus(200)
            ->assertSee('recipient@example.org')
            ->assertDontSee('Logged in');

        // Outgoing emails can't be cleared.
        \Session::start();
        $this->actingAs($this->admin)->post('/app-logs', ['_token' => csrf_token(), 'action' => 'clean'])
            ->assertRedirect(route('logs', ['name' => ActivityLog::NAME_OUT_EMAILS]));
        $this->assertSame(1, SendLog::count());
        $this->assertSame(1, ActivityLog::where('log_name', ActivityLog::NAME_USER)->count());
    }

    public function testClearALog()
    {
        activity()->causedBy($this->admin)->useLog(ActivityLog::NAME_USER)->log(ActivityLog::DESCRIPTION_USER_LOGIN);
        activity()->useLog(ActivityLog::NAME_SYSTEM)->log(ActivityLog::DESCRIPTION_SYSTEM_ERROR);

        \Session::start();
        $this->actingAs($this->admin)->post('/app-logs/'.ActivityLog::NAME_USER, ['_token' => csrf_token(), 'action' => 'clean'])
            ->assertRedirect(route('logs', ['name' => ActivityLog::NAME_USER]))
            ->assertSessionHas('flash_success_floating', 'Log successfully cleared');

        $this->assertSame(0, ActivityLog::where('log_name', ActivityLog::NAME_USER)->count());
        $this->assertSame(1, ActivityLog::where('log_name', ActivityLog::NAME_SYSTEM)->count());
    }

    public function testUploadWithoutAFile()
    {
        $response = $this->actingAs($this->admin)->post('/uploads/upload', [], ['X-Requested-With' => 'XMLHttpRequest'])->json();

        $this->assertSame(['status' => 'error', 'msg' => 'Error occurred uploading file'], $response);
    }
}
