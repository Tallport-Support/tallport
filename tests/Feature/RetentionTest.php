<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Livewire\ConversationToolbar;
use App\Retention\Retention;
use App\Thread;
use Livewire\Livewire;
use Tests\FeatureTestCase;

/**
 * Retention (Settings » Retention, App\Retention\Retention): conversations expire,
 * are restored when the customer writes again, and are deleted for good after the
 * grace period; trash, spam, customers without conversations and logs have their
 * own periods; legal holds keep everything.
 */
class RetentionTest extends FeatureTestCase
{
    protected $admin;
    protected $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        \Option::$cache = [];
        $this->admin = $this->createAdmin();
        $this->mailbox = $this->createMailbox([$this->admin]);
        \Option::set('retention_enabled', true);
    }

    protected function tearDown(): void
    {
        \Option::$cache = [];
        parent::tearDown();
    }

    /**
     * A conversation from the customer, closed, its last message and the
     * customer's last contact $months ago.
     */
    protected function conversation($from, $months, $status = Conversation::STATUS_CLOSED)
    {
        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => $from, 'to' => $this->mailbox->email, 'subject' => 'Question from '.$from]));
        $conversation = Conversation::where('mailbox_id', $this->mailbox->id)->orderBy('id', 'desc')->first();
        $conversation->status = $status;
        $conversation->updateFolder();
        $conversation->save();
        Conversation::where('id', $conversation->id)->update(['last_reply_at' => now()->subMonths($months)]);
        Customer::where('id', $conversation->customer_id)->update(['last_contact_at' => now()->subMonths($months)]);

        return $conversation->fresh();
    }

    public function testClosedConversationsExpireOnTheCustomersClock()
    {
        $old = $this->conversation('casey@customer.example.org', 30);
        $recent = $this->conversation('sam@customer.example.org', 6);
        $open = $this->conversation('pat@customer.example.org', 30, Conversation::STATUS_ACTIVE);
        // An old conversation, but its customer wrote last month (in another conversation).
        $kept = $this->conversation('lee@customer.example.org', 30);
        Customer::where('id', $kept->customer_id)->update(['last_contact_at' => now()->subMonth()]);
        // On legal hold, itself or through its customer.
        $held = $this->conversation('jo@customer.example.org', 30);
        $held->retention_hold_at = now();
        $held->save();
        $held_customer = $this->conversation('max@customer.example.org', 30);
        Customer::where('id', $held_customer->customer_id)->update(['retention_hold_at' => now()]);

        $this->assertSame(1, Retention::run(true)['expired'], 'A dry run only counts.');
        $this->assertNull($old->fresh()->expired_at);

        $this->artisan('tallport:retention')->assertExitCode(0);
        $old = $old->fresh();
        $this->assertNotNull($old->expired_at);
        $this->assertSame(Conversation::STATE_DELETED, (int) $old->state);
        $this->assertSame(Folder::TYPE_DELETED, $old->folder->type);
        foreach ([$recent, $open, $kept, $held, $held_customer] as $conversation) {
            $this->assertNull($conversation->fresh()->expired_at, $conversation->subject);
        }
        $this->assertSame(1, \Option::get(Retention::LAST_RUN_OPTION)['expired']);

        // In the Deleted folder, marked Expired.
        $this->actingAs($this->admin)->get($old->folder->url($this->mailbox->id))->assertSee('conv-expired', false);
    }

    public function testTheMaximumAgeAppliesToCustomersWhoStillWrite()
    {
        $conversation = $this->conversation('casey@customer.example.org', 100);
        Customer::where('id', $conversation->customer_id)->update(['last_contact_at' => now()]);

        Retention::expire();
        $this->assertNull($conversation->fresh()->expired_at);

        \Option::set('retention_max_age_years', 7);
        Retention::expire();
        $this->assertNotNull($conversation->fresh()->expired_at);
    }

    public function testTheCustomerWritingAgainRestoresTheirConversations()
    {
        $conversation = $this->conversation('casey@customer.example.org', 30);
        Retention::expire();
        $this->assertNotNull($conversation->fresh()->expired_at);

        $this->receiveEmail($this->mailbox, $this->makeEmail(['from' => 'casey@customer.example.org', 'to' => $this->mailbox->email, 'subject' => 'Me again']));
        $conversation = $conversation->fresh();
        $this->assertNull($conversation->expired_at);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->state);
        $this->assertSame(Folder::TYPE_CLOSED, $conversation->folder->type);
        $this->assertTrue(Customer::find($conversation->customer_id)->last_contact_at->isToday());
    }

    public function testRestoredByHandItsClockStartsOver()
    {
        $conversation = $this->conversation('casey@customer.example.org', 30);
        Retention::expire();
        \App\Misc\ConversationActions::restore($conversation->fresh(), $this->admin);
        $this->assertSame(Conversation::STATE_PUBLISHED, (int) $conversation->fresh()->state);

        Retention::expire();
        $this->assertNull($conversation->fresh()->expired_at);
        $this->assertNotNull($conversation->fresh()->retention_reset_at);
    }

    public function testExpiredConversationsAreDeletedForGoodWithWhatsKeptAboutThem()
    {
        $conversation = $this->conversation('casey@customer.example.org', 30);
        $thread = $conversation->threads()->first();
        \DB::table('send_logs')->insert(['thread_id' => $thread->id, 'email' => 'casey@customer.example.org', 'mail_type' => 1, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('notifications')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'x', 'notifiable_id' => $this->admin->id, 'notifiable_type' => \App\User::class, 'data' => '{}', 'conversation_id' => $conversation->id, 'created_at' => now(), 'updated_at' => now()]);
        \DB::table('report_replies')->insert(['thread_id' => $thread->id, 'conversation_id' => $conversation->id, 'user_id' => $this->admin->id, 'replied_at' => now()]);

        Retention::expire();
        Retention::deleteExpired();
        $this->assertNotNull(Conversation::find($conversation->id), 'Within the grace period.');

        Conversation::where('id', $conversation->id)->update(['expired_at' => now()->subDays(91)]);
        $this->assertSame(1, Retention::deleteExpired());
        $this->assertNull(Conversation::find($conversation->id));
        $this->assertSame(0, Thread::where('conversation_id', $conversation->id)->count());
        $this->assertSame(0, \DB::table('send_logs')->where('thread_id', $thread->id)->count());
        $this->assertSame(0, \DB::table('notifications')->where('conversation_id', $conversation->id)->count());
        $this->assertSame(0, \DB::table('report_replies')->where('conversation_id', $conversation->id)->count());
    }

    public function testTrashSpamAndCustomersWithoutConversations()
    {
        $trash = $this->conversation('casey@customer.example.org', 1);
        $trash->state = Conversation::STATE_DELETED;
        $trash->save();
        Conversation::where('id', $trash->id)->update(['user_updated_at' => now()->subDays(31)]);
        $spam = $this->conversation('sam@customer.example.org', 1, Conversation::STATUS_SPAM);
        Conversation::where('id', $spam->id)->update(['updated_at' => now()->subDays(31)]);
        $fresh_trash = $this->conversation('pat@customer.example.org', 1);
        $fresh_trash->state = Conversation::STATE_DELETED;
        $fresh_trash->save();

        $counts = Retention::run();
        $this->assertSame(1, $counts['trash']);
        $this->assertSame(1, $counts['spam']);
        $this->assertNull(Conversation::find($trash->id));
        $this->assertNull(Conversation::find($spam->id));
        $this->assertNotNull(Conversation::find($fresh_trash->id));

        // Their customers: deleted once they've had no contact for the period.
        $this->assertNotNull(Customer::find($trash->customer_id));
        Customer::where('id', $trash->customer_id)->update(['last_contact_at' => now()->subMonths(13)]);
        $this->assertSame(1, Retention::deleteCustomers());
        $this->assertNull(Customer::find($trash->customer_id));
        $this->assertNull(Customer::getByEmail('casey@customer.example.org'));
        $this->assertNotNull(Customer::find($fresh_trash->customer_id));
    }

    public function testLogsAreCleanedEvenWhenRetentionIsOff()
    {
        \Option::set('retention_enabled', false);
        $old = $this->conversation('casey@customer.example.org', 30);
        \DB::table('activity_logs')->insert(['log_name' => 'system', 'description' => 'old', 'created_at' => now()->subDays(100), 'updated_at' => now()]);
        \DB::table('failed_jobs')->insert(['connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()->subDays(40)]);

        $counts = Retention::run();
        $this->assertSame(1, $counts['logs']['activity_log']);
        $this->assertSame(1, $counts['logs']['failed_jobs']);
        $this->assertArrayNotHasKey('expired', $counts);
        $this->assertNull($old->fresh()->expired_at);
    }

    public function testSettingsAndLegalHolds()
    {
        $this->actingAs($this->admin)->get(route('settings', ['section' => 'retention']))->assertOk()
            ->assertSee('Keep Closed Conversations For')->assertSee('2 years')->assertSee('18 months');
        \Session::start();
        $this->actingAs($this->admin)->post(route('settings', ['section' => 'retention']), [
            '_token' => csrf_token(), 'settings' => ['retention_enabled' => 1, 'retention_keep_months' => 36, 'retention_grace_days' => 13],
        ])->assertRedirect();
        $this->assertSame(36, Retention::get('retention_keep_months'));
        $this->assertSame(90, Retention::get('retention_grace_days'), 'Not one of the choices: the default.');

        // A conversation on legal hold (its toolbar's menu), a customer (their profile).
        $conversation = $this->conversation('casey@customer.example.org', 1);
        Livewire::actingAs($this->admin)->test(ConversationToolbar::class, ['conversation' => $conversation])->call('toggleHold');
        $this->assertNotNull($conversation->fresh()->retention_hold_at);
        $agent = $this->createUser();
        $this->mailbox->users()->attach($agent->id);
        Livewire::actingAs($agent)->test(ConversationToolbar::class, ['conversation' => $conversation])->call('toggleHold')->assertForbidden();

        $customer = $conversation->customer;
        $this->actingAs($this->admin)->post(route('customers.update', ['id' => $customer->id]), [
            '_token' => csrf_token(), 'first_name' => 'Casey', 'emails' => ['casey@customer.example.org'], 'retention_hold' => 1,
        ]);
        $this->assertSame($this->admin->id, (int) $customer->fresh()->retention_hold_by);

        $this->actingAs($this->admin)->get(route('system'))->assertOk();
    }

    public function testFilesNoRecordPointsTo()
    {
        // Never the real storage: the sweep removes what this database doesn't know.
        \Storage::fake('local');
        \Storage::fake(\App\Attachment::getDiskName());
        $customer = $this->conversation('casey@customer.example.org', 1)->customer;
        $customer->photo_url = 'kept.jpg';
        $customer->save();
        foreach (['kept.jpg', 'orphan.jpg', 'new.jpg'] as $file) {
            \Storage::disk('local')->put(Customer::PHOTO_DIRECTORY.'/'.$file, 'x');
        }
        foreach (['kept.jpg', 'orphan.jpg'] as $file) {
            touch(\Storage::disk('local')->path(Customer::PHOTO_DIRECTORY.'/'.$file), now()->subDays(2)->getTimestamp());
        }

        $this->artisan('tallport:retention', ['--sweep-files' => true])->assertExitCode(0);
        $this->assertTrue(\Storage::disk('local')->exists(Customer::PHOTO_DIRECTORY.'/kept.jpg'));
        $this->assertFalse(\Storage::disk('local')->exists(Customer::PHOTO_DIRECTORY.'/orphan.jpg'));
        $this->assertTrue(\Storage::disk('local')->exists(Customer::PHOTO_DIRECTORY.'/new.jpg'), 'Too new: maybe being saved.');
    }

    public function testThePreviewFollowsUnsavedChanges()
    {
        $this->conversation('casey@customer.example.org', 8);
        $this->conversation('sam@customer.example.org', 30);

        $preview = Livewire::actingAs($this->admin)->test(\App\Livewire\RetentionPreview::class)
            ->assertSee('1 conversations expire');
        $preview->dispatch('retention-settings-changed', settings: ['_token' => 'x', 'settings[retention_keep_months]' => '6'])
            ->assertSee('2 conversations expire');
        $this->assertSame(24, Retention::get('retention_keep_months'), 'Not saved.');

        Livewire::actingAs($this->createUser())->test(\App\Livewire\RetentionPreview::class)->assertForbidden();
    }
}
