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
     * The in-memory database, kept from test to test.
     */
    protected static $memory_pdo;

    /**
     * Rebuild the database once per run, right after the application is
     * created and before traits like DatabaseTransactions start a transaction
     * (schema changes would commit it).
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        // In memory: one database for the run, handed to each test's application (a test in a
        // separate process, @runInSeparateProcess, builds its own).
        $connection = $this->app['db']->connection();
        if (static::$database_migrated && static::$memory_pdo) {
            $connection->setPdo(static::$memory_pdo)->setReadPdo(static::$memory_pdo);
        }
        if (!static::$database_migrated) {
            $this->app[Kernel::class]->call('migrate:fresh', ['--force' => true]);
            static::$database_migrated = true;
            if ($connection->getDatabaseName() == ':memory:') {
                static::$memory_pdo = $connection->getPdo();
            }
        }
    }
}
