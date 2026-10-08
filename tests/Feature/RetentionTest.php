<?php

namespace Tests\Feature;

use App\Ai\DraftJob;
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
        $completed_draft = new DraftJob();
        $completed_draft->conversation_id = $conversation->id;
        $completed_draft->user_id = $this->admin->id;
        $completed_draft->status = DraftJob::STATUS_COMPLETED;
        $completed_draft->locale = 'en';
        $completed_draft->document_limit = 3;
        $completed_draft->result = ['draft' => 'Private reply', 'retrieved_documents' => [['excerpt' => 'Private excerpt']]];
        $completed_draft->started_at = now();
        $completed_draft->completed_at = now();
        $completed_draft->save();
        $failed_draft = new DraftJob();
        $failed_draft->conversation_id = $conversation->id;
        $failed_draft->user_id = $this->admin->id;
        $failed_draft->status = DraftJob::STATUS_FAILED;
        $failed_draft->error_type = \RuntimeException::class;
        $failed_draft->error_message = 'Private error';
        $failed_draft->error_detail = 'Private detail';
        $failed_draft->save();
        $old_draft = new DraftJob();
        $old_draft->conversation_id = $conversation->id;
        $old_draft->user_id = $this->admin->id;
        $old_draft->status = DraftJob::STATUS_COMPLETED;
        $old_draft->result = ['draft' => 'Old private reply'];
        $old_draft->save();
        DraftJob::whereKey($old_draft->id)->update(['created_at' => now()->subDay()]);

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
        $this->assertNull($old_draft->fresh(), 'An earlier day no longer needs a quota row.');
        $this->assertSame(2, DraftJob::countToday($this->admin));
        $this->assertSame(0, DraftJob::where('conversation_id', $conversation->id)->count());
        foreach ([$completed_draft, $failed_draft] as $draft) {
            $draft = $draft->fresh();
            $this->assertSame(DraftJob::STATUS_DELETED, $draft->status);
            $this->assertNull($draft->conversation_id);
            $this->assertNull($draft->locale);
            $this->assertSame(0, $draft->document_limit);
            $this->assertNull($draft->result);
            $this->assertNull($draft->error_type);
            $this->assertNull($draft->error_message);
            $this->assertNull($draft->error_detail);
            $this->assertNull($draft->started_at);
            $this->assertNull($draft->completed_at);
        }
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
        $today_draft = new DraftJob();
        $today_draft->conversation_id = $old->id;
        $today_draft->user_id = $this->admin->id;
        $today_draft->status = DraftJob::STATUS_COMPLETED;
        $today_draft->result = ['draft' => 'Today'];
        $today_draft->save();
        $yesterday_draft = new DraftJob();
        $yesterday_draft->conversation_id = $old->id;
        $yesterday_draft->user_id = $this->admin->id;
        $yesterday_draft->status = DraftJob::STATUS_COMPLETED;
        $yesterday_draft->result = ['draft' => 'Yesterday'];
        $yesterday_draft->save();
        DraftJob::whereKey($yesterday_draft->id)->update(['created_at' => now()->subDay()]);
        $undated_draft = new DraftJob();
        $undated_draft->conversation_id = $old->id;
        $undated_draft->user_id = $this->admin->id;
        $undated_draft->status = DraftJob::STATUS_COMPLETED;
        $undated_draft->result = ['draft' => 'Undated'];
        $undated_draft->save();
        DraftJob::whereKey($undated_draft->id)->update(['created_at' => null]);

        $this->assertSame(2, Retention::run(true)['logs']['ai_drafts']);
        $this->assertSame(1, DraftJob::countToday($this->admin));
        $counts = Retention::run();
        $this->assertSame(1, $counts['logs']['activity_log']);
        $this->assertSame(1, $counts['logs']['failed_jobs']);
        $this->assertSame(2, $counts['logs']['ai_drafts']);
        $this->assertNull($yesterday_draft->fresh());
        $this->assertNull($undated_draft->fresh());
        $this->assertSame(['draft' => 'Today'], $today_draft->fresh()->result);
        $this->assertSame(1, DraftJob::countToday($this->admin));
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

    /**
     * Past the maximum age, a customer writing again doesn't bring a conversation back.
     */
    public function testTooOldToBeRestored()
    {
        $old = $this->conversation('casey@customer.example.org', 100);
        $recent = $this->conversation('casey@customer.example.org', 30);
        Retention::expire();
        \Option::set('retention_max_age_years', 7);
        $this->assertNotNull($old->fresh()->expired_at);
        $this->assertNotNull($recent->fresh()->expired_at);

        Retention::customerContacted($old->customer);
        Retention::customerContacted(null);

        $this->assertNotNull($old->fresh()->expired_at, 'Over 7 years old.');
        $this->assertNull($recent->fresh()->expired_at);
    }

    /**
     * Attachment files no attachment points to go; those it points to stay.
     */
    public function testAttachmentFilesNoRecordPointsTo()
    {
        \Storage::fake('local');
        \Storage::fake(\App\Attachment::getDiskName());
        $conversation = $this->conversation('casey@customer.example.org', 1);
        $attachment = \App\Attachment::create('kept.txt', 'text/plain', null, 'kept', null, false, $conversation->threads()->first()->id);
        $disk = \Storage::disk(\App\Attachment::getDiskName());
        $kept = $attachment->getStorageFilePath();
        $orphan = \App\Attachment::DIRECTORY.'/9/9/9/orphan.txt';
        $disk->put($orphan, 'x');
        foreach ([$kept, $orphan] as $file) {
            touch($disk->path($file), now()->subDays(2)->getTimestamp());
        }

        $this->assertSame(1, Retention::sweepFiles(true), 'A dry run counts.');
        $this->assertTrue($disk->exists($orphan));
        $this->assertSame(1, Retention::sweepFiles());
        $this->assertFalse($disk->exists($orphan));
        $this->assertTrue($disk->exists($kept));
    }

    /**
     * System Status: what waits to be deleted for good, and when the first goes.
     */
    public function testWhatWaitsIsShown()
    {
        $this->assertSame(['count' => 0, 'next' => null], Retention::pending());
        $conversation = $this->conversation('casey@customer.example.org', 30);
        Retention::expire();
        $expired_at = $conversation->fresh()->expired_at;

        $pending = Retention::pending();
        $this->assertSame(1, $pending['count']);
        $this->assertSame($expired_at->copy()->addDays(Retention::get('retention_grace_days'))->format('Y-m-d'), $pending['next']->format('Y-m-d'));
        \Livewire\Livewire::withoutLazyLoading();
        \Livewire\Livewire::actingAs($this->admin)->test(\App\Livewire\SystemStatus::class)->assertSee('1 waiting, the first to be deleted on');
    }
}
