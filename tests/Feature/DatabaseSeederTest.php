<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\CustomerChannel;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\Telegram\Telegram;
use App\User;
use App\Workflow;
use App\Workflows\Conditions;
use App\Workflows\Runner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Tests\FeatureTestCase;

class DatabaseSeederTest extends FeatureTestCase
{
    protected $sample_storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sample_storage = sys_get_temp_dir().'/tallport-seeder-'.uniqid();
        mkdir($this->sample_storage.'/app', 0777, true);
        $this->app->useStoragePath($this->sample_storage);
        config(['app.incoming_mail_retention_days' => 30]);
    }

    protected function tearDown(): void
    {
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->sample_storage);
        parent::tearDown();
    }

    public function testSeederFillsAllFoldersWithoutSendingMailAndIsRepeatable()
    {
        Queue::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame(3, Mailbox::count());
        $this->assertSame(13, Customer::whereHas('emails')->count());
        foreach (Mailbox::all() as $mailbox) {
            foreach ($mailbox->folders as $folder) {
                $user_id = $folder->user_id ?: User::orderBy('id')->first()->id;
                $conversations = Conversation::getQueryByFolder($folder, $user_id)->get();
                $minimum = in_array($folder->type, Folder::$personal_types) ? 2 : 4;
                $this->assertGreaterThanOrEqual($minimum, $conversations->count(), $folder->type.' in '.$mailbox->name);
                $this->assertGreaterThanOrEqual($minimum, $folder->total_count);
                foreach ($conversations as $conversation) {
                    $this->assertNotNull($conversation->customer);
                    if (!$conversation->hasChannel()) {
                        $this->assertNotEmpty($conversation->customer->emails);
                    }
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
        $this->assertSame(4, $folder->conversations()->where('type', Conversation::TYPE_EMAIL)->whereNull('channel')->count());
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
        $this->assertSame(4, $folder->conversations()->where('type', Conversation::TYPE_EMAIL)->whereNull('channel')->whereHas('threads', function ($query) {
            $query->where('type', Thread::TYPE_MESSAGE)->where('state', Thread::STATE_PUBLISHED);
        })->count());
        $this->assertFalse($conversation->threads()->where('type', Thread::TYPE_MESSAGE)->exists());
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before + 1, Conversation::count());
    }

    public function testSeederAddsLongExchangesToAlreadyPopulatedMailboxes()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        foreach (Conversation::where('type', Conversation::TYPE_EMAIL)->whereNull('channel')->where('threads_count', '>=', 20)->get() as $conversation) {
            $conversation->threads()->delete();
            $conversation->delete();
        }
        $existing = Conversation::orderBy('id')->get()->toArray();
        $before = Conversation::count();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before + 6, Conversation::count());
        $this->assertSame($existing, Conversation::whereIn('id', array_column($existing, 'id'))->orderBy('id')->get()->toArray());
        foreach (Mailbox::all() as $mailbox) {
            $long = $mailbox->conversations()->where('type', Conversation::TYPE_EMAIL)->whereNull('channel')->where('threads_count', '>=', 20)->get();
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
        // Existing samples may already have an AI result saying English needs no translation.
        \DB::table('threads')->update([
            'ai_assistant' => json_encode(['language' => 'en', 'same' => ['en']]),
            'ai_assistant_updated_at' => null,
        ]);
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
                $this->assertNotEmpty(\App\Ai\Translations::get($thread, $language));
                (new \App\Jobs\AiTranslateThread($thread->id, $language))->handle();
            }
        }
        $this->actingAs($agent);
        $thread = Thread::where('type', Thread::TYPE_CUSTOMER)->first();
        $html = view('conversations.partials.ai_translation', [
            'thread' => $thread,
            'conversation' => $thread->conversation,
        ])->render();
        $this->assertStringContainsString('Sample translation (English)', $html);
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
        $summary = ['one_liner' => 'A real summary', 'background' => 'Keep this text.', 'thread_id' => 0, 'at' => '2026-10-01 12:00:00'];
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

    public function testSeederAddsUsableWorkflowsWithoutRunningAutomaticExamples()
    {
        Queue::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        foreach (Mailbox::all() as $mailbox) {
            $automatic = Workflow::where('mailbox_id', $mailbox->id)->where('type', Workflow::TYPE_AUTOMATIC)->orderBy('sort_order')->get();
            $this->assertCount(2, $automatic);
            foreach ($automatic as $workflow) {
                $this->assertFalse($workflow->active);
                $this->assertFalse($workflow->apply_to_prev);
                $this->assertTrue($workflow->complete);
                $this->assertSame(0, $workflow->conversationsCount());
                $assignee = $workflow->getActions()[0][0]['value'];
                $this->assertTrue($mailbox->users()->where('users.id', $assignee)->exists());
            }
            $conversation = $mailbox->conversations()->where('state', Conversation::STATE_PUBLISHED)->first();
            $conversation->subject = 'A payment question';
            $conversation->status = Conversation::STATUS_ACTIVE;
            $this->assertTrue(Conditions::check($automatic[0], $conversation));
            $conversation->subject = 'An unrelated question';
            $this->assertFalse(Conditions::check($automatic[0], $conversation));
            $conversation->last_reply_from = Conversation::PERSON_CUSTOMER;
            $conversation->last_reply_at = now()->subDays(3);
            $this->assertTrue(Conditions::check($automatic[1], $conversation));
            $conversation->last_reply_at = now();
            $this->assertFalse(Conditions::check($automatic[1], $conversation));

            $manual = Workflow::activeFor($mailbox->id, Workflow::TYPE_MANUAL)->where('mailbox_id', $mailbox->id);
            $this->assertCount(1, $manual);
            $agent = $mailbox->users()->first();
            $conversation->user_id = null;
            $conversation->status = Conversation::STATUS_PENDING;
            $conversation->saveQuietly();
            $this->assertSame(1, Runner::runManual($manual->first(), [$conversation], $agent));
            $this->assertSame($agent->id, $conversation->fresh()->user_id);
            $this->assertSame(Conversation::STATUS_ACTIVE, $conversation->fresh()->status);
        }
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederReplenishesMissingWorkflowsAndPreservesExistingOnes()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $existing = Workflow::orderBy('id')->first();
        $existing->name = 'Our own routing rule';
        $existing->active = true;
        $existing->sort_order = 20;
        $existing->setActions([[['type' => 'status', 'value' => (string) Conversation::STATUS_CLOSED]]]);
        $existing->save();
        $extra = $existing->replicate();
        $extra->name = 'Another existing rule';
        $extra->save();
        $missing = Workflow::where('mailbox_id', '<>', $existing->mailbox_id)->where('type', Workflow::TYPE_AUTOMATIC)->first();
        $mailbox_id = $missing->mailbox_id;
        $missing->delete();
        $before = Workflow::orderBy('id')->get()->toArray();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(count($before) + 1, Workflow::count());
        $this->assertSame(2, Workflow::where('mailbox_id', $mailbox_id)->where('type', Workflow::TYPE_AUTOMATIC)->count());
        $this->assertSame($before, Workflow::whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->toArray());
        $snapshot = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function testSeederAddsAGlobalWorkflowAndPreservesExistingGlobalRules()
    {
        Queue::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $global = Workflow::whereNull('mailbox_id')->sole();
        $this->assertTrue($global->isGlobal());
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->first())
            ->get(route('workflows'))->assertSee($global->name);

        foreach (Mailbox::all() as $mailbox) {
            $this->assertTrue(Workflow::activeFor($mailbox->id, Workflow::TYPE_MANUAL)->contains('id', $global->id));
            $conversation = $mailbox->conversations()->where('state', Conversation::STATE_PUBLISHED)
                ->where('status', Conversation::STATUS_ACTIVE)->first();
            $this->assertSame(1, Runner::runManual($global, [$conversation], $mailbox->users()->first()));
            $this->assertSame(Conversation::STATUS_CLOSED, $conversation->fresh()->status);
        }

        $global->name = 'Our global rule';
        $global->active = false;
        $global->setActions([[['type' => 'status', 'value' => (string) Conversation::STATUS_PENDING]]]);
        $global->save();
        $before = $global->fresh()->toArray();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, Workflow::whereNull('mailbox_id')->sole()->toArray());

        $global->delete();
        $local = Workflow::whereNotNull('mailbox_id')->orderBy('id')->get()->toArray();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertTrue(Workflow::whereNull('mailbox_id')->sole()->active);
        $this->assertSame($local, Workflow::whereNotNull('mailbox_id')->orderBy('id')->get()->toArray());
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederAddsTelegramChatsWithoutConnectingOrSending()
    {
        $admin = $this->createAdmin();
        $mailbox = $this->createMailbox([$admin]);
        Telegram::saveSettings($mailbox, ['enabled' => true, 'token' => '123:existing-token']);
        $settings = $mailbox->fresh()->meta;
        Queue::fake();
        Http::fake();
        Http::preventStrayRequests();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($settings, $mailbox->fresh()->meta);
        $this->assertSame(3, CustomerChannel::where('channel', Telegram::CHANNEL)->count());
        foreach (Mailbox::all() as $mailbox) {
            $chats = $mailbox->conversations()->where('channel', Telegram::CHANNEL)->get();
            $this->assertCount(3, $chats);
            $this->assertCount(1, $chats->where('threads_count', '>=', 20));
            foreach ($chats as $chat) {
                $this->assertTrue($chat->hasChannel());
                $this->assertSame('Telegram', $chat->getChannelName());
                $this->assertNull($chat->customer_email);
                $this->assertCount(0, $chat->customer->emails);
                $this->assertStringStartsWith('sample-telegram-', $chat->customer->getChannelId(Telegram::CHANNEL));
                $this->assertSame(3, $chat->threads()->where('type', Thread::TYPE_MESSAGE)->distinct()->count('created_by_user_id'));
                $this->assertTrue($chat->threads()->where('type', Thread::TYPE_NOTE)->exists());
                $this->assertFalse(\App\Ai\Summaries::isStale($chat, 'en'));
                foreach ($chat->threads()->where('type', Thread::TYPE_CUSTOMER)->get() as $thread) {
                    $this->assertNotEmpty(\App\Ai\Translations::get($thread, 'en'));
                    $this->assertSame(Thread::SOURCE_TYPE_API, $thread->source_type);
                }
            }
        }
        $this->actingAs($admin)->followingRedirects()->get('/conversation/'.$chats->first()->id)->assertSee('Telegram');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederOnlyTopsUpInsufficientTelegramChats()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $chat = Conversation::where('channel', Telegram::CHANNEL)->where('status', Conversation::STATUS_CLOSED)->first();
        $chat->threads()->where('type', Thread::TYPE_MESSAGE)->delete();
        $chat->subject = 'Existing Telegram conversation';
        $chat->imported = false;
        $chat->saveQuietly();
        $before = Conversation::orderBy('id')->get()->toArray();
        $customers = Customer::orderBy('id')->get()->toArray();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame(count($before) + 1, Conversation::count());
        $this->assertSame($before, Conversation::whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->toArray());
        $this->assertSame($customers, Customer::orderBy('id')->get()->toArray());
        $snapshot = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function testSeededEmailsHaveOriginalBodiesHeadersAndDownloadableSources()
    {
        Queue::fake();
        Http::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        foreach (Conversation::with('threads')->get() as $conversation) {
            $previous = null;
            $references = [];
            foreach ($conversation->threads->sortBy('created_at') as $thread) {
                $path = \App\Incoming\RawSources::path($thread);
                if ($conversation->hasChannel() || $thread->type == Thread::TYPE_NOTE || $thread->state == Thread::STATE_DRAFT) {
                    $this->assertNull($thread->headers);
                    $this->assertNull($thread->body_original);
                    $this->assertFileDoesNotExist($path);
                    continue;
                }
                $this->assertFileExists($path);
                $raw = file_get_contents($path);
                $message = \App\Incoming\Parser::parse($raw);
                $this->assertSame($thread->message_id, $message->messageId());
                $this->assertTrue($message->date()->eq($thread->created_at));
                $this->assertSame($thread->body_original, $message->htmlBody());
                $this->assertStringContainsString($thread->body, $thread->body_original);
                $this->assertStringStartsWith($thread->headers."\r\n\r\n", $raw);
                $summary = \App\Incoming\OriginalHeaders::summary($thread->headers);
                $this->assertStringContainsString($thread->from, $summary['From']);
                $this->assertStringContainsString($thread->getToArray()[0], $summary['To']);
                $this->assertSame(($previous ? 'Re: ' : '').$conversation->subject, $message->subject());
                $this->assertSame(['spf' => 'pass', 'dkim' => 'none', 'dmarc' => 'pass'], \App\Incoming\OriginalHeaders::authentication($thread->headers));
                $this->assertSame('smtp.demo.example.test → mx.demo.example.test', \App\Incoming\OriginalHeaders::deliveredVia($thread->headers));
                $this->assertCount(2, array_filter(\App\Incoming\OriginalHeaders::all($thread->headers), fn ($row) => $row[0] == 'Received'));
                if ($previous) {
                    $this->assertSame('<'.$previous->message_id.'>', \MailHelper::getHeader($thread->headers, 'In-Reply-To'));
                    $this->assertSame(implode(' ', $references), \MailHelper::getHeader($thread->headers, 'References'));
                    $this->assertStringContainsString('<blockquote>', $thread->body_original);
                } else {
                    $this->assertEmpty(\MailHelper::getHeader($thread->headers, 'In-Reply-To'));
                }
                $previous = $thread;
                $references[] = '<'.$thread->message_id.'>';
            }
        }
        $thread = Thread::where('type', Thread::TYPE_CUSTOMER)->whereNotNull('headers')->first();
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->first())
            ->get('/conversation/ajax-html/show_original?thread_id='.$thread->id)->assertOk()
            ->assertSee('Headers')->assertSee('Authentication-Results')->assertSee('Download .eml')
            ->assertDontSee('could not be loaded from mail server');
        $this->get(route('threads.original_eml', ['thread_id' => $thread->id]))->assertOk()
            ->assertDownload('message-'.$thread->id.'.eml')->assertHeader('Content-Type', 'message/rfc822');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederBackfillsOnlyMissingSampleSourcesAndPreservesExistingMaterial()
    {
        config(['app.incoming_mail_retention_days' => 0]);
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame([], glob($this->sample_storage.'/app/incoming-mail/*.eml'));
        $threads = Thread::whereNotNull('headers')->orderBy('id')->take(4)->get();
        $missing = $threads[0];
        $preserved = $threads[1];
        $partial = $threads[2];
        $body_only = $threads[3];
        $non_sample = $threads[3]->replicate();
        $non_sample->message_id = 'real-message@example.org';
        $non_sample->headers = null;
        $non_sample->body_original = null;
        $non_sample->saveQuietly();
        \DB::table('threads')->where('id', $missing->id)->update(['headers' => null, 'body_original' => null]);
        \DB::table('threads')->where('id', $preserved->id)->update(['headers' => 'X-Existing: keep me', 'body_original' => '<p>Existing original</p>']);
        \DB::table('threads')->where('id', $partial->id)->update(['headers' => null, 'body_original' => '<p>Preserved source body</p>']);
        \DB::table('threads')->where('id', $body_only->id)->update(['headers' => 'X-Existing: body missing', 'body_original' => null]);
        $preserved_before = $preserved->fresh()->toArray();
        $non_sample_before = $non_sample->fresh()->toArray();
        mkdir($this->sample_storage.'/app/incoming-mail');
        file_put_contents(\App\Incoming\RawSources::path($preserved), 'Existing retained source');
        $count = Thread::count();
        config(['app.incoming_mail_retention_days' => 30]);

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($count, Thread::count());
        $this->assertNotEmpty($missing->fresh()->headers);
        $this->assertSame($missing->updated_at->toDateTimeString(), $missing->fresh()->updated_at->toDateTimeString());
        $this->assertNotEmpty($partial->fresh()->headers);
        $this->assertSame('<p>Preserved source body</p>', $partial->fresh()->body_original);
        $this->assertSame('X-Existing: body missing', $body_only->fresh()->headers);
        $this->assertNotEmpty($body_only->fresh()->body_original);
        $this->assertSame($preserved_before, $preserved->fresh()->toArray());
        $this->assertSame($non_sample_before, $non_sample->fresh()->toArray());
        $this->assertSame('Existing retained source', file_get_contents(\App\Incoming\RawSources::path($preserved)));
        $this->assertFileDoesNotExist(\App\Incoming\RawSources::path($non_sample));
        $files = [];
        foreach (glob($this->sample_storage.'/app/incoming-mail/*.eml') as $path) {
            $files[$path] = sha1_file($path);
        }
        $snapshot = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
        foreach ($files as $path => $hash) {
            $this->assertSame($hash, sha1_file($path));
        }
    }

    public function testSeederAddsEmailsThatShowTheRemoteImageWarning()
    {
        Queue::fake();
        Http::fake();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $agent = User::where('role', User::ROLE_ADMIN)->first();

        foreach (Mailbox::all() as $mailbox) {
            $conversations = $mailbox->conversations()->whereHas('threads', function ($query) {
                $query->where('body', 'like', '%<img%');
            })->get();
            $this->assertCount(2, $conversations);
            foreach ($conversations as $conversation) {
                $thread = $conversation->threads()->where('type', Thread::TYPE_CUSTOMER)->where('first', true)->first();
                $this->assertTrue(\App\Misc\ExternalImages::appliesTo($thread));
                $this->assertSame(1, \App\Misc\ExternalImages::block($thread->body)[1]);
                $this->assertStringContainsString('https://images.example.invalid/', $thread->body_original);
                $source = \App\Incoming\Parser::parse(file_get_contents(\App\Incoming\RawSources::path($thread)));
                $this->assertStringContainsString('<img src="https://images.example.invalid/', $source->htmlBody());
                $this->actingAs($agent)->followingRedirects()->get('/conversation/'.$conversation->id)->assertOk()
                    ->assertSee('Images from other servers are not shown.')
                    ->assertSee('data-blocked-src="https://images.example.invalid/', false);
            }
        }
        $before = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->snapshot());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertCount(0, $this->sentEmails());
    }

    public function testSeederTopsUpRemoteImagesWithoutChangingExistingEmailsOrPreferences()
    {
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $thread = Thread::where('type', Thread::TYPE_CUSTOMER)->where('body', 'like', '%<img%')->first();
        $thread->body = '<p>Keep this edited message.</p><img src="/img/local.png" alt="Local image">';
        $thread->saveQuietly();
        $thread->customer->setMeta(\App\Misc\ExternalImages::META_KEY, 1);
        $thread->customer->saveQuietly();
        $before = $thread->fresh()->toArray();
        $customer = $thread->customer->fresh()->toArray();
        $count = Conversation::count();

        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);

        $this->assertSame($count + 1, Conversation::count());
        $this->assertSame($before, $thread->fresh()->toArray());
        $this->assertSame($customer, $thread->customer->fresh()->toArray());
        $snapshot = $this->snapshot();
        $this->artisan('db:seed', ['--force' => true])->assertExitCode(0);
        $this->assertSame($snapshot, $this->snapshot());
    }

    private function snapshot()
    {
        $snapshot = [];
        foreach (['users', 'mailboxes', 'customers', 'customer_channel', 'emails', 'conversations', 'threads', 'folders', 'conversation_folder', 'mailbox_user', 'workflows', 'conversation_workflow'] as $table) {
            $snapshot[$table] = \DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }
}
