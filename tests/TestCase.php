<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Whether the test database has been rebuilt in this run.
     */
    protected static $database_migrated = false;

    /**
     * Rebuild the database once per run, right after the application is
     * created and before traits like DatabaseTransactions start a transaction
     * (schema changes would commit it).
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        // Tests run in a separate process (@runInSeparateProcess) use the
        // database the main process already rebuilt.
        if (!static::$database_migrated && !$this->isInIsolation()) {
            $this->app[Kernel::class]->call('migrate:fresh', ['--force' => true]);
            static::$database_migrated = true;
        }
    }
}
