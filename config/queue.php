<?php

$queue_default = env('QUEUE_CONNECTION', env('QUEUE_DRIVER', 'database'));

$database = [
    'driver'      => 'database',
    'table'       => 'jobs',
    'queue'       => 'default',
    // Retry after does not work as delay between retry attempts.
    'retry_after' => 3660,
];

$beanstalkd = [
    'driver'      => 'beanstalkd',
    'host'        => 'localhost',
    'queue'       => 'default',
    'retry_after' => 3660,
];

$redis = [
    'driver'       => 'redis',
    'connection'   => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue'        => env('REDIS_QUEUE', 'default'),
    'retry_after'  => max(3660, (int) env('REDIS_QUEUE_RETRY_AFTER', 3660)),
    'block_for'    => null,
    'after_commit' => false,
];

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

    'default' => $queue_default,

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

        'database'        => $database,
        'database_emails' => array_replace($database, ['retry_after' => 360]),
        'database_ai'     => array_replace($database, ['retry_after' => 960]),

        'beanstalkd'        => $beanstalkd,
        'beanstalkd_emails' => array_replace($beanstalkd, ['retry_after' => 360]),
        'beanstalkd_ai'     => array_replace($beanstalkd, ['retry_after' => 960]),

        'sqs' => [
            'driver' => 'sqs',
            'key'    => env('SQS_KEY', 'your-public-key'),
            'secret' => env('SQS_SECRET', 'your-secret-key'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue'  => env('SQS_QUEUE', 'your-queue-name'),
            'region' => env('SQS_REGION', 'us-east-1'),
        ],

        'redis'        => $redis,
        'redis_emails' => array_replace($redis, ['retry_after' => 360]),
        'redis_ai'     => array_replace($redis, ['retry_after' => 960]),

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
