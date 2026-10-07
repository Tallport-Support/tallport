# Known bugs

Bugs and oddities found while writing the test suite (September 2026), as
the starting point for a bug-fixing pass. Fixed ones are removed. The tests
cover the intended behaviour around them, but deliberately don't assert any
of these, so fixing one shouldn't break a test; each fix should come with a
test that fails first. Where a test for the intended behaviour already
exists, it is marked incomplete with the bug's id (`$this->knownBug('C12')`):
fixing the bug means removing that line and seeing the test pass.

Line numbers refer to the code as of Tallport 1.8.244. Severity is a first
guess: **security** (permission gaps), **high** (data loss or wrong data),
**medium** (500 errors, broken features), **low** (inconsistencies),
**decision** (intended upstream, but worth reconsidering).

## Conversations (`app/Http/Controllers/ConversationsController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| C1 | decision | `conversation_move`, :2269 | No access check on the **target** mailbox: an agent can move a conversation into a mailbox they can't see. FreeScout documents this as intended (its SECURITY.md: "Support agents are allowed to move conversations to any mailbox, even to ones they don't have access to"); kept for now (decided 2026-09-30). |
| C6 | low | `save_draft`, :1546 | A new-conversation draft has no customer until it is sent (recipients are kept on the draft thread; sending sets the customer, see `testSendingNewConversationDraftSetsCustomer`), so drafts show no customer name. |

## Incoming email (`app/Console/Commands/FetchEmails.php`)

| # | Severity | Where | Bug |
|---|---|---|---|

## Users and login

| # | Severity | Where | Bug |
|---|---|---|---|

## Mailboxes (`app/Http/Controllers/MailboxesController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| M8 | low | `permissionsSave`, :323 | Users whose access is removed keep their personal folders. |

## Settings, system and modules

| # | Severity | Where | Bug |
|---|---|---|---|
| S2 | low | `SettingsController::processSave` | Every `env` setting of a section is written to `.env`, as an empty value when absent from the request. Intended for checkboxes (an unticked box isn't sent), and every such setting is in its section's form, so only hand-made requests that leave out a text or select field blank it. |

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E3 | medium | PHP 8.4+ | Without the PECL `imap` extension, Webklex's fallback header parser gets `Date` empty, miscounts headers and keeps quotes in names (tests marked `@requires extension imap`). Production (PHP 8.3) has imap. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. Production has 2.9.14. |
