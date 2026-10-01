# Tallport

Tallport is a fork of the FreeScout help desk, upgraded from Laravel 5.5 to Laravel 13. Production runs MariaDB. The minimum PHP version is 8.5 (`require.php` and `config.platform.php` in composer.json), so PHP 8.5 features may be used.

## Keep changes small

- Make the smallest change that does the job. Don't bundle refactors, cleanups or dependency bumps into a fix or feature.
- Match the surrounding code: FreeScout style, comment density, naming, the `\Helper`/`\Eventy` facades and global helpers it already uses.
- Tooling stays PHP/Laravel-native. No Node-based test tools (no TypeScript, no Playwright).

## Dependencies and vendor/

- `vendor/` is committed and ships with every release. It must equal what `composer install --ignore-platform-reqs` produces, which a CI job checks. Never edit files in `vendor/`. The root composer.json must not have development-only packages, because they would end up in `vendor/`.
- The committed `vendor/composer/autoload_*.php` classmap lists app classes too (optimized autoloader). After adding, moving or deleting a class, run `composer dump-autoload` and commit the result, or CI's vendor check fails.
- Patched vendor files live in `overrides/`, mirroring the vendor path. composer.json maps them in `autoload.psr-4` and lists the originals in `exclude-from-classmap`.
- Packages whose Laravel constraints were too narrow are redefined as `type: package` entries in composer.json `repositories`, with the same code.
- To update dependencies, run `composer update <package> --ignore-platform-req='ext-*'`. Then reinstall cleanly so `vendor/` matches a fresh install.
- Development tools live in separate Composer projects that are not shipped: `dev/` (phpunit, mockery, faker) and `dev/boost/` (Laravel Boost, loaded by `AppServiceProvider::registerDevBoost()` when present). `composer install` in the root installs both (`dev/install.php`). Add new dev tools there, not to the root `require-dev`.

## FreeScout modules

- `Modules/` holds FreeScout modules. It is not part of the repository, and production runs modules that are not available locally.
- Modules rely on Laravel 5.5 behaviour that Tallport keeps. Don't remove any of it:
  - `route()` filling missing parameters with null;
  - the `Input` facade;
  - `Event::fire()`;
  - the `str_*`/`array_*` helpers;
  - Laravel 8 Model method signatures;
  - `Mail::failures()`;
  - the "Y-m-d H:i:s" model date serialization.
- Keep Eventy hooks (`\Eventy::filter`/`action` names and arguments) stable. Modules depend on them.
- `config('app.compatibility_version')` is the FreeScout version reported to modules. `config('app.version')` is Tallport's own version.

## Testing

- Run tests with `./test.sh`, never `php artisan test` or `vendor/bin/phpunit`. The script installs the dev tools and uses the `testing` database connection (MariaDB database `freescout-test`).
- Use `./test.sh --filter=SomeTest` or `./test.sh tests/Feature/SomeTest.php` while working. Run the full `./test.sh` with no arguments before finishing.
- The full run also checks that every route, ajax action, console command and job is exercised by a test or listed in `tests/inventory-exclusions.php`. New endpoints and commands need a test.
- Feature tests extend `Tests\FeatureTestCase`. It provides:
  - transactions;
  - captured outgoing mail (`Tests\Support\CapturedEmail`);
  - `postAjax()`, `receiveEmail()` and `makeEmail()`;
  - `StubCommand` with `assertCommandCalled()` for destructive artisan commands.
- Tests create models with the builders in `tests/Concerns/CreatesModels.php`, not Laravel model factories.
- A bug found while testing gets an entry in `KNOWN_BUGS.md` and an intended-behaviour test that calls `$this->knownBug('ID')`.

## Releases and production

- Releases are made with `./release.sh <version> -m "notes"` from a commit on origin/main that passed the Tests workflow. Installations pick them up through the built-in updater.
- After an update, `php artisan freescout:after-app-update` must run from the command line.
- After changing `.env` or config, run `php artisan freescout:clear-cache`.
