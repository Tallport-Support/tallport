<?php
/**
 * Installs the development tools after `composer install` or `composer update`
 * in the project root: test tools into dev/vendor and Laravel Boost into
 * dev/boost/vendor. Neither is committed, so none of it ships with releases.
 * Skipped with --no-dev.
 */
if (getenv('COMPOSER_DEV_MODE') !== '1') {
    exit(0);
}

$composer = escapeshellarg(PHP_BINARY).' '.escapeshellarg(getenv('COMPOSER_BINARY') ?: 'composer');

foreach ([__DIR__, __DIR__.'/boost'] as $dir) {
    passthru($composer.' install --no-interaction -d '.escapeshellarg($dir), $exit_code);
    if ($exit_code !== 0) {
        exit($exit_code);
    }
}
