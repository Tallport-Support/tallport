<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Console\Kernel;

/**
 * For tests on MariaDB, production's database (its full-text search, its exact schema): the
 * class runs on the testing_mariadb connection instead of in-memory SQLite, and is skipped
 * when that database isn't there (see test.sh for creating it).
 */
trait UsesMariaDB
{
    /**
     * Whether the MariaDB test database has been rebuilt for this class.
     */
    protected static $mariadb_migrated = false;

    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->app['config']->set('database.default', 'testing_mariadb');
        $this->app['db']->setDefaultConnection('testing_mariadb');
        try {
            $this->app['db']->connection()->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No MariaDB test database: '.$e->getMessage());
        }
        if (!static::$mariadb_migrated) {
            $this->app[Kernel::class]->call('migrate:fresh', ['--force' => true]);
            static::$mariadb_migrated = true;
        }
    }
}
