# Known bugs

Bugs and oddities found while writing the test suite (September 2026), as
the starting point for a bug-fixing pass. None are fixed yet. The tests
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
| C1 | decision | `conversation_move`, :2269 | No access check on the **target** mailbox: an agent can move a conversation into a mailbox they can't see. FreeScout documents this as intended (its SECURITY.md: "Support agents are allowed to move conversations to any mailbox, even to ones they don't have access to"); decide whether Tallport keeps it. |
| C6 | low | `save_draft`, :1546 | A new-conversation draft has no customer until it is sent (recipients are kept on the draft thread; sending sets the customer, see `testSendingNewConversationDraftSetsCustomer`), so drafts show no customer name. |
| C13 | low | `send_reply` with `is_create` and several `to` | The code puts extra recipients in the conversation's Cc ("first recipient becomes To"), but the email goes out with all of them in To. |

## Incoming email (`app/Console/Commands/FetchEmails.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| F1 | high | :747–755 | The branch meant for an agent following up by email on their own earlier emailed reply has `$user_id = $user->id` while `$user` is still null; if reached, the ErrorException is caught and the email **dropped**. In testing the branch wasn't reached: such follow-ups are saved as customer messages instead (F3). Test: `testAgentFollowingUpOnOwnEmailedReply`. |
| F3 | medium | :707, :743 | An agent answering by email in a reply thread (their own UI reply, or their own emailed reply) is saved as a **customer** message from the agent's address rather than as the agent's reply. Only replies to notification emails are recognised as agent replies. Test: `testAgentAnsweringOwnUiReplyByEmail`. |
| F2 | low | `app/Thread.php` :1233/:1235 vs :1258/:1274 | Hooks `conversation.created_by_customer` and `conversation.customer_replied` are fired with different numbers of arguments (see `tests/Snapshots/hooks.json`). |

## Users and login

| # | Severity | Where | Bug |
|---|---|---|---|
| U7 | low | `UsersController::profileSave`, `OpenController::userSetupSave` | An uploaded photo is saved to disk during validation, even when another field then fails validation (the file is left unused). |

## Mailboxes (`app/Http/Controllers/MailboxesController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| M1 | low | `updateSave`, `resources/views/mailboxes/update.blade.php` :163 | The email template setting (fancy/plain) is hidden in the form, validated but never saved, and nothing reads it when sending: an unfinished FreeScout feature. Finish or remove. |
| M7 | low | `update` GET, :111 | Viewing the settings attaches an admin to `mailbox_user` as a side effect. |
| M8 | low | `permissionsSave`, :323 | Users whose access is removed keep their personal folders. |

## Settings, system and modules

| # | Severity | Where | Bug |
|---|---|---|---|
| S2 | low | `SettingsController::processSave` | Every `env` setting of a section is written to `.env`, as an empty value when absent from the request. Intended for checkboxes (an unticked box isn't sent), and every such setting is in its section's form, so only hand-made requests that leave out a text or select field blank it. |
| S6 | low | `ModulesController ajax activate`, :335 | Reports `status: success` even when activation failed (only the flash type says so). |
| S7 | low | `SystemController::action retry_job` | `sleep(1)` inside the web request. |
| S13 | low | `app/Console/Commands/ModuleBuild.php` :78 | With an alias given, calls `freescout:module-laroute` without it, so all modules' routes are rebuilt. |

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E3 | medium | PHP 8.4+ | Without the PECL `imap` extension, Webklex's fallback header parser gets `Date` empty, miscounts headers and keeps quotes in names (tests marked `@requires extension imap`). Production (PHP 8.3) has imap. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. Production has 2.9.14. |
| E5 | low | `vendor/` | The committed vendor can't be reproduced by Composer (`rachidlaasri/laravel-installer` differs from its release, `rap2hpoutre/laravel-log-viewer` has files removed). Matters for the Laravel upgrade. |
| E6 | low | `artisan`, `public/index.php` | Laravel 5.5's `e()` isn't null-safe; the entry points define a safe one first. Anything bootstrapping the app another way (scripts, tests) breaks on `{{ null }}`. |
| E7 | low (tests) | `overrides/nesbot/carbon` (Carbon 1.35) | With `Carbon::setTestNow()` set, `Carbon::now()` passes null to `strtotime()`, a deprecation since PHP 8.1 that becomes an exception: tests can't freeze time until Carbon is upgraded with Laravel; use times relative to the real clock. |
