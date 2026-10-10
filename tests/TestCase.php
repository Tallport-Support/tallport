<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Host names resolve here, never through DNS (a VPN's DNS may answer anything): localhost
     * to the loopback address, other names to a public one. A test may set its own.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Helper::$resolver = function ($host) {
            // Numeric forms ("2130706433", "127.0.1", "0x7f.1") are converted without DNS.
            if (preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/i', $host)) {
                return array_filter([gethostbyname($host)], fn ($address) => filter_var($address, FILTER_VALIDATE_IP));
            }

            return in_array(strtolower($host), ['localhost', 'localhost.localdomain']) ? ['127.0.0.1'] : ['93.184.215.14'];
        };
    }

    protected function tearDown(): void
    {
        \Helper::$resolver = null;

        parent::tearDown();
    }

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
