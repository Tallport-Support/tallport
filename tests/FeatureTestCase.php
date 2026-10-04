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

        $this->captureSentMail();

        // A narrow window: a folder shows its list, rather than opening at a
        // conversation (ConversationsController::openFolder()).
        $this->withUnencryptedCookie('tallport_narrow', '1');

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
}
