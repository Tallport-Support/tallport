<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Support\Facades\Queue;
use Tests\FeatureTestCase;

class DatabaseSeederTest extends FeatureTestCase
{
    public function testSeederFillsAllFoldersWithoutSendingMailAndIsRepeatable()
    {
        Queue::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame(3, Mailbox::count());
        $this->assertSame(12, Customer::count());
        foreach (Mailbox::all() as $mailbox) {
            foreach ($mailbox->folders as $folder) {
                $user_id = $folder->user_id ?: User::orderBy('id')->first()->id;
                $conversations = Conversation::getQueryByFolder($folder, $user_id)->get();
                $minimum = in_array($folder->type, Folder::$personal_types) ? 2 : 4;
                $this->assertGreaterThanOrEqual($minimum, $conversations->count(), $folder->type.' in '.$mailbox->name);
                $this->assertGreaterThanOrEqual($minimum, $folder->total_count);
                foreach ($conversations as $conversation) {
                    $this->assertNotNull($conversation->customer);
                    $this->assertNotEmpty($conversation->customer->emails);
                    $this->assertSame(3, $conversation->threads_count);
                    $this->assertTrue($conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->exists());
                    $this->assertTrue($conversation->threads()->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED)->exists());
                    if ($folder->type == Folder::TYPE_DRAFTS) {
                        $this->assertTrue($conversation->threads()->where('state', Thread::STATE_DRAFT)->exists());
                    }
                }
            }
        }
        $before = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederOnlyReplenishesTheFolderBelowItsMinimum()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $folder = Folder::where('type', Folder::TYPE_CLOSED)->first();
        $conversation = $folder->conversations()->first();
        $conversation->threads()->delete();
        $conversation->delete();
        $before = Conversation::count();
        $unaffected = Conversation::where('folder_id', '<>', $folder->id)->orderBy('id')->get()->toArray();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before + 1, Conversation::count());
        $this->assertSame(4, $folder->conversations()->count());
        $this->assertSame($unaffected, Conversation::where('folder_id', '<>', $folder->id)->orderBy('id')->get()->toArray());
    }

    public function testSeederCountsExistingSamplesAndPreservesTheirContents()
    {
        $admin = $this->createAdmin();
        $this->createUser();
        foreach (['Existing support', 'Existing billing', 'Existing sales'] as $name) {
            $this->createMailbox([$admin], ['name' => $name]);
        }
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $conversation = Conversation::whereHas('folder', function ($query) {
            $query->where('type', Folder::TYPE_UNASSIGNED);
        })->first();
        $conversation->subject = 'An existing conversation edited by a person';
        $conversation->save();
        $conversation->threads()->update(['body' => '<p>Existing correspondence</p>', 'message_id' => null]);
        $before = $this->snapshot();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['Existing support', 'Existing billing', 'Existing sales'], Mailbox::orderBy('id')->pluck('name')->all());
    }

    public function testConversationsWithoutRepliesDoNotSatisfyTheSampleMinimum()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $folder = Folder::where('type', Folder::TYPE_UNASSIGNED)->first();
        $conversation = $folder->conversations()->first();
        $conversation->threads()->where('type', Thread::TYPE_MESSAGE)->delete();
        $before = Conversation::count();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before + 1, Conversation::count());
        $this->assertSame(4, $folder->conversations()->whereHas('threads', function ($query) {
            $query->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED);
        })->count());
        $this->assertFalse($conversation->threads()->where('type', Thread::TYPE_MESSAGE)->exists());
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before + 1, Conversation::count());
    }

    private function snapshot()
    {
        $snapshot = [];
        foreach (['users', 'mailboxes', 'customers', 'emails', 'conversations', 'threads', 'folders', 'conversation_folder', 'mailbox_user'] as $table) {
            $snapshot[$table] = \DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
