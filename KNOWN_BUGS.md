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
**medium** (500 errors, broken features), **low** (inconsistencies).

## Conversations (`app/Http/Controllers/ConversationsController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| C1 | security | `conversation_move`, :2269 | No access check on the **target** mailbox: an agent can move a conversation into a mailbox they can't see. |
| C2 | high | `delete_conversation_forever`, :1966 | Hard-deletes any conversation, not only ones already in Deleted. |
| C3 | medium | `conversation_change_status`, :651 | `status=not_spam` with a non-existent `conversation_id` reads `$conversation->threads()` before the null check → 500. |
| C4 | medium | `conversation_change_customer`, :1905 | An email not in the `emails` table (or a missing conversation) dereferences null → 500 instead of an error message. |
| C5 | low | `conversation_merge`, :2318 | Merging a conversation with itself reports success and does nothing. With several `merge_conversation_id[]`, `msg` is reset per item, so only the last result is reported and `status` can be `success` next to an error. |
| C6 | medium | `save_draft`, :1546 | A new-conversation draft calls `Customer::create('')`, so `customer_id` stays NULL; the recipient only lives in `threads.to`. |
| C7 | low | `conversations_pagination`, :1870 / :2910 | Always returns `status: success`, even with "Not enough permissions" in `msg`. |
| C8 | low | `restore_conversation`, :1992 | No check that the conversation was deleted; restoring a published one adds a "restored" line item. |
| C9 | low | `bulk_conversation_change_user` / `_status`, :2108 / :2136 | No "already set" check: line items are added even when nothing changes. |
| C10 | low | `save_edit_thread`, :2058 | A missing thread reports "Conversation not found". |
| C11 | medium | `send_reply`, :831 → `Thread::replaceBase64ImagesWithAttachments`, `app/Thread.php` :1566 | An empty `body` becomes null and is passed to `preg_replace_callback()` before validation → 500 instead of "The body field is required". |
| C12 | high | `send_reply` with `multiple_conversations`, :1316–1375 | "Send separately to each recipient" is broken: the copied conversation is inserted with `has_attachments` NULL, which MariaDB's strict mode rejects → 500 after the first recipient's conversation was already created and emailed. |
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
| U1 | security | `Auth/LoginController` | Disabled users can log in; they are only logged out on their next request (`LogoutIfDeleted`). |
| U2 | security | `UsersController::profileSave`, `ajax delete_user` | The "only administrator" check counts disabled and deleted admins; `delete_user` has no last-admin check at all. Admins can demote or delete other admins. |
| U3 | high | `UsersController::permissionsSave`, :353 | Replaces `users.permissions` wholesale and wipes "only assigned tickets" (perm 11), which is set on the profile page. |
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
| S2 | high | `SettingsController::processSave` | Every `env` setting of a section is written to `.env`, as an empty value when absent from the request; a partial form post blanks those values. |
| S3 | security | `SystemController::ajax update`, `freescout:update` | `APP_DISABLE_UPDATING` only hides the UI; the update action and the artisan command still run. |
| S4 | low | `SystemController::ajax check_updates` | With updating disabled it returns `status: error` together with a `msg_success`. |
| S5 | medium | `ModulesController ajax delete`, :595 | Recursively deletes `Modules/<Name>` without deactivating it; reports success even when the module doesn't exist. |
| S6 | low | `ModulesController ajax activate`, :335 | Reports `status: success` even when activation failed (only the flash type says so). |
| S7 | low | `SystemController::action retry_job` | `sleep(1)` inside the web request. |
| S8 | low | `app/Option.php` | The static `Option::$cache` isn't updated by `Option::set()` / `remove()`: stale values within one process. |
| S9 | medium | `app/Jobs/SendAlert.php` :101 | With no recipients (no activated admins, no alert recipients) `$exception` is undefined → ErrorException in the job. Test: `testAlertWithoutRecipientsIsHarmless`. |
| S10 | medium | `app/Jobs/SendAlert.php` | `$exception` is reset per recipient, so only the last recipient's failure is rethrown; earlier failures are only logged. |
| S11 | high | `app/Console/Commands/CleanTmp.php` :49 | Deletes **any** directory in the system temp dir (any depth) named 32 hex characters and older than a day, not only FreeScout's — other programs' temp dirs on the server included. |
| S12 | medium | `app/Console/Commands/ModuleUpdate.php` :54 | Uses the undefined local `$lastError` instead of `\WpApi::$lastError` → ErrorException whenever the modules directory can't be fetched. |
| S13 | low | `app/Console/Commands/ModuleBuild.php` :78 | With an alias given, calls `freescout:module-laroute` without it, so all modules' routes are rebuilt. |
| S14 | low | `app/Console/Commands/CreateUser.php` | Declining the confirmation still prints "User created with id:" (without an id). |
| S15 | low | `app/Console/Commands/Update.php` :47 | Lowers `memory_limit` to 128M for the rest of the process. |

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E1 | high (perf) | `config/purifier.php` | `AutoFormat.AutoParagraph` makes HTML purification super-linear: a 1 MB message takes ~4 s to render, every time it's viewed. Output isn't cached. The original reason for the fork. |
| E2 | medium | `Thread::getCleanBody`, :338 | mews/purifier builds its config once; `Config::set('purifier…EscapeNonASCIICharacters')` at runtime has no effect afterwards (long-running queue workers). |
| E3 | medium | PHP 8.4+ | Without the PECL `imap` extension, Webklex's fallback header parser gets `Date` empty, miscounts headers and keeps quotes in names (tests marked `@requires extension imap`). Production (PHP 8.3) has imap. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. Production has 2.9.14. |
| E5 | low | `vendor/` | The committed vendor can't be reproduced by Composer (`rachidlaasri/laravel-installer` differs from its release, `rap2hpoutre/laravel-log-viewer` has files removed). Matters for the Laravel upgrade. |
| E6 | low | `artisan`, `public/index.php` | Laravel 5.5's `e()` isn't null-safe; the entry points define a safe one first. Anything bootstrapping the app another way (scripts, tests) breaks on `{{ null }}`. |
| E7 | low (tests) | `overrides/nesbot/carbon` (Carbon 1.35) | With `Carbon::setTestNow()` set, `Carbon::now()` passes null to `strtotime()`, a deprecation since PHP 8.1 that becomes an exception: tests can't freeze time until Carbon is upgraded with Laravel; use times relative to the real clock. |
| E8 | low | `Helper::linkify()`, `app/Misc/Helper.php` :1729 | Email addresses are linked together with the characters before them: `user=bob@example.com` becomes `mailto:user=bob@example.com`. Visible in `tests/Snapshots/body_rendering/pasted_log.html`; fixing it updates that snapshot. |
