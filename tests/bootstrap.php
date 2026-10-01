<?php

// PHPUnit and the other test tools come from dev/vendor (see dev/composer.json),
// the application from vendor/.
$dev_autoload = __DIR__.'/../dev/vendor/autoload.php';
if (!file_exists($dev_autoload)) {
    fwrite(STDERR, "Test tools are not installed: run ./test.sh, or composer install -d dev\n");
    exit(1);
}
require_once $dev_autoload;

// The application's autoloader maps these PHPUnit classes to copies in overrides/
// that were patched for PHPUnit 9.5. Load PHPUnit's own versions first so the
// runner doesn't end up mixing versions.
class_exists(\PHPUnit\Runner\PhptTestCase::class);
class_exists(\PHPUnit\Util\PHP\AbstractPhpProcess::class);
class_exists(\PHPUnit\Util\Xml\Loader::class);

// Like artisan and public/index.php, replace Laravel 5.5's e() with one that
// accepts null before the framework's helpers are loaded. Views echo nulls,
// and since PHP 8.1 htmlspecialchars(null) is a deprecation, which the
// framework's error handler turns into an exception.
if (!function_exists('e')) {
    function e($value, $doubleEncode = false)
    {
        if ($value instanceof \Illuminate\Contracts\Support\Htmlable) {
            return $value->toHtml();
        }

        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', $doubleEncode);
    }
}

$loader = require __DIR__.'/../vendor/autoload.php';

// The Tests namespace is not part of the committed autoloader.
$loader->addPsr4('Tests\\', __DIR__.'/');

// Laravel 5.5 testing classes patched for PHP 8.4+ and PHPUnit 9 (string
// assertions). These are only used by tests, so they live here rather than in
// overrides/, which would need the committed autoloader to be regenerated.
require_once __DIR__.'/Overrides/laravel/framework/src/Illuminate/Foundation/Testing/TestResponse.php';
