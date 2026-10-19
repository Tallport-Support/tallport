# Hosting Tallport for others

This describes how to run Tallport for other organisations on your own
servers. For a single installation, see [README.md](README.md).

The [TODO](#todo) at the end lists the changes Tallport still needs before
this works as described.

## One installation per customer

Tallport is not multi-tenant: users, mailboxes, settings and modules are
global to an installation. Give each customer their own installation:

* **Directory:** its own code directory, owned by its own system user
  (`vendor/` is committed, so a release is the whole application).
* **Database:** its own database and database user.
* **Configuration:** its own `.env` and `APP_KEY`.
* **Site:** its own domain and PHP-FPM pool.
* **Background work:** its own cron entry (`php artisan schedule:run`) and
  queue workers.

This keeps one customer's data, load and mistakes away from the others. It
also lets a customer leave with a database dump and their `storage/`
directory.

Per installation:

1. Install from the command line with the customer's domain as `APP_URL`
   (see [Installing](README.md#installing)). Don't leave the web installer
   reachable.
2. Create the operator account (see below), then the customer's first
   administrator.
3. Add the cron entry and the queue workers from
   [Requirements](README.md#requirements) and [AI](README.md#ai).
4. Keep `.env` to what the installation needs to run (database, `APP_URL`,
   mail, security settings); everything else is set in the browser.

## Operator mode

You need a way in that the customer's administrators can't take away, and
some settings only you should change. Both come from the operator account,
the host's own administrator in every installation.

Creating an operator account (from the command line) turns on operator mode.
It only locks things down; it doesn't change how anything else works:

* **The operator outranks the other administrators.** They can't delete,
  disable or demote it, change its email or reset its two-factor
  authentication.
* **Some settings and pages are the operator's** (the table below). Other
  administrators don't see them, and the server refuses them.
* **Everything else is the same.** Mail, conversations, mailboxes, users and
  every other setting work exactly as without an operator.

Without an operator account every administrator is the highest-ranking, so
an installation that isn't hosted works as it does now.

The operator account is set from the command line only:

```sh
php artisan tallport:operator host@example.com            # makes it (prints a password), or makes an existing user the operator
php artisan tallport:operator host@example.com --reset    # a new password; two-factor authentication and passkeys off
php artisan tallport:operator host@example.com --remove   # a regular administrator again
```

So a customer can't lock you out, and anyone with the command line can make
themselves operator to deal with an administrator.

### Rank

Administrators don't change each other's accounts. An administrator sees
another administrator's account but can't change their email, name, role or
sign-in, reset their password or two-factor authentication, or delete them.
The operator outranks the administrators and can do all of that. Operators
don't change each other's accounts either. The API follows the same rule: an
administrator is deleted only with an operator's API key.

This holds without an operator too: then an administrator is dealt with from
the command line.

### Keeping it to one place

So that Tallport doesn't need testing twice, operator mode is one question
the code asks: can this user change what only the highest-ranking
administrator may change (a gate such as `can('operate')`)?

* Without an operator, it's true for every administrator.
* With one, it's true only for the operator.

Each locked-down page or setting asks that question and nothing else. Nothing
outside the gate checks whether an operator exists. So the gate is tested in
both situations, each locked-down entry point gets one test that it's refused
to a customer administrator, and the rest of the suite runs as it does now.

## Updates

You choose when a release goes out. In operator mode, System » Status »
Update Now is the operator's. Update from the command line instead: run
`php artisan tallport:update` and then `php artisan tallport:after-app-update`
in each installation, one at a time so a bad release stops early.

## What stays with the host

The customer's administrators manage their help desk: users, mailboxes,
mailbox connections, channels, workflows, saved replies, the knowledge base,
retention, appearance, API keys and webhooks. The settings below run on your
servers or cost you money, so in operator mode only the operator changes them.

| Where | What | Why |
|---|---|---|
| Modules | Activating, deactivating, deleting and updating modules | A module is PHP code that runs on your server. Updating downloads code from the module's own address. |
| System » Tools | Migrate database, clear cache | They are maintenance work; you run them during updates. |
| System » Status | Updates, the server's paths, PHP and permission fixes | They describe your server. The customer can't act on them. |
| Logs | Application log | It shows server paths and stack traces. |
| Settings » Mail Settings | The system's outgoing mail (SMTP) and the fetch schedule | You provide the mail server for notifications. The fetch schedule is load on your server. |
| Settings » AI | Providers, API keys and the daily token limit, when you provide AI | The usage is billed to you. If a customer brings their own key, you enter it for them. |
| Settings » Alerts | Log and fetch alerts about the server | They go to you, not the customer. |
| Settings » Appearance | "Powered by" in the chat widget | Your choice as the host. |
| `.env` | Everything in it | Its values are security settings (trusted hosts, CSP, frame options, allowed extensions) and paths. |

Settings a customer can keep:

* **General:** company name, locale, time zone, conversation numbers and
  email history.
* **Retention.**
* **API & Webhooks**, apart from the CORS hosts.

## Outgoing connections

A customer's administrator enters addresses Tallport connects to:

* **Mailboxes:** IMAP and SMTP servers.
* **Webhooks:** their URLs.
* **AI:** provider and embedding base URLs.

On a shared server those addresses can point at your internal network
(localhost, the database, metadata services). Firewall each installation's
outgoing traffic. In operator mode Tallport also refuses private addresses
when a customer administrator saves one (see the TODO); the operator still
can.

## TODO

Changes Tallport needs before it can be hosted like this.

* **The gate** (`operate`, see
  [Keeping it to one place](#keeping-it-to-one-place)), tested with and
  without an operator.
* **The operator in the user list.** Shown as the operator, and not counted
  as one of the customer's users.
* **Operator's pages and settings.** Behind the gate, checked in the
  controller (not only by hiding the menu item):
  * Modules;
  * System » Tools;
  * Update Now and the server details on System » Status;
  * the application log;
  * Settings » Mail Settings' system mail and fetch schedule;
  * Settings » AI's providers, keys and daily token limit;
  * Settings » Alerts;
  * the chat widget's "Powered by".
* **Settings out of `.env`.** General (custom number, message size, email
  history, locale, time zone, user permissions), Mail Settings (fetch
  schedule), Alerts (log alerts) and API (CORS hosts) still write `.env`
  from the browser. They should be options in the database, like the other
  settings. Then `.env` can be read-only to the web server.
* **Outgoing addresses.** When saved by someone the gate refuses, refuse
  private and reserved addresses for:
  * mailbox servers;
  * webhooks;
  * AI and embedding base URLs.

  This is a check on saving only; connecting works the same.
  `Helper::sanitizeRemoteUrl()` does this today only for customer photos.
* **Command-line install.** Check that a full install, with no browser step,
  works from the command line and leaves the web installer closed.
* **Leaving.** A documented export for a customer who moves away: a database
  dump plus `storage/`, and how to restore it on their own server.
