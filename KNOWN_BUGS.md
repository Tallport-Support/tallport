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
| C6 | decision | `save_draft`, :1546 | A new-conversation draft has no customer until it is sent (recipients are kept on the draft thread; sending sets the customer, see `testSendingNewConversationDraftSetsCustomer`), so drafts show no customer name. Kept (decided 2026-10-07): drafts autosave while typing, so linking or creating customers would leave strays and show unsent drafts on customer pages. |
## Incoming email (`app/Console/Commands/FetchEmails.php`)

| # | Severity | Where | Bug |
|---|---|---|---|

## Users and login

| # | Severity | Where | Bug |
|---|---|---|---|

## Mailboxes (`app/Http/Controllers/MailboxesController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| M8 | decision | `permissionsSave`, :323 | Users whose access is removed keep their personal folders. Kept (decided 2026-10-07), as FreeScout does: a user whose access returns keeps their stars; the folders go when the user is deleted. |
## Settings, system and modules

| # | Severity | Where | Bug |
|---|---|---|---|

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E3 | medium | PHP without `imap` | Webklex's fallback header parser differs from the `imap` extension on dates, header counts, and quoted names. On 2026-10-09, the PHP 8.5.4 test CLI had `imap`, so its `@requires extension imap` tests ran; this entry's no-`imap` behavior was not rechecked. Check each installation's extensions before assuming it is affected. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. On 2026-10-09, the test CLI used libxml2 2.15.2 and the two `ReplySeparationTest` cases skipped at 2.14+; their expected output was not verified. Check each installation's libxml2 version before assuming it is unaffected. |
