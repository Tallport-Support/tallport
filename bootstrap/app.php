<?php

/*
|--------------------------------------------------------------------------
| PCRE JIT
|--------------------------------------------------------------------------
|
| Where PHP may not allocate executable memory (SELinux, systemd's
| MemoryDenyWriteExecute, a mail server's sandbox), the first regular
| expression turns PCRE's JIT off with a warning, which Laravel's error
| handler makes fatal. Let it happen here, quietly.
|
*/

error_clear_last();
@preg_match('/^(?:a|b)+$/', 'ab');
if (str_contains(error_get_last()['message'] ?? '', 'JIT')) {
    App\Misc\Helper::$pcre_jit_available = false;
    // Off for the rest of this process too, so no other expression tries again
    // (no need for "php -d pcre.jit=0" wherever Tallport runs, e.g. under Postfix).
    ini_set('pcre.jit', '0');
}

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| The first thing we will do is create a new Laravel application instance
| which serves as the "glue" for all the components of Laravel, and is
| the IoC container for the system binding all of the various parts.
|
*/

$app = new App\Foundation\Application(
    realpath(__DIR__.'/../')
);

/*
|--------------------------------------------------------------------------
| Bind Important Interfaces
|--------------------------------------------------------------------------
|
| Next, we need to bind some important interfaces into the container so
| we will be able to resolve them when needed. The kernels serve the
| incoming requests to this application from both the web and CLI.
|
*/

$app->singleton(
    Illuminate\Contracts\Http\Kernel::class,
    App\Http\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

/*
|--------------------------------------------------------------------------
| Return The Application
|--------------------------------------------------------------------------
|
| This script returns the application instance. The instance is given to
| the calling script so we can separate the building of the instances
| from the actual running of the application and sending responses.
|
*/

return $app;
