<?php

// PHPUnit and the other test tools come from dev/vendor (see dev/composer.json),
// the application from vendor/.
$dev_autoload = __DIR__.'/../dev/vendor/autoload.php';
if (!file_exists($dev_autoload)) {
    fwrite(STDERR, "Test tools are not installed: run ./test.sh, or composer install -d dev\n");
    exit(1);
}
require_once $dev_autoload;

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
