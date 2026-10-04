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
                    $this->assertSame($conversation->threads()->whereIn('type', [Thread::TYPE_CUSTOMER, Thread::TYPE_MESSAGE])
                        ->where('state', Thread::STATE_PUBLISHED)->count(), $conversation->threads_count);
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

    public function testSeederAddsLongExchangesToAlreadyPopulatedMailboxes()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        foreach (Conversation::where('threads_count', '>=', 20)->get() as $conversation) {
            $conversation->threads()->delete();
            $conversation->delete();
        }
        $existing = Conversation::orderBy('id')->get()->toArray();
        $before = Conversation::count();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before + 6, Conversation::count());
        $this->assertSame($existing, Conversation::whereIn('id', array_column($existing, 'id'))->orderBy('id')->get()->toArray());
        foreach (Mailbox::all() as $mailbox) {
            $long = $mailbox->conversations()->where('threads_count', '>=', 20)->get();
            $this->assertCount(2, $long);
            foreach ($long as $conversation) {
                $threads = $conversation->threads()->orderBy('created_at')->get();
                $this->assertCount(24, $threads);
                $this->assertSame(23, $conversation->threads_count);
                $this->assertTrue($threads->every(fn ($thread) => $thread->state == Thread::STATE_PUBLISHED));
                $this->assertGreaterThanOrEqual(2, $threads->filter(fn ($thread) => strlen($thread->body) >= 1000)->count());
                $this->assertStringContainsString('</p><p>', $threads[4]->body);
                $this->assertTrue($conversation->last_reply_at->eq($threads->last()->created_at));
                $this->assertSame(Thread::TYPE_CUSTOMER, $threads->last()->type);
            }
        }
        $before = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
    }

    public function testSeederBackfillsAgentParticipationAndAiCachesWithoutProviderCalls()
    {
        \App\Ai\Agents\ConversationSummarizer::fake()->preventStrayPrompts();
        \App\Ai\Agents\ThreadTranslator::fake()->preventStrayPrompts();
        Queue::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame(3, User::count());
        $agent = User::orderBy('id')->first();
        \DB::table('threads')->whereIn('type', [Thread::TYPE_MESSAGE, Thread::TYPE_NOTE])
            ->update(['created_by_user_id' => $agent->id]);
        \DB::table('threads')->update(['ai_assistant' => null, 'ai_assistant_updated_at' => null]);
        \DB::table('conversations')->update(['ai_assistant' => null, 'ai_assistant_updated_at' => null]);
        $before_count = Conversation::count();
        $before_threads = Thread::orderBy('id')->get(['id', 'body', 'created_at', 'updated_at'])->toArray();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before_count, Conversation::count());
        $this->assertSame($before_threads, Thread::whereIn('id', array_column($before_threads, 'id'))->orderBy('id')->get(['id', 'body', 'created_at', 'updated_at'])->toArray());
        \App\Option::set('aiassistant.api_key', encrypt('sk-test'));
        foreach (Conversation::all() as $conversation) {
            $participants = $conversation->threads()->where('state', Thread::STATE_PUBLISHED)
                ->where('type', Thread::TYPE_MESSAGE)->whereNotNull('created_by_user_id')->distinct()->count('created_by_user_id');
            $this->assertSame(3, $participants);
            foreach (array_keys(\App\Ai\Settings::LANGUAGES) as $language) {
                $this->assertFalse(\App\Ai\Summaries::isStale($conversation, $language));
                (new \App\Jobs\AiSummarizeConversation($conversation->id, $language))->handle();
            }
        }
        foreach (Thread::where('type', Thread::TYPE_CUSTOMER)->get() as $thread) {
            foreach (array_keys(\App\Ai\Settings::LANGUAGES) as $language) {
                $this->assertFalse(\App\Ai\Translations::isMissing($thread, $language));
                (new \App\Jobs\AiTranslateThread($thread->id, $language))->handle();
            }
        }
        $before = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
        \App\Ai\Agents\ConversationSummarizer::assertNeverPrompted();
        \App\Ai\Agents\ThreadTranslator::assertNeverPrompted();
        Queue::assertNothingPushed();
    }

    public function testAiBackfillPreservesRealConversationsAndExistingAiResults()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $real = Conversation::orderBy('id')->first();
        \DB::table('conversations')->where('id', $real->id)->update(['imported' => false, 'ai_assistant' => null]);
        \DB::table('threads')->where('conversation_id', $real->id)->update(['ai_assistant' => null]);
        $real_before = $real->fresh()->toArray();
        $threads_before = $real->threads()->orderBy('id')->get()->toArray();
        $sample = Conversation::where('id', '<>', $real->id)->first();
        $summary = ['one_liner' => 'A real summary', 'summary' => 'Keep this text.', 'thread_id' => 0, 'at' => '2026-10-01 12:00:00'];
        $sample->ai_assistant = json_encode(['summaries' => ['en' => $summary]]);
        $sample->saveQuietly();
        $thread = $sample->threads()->where('type', Thread::TYPE_CUSTOMER)->first();
        $thread->ai_assistant = json_encode(['translations' => ['fr' => 'Une vraie traduction.']]);
        $thread->saveQuietly();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($real_before, $real->fresh()->toArray());
        $this->assertSame($threads_before, $real->threads()->orderBy('id')->get()->toArray());
        $this->assertSame($summary, \App\Ai\Summaries::get($sample->fresh(), 'en'));
        $this->assertSame('Une vraie traduction.', \App\Ai\Translations::get($thread->fresh(), 'fr'));
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
