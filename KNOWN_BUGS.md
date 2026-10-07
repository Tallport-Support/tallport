# Known bugs

Bugs and oddities found while writing the test suite (September 2026), as
the starting point for a bug-fixing pass. Fixed ones are removed. The tests
cover the intended behaviour around them, but deliberately don't assert any
of these, so fixing one shouldn't break a test; each fix should come with a
test that fails first. Where a test for the intended behaviour already
exists, it is marked incomplete with the bug's id (`$this->knownBug('C12')`):
fixing the bug means removing that line and seeing the test pass.

Line numbers refer to the code as of Tallport 1.8.244, and for the entries added on
2026-10-07 (from C16, F3, U8, M9, S14 and the new sections) as of 2.19.0. Severity is a first
guess: **security** (permission gaps), **high** (data loss or wrong data),
**medium** (500 errors, broken features), **low** (inconsistencies),
**decision** (intended upstream, but worth reconsidering).

## Conversations (`app/Http/Controllers/ConversationsController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| C1 | decision | `conversation_move`, :2269 | No access check on the **target** mailbox: an agent can move a conversation into a mailbox they can't see. FreeScout documents this as intended (its SECURITY.md: "Support agents are allowed to move conversations to any mailbox, even to ones they don't have access to"); kept for now (decided 2026-09-30). |
| C6 | low | `save_draft`, :1546 | A new-conversation draft has no customer until it is sent (recipients are kept on the draft thread; sending sets the customer, see `testSendingNewConversationDraftSetsCustomer`), so drafts show no customer name. |
| C16 | low | `Conversation::setLastReplyAt()` | Passing a Carbon date fails with "Undefined variable $date" (should format `$value`). Callers in core pass strings; the method is public. |
| C17 | high | `Conversation::forward()` | With remote storage (`filesystems.default` not local) it calls `$this->getFileStream()` instead of the attachment's; the error is caught, so the forwarded copy points at the original file and the conversation isn't marked as having attachments. Reached through the workflow Forward action. |
| C18 | low | `Thread::create()` | `$data['first']` is written into `from` instead of `first`. |
| C19 | low | `Thread::getActionTypeName()` | Reads `self::$action_types`, not the `thread.action_types` filter, so modules' action types have no name. |
| C20 | medium | `Thread::createExtended()` | Calls `utcStringToServerDate()`, which doesn't exist: a thread with `created_at` throws, including through the API (`createdAt`). |
| C21 | medium | `NewConversation` (Livewire), `new-ticket?from_thread_id=` | New conversation from a message with attachments gives a 500: the `attachments` mount parameter fills the public `$attachments` with Attachment models before `mount()` adds its arrays, and composer_editor reads them as arrays. |
| C22 | medium | `Listeners/SendReplyToCustomer.php:28` | A reply in a custom conversation (type 4, made by modules, no customer) crashes on `$conversation->customer->getMainEmail()`; it should save without emailing. |
| C23 | high | `routes/web.php` `/thread/{thread_id}/attachments.zip` | The route ends in a file extension, which production nginx answers with 404 (as it did for `.eml`): Download All is probably broken in production. Use an extensionless path and Content-Disposition. |
| C24 | low | `Attachment::typeNameToInt('text')` | Returns TYPE_OTHER: `TYPE_TEXT` is 0 and fails `!empty()`. Fetched text attachments get the wrong type. |
| C25 | low | `Attachment::deleteAttachments()` | `break 2` leaves the loop after the first thread, so other threads and the conversation keep `has_attachments` after their files are deleted (`continue 2`?). |

## Incoming email (`app/Console/Commands/FetchEmails.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| F2 | low | `app/Thread.php` :1233/:1235 vs :1258/:1274 | Hooks `conversation.created_by_customer` and `conversation.customer_replied` are fired with different numbers of arguments (see `tests/Snapshots/hooks.json`). |
| F3 | high | `FetchEmails`, `Listeners/SendAutoReply.php:42` | Auto replies go to bounces without an Auto-Submitted header (From MAILER-DAEMON, empty Return-Path): `CustomerCreatedConversation` fires before `saveBounceData()` marks the thread as a bounce. Risk of a bounce/auto-reply loop. |
| F4 | medium | `FetchEmails::fetch()` | An unreadable INBOX never fails the run (`$messages` is always a Collection), and `fetch_emails_last_successful_run` is still updated, so System Status hides it. |
| F5 | low | `FetchEmails::handle()` | `--debug` sets `imap.options.debug`, which `getMailboxClient()` ignores: no IMAP log. |
| F6 | low | `FetchEmails::executeFetch()` | With `--debug`, a mailbox that throws leaves `ob_start()` open and later output is swallowed. |
| F7 | medium | `FetchEmails::saveUserThread()` | With the mailbox's assignee setting "Anyone", an agent's emailed reply stores `user_id = -1` directly instead of through `setUser()`; the conversation sits in the wrong folder. |
| F8 | low | `FetchEmails` (no test) | Base64 images in fetched emails become attachments with `thread_id` NULL, so their files stay behind when the conversation is deleted. |
| F9 | medium | `Nostr/IncomingMessageHandler::fileName()` (no test) | Uses Symfony's `MimeType\ExtensionGuesser`, gone in Symfony 7: files sent without a name are always saved as `.bin`. |

## Users and login

| # | Severity | Where | Bug |
|---|---|---|---|
| U7 | low | `UsersController::profileSave`, `OpenController::userSetupSave` | An uploaded photo is saved to disk during validation, even when another field then fails validation (the file is left unused). |
| U8 | low | `User::getInitials()` | `strtoupper`, not `mb_strtoupper`: non-ASCII initials stay lowercase. |
| U9 | medium | `Helper::resizeImage()` | A file that isn't a readable image throws ("Cannot use bool as array", or a getimagesize warning on PHP 8.5) instead of returning false: broken customer, user and invite photos give a 500 instead of a validation error, and API user creation fails after the user is created. |
| U10 | low | `UsersController` `reset_password` | An unsendable reset email (TransportException) gives a 500; `send_invite` and `createSave` handle it. |
| U11 | medium | `tallport:create-user` | Exit codes are inverted since Laravel 13 casts `handle()`'s bool: success exits 1, failures exit 0. |

## Mailboxes (`app/Http/Controllers/MailboxesController.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| M7 | low | `update` GET, :111 | Viewing the settings attaches an admin to `mailbox_user` as a side effect. |
| M8 | low | `permissionsSave`, :323 | Users whose access is removed keep their personal folders. |
| M9 | low | `Mailbox::userHasAccess()` | `$filter != -1` is loose, so a module's `mailbox.user_has_access` filter returning `true` can't grant access. |
| M10 | low | `/mailbox/oauth/{id}/{in_out}/{provider}` | An unknown provider gives a 500 (`oauthGetAuthorizationUrl()` returns an undefined `$url`). |
| M11 | medium | `MailHelper::oauthGetAccessToken()` | An empty body or curl failure returns `[]` silently: a failed OAuth refresh isn't logged, and the callback redirects as if it worked. |

## Settings, system and modules

| # | Severity | Where | Bug |
|---|---|---|---|
| S2 | low | `SettingsController::processSave` | Every `env` setting of a section is written to `.env`, as an empty value when absent from the request. Intended for checkboxes (an unticked box isn't sent), and every such setting is in its section's form, so only hand-made requests that leave out a text or select field blank it. |
| S6 | low | `ModulesController ajax activate`, :335 | Reports `status: success` even when activation failed (only the flash type says so). |
| S7 | low | `SystemController::action retry_job` | `sleep(1)` inside the web request. |
| S13 | low | `app/Console/Commands/ModuleBuild.php` :78 | With an alias given, calls `freescout:module-laroute` without it, so all modules' routes are rebuilt. |
| S14 | medium | `SystemController` Background Tasks, :227 | Sets `$date_` but formats `$date`: "Last successful run" shows the last run's date, and with only a successful run recorded the undefined variable breaks the checks. |
| S15 | medium | `App\Job::runNow()` (System Status Retry) | Saves a datetime string into the int `jobs.available_at` (because of `$dates`): on SQLite the job never becomes due, on MariaDB it's truncated to 2026 and works by accident. |
| S16 | low | `ModulesController` :293 | `output \|\| status` is always true: a failed module update shows an extra empty red message. |
| S17 | low | `AiDocumentsController::apiErrors()` | A non-string `canonical_locale` gives a `trim()` TypeError (500) instead of a 422. |
| S18 | medium | `App\Modules\Module` `registerProviders()`/`registerFiles()` | Only `\Exception` is caught: a missing provider class (half-updated module) throws `\Error`, never reaches `modules.register_error`, and breaks the page. |

## Sending replies and notifications

| # | Severity | Where | Bug |
|---|---|---|---|
| R1 | medium | `Jobs/SendReplyToCustomer.php:293` | With the customer gone (deleted since the reply was queued) the job crashes before its own fallback (lookup by email). It should send to the address or log "not sent". |
| R2 | low | `Listeners/SendReplyToCustomer.php:64-76` | Builds `$mailbox_change_history` but never passes it on: after a move to another mailbox, quoted earlier replies get the new mailbox's signature (#5419). |
| R3 | low | `Notifications/BroadcastNotification.php:105-111` | The realtime notification entry previews the conversation's first message, not the new one (correct after a reload). |
| R4 | medium | `MailHelper::setMailDriver()`, `Mail/ReplyToCustomer` | A workflow email's sender name (`Actions::META_SENDER`) never reaches the email: the thread SendReplyToCustomer passes as a 4th argument is dropped, and `getMailFrom()` is called without it. |
| R5 | medium | `MailHelper::sendTestMail()` | Reports success when sending throws an exception with an empty message (`Mail::failures()` always returns `[]`); the mailbox test email likely too. |

## Customers

| # | Severity | Where | Bug |
|---|---|---|---|
| K1 | low | `Customer::getEmailOrPhone()` | Operator precedence makes `$phones` a bool: a customer with only a phone number shows nothing in the customers table. |
| K2 | low | `Customer::setData()` | The loop never reaches the `country` branch: "Germany" isn't turned into "DE". |
| K3 | high | `CustomersController::updateSave` (~198-209) | Moving one email from customer B to A moves all of B's conversations and rewrites their `customer_email` to the moved address, so replies go to the wrong address. Only that address's conversations should move. |
| K4 | security | `NostrController` `customerKeys`, `customerKeysSave` | No customer visibility check: with `APP_LIMIT_USER_CUSTOMER_VISIBILITY` on, an agent can view and edit the Nostr keys of customers they can't see elsewhere. |

## Helpers (`app/Misc/Helper.php`, `Functions.php`, `LegacyHelpers.php`)

| # | Severity | Where | Bug |
|---|---|---|---|
| H1 | low | `Helper::isCarbon()` | Compares the exact class name: `isCarbon(now())` is false (use `instanceof`). |
| H2 | medium | `Helper::resizeImage()` | Opaque PNGs and all GIFs are flood-filled white from pixel (0,0), damaging photos; only transparency should become white. |
| H3 | low | `Helper::linkify()` | For protocols other than http(s)/mail the regex has no end anchor: `ftp://files.example.org/a.txt` links to `ftp://f`. Only modules use them. |
| H4 | low | `__h()` with `$escape_replacements` | The loop overwrites `$key`: the result is the last replacement's name. |
| H5 | low | `array_prepend()` without a key | Gives `['' => 0, …]` instead of Laravel 5.5's `[0, 1, 2]` (module compatibility). |
| H6 | low | `Helper::normalizeIPv6InUrl()` (no test) | Treats `host:port` as IPv6: `http://127.0.0.1:8080/x` becomes `[127.0.0.1:8080]`, so the URL whitelist compares the wrong host. |

## Workflows and API

| # | Severity | Where | Bug |
|---|---|---|---|
| W1 | medium | `Workflows/Runner::candidates()` :433 | "Assigned to User is not X" in time-based workflows uses SQL `user_id != X`, leaving out unassigned conversations (NULL) that `Conditions::check()` matches. |
| W2 | low | `Workflows/Actions.php:123` | The empty-body check `strip_tags(trim($body), '<img>') === ''` lets `<p> </p>` through: empty replies, emails or notes are created. |
| W3 | medium | `Api/Writer.php:365` | When an API reply closes a conversation, `conversation.status_changed` only fires if the in-memory `threads_count > 1`: the first reply to a customer's only message doesn't fire it, so workflows and the `convo.status` webhook miss it. |

## Environment and dependencies

| # | Severity | Where | Bug |
|---|---|---|---|
| E3 | medium | PHP 8.4+ | Without the PECL `imap` extension, Webklex's fallback header parser gets `Date` empty, miscounts headers and keeps quotes in names (tests marked `@requires extension imap`). Production (PHP 8.3) has imap. |
| E4 | low | libxml2 2.14+ | `DOMDocument::loadHTML` no longer wraps bare text in `<p>`, changing reply separation output. Production has 2.9.14. |
