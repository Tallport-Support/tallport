<?php

/*
|--------------------------------------------------------------------------
| Logging
|--------------------------------------------------------------------------
|
| Same behaviour as before Laravel 5.6: APP_LOG picks the handler
| ("single", "daily", "syslog" or "errorlog"), APP_LOG_LEVEL the minimum
| level, and daily logs are kept for 5 days (APP_LOG_MAX_FILES).
|
*/

$level = env('APP_LOG_LEVEL', 'error');

return [

    'default' => env('APP_LOG', 'daily'),

    'channels' => [
        'single' => [
            'driver' => 'single',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => $level,
        ],

        'daily' => [
            'driver' => 'daily',
            'path'   => storage_path('logs/laravel.log'),
            'level'  => $level,
            'days'   => env('APP_LOG_MAX_FILES', 5),
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level'  => $level,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level'  => $level,
        ],
    ],

];
