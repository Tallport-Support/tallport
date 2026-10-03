<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Driver
    |--------------------------------------------------------------------------
    |
    | Laravel's queue API supports an assortment of back-ends via a single
    | API, giving you convenient access to each back-end using the same
    | syntax for each one. Here you may set the default queue driver.
    |
    | Supported: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    | QUEUE_DRIVER is the name used before Laravel 5.7.
    |
    */

    'default' => env('QUEUE_CONNECTION', env('QUEUE_DRIVER', 'database')),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection information for each server that
    | is used by your application. A default configuration has been added
    | for each back-end shipped with Laravel. You are free to add more.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver'      => 'database',
            'table'       => 'jobs',
            'queue'       => 'default',
            // Retry after does not work as delay between retry attempts.
            'retry_after' => 90,
        ],

        'beanstalkd' => [
            'driver'      => 'beanstalkd',
            'host'        => 'localhost',
            'queue'       => 'default',
            'retry_after' => 90,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key'    => env('SQS_KEY', 'your-public-key'),
            'secret' => env('SQS_SECRET', 'your-secret-key'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'  => env('SQS_QUEUE', 'your-queue-name'),
            'region' => env('SQS_REGION', 'us-east-1'),
        ],

        'redis' => [
            'driver'       => 'redis',
            'connection'   => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue'        => env('REDIS_QUEUE', 'default'),
            'retry_after'  => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for'    => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control which database and table are used to store the jobs that
    | have failed. You may change them to any database / table you wish.
    |
    */

    'failed' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table'    => 'failed_jobs',
    ],

];
