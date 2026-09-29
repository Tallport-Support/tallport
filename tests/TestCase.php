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

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$database_migrated) {
            $this->app[Kernel::class]->call('migrate:fresh', ['--force' => true]);
            static::$database_migrated = true;
        }
    }
}
