<?php

use Illuminate\Support\Str;

// The test suite's database (DB_TEST_DRIVER): in-memory SQLite by default, or mysql (MariaDB,
// or MySQL) or pgsql (PostgreSQL) to run it on those. Tests of what only MariaDB does (Tests\Concerns\UsesMariaDB)
// always use testing_mariadb.
$testing = [
    'sqlite' => [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => env('DB_TABLE_PREFIX', ''),
        'foreign_key_constraints' => false,
    ],
    'mysql' => [
        'driver'         => 'mysql',
        //'url'            => env('DB_TEST_DATABASE_URL'),
        'host'           => env('DB_TEST_HOST', '127.0.0.1'),
        'database'       => env('DB_TEST_DATABASE', 'freescout-test'),
        'username'       => env('DB_TEST_USERNAME', 'freescout-test'),
        'password'       => env('DB_TEST_PASSWORD', 'freescout-test'),
        'port'           => env('DB_TEST_PORT', '3306'),
        'charset'        => 'utf8mb4',
        'collation'      => 'utf8mb4_unicode_ci',
        'prefix'         => env('DB_TABLE_PREFIX', ''),
        //'prefix_indexes' => true,
        'strict'         => false,
        'engine'      => null,
    ],
    'pgsql' => [
        'driver'   => 'pgsql',
        'host'     => env('DB_TEST_PGSQL_HOST', '127.0.0.1'),
        'port'     => env('DB_TEST_PGSQL_PORT', '5432'),
        'database' => env('DB_TEST_DATABASE', 'freescout-test'),
        'username' => env('DB_TEST_USERNAME', 'freescout-test'),
        'password' => env('DB_TEST_PASSWORD', 'freescout-test'),
        'charset'  => 'utf8',
        'prefix'   => env('DB_TABLE_PREFIX', ''),
        'schema'   => 'public',
        'sslmode'  => 'prefer',
    ],
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for all database work. Of course
    | you may use many connections at once using the Database library.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Here are each of the database connections setup for your application.
    | Of course, examples of configuring each database platform that is
    | supported by Laravel is shown below to make development simple.
    |
    |
    | All database work in Laravel is done through the PHP PDO facilities
    | so make sure you have the driver for your particular database of
    | choice installed on your machine before you begin development.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver'       => 'sqlite',
            // Not in database/: updates replace that folder.
            'database'     => env('DB_DATABASE', storage_path('app/database.sqlite')),
            'prefix'       => env('DB_TABLE_PREFIX', ''),
            // The web server and background commands write at the same time.
            'busy_timeout' => 10000,
            'journal_mode' => 'wal',
        ],

        'mysql' => [
            'driver'      => 'mysql',
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '3306'),
            'database'    => env('DB_DATABASE', 'forge'),
            'username'    => env('DB_USERNAME', 'forge'),
            'password'    => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset'     => env('DB_CHARSET', 'utf8mb4'),
            'collation'   => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix'      => env('DB_TABLE_PREFIX', ''),
            'strict'      => false,
            'engine'      => null,
            'options'     => extension_loaded('pdo_mysql') ? array_filter([
                (defined('Pdo\Mysql::ATTR_SSL_CA') ? Pdo\Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('DB_MYSQL_ATTR_SSL_CA'),
                (defined('Pdo\Mysql::ATTR_SSL_CERT') ? Pdo\Mysql::ATTR_SSL_CERT : PDO::MYSQL_ATTR_SSL_CERT) => env('DB_MYSQL_ATTR_SSL_CERT'),
                // https://github.com/freescout-help-desk/freescout/issues/5273
                (defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : -1)) => env('DB_MYSQL_ATTR_SSL_VERIFY_SERVER_CERT', true),
                PDO::ATTR_PERSISTENT => env('DB_ATTR_PERSISTENT'),
            ], function($value) { return $value !== null; }) : [],
        ],

        'testing'         => $testing[env('DB_TEST_DRIVER', 'sqlite')],
        // MariaDB also when the suite runs on MySQL (DB_TEST_PORT).
        'testing_mariadb' => ['port' => env('DB_TEST_MARIADB_PORT', '3306')] + $testing['mysql'],
        'testing_pgsql'   => $testing['pgsql'],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'prefix'   => env('DB_TABLE_PREFIX', ''),
            'schema'   => 'public',
            'sslmode'  => env('DB_PGSQL_SSLMODE', 'prefer'),
            'options'  => extension_loaded('pdo_pgsql') ? array_filter([
                (defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES') ? Pdo\Pgsql::ATTR_DISABLE_PREPARES : PDO::PGSQL_ATTR_DISABLE_PREPARES) => env('DB_PGSQL_ATTR_DISABLE_PREPARES'),
                PDO::ATTR_PERSISTENT => env('DB_ATTR_PERSISTENT'),
            ]) : [],
        ],

        'sqlsrv' => [
            'driver'   => 'sqlsrv',
            'host'     => env('DB_HOST', 'localhost'),
            'port'     => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
            'charset'  => 'utf8',
            'prefix'   => env('DB_TABLE_PREFIX', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run in the database.
    |
    */

    'migrations' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer set of commands than a typical key-value systems
    | such as APC or Memcached. Laravel makes it easy to dig right in.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster'    => env('REDIS_CLUSTER', 'redis'),
            'prefix'     => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'tallport')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6379'),
            'database'          => env('REDIS_DB', '0'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
            'command_retries'   => env('REDIS_COMMAND_RETRIES', 0),
        ],

        // A database of its own: cache:clear empties it.
        'cache' => [
            'url'               => env('REDIS_URL'),
            'host'              => env('REDIS_HOST', '127.0.0.1'),
            'username'          => env('REDIS_USERNAME'),
            'password'          => env('REDIS_PASSWORD'),
            'port'              => env('REDIS_PORT', '6379'),
            'database'          => env('REDIS_CACHE_DB', '1'),
            'max_retries'       => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base'      => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap'       => env('REDIS_BACKOFF_CAP', 1000),
            'command_retries'   => env('REDIS_COMMAND_RETRIES', 0),
        ],

    ],

];
