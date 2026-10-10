<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesModels;
use Tests\Concerns\InteractsWithMail;

/**
 * Base for tests that exercise the application end to end: HTTP requests,
 * email in and out, the database. Each test runs in a transaction that is
 * rolled back afterwards, and outgoing email is captured.
 */
abstract class FeatureTestCase extends TestCase
{
    use DatabaseTransactions;
    use CreatesModels;
    use InteractsWithMail;

    /**
     * Commands that write outside the database (bootstrap/cache, public/,
     * sessions), change the schema (committing the test's transaction),
     * connect to mail servers or start processes. They are replaced by
     * StubCommand, which records calls for assertCommandCalled().
     */
    protected $stubbed_commands = [
        'tallport:clear-cache', 'config:cache', 'config:clear', 'route:cache', 'clear-compiled',
        'tallport:generate-vars', 'laroute:generate', 'tallport:module-laroute',
        'tallport:module-install', 'module:migrate', 'module:seed', 'migrate',
        'tallport:fetch-emails', 'tallport:logout-users',
        'schedule:run', 'storage:link',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Files written at runtime (raw sources, fake disks, temp files) go to a folder of this
        // test's own, never to the installation's storage or another test run's.
        $storage = sys_get_temp_dir().'/tallport-test-storage-'.getmypid().'-'.uniqid();
        foreach (['app/public', 'logs', 'framework/cache'] as $folder) {
            mkdir($storage.'/'.$folder, 0777, true);
        }
        $this->app->useStoragePath($storage);
        $this->beforeApplicationDestroyed(function () use ($storage) {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($storage);
        });

        $this->captureSentMail();

        // Requests that set a time limit for themselves (set_time_limit()) don't limit the test run.
        set_time_limit(0);

        // Nothing an earlier test cached by ID is kept: its rows were rolled back, and SQLite
        // gives the IDs out again.
        \Option::$cache = [];
        \App\Conversation::$starred_conversation_ids = [];
        \App\Misc\Helper::$memory_cache = [];
        foreach ([[\App\Misc\ExternalImages::class, 'blocked'], [\App\Nostr\NostrEvent::class, 'senders'],
            [\App\AutoReply\AutoReplies::class, 'chosen'], [\App\Workflows\Runner::class, 'last_threads']] as [$class, $property]) {
            (new \ReflectionProperty($class, $property))->setValue(null, []);
        }
        (new \ReflectionProperty(\App\Workflows\Runner::class, 'robot'))->setValue(null, null);

        // No customer photos looked up online (Gravatar unless set) but in
        // the tests about them.
        \Option::set(\App\Misc\CustomerPhotos::OPTION, \App\Misc\CustomerPhotos::NONE);

        // Attachments and other files go to throwaway disks, never to the real storage.
        foreach (['local', \App\Attachment::DISK] as $disk) {
            \Storage::fake($disk, config('filesystems.disks.'.$disk));
        }

        // A narrow window: a folder shows its list, rather than opening at a
        // conversation (ConversationsController::openFolder()).
        $this->withUnencryptedCookie('tallport_narrow', '1');

        // Lazy components load lazily, also after a test that turned it off
        // (Livewire::withoutLazyLoading() lasts until Livewire's state is flushed).
        \Livewire\Features\SupportLazyLoading\SupportLazyLoading::$disableWhileTesting = false;

        \Tests\Support\StubCommand::$calls = [];
        foreach ($this->stubbed_commands as $name) {
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->registerCommand(new \Tests\Support\StubCommand($name));
        }

        // Helper::queueWorkerRestart() dispatches RestartQueueWorker, which
        // calls exit(): with the sync queue that would end the test run
        // silently. It isn't dispatched while one is already queued.
        \DB::table('jobs')->insert([
            'queue'        => 'default',
            'payload'      => '{"uuid":"6f1c0d2e-0000-4000-8000-000000000001","displayName":"App\\\\Jobs\\\\RestartQueueWorker"}',
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => time(),
            'created_at'   => time(),
        ]);
    }

    /**
     * Mark a test of intended behaviour as blocked by a bug in KNOWN_BUGS.md.
     * Remove the call when fixing the bug.
     */
    protected function knownBug($id)
    {
        $this->markTestIncomplete("Known bug $id, see KNOWN_BUGS.md");
    }

    protected function assertCommandCalled($name)
    {
        $this->assertContains($name, array_column(\Tests\Support\StubCommand::$calls, 'name'), "Command $name was not called.");
    }

    /**
     * POST to an ajax endpoint the way the frontend does.
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function postAjax($user, $uri, array $data)
    {
        \Session::start();

        return $this->actingAs($user)->post($uri, array_merge($data, [
            '_token' => csrf_token(),
        ]), [
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
    }

    /**
     * What a Livewire call streamed to the browser (wire:stream): type, content, mode and name of each.
     */
    protected function streamed(callable $call)
    {
        $output = '';
        ob_start(function ($buffer) use (&$output) {
            $output .= $buffer;

            return '';
        });
        try {
            $call();
        } finally {
            ob_end_clean();
        }
        preg_match_all('/\{"stream":true.*?"endStream":true\}/', $output, $matches);

        return array_map(fn ($json) => array_intersect_key(json_decode($json, true)['body'], array_flip(['type', 'content', 'mode', 'name'])), $matches[0]);
    }
}
