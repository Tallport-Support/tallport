<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        // Don't read the local .env, so tests see the same configuration
        // everywhere (phpunit.xml, plus .env.testing when it exists).
        $app->loadEnvironmentFrom('.env.testing');

        $app->make(Kernel::class)->bootstrap();

        // With a cached config (bootstrap/cache/config.php) Laravel ignores the
        // environment from phpunit.xml, and tests would run against the
        // development database.
        if ($app['config']->get('app.env') !== 'testing' || $app['config']->get('database.default') !== 'testing') {
            fwrite(STDERR, "Refusing to run tests outside the testing environment. Clear the config cache (php artisan config:clear) or use ./test.sh\n");
            exit(1);
        }

        Hash::setRounds(4);

        return $app;
    }
}
