<div align="center">

<img src="public/img/logo-300.png" width="180" height="180" alt="Tallport" />

</div>

# Tallport

Tallport is a self-hosted help desk and shared mailbox: customer email
arrives in shared mailboxes, agents reply, assign, add notes and follow up,
and customers just see email. It's a PHP (Laravel) application for MariaDB.

Tallport is a fork of [FreeScout](https://github.com/freescout-help-desk/freescout),
starting from FreeScout 1.8.243 (September 2026). The fork exists to move
faster on fixes and performance, and to bring the codebase to a current
Laravel version; see [Changes from FreeScout](#changes-from-freescout).
Credit for everything up to the fork goes to the FreeScout team.

## Contents

* [Changes from FreeScout](#changes-from-freescout)
* [Requirements](#requirements)
* [Installing](#installing)
* [Switching from FreeScout](#switching-from-freescout)
* [Updating](#updating)
* [Incoming email sources and re-importing](#incoming-email-sources-and-re-importing)
* [Receiving email from a mail server](#receiving-email-from-a-mail-server)
* [Two-factor authentication and passkeys](#two-factor-authentication-and-passkeys)
* [Modules](#modules)
* [Development](#development)
* [Security](#security)
* [License](#license)

## Changes from FreeScout

So far:

* **Updates come from this repository.** The built-in updater installs the
  latest published [Tallport release](https://github.com/nielspeen/tallport/releases)
  instead of FreeScout releases.
* **Branding.** The app says Tallport, with credit to FreeScout.
* **Current Laravel.** Laravel 13 with Symfony 7.4 and Symfony Mailer,
  instead of Laravel 5.5 with SwiftMailer. Laravel 5.5 behaviour that
  FreeScout modules rely on is kept (e.g. `route()` parameters, the `Input`
  facade, `Event::fire()`, the `str_*`/`array_*` helpers).
* **Incoming email** is fetched and read with webklex/php-imap 6 and
  Tallport's own code instead of FreeScout's patched copy of an old version,
  and can also be [received straight from the mail server](#receiving-email-from-a-mail-server).
* **Faster conversations with large messages** and many bug fixes (see
  [KNOWN_BUGS.md](KNOWN_BUGS.md) for what's left).
* **A test suite.** About 420 tests (feature, unit and snapshot tests) run
  on every push, covering the email conversation loop, every route, ajax
  action, artisan command and queued job. See [Development](#development).
* **Known bugs are listed** in [KNOWN_BUGS.md](KNOWN_BUGS.md): bugs found
  while writing the tests, most of them present in FreeScout too.

Planned next: replacing the FreeScout modules Tallport installations depend
on with Tallport features.

## Requirements

* PHP 8.5 or newer with the `mbstring`, `xml`, `zip`, `gd`, `curl`, `intl`
  and `mysql` extensions, and `imap` (from PECL since PHP 8.4) for POP3
  mailboxes
* MariaDB (tested with 11.8)
* Nginx or Apache
* A cron job running `php artisan schedule:run` every minute (fetches
  mail and runs the queue)

Tallport is tested on PHP 8.5 and MariaDB. FreeScout also supports older
PHP versions, MySQL and PostgreSQL; Tallport may still work there, but
isn't tested on them.

## Installing

Tallport installs like FreeScout, so FreeScout's
[Installation Guide](https://github.com/freescout-help-desk/freescout/wiki/Installation-Guide)
applies, using Tallport's code instead of FreeScout's:

1. Download the latest [release](https://github.com/nielspeen/tallport/releases)
   (or `git clone https://github.com/nielspeen/tallport.git`) into your web
   root. `vendor/` is included, so Composer isn't needed.
2. Point the web server at `public/`, then open the site and follow the web
   installer.
3. Add the cron job from [Requirements](#requirements).

## Switching from FreeScout

NOTE: we've incrementially upgraded our FreeScout 1.8.243 installs to the 
latest version of Tallport. Starting at a different version of FreeScout
or skipping versions has not been tried or tested. YMMV. 

I recommend you have your favorite AI take you through the upgrades, or 
simply start fresh with the latest version of Tallport.

Back up the database and files first. Then point your FreeScout updater at
Tallport:

```bash
cd /path/to/freescout
RAW=https://raw.githubusercontent.com/nielspeen/tallport/main
sudo -u www-data curl -fsSL $RAW/config/self-update.php -o config/self-update.php
sudo -u www-data curl -fsSL $RAW/overrides/codedge/laravel-selfupdater/src/SourceRepositoryTypes/GithubRepositoryType.php \
  -o overrides/codedge/laravel-selfupdater/src/SourceRepositoryTypes/GithubRepositoryType.php
sudo -u www-data php artisan freescout:clear-cache
sudo -u www-data php artisan freescout:update
```

Replace `www-data` with your web server's user. The update keeps `.env`,
`storage/` and `Modules/`. If the installation is a git checkout of
FreeScout, move `.git` away afterwards so a `git pull` doesn't bring
FreeScout back.


## Updating

**Manage » System » Status » Update Now**, or on the server:

```bash
sudo -u www-data php artisan tallport:update
```

The updater installs the latest published release from this repository.
Draft and pre-release versions are skipped. `tallport:update` then runs
`php artisan tallport:after-app-update` (cache, database migrations, queue
restart); after updating from the web interface, run that command on the
server yourself.

Tallport's artisan commands are named `tallport:*`. The FreeScout names
(`freescout:update`, `freescout:clear-cache`, ...) still work, for modules,
cron jobs and scripts.

## Incoming email sources and re-importing

Tallport keeps the raw source of each incoming email for 30 days, in
`storage/app/incoming-mail/<thread id>.eml` (set
`APP_INCOMING_MAIL_RETENTION_DAYS` in `.env`; 0 turns it off). The daily
`tallport:clean-tmp` removes older ones.

To import an email file, for example to re-import a message that was saved
wrongly after deleting its conversation for good:

```bash
sudo -u www-data php artisan tallport:receive storage/app/incoming-mail/1234.eml
```

The mailbox is found from the recipients, or given with
`--mailbox=<id or email address>`. An email that is already in Tallport (same
Message-ID) is skipped. `tallport:receive` also reads an email from standard
input, for receiving mail straight from a mail server.

## Receiving email from a mail server

Instead of fetching from an IMAP or POP3 server, the mail server can hand
each email to Tallport as it arrives. With Postfix, add a transport to
`/etc/postfix/master.cf` (adjust the paths and the web server's user):

```
tallport  unix  -       n       n       -       -       pipe
  flags=R user=www-data argv=/usr/bin/php /var/www/html/artisan tallport:receive --mailbox=${recipient}
```

and in `/etc/postfix/main.cf` deliver one recipient at a time and send the
mailboxes' addresses (or their whole domain) to it:

```
tallport_destination_recipient_limit = 1
transport_maps = hash:/etc/postfix/transport
```

```
# /etc/postfix/transport, then: postmap /etc/postfix/transport && postfix reload
support@example.com    tallport:
sales@example.com      tallport:
```

Postfix must accept mail for the domain (for example in `relay_domains`).
`--mailbox` takes a mailbox's address or one of its aliases, so a mailbox in
Bcc gets the email too. The exit code tells Postfix what happened:

* 0: saved, or skipped on purpose (for example already received, by
  Message-ID). Delivering an email again is safe.
* 75: saving failed (logged in Manage » Logs » Fetch Errors). Postfix keeps
  the email and tries again later (for up to `maximal_queue_lifetime`, 5 days
  by default). Until it is saved, Tallport shows a warning above the
  conversation list and, with the fetching problems alert on (Manage »
  Alerts), emails an alert, and another when it has been received.
* 67: no such mailbox. Postfix bounces the email.

To try it without Postfix:

```bash
sudo -u www-data php artisan tallport:receive --mailbox=support@example.com < test.eml; echo $?
```

Postfix delivers an address in `transport_maps` only to Tallport, so the
mailbox's fetching settings can stay as a fallback (for mail that still ends
up in its IMAP inbox). Just don't deliver the same email both ways (for
example through an alias to the IMAP inbox as well): the two copies arrive
with different headers and would be saved twice.

## Two-factor authentication and passkeys

Logging in uses [Laravel Fortify](https://laravel.com/docs/fortify): after
the password, users enter a code from an authenticator app (Google
Authenticator, 1Password, ...), or a recovery code if they lost their phone.
They set it up under their profile » Security, where they can also add
passkeys (sign in with a fingerprint, face or device PIN instead of the
password and code). "Remember this device" skips the code on that device for
14 days (at most 3 devices per user).

Two-factor authentication is required: users who haven't turned it on are
sent to set it up after logging in. To leave it to each user, set
`APP_TWO_FACTOR_REQUIRED=false` in `.env` (then
`php artisan tallport:clear-cache`). An admin can reset a user's two-factor
authentication and passkeys on that user's Security page, for someone who
lost their phone.

Users of FreeScout's Two-Factor Authentication module keep their
authenticator apps and unused recovery codes: updating moves them over and
switches the module off (its table is left as it was).

Passkeys belong to the address in `APP_URL`: they only work on that address,
over HTTPS.

## Modules

FreeScout modules keep working: Tallport reports itself to modules and to
the FreeScout modules directory as FreeScout 1.8.243
(`compatibility_version` in `config/app.php`), so modules that require up
to that version install and run. Module licenses are still managed by
freescout.net.

Tallport fixes bugs that FreeScout still has (see [KNOWN_BUGS.md](KNOWN_BUGS.md)
and the release notes). A module that happens to depend on the old, buggy
behaviour may work differently after such a fix. If a module stops working
properly after an update, please [open an issue](https://github.com/nielspeen/tallport/issues/new/choose)
naming the module and the Tallport version.

## Development

What you need: PHP 8.x with the extensions above, Composer, a MariaDB
server, and the [GitHub CLI](https://cli.github.com/) (`gh`) for releases.

After cloning, run `composer install`. It leaves the committed `vendor/` as
it is and installs the development tools into the ignored `dev/vendor` and
`dev/boost/vendor`, so none of them ship with releases. Then install the
application as usual and set `APP_DEBUG=true` in `.env`.

### AI coding agents

Tallport uses [Laravel Boost](https://laravel.com/docs/boost) to give coding
agents project guidelines, skills and an MCP server. The generated files are
committed for every agent Boost supports (`AGENTS.md`, `.mcp.json`,
`.claude/`, `.cursor/`, `.junie/` and others). Boost runs only when
`APP_DEBUG=true`. Junie's MCP config needs absolute paths, so it isn't
committed: Junie users run `php artisan boost:install` once.

Tallport's own rules are in `.ai/guidelines/`. After changing them, or the
agent list in `boost.json`, run `php artisan boost:update` and commit the
result.

### Tests

The tests use their own database. Create it once, as a MariaDB admin:

```sql
CREATE DATABASE `freescout-test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'freescout-test'@'localhost' IDENTIFIED BY 'freescout-test';
GRANT ALL ON `freescout-test`.* TO 'freescout-test'@'localhost';
```

Then:

```bash
./test.sh                      # everything, plus the inventory check
./test.sh --filter Branding    # just some tests
```

* Test tools (PHPUnit and friends) live in their own Composer project in
  `dev/`, installed into the ignored `dev/vendor`, so they never ship with
  releases. `composer install` and `./test.sh` install them.
* Tests don't read your `.env`, send no real email, make no outside network
  requests and don't touch your installation's files; see
  `tests/FeatureTestCase.php` for how.
* **Snapshots** in `tests/Snapshots/` record routes, the database schema,
  commands, the scheduler, module hooks and how message bodies render. After
  an intended change, run `UPDATE_SNAPSHOTS=1 ./test.sh`, review the diff
  and commit it.
* **Inventory:** a full run fails if any route, ajax action, command or job
  isn't exercised by a test and isn't listed with a reason in
  `tests/inventory-exclusions.php`. New endpoints need a test.
* **Known bugs** have an entry in [KNOWN_BUGS.md](KNOWN_BUGS.md); tests of
  the intended behaviour call `$this->knownBug('C12')` and show up as
  incomplete until the bug is fixed.

CI runs the tests on every push and pull request with PHP 8.5 and MariaDB,
and checks code style with PHP_CodeSniffer using the rules in `phpcs.xml`
(`.github/workflows/test.yml`; run `phpcs` locally to check before
pushing). It also publishes a coverage report for every push to `main`
(`coverage.yml`).

### Dependencies

`vendor/` is committed, because installations update from the release
zip and never run Composer. It must be exactly what Composer produces from
`composer.lock`, with Composer 2.9.7 (the version CI uses):

```bash
rm -rf vendor
composer install --ignore-platform-reqs
```

Commit the result as it is. CI reinstalls `vendor/` the same way and fails
if anything differs.

Some vendor classes are patched: the patched copies are in `overrides/`,
`composer.json` maps their namespaces there (`autoload.psr-4`), and the
original files are deleted after install (`autoload.exclude-from-classmap`,
used by a `post-autoload-dump` script). To patch another file, copy it to
the same path under `overrides/`, add its namespace to `autoload.psr-4` if
it isn't there yet, and add the original to `exclude-from-classmap`.

A few packages are defined in `composer.json` itself (`repositories`,
type `package`): the same code as their locked version, with only the
Laravel versions they accept widened, because no release of theirs accepts
the next Laravel version without other changes (FreeScout's
`codedge/laravel-selfupdater` fork).

`composer.json` resolves dependencies for PHP 8.5 (`config.platform.php`,
the minimum Tallport supports), so an update can't pull in a package that
needs a newer PHP. When updating, ignore only missing extensions:
`composer update ... --ignore-platform-req='ext-*'`.

Incoming email is fetched and read with webklex/php-imap 6, installed
normally, plus Tallport's own code in `app/Incoming` (FreeScout shipped a
patched copy of webklex/php-imap 4.1.1 instead).

### Releasing

```bash
./release.sh "What changed"            # bumps the last version number
./release.sh 1.9.0 "What changed"      # for significant changes
```

It only releases a commit that is pushed to `main` and passed CI, then
creates the GitHub release that installations update to. The notes go at
the top of the release, above GitHub's list of changes.

### Logo and icons

The logo is `public/img/logo-brand.svg`. After changing it, run
`resources/brand/generate-icons.sh` to regenerate the favicons and other
icons (needs Google Chrome and ImageMagick).

## Security

Please report vulnerabilities privately through
[GitHub security advisories](https://github.com/nielspeen/tallport/security/advisories/new)
for this repository, not in public issues.

## License

Tallport is licensed under the [GNU Affero General Public License v3.0](LICENSE),
like FreeScout, which it is based on. FreeScout is © its authors; see the
[FreeScout repository](https://github.com/freescout-help-desk/freescout).
