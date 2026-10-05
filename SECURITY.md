# Security Policy

## Reporting a vulnerability

Please report vulnerabilities in Tallport privately through
[GitHub security advisories](https://github.com/nielspeen/tallport/security/advisories/new),
not in public issues.

* One issue per advisory, so each can be tracked and fixed on its own.
* Include the Tallport version (Settings » System » Status), steps to
  reproduce and what an attacker gains.
* Fixes are released as a new Tallport version; the advisory is published
  after that.

Vulnerabilities in a **module** go to that module's author, through the
security advisories of the module's own GitHub repository (its "View details"
link on the Modules page).

Tallport is a fork of FreeScout. If a vulnerability also affects FreeScout,
please report it to FreeScout as well, the same way:
https://github.com/freescout-help-desk/freescout/security/advisories/new.

## Supported versions

Only the latest release is supported. Installations update to it through
Settings » System » Status » Update Now.

## Current behaviour that is not a vulnerability

Tallport inherits these from FreeScout; some may change (see
[KNOWN_BUGS.md](KNOWN_BUGS.md)):

* Support agents can move conversations to any mailbox, even ones they
  don't have access to.
* Drafts can be viewed and discarded by any member of the mailbox where
  they were created.
* Images in received emails load from their original servers.
* With `APP_LIMIT_USER_CUSTOMER_VISIBILITY=true`, see FreeScout's
  [FAQ](https://github.com/freescout-help-desk/freescout/wiki/FAQ#is-it-possible-to-have-separate-contactscustomers-per-mailbox)
  for what is and isn't separated per mailbox.
