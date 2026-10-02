<laravel-boost-guidelines>
=== .ai/phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit 9. Create test classes by hand in `tests/Feature` or `tests/Unit`, following the existing tests there.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run tests with `./test.sh`. It accepts phpunit arguments, e.g. `./test.sh --filter=testName` or `./test.sh tests/Feature/SomeTest.php`.
- Run the narrowest set of tests that covers the change, and rerun a test after each change to it.
- Before finishing, run the full suite with `./test.sh` (no arguments). It also runs the inventory check.

=== .ai/tallport rules ===

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

## Incoming mail

- Incoming email is read by `App\Incoming\Parser`: webklex/php-imap 6 (`Webklex\PHPIMAP`) plus Tallport's own code in `app/Incoming` (`Webklex6Message`, `HeaderText`, `Address::parseList()`), falling back to the legacy parser if that fails.
- `app/LegacyImap` (namespace `App\LegacyImap`) is FreeScout's patched webklex/php-imap 4.1.1, kept as is: fetching (IMAP/POP3 connections) still runs on it, and it is the parsing fallback. It mixes library code with Tallport behaviour (charset fixes, subject decoding, Message-ID generation, error handling, a POP3 client).
- It is being taken apart in small steps: move a piece of behaviour into Tallport's own incoming-mail code (with a test) or onto webklex/php-imap 6 (`Webklex\PHPIMAP`, installed normally), then delete it from `app/LegacyImap`. Don't add new code that depends on `App\LegacyImap`.

## FreeScout modules

- `Modules/` holds FreeScout modules. It is not part of the repository, and production runs modules that are not available locally.
- Modules rely on Laravel 5.5 behaviour that Tallport keeps. Don't remove any of it:
  - `route()` filling missing parameters with null;
  - the `Input` facade;
  - `Event::fire()`;
  - the `str_*`/`array_*` helpers;
  - Laravel 8 Model method signatures;
  - `Mail::failures()`;
  - model `$dates` (cast to Carbon) and the "Y-m-d H:i:s" date serialization.
- Keep Eventy hooks (`\Eventy::filter`/`action` names and arguments) stable. Modules depend on them.
- `config('app.compatibility_version')` is the FreeScout version reported to modules. `config('app.version')` is Tallport's own version.

## Translations

- Tallport supports the languages in `config('app.locales')`. Every user-facing string (`__()`, `@lang()`, `trans()`) needs a translation in each of them: in `resources/lang/<locale>.json`, keyed by the English text, and for Laravel's messages in `resources/lang/<locale>/{auth,passwords,validation}.php`.
- When adding or changing text, add the translations in the same change, matching each language's existing terminology and form of address. Keep placeholders (`:name`, `%a_start%`, `{name}`) and HTML exactly as in English.
- `tests/Unit/TranslationsTest.php` fails on a missing translation or a changed placeholder; there is no translation editor in the app.

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
- After an update, `php artisan tallport:after-app-update` must run from the command line.
- After changing `.env` or config, run `php artisan tallport:clear-cache`.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.5. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record a rule with `record-rule` only when the user explicitly asks for one. Instructions for the work at hand are not rules, no matter how emphatic: "remove this typo", "use X here" are work to do, not rules to record. Never record a rule on your own initiative, as a byproduct of a change, or to summarize what you just did. When the user does ask, pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Use `record-rule` rather than your native memory or notes tool, because native memory is personal and session-scoped, while only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Follow the style of the file you are editing. Most FreeScout code has no parameter or return types and uses `snake_case` local variables. Don't add types to existing methods, and don't restyle code you aren't changing.
- Always use curly braces for control structures, even for single-line bodies.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for non-obvious logic.
- Keep overridden framework methods' signatures as they are (e.g. Model methods without return types). FreeScout modules extend them.

=== deployments rules ===

# Deployment

- Tallport isn't deployed with Laravel Cloud or Forge. Installations update themselves from GitHub releases through the built-in updater (System > Status > Update Now, or `php artisan tallport:update`).
- A release is made with `./release.sh` (see the Tallport rules). Nothing else deploys code.

=== laravel/core rules ===

# Laravel in Tallport

- Tallport keeps FreeScout's Laravel 5.5-era layout:
  - models in `app/` (`App\User`, `App\Conversation`, …), not `app/Models`;
  - kernels in `app/Http/Kernel.php` and `app/Console/Kernel.php`;
  - providers in `config/app.php`;
  - routes in `routes/web.php`.
- Put new files where their siblings are. If you use `php artisan make:` commands, move and rename the output to match.
- Pass `--no-interaction` to Artisan commands.
- Many app actions go through `ajax()` controller methods switched on an `action` parameter, not one route per action. Follow that pattern where it is used.
- Prefer named routes and `route()` when generating links.
- Frontend assets are plain jQuery and Bootstrap 3, built with Laravel Mix (`webpack.mix.js`) and minified by devfactory/minify at runtime. There is no Vite.
- Tests don't use model factories or Faker for new code. See the Tallport testing rules.

</laravel-boost-guidelines>
