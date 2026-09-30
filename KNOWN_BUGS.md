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
| C5 | low | `conversation_merge`, :2318 | Merging a conversation with itself reports success and does nothing. With several `merge_conversation_id[]`, `msg` is reset per item, so only the last result is reported and `status` can be `success` next to an error. |
| C6 | medium | `save_draft`, :1546 | A new-conversation draft calls `Customer::create('')`, so `customer_id` stays NULL; the recipient only lives in `threads.to`. |
| C7 | low | `conversations_pagination`, :1870 / :2910 | Always returns `status: success`, even with "Not enough permissions" in `msg`. |
| C8 | low | `restore_conversation`, :1992 | No check that the conversation was deleted; restoring a published one adds a "restored" line item. |
| C9 | low | `bulk_conversation_change_user` / `_status`, :2108 / :2136 | No "already set" check: line items are added even when nothing changes. |
| C10 | low | `save_edit_thread`, :2058 | A missing thread reports "Conversation not found". |
| C13 | low | `send_reply` with `is_create` and several `to` | The code puts extra recipients in the conversation's Cc ("first recipient becomes To"), but the email goes out with all of them in To. |
| C14 | medium | `undoReply`, :3342 | When the reply belongs to another user, the redirect uses `$conversation` before it is assigned → 500 instead of "Sending can not be undone". Test: `testOthersCannotUndoYourReply`. |

## Incoming email (`app/Console/Commands/FetchEmails.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| F1 | high | :747–755 | The branch meant for an agent following up by email on their own earlier emailed reply has `$user_id = $user->id` while `$user` is still null; if reached, the ErrorException is caught and the email **dropped**. In testing the branch wasn't reached: such follow-ups are saved as customer messages instead (F3). Test: `testAgentFollowingUpOnOwnEmailedReply`. |
| F3 | medium | :707, :743 | An agent answering by email in a reply thread (their own UI reply, or their own emailed reply) is saved as a **customer** message from the agent's address rather than as the agent's reply. Only replies to notification emails are recognised as agent replies. Test: `testAgentAnsweringOwnUiReplyByEmail`. |
| F2 | low | `app/Thread.php` :1233/:1235 vs :1258/:1274 | Hooks `conversation.created_by_customer` and `conversation.customer_replied` are fired with different numbers of arguments (see `tests/Snapshots/hooks.json`). |

## Users and login

| # | Severity | Where | Bug |
|---|---|---|---|
| U4 | medium | `User::deleteUser`, `app/User.php` :1358 | `assign_user[mailbox] = user_id` isn't validated: conversations can be assigned to a missing user or one without mailbox access. |
| U5 | low | password forms | Minimum password length is 8 (change password, invite setup), 6 (reset form), none (admin creates user). |
| U6 | low | `UsersController::createSave` | A user manager (perm 10) gets mailbox access filtered to their own mailboxes, but personal folders are created for the unfiltered list. |
| U7 | low | `UsersController::profileSave` | The photo is written to disk during validation even if the save fails; deleted users can still be saved; an admin saving without `disabled` re-activates the user. |
| U8 | low | `ajax send_invite` / `reset_password` | Don't check whether the user is disabled or deleted. |
| U9 | low | `UsersController` | `permissions` GET returns 404 for deleted users but POST doesn't; `notifications` GET authorizes before the deleted check. |
| U10 | low | `OpenController::userSetupSave` | The email isn't sanitized/lowercased and isn't checked against mailbox addresses (createSave and profileSave do both). |
| U11 | medium | `User::sendInvite()` :720, `User::sendPasswordChanged()` :803, `SecureController::logs` :48 | Declare global functions inside methods; a second call in the same process fatals with "Cannot redeclare" (e.g. any long-running process). Tests work around it with `@runInSeparateProcess`. |

## Mailboxes (`app/Http/Controllers/MailboxesController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| M1 | medium | `updateSave`, :165 | `template` is validated but never saved. |
| M2 | medium | `updateSave` | A failed signature validation redirects to `mailboxes.email_signature`, which isn't a route → 500. `emailSignature()` (:701) has no route. |
| M3 | medium | `updateSave` | `signature=''` becomes null (ConvertEmptyStringsToNull) and `strtr(null)` → 500. |
| M4 | low | `updateSave` | An admin saving the form without `state` un-archives the mailbox. |
| M5 | medium | `autoReplySave`, :650 | Enabled with the message missing or empty → 500 (`strip_tags(null)`) before validation runs. |
| M6 | low | `createSave`, :65 | The "user with this email" check also matches deleted users. |
| M7 | low | `update` GET, :111 | Viewing the settings attaches an admin to `mailbox_user` as a side effect. |
| M8 | low | `permissionsSave`, :323 | Users whose access is removed keep their personal folders. |

## Settings, system and modules

| # | Severity | Where | Bug |
|---|---|---|---|
| S1 | medium | `SettingsController` :305 | Saving the emails section without `settings[mail_password]` → 500 (undefined index). |
| S2 | low | `SettingsController::processSave` | Every `env` setting of a section is written to `.env`, as an empty value when absent from the request. Intended for checkboxes (an unticked box isn't sent), and every such setting is in its section's form, so only hand-made requests that leave out a text or select field blank it. |
| S4 | low | `SystemController::ajax check_updates` | With updating disabled it returns `status: error` together with a `msg_success`. |
| S5 | medium | `ModulesController ajax delete`, :595 | Recursively deletes `Modules/<Name>` without deactivating it; reports success even when the module doesn't exist. |
| S6 | low | `ModulesController ajax activate`, :335 | Reports `status: success` even when activation failed (only the flash type says so). |
| S7 | low | `SystemController::action retry_job` | `sleep(1)` inside the web request. |
| S8 | low | `app/Option.php` | The static `Option::$cache` isn't updated by `Option::set()` / `remove()`: stale values within one process. |
| S9 | medium | `app/Jobs/SendAlert.php` :101 | With no recipients (no activated admins, no alert recipients) `$exception` is undefined → ErrorException in the job. Test: `testAlertWithoutRecipientsIsHarmless`. |
| S10 | medium | `app/Jobs/SendAlert.php` | `$exception` is reset per recipient, so only the last recipient's failure is rethrown; earlier failures are only logged. |
| S12 | medium | `app/Console/Commands/ModuleUpdate.php` :54 | Uses the undefined local `$lastError` instead of `\WpApi::$lastError` → ErrorException whenever the modules directory can't be fetched. |
| S13 | low | `app/Console/Commands/ModuleBuild.php` :78 | With an alias given, calls `freescout:module-laroute` without it, so all modules' routes are rebuilt. |
| S14 | low | `app/Console/Commands/CreateUser.php` | Declining the confirmation still prints "User created with id:" (without an id). |
| S15 | low | `app/Console/Commands/Update.php` :47 | Lowers `memory_limit` to 128M for the rest of the process. |

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E3 | medium | PHP 8.4+ | Without the PECL `imap` extension, Webklex's fallback header parser gets `Date` empty, miscounts headers and keeps quotes in names (tests marked `@requires extension imap`). Production (PHP 8.3) has imap. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. Production has 2.9.14. |
| E5 | low | `vendor/` | The committed vendor can't be reproduced by Composer (`rachidlaasri/laravel-installer` differs from its release, `rap2hpoutre/laravel-log-viewer` has files removed). Matters for the Laravel upgrade. |
| E6 | low | `artisan`, `public/index.php` | Laravel 5.5's `e()` isn't null-safe; the entry points define a safe one first. Anything bootstrapping the app another way (scripts, tests) breaks on `{{ null }}`. |
| E7 | low (tests) | `overrides/nesbot/carbon` (Carbon 1.35) | With `Carbon::setTestNow()` set, `Carbon::now()` passes null to `strtotime()`, a deprecation since PHP 8.1 that becomes an exception: tests can't freeze time until Carbon is upgraded with Laravel; use times relative to the real clock. |
| E8 | low | `Helper::linkify()`, `app/Misc/Helper.php` :1729 | Email addresses are linked together with the characters before them: `user=bob@example.com` becomes `mailto:user=bob@example.com`. Visible in `tests/Snapshots/body_rendering/pasted_log.html`; fixing it updates that snapshot. |
