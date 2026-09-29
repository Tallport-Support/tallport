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

$loader = require __DIR__.'/../vendor/autoload.php';

// autoload-dev is not part of the committed autoloader.
$loader->addPsr4('Tests\\', __DIR__.'/');

// Laravel 5.5 testing classes patched for newer PHP versions. These are only
// used by tests, so they live here rather than in overrides/, which would need
// the committed autoloader to be regenerated.
require_once __DIR__.'/Overrides/laravel/framework/src/Illuminate/Foundation/Testing/TestResponse.php';
