# Tallport review

Reviewed on 2026-10-07 at Tallport `3f67a436` (2.21.0), with Laravel 13.34.0, PHP 8.5, Livewire 4.4.7, Laravel AI 1.0.1, and FruitUI `157fdbd`.

The foundations worth keeping are the module compatibility contracts, Eventy hooks, native FruitUI controls, established authorization policies, mail fixtures, and substantial feature coverage. Improvements should make the important operations easier to understand and harder to get wrong. Preserve the recorded C1, C6, and M8 product decisions in `KNOWN_BUGS.md`.

This is a source review of the application, with deeper inspection of AI, conversation operations, queues, Livewire, and the FruitUI integration. It is not an exhaustive security audit or a visual/browser audit. The defects below follow from the inspected code; the listed regression cases are work to add, not tests claimed to have run. P1 items affect correctness, confidentiality, or reliable execution; P2 items address bounded operation, maintainability, and product quality. Each item is independently deliverable.

- [x] **01 · P1 · Tallport — Make queue reservations outlast job execution.**

  Evidence: `config/queue.php:44` sets the database reservation to 90 seconds; Beanstalkd and Redis also default to 90. `config/app.php:225` gives workers 1,800/900 seconds, while job overrides include 240 seconds for translations, 600 for indexing, and 3,600 for workflows. With overlapping workers, a live job can become available again before its first execution ends.

  Set connection reservation/visibility limits from the actual longest permitted execution, with a safety margin. Consider a separate connection for unusually long work, and split large workflow runs into bounded jobs. Keep delivery operations safe when an acknowledgement is lost. Acceptance: exercise reservation expiry with a real database/Redis queue and verify a second worker cannot reserve live work. Laravel explicitly requires the execution timeout to be shorter than the reservation. [Queue timeouts](https://laravel.com/framework/docs/13.x/queues#job-expirations-and-timeouts).

  Completed: database, Redis, and Beanstalkd now have separate `default`, `emails`, and `ai` reservations (3,660/360/960 seconds) and workers; mail jobs are capped at 300 seconds, including module payloads. Database and Redis queue tests verify reservation behavior. SQS visibility remains operator-configured as documented in `README.md`. External delivery idempotency and bounded workflow batches remain separate follow-up work.

- [x] **02 · P1 · Tallport — Apply mailbox AI changes to running workers.**

  Evidence: `MailboxesController::aiSave()` (`app/Http/Controllers/MailboxesController.php:302`) clears `Option::$cache` only in its own process. `app/Option.php:78` memoizes values in static memory. Unlike the installation settings path, the mailbox path does not restart workers, and there is no per-job option reset in `app/`. A worker that already read a feature switch or glossary can retain the old value.

  Use the existing worker restart mechanism or explicit cache invalidation between jobs. Acceptance: warm a worker's settings, disable translations/change a glossary through mailbox settings, and verify the next job respects the new values. Disabling a feature must stop subsequent external AI requests.

  Completed: the AI queue clears its in-process option cache before each job. A worker regression test warms old settings, saves a disabled feature and new glossary, then proves subsequent jobs skip the AI call and use the latest glossary when re-enabled.

- [x] **03 · P1 · Tallport — Fix the translation sanitizer's missing closure variable.**

  Evidence: `app/Ai/Translations.php:203` captures only `&$clean`, but the image fallback at line 236 calls `$document->createTextNode()`. `$document` is undefined inside that closure. Any image reaching the branch after its unsupported source is removed causes an error instead of preserving its alternative text.

  Capture the document or use the node's owner document. Acceptance: add an HTML translation fixture that reaches this branch, including an image with a non-HTTP source and alternative text; assert the text survives and translation completes. Extend `AiTranslationsTest::testWhatGoesToTranslation()` coverage.

  Completed: the sanitizer captures its DOM document. A regression test uses a local image URL (which survives the earlier purifier), verifies its alternative text replaces the image in the AI prompt, and completes the translation.

- [x] **04 · P1 · Tallport — Validate completed AI answers before accepting them.**

  Evidence: `TallportAgent::streamJson()` (`app/Ai/Agents/TallportAgent.php:125`) accepts anything `PartialJson::decodeComplete()` returns as an array. The schema is supplied as instructions, but is not enforced on the completed answer. `{}` or `[]` therefore counts as a successful call. `Translations::translate()` and `ChatTranslation::translateReply()` interpret an empty translation as “same language,” which can silently suppress a translation or permit the original reply.

  Keep partial parsing for previews, then validate the complete object's fields, types, booleans, and feature-specific requirements inside the provider-attempt boundary. Missing output must fail or try the backup; only an explicit, valid same-language result may bypass translation. Acceptance: cover empty objects/arrays, missing fields, string booleans, wrong field types, and invalid batch message IDs, alongside valid same-language answers.

  Completed: finished streamed answers now have schema and feature checks inside each provider attempt, so invalid primary answers try the backup. Empty translations require an explicit valid `same_language` result; nonempty translations are kept even when the detected language matches the target. Chat batches reject unknown or duplicate IDs, while valid entries are kept and omitted messages alone are marked failed. Partial previews remain unchanged.

- [x] **05 · P1 · Tallport — Use the AI error redactor at every output boundary.**

  Evidence: `app/Ai/Usage.php:104` already redacts provider secrets, but `AiDraftsController::store()` stores raw exception text and streams it to agents (`app/Http/Controllers/AiDraftsController.php:78`). `Translations::failed()`, the composer translation error, document errors, and calls to `Helper::logException()` take separate raw paths. A provider or custom endpoint can echo credentials or request content into an error.

  Centralize safe error rendering: localized user messages with a reference ID, and bounded, redacted diagnostic details for operators. Apply it to persistence, SSE, API responses, and logs. Acceptance: inject an exception containing the configured key and an Authorization header; neither appears in any response, saved error, or log. Preserve useful status and provider information.

  Completed: AI failures now share one reference across user-visible messages, saved error fields, and bounded redacted operator logs. Draft SSE, translation and document errors, the documentation API, customer-context results, and AI exception logs use it; failed customer-context tests retain the HTTP status without returning the endpoint's body. Expected token-limit messages remain actionable. Regression tests cover configured keys and Authorization headers in responses, saved errors, and logs.

- [x] **06 · P1 · Tallport — Include saved AI drafts in deletion and retention.**

  Evidence: `aiassistant_draft_jobs` stores full results and errors without a foreign-key cascade (`database/migrations/2026_10_04_010103_create_ai_draft_jobs_table.php:16`). `Drafts::draft()` includes the generated reply and retrieved document excerpts in that result. `Conversation::deleteConversationsForever()` and `Retention::cleanLogs()` do not remove these records; application references to `DraftJob` only create or count them.

  Delete associated draft results when a conversation is permanently deleted, and define a bounded lifetime for remaining draft payloads. Keep only the minimal counters needed for today's quota. Acceptance: permanently delete a conversation with a completed draft and verify the reply, excerpts, and error payloads are gone; normal retention must preserve the intended quota behavior.

  Completed: permanent conversation deletion removes older draft rows and clears today's rows of the conversation link, result, errors, and other draft metadata while preserving their quota count. An in-progress draft cannot write its result back after deletion, even when its quota row is inserted late. The daily retention run deletes rows from previous days, including undated rows, even when conversation retention is off. Regression tests cover deletion, quota counts, cleanup, and both timing windows.

- [ ] **07 · P1 · Tallport — Make sending a reply one atomic, repeatable operation.**

  Evidence: `ConversationsController::ajaxSendReply()` (`app/Http/Controllers/ConversationsController.php:1590`) checks draft state, writes conversations and threads, attaches files, and dispatches events through separate steps without a surrounding transaction or atomic draft claim. Two concurrent submissions can both pass the “already sent” check. Failure after an early write can leave partial state. `TeamChat::send()` similarly persists the message before all its attachments succeed.

  Establish a transaction around each database operation, atomically claim the draft/submission, and dispatch external effects after commit. Handle file failures explicitly because a database rollback cannot undo storage writes. Retain hook names and arguments. Acceptance: two concurrent sends produce one logical reply; failure during attachment persistence leaves a recoverable draft or a clearly reported partial failure, with no premature notification.

- [ ] **08 · P1 · Tallport — Merge AI metadata without overwriting concurrent changes.**

  Evidence: `Translations::save()` (`app/Ai/Translations.php:349`), `Summaries::summarize()` (`app/Ai/Summaries.php:69`), and `ChatTranslation::setCustomerLanguage()` (`app/Ai/ChatTranslation.php:70`) replace the entire JSON column from a model snapshot. Another language's translation or an agent's language selection can be lost when a slow AI request completes later. Per-language unique jobs do not protect a shared JSON document.

  Perform the external request first, then reread and merge the relevant field in a short locked transaction or use an equivalent atomic update supported by the application's databases. Recheck agent-selected language precedence at commit time. Acceptance: interleave two languages and a summary with a manual language change; all independent updates survive.

- [ ] **09 · P1 · Tallport — Reserve AI quotas atomically and account for all supported work.**

  Evidence: `AiDraftsController::store()` checks a count and then inserts a draft record separately. `Settings::withinBudget()` reads completed usage before allowing work. Concurrent web requests and jobs can all pass the same remaining allowance. `Documents::embed()` makes billable calls without recording their usage, although the settings describe a limit for all AI features. Failed attempts are currently recorded with zero tokens, even when some output was produced.

  Atomically reserve draft/customer allowances and budget capacity, then reconcile known usage. Define whether embeddings belong to that budget and make the implementation and wording agree. Preserve unknown usage as unknown when the provider cannot report it. Acceptance: concurrent callers cannot all consume the final allowance; rejection, failure, and recovery release or reconcile reservations predictably.

- [ ] **10 · P2 · Tallport — Bound the entire AI operation and recover interrupted work.**

  Evidence: each agent attempt may take 180 seconds (`TallportAgent::timeout()`), with retries for options and a backup model. Translation jobs allow 240 seconds; draft SSE calls request a 240-second PHP limit. Drafting can also fetch customer context and embed a query first. Catch blocks cannot reliably repair state when a worker/process is forcibly terminated, and the AI jobs have no `failed()` cleanup.

  Give the whole operation a deadline, pass its remaining time to attempts, and leave room to persist completion/failure. Classify transport/model failures separately from local callback/programming failures before retrying. Add terminal failure handling and reconciliation of abandoned `running` drafts. Acceptance: a slow primary still leaves a bounded opportunity for backup; forced termination eventually produces a visible, retryable outcome without an indefinite loading state.

- [ ] **11 · P2 · Tallport — Enforce external request boundaries during transfer.**

  Evidence: `Documents::fetch()` checks its 5 MB limit after `body()` has loaded the response. Customer context checks 128 KB after download, and its test path only truncates after receiving the body. `CustomerContext::post()` (`app/Ai/CustomerContext.php:137`) follows redirects, including 307/308 redirects that can forward the customer's JSON and signature to a different destination. The document fetcher checks resolved addresses separately from the actual connection.

  Enforce byte limits while receiving data, including chunked responses. Disable customer-context redirects by default or explicitly restrict destinations and credential forwarding. Make private integration destinations an explicit operator policy, preserving supported local integrations. For public-document fetching, bind address validation to the connection and repeat it at redirects. Acceptance: oversized streams stop early, cross-origin redirects do not receive customer data, and controlled private integrations still work as configured.

- [ ] **12 · P2 · Tallport — Queue document indexing and commit only the generation that was indexed.**

  Evidence: `AiDocumentsController::api()` calls `Documents::index()` synchronously (`app/Http/Controllers/AiDocumentsController.php:164`), despite an existing `AiIndexDocument` job. Indexing can perform multiple 120-second embedding requests. `Documents::index()` later replaces chunks and marks the document indexed without checking whether its content changed during those requests. Chunks identify their embedding space only by model name, so changing an endpoint/provider under the same model name can reuse incompatible vectors.

  Accept and queue work, expose its status, and compare the content generation before replacing chunks. Record an embedding configuration fingerprint including provider/endpoint, model, and relevant dimensional settings. Schedule a fresh generation if content changed while work was running. Acceptance: slow indexing does not occupy the ingestion request; an old result never marks newer content indexed; changing embedding configuration invalidates the affected index.

- [ ] **13 · P2 · Tallport — Enforce chunk limits and keep retrieval memory bounded.**

  Evidence: `Documents::chunks()` (`app/Ai/Documents.php:196`) prepends overlap to the next paragraph without checking the combined size. With size 500, overlap 100, and two 450-character paragraphs, the second chunk becomes 552 characters. `Documents::search()` fetches chunks in batches but accumulates every qualifying result before sorting, and eager-loads complete documents including their content.

  Enforce the size contract after adding overlap, select only document fields retrieval needs, and retain only the best requested results while scanning. Keep MariaDB support; a new vector service is not a prerequisite. Acceptance: boundary cases always fit the configured chunk size and preserve content, while retrieval memory stays bounded as the document collection grows. Benchmark before changing the search storage architecture.

- [ ] **14 · P2 · Tallport — Distinguish refused AI options from unrelated request errors.**

  Evidence: `TallportAgent::refusedOption()` (`app/Ai/Agents/TallportAgent.php:216`) treats any 400/422 response as an option refusal when only one option was sent. Context-length, payload-validation, and other errors can therefore disable a supported option. Rejection cache keys in `app/Ai/Providers.php:345` identify only the configured provider ID and model, so editing that provider's endpoint can inherit an obsolete rejection.

  Recognize supported provider error codes/parameters before caching a capability rejection; keep uncertain errors as ordinary failures. Include a configuration revision in the cache identity or clear rejection entries on provider edits. Acceptance: a context-length error does not disable fast mode; an actual refusal retries once without the refused option; endpoint changes do not retain stale capability decisions.

- [ ] **15 · P2 · Tallport — Extract shared conversation operations out of HTTP controllers.**

  Evidence: `ConversationComposer`, `NewConversation`, and `ConversationList` mutate the global request and invoke controller methods directly, for example `app/Livewire/ConversationComposer.php:535` and `app/Livewire/ConversationList.php:294`. This makes dependencies implicit and couples Livewire behavior to AJAX transport details. Bundled component requests also share mutable request state.

  Follow the existing `App\Misc\ConversationActions` precedent: extract sending, draft persistence, and list querying incrementally, with explicit input and actor arguments. Controllers and Livewire components should adapt their inputs to the same operation. Keep existing AJAX actions and module hooks. Acceptance: both entry points have the same validation, permissions, effects, and errors; one component's parameters cannot change another component's result.

- [ ] **16 · P2 · Tallport — Use state-changing HTTP methods for user commands.**

  Evidence: `routes/web.php` registers GET routes for undoing a reply, cloning a conversation, and disconnecting mailbox OAuth. Their implementations perform writes and use tokens in URLs. Existing token and authorization checks matter, but they do not make GET a safe read or keep tokens out of URL history/logs.

  Move mutations to CSRF-protected POST/DELETE actions. Preserve named integration entry points where needed by making GET display a confirmation or redirect without performing the mutation. Acceptance: merely visiting or prefetching a link cannot cancel a reply or disconnect a mailbox; unauthorized and invalid-CSRF mutations fail, and the current UI actions still work.

- [ ] **17 · P2 · Tallport with FruitUI — Make older team messages reachable.**

  Evidence: `TeamChat::render()` loads only the last 300 messages (`app/Livewire/TeamChat.php:27`), with no older-page action. Search filters only that rendered DOM. `TeamChatDetails` lists all pinned messages, while `resources/views/livewire/team-chat-details.blade.php:29` only scrolls if the target already exists. A sufficiently old pinned message therefore has a button that does nothing.

  Add bounded history loading and a server-authorized jump that loads the target message before focusing it. State the search scope accurately; provide an intentional strategy for older encrypted history instead of reporting a complete-looking “No Messages Found.” Preserve the reader's position through prepends using FruitUI's history behavior. Acceptance: with more than 300 messages, an old pin opens, older messages can be read, and loading history does not jump a reader to the bottom.

- [ ] **18 · P2 · Tallport — Restore zoom and name attachment previews.**

  Evidence: `resources/views/layouts/app.blade.php:6` sets `maximum-scale=1`. `public/js/attachments.js` creates PDF/text/email iframes without a title and gives preview images empty alternative text. These are application integration issues; FruitUI already supplies the surrounding dialog semantics.

  Remove the zoom restriction and give each preview an accessible name derived from its filename/type. Check the actual workspace, composer, dialogs, and inspector at increased text/zoom sizes, in both system appearances, with keyboard navigation. Acceptance: zoom remains available, controls remain reachable, and assistive technology can identify the embedded document. [W3C viewport guidance](https://www.w3.org/WAI/standards-guidelines/act/rules/b4f0c3/).

- [ ] **19 · P2 · FruitUI — Handle expired sessions and unexpected dialog responses.**

  Evidence: `../fruitui/src/js/remote-dialog.js`, `dialog()`/`load()`, accepts any successful response as HTML, including a followed login redirect or an unexpected JSON response. Tallport callers depend on `dialog.loaded.then(...)` to initialize controls, so an unrelated document can be treated as successfully loaded dialog content. The existing network-error Retry and close handling should be retained.

  Validate the response type and provide a host hook for authentication redirects and other unexpected destinations. Let Tallport navigate to login explicitly when its session expires. Implement the reusable contract in FruitUI, then update Tallport's integration and published build. Acceptance: offline/retry/close behavior continues to work, JSON is not inserted as HTML, and an expired session never silently embeds the full login page as dialog content. This finding is from source inspection; browser behavior still needs verification.

- [ ] **20 · P2 · Tallport — Add a focused static correctness check alongside PHPCS.**

  Evidence: `./test.sh` runs style, PHPUnit, and inventory checks, but no static type/data-flow analysis. Item 03 is an example of an undefined variable that survived that combination. Existing AI tests cover many transport cases but omit the failing sanitizer branch and completed-result shapes described above.

  Introduce PHP-native analysis in the separate development tool project, subject to dependency approval, starting with AI and newly extracted operations. Baseline legacy compatibility code narrowly; do not add types to overridden framework/module methods to satisfy a tool. Add behavior tests for the failures in this review using existing builders and PHPUnit conventions. Acceptance: an undefined closure variable fails checks before release, and the new regressions fail against the old behavior. Inventory coverage remains an entry-point check, not a substitute for failure-path assertions.

- [ ] **21 · P2 · Tallport — Bring release-only guarantees into the local release check.**

  Evidence: `release.sh` runs `./test.sh` and publishes before CI finishes. The vendor reconstruction check exists only in `.github/workflows/test.yml`. The local test run can skip environment-dependent tests, and it does not perform dependency auditing. The review's `composer audit --locked --format=json` reported no advisories, but returned exit code 2 for abandoned `doctrine/cache`, required through Doctrine DBAL as well as a direct development constraint.

  Preserve the chosen local release workflow, but run its critical guarantees before publication: reconstruct dependencies in an isolated checkout, verify committed vendor and published assets, require the production database checks in release mode, and audit dependencies. Resolve or explicitly time-bound the abandoned dependency's replacement path with module/schema compatibility checks. Acceptance: stale vendor/assets or unavailable required services block a normal release before upload, while an emergency bypass remains explicit and auditable.

- [ ] **22 · P2 · Tallport — Reconcile contributor guidance with the executable project.**

  Evidence: `AGENTS.md` says the default test connection is MariaDB, while `test.sh`, `phpunit.xml`, and `Tests\TestCase` use in-memory SQLite by default with separate database coverage. `tests/inventory-exclusions.php` describes excluded Livewire routes as unused although `TeamChat` uses file uploads. `KNOWN_BUGS.md` still describes PHP 8.3/libxml2 production conditions despite the PHP 8.5 project requirement.

  Correct the source guidelines and generated guidance together, explain the specific excluded upload-route boundary, and revalidate the environment notes before retaining them as current facts. Keep C1/C6/M8 as deliberate decisions. Acceptance: a contributor can follow the documented commands and understand exactly which databases, frontend interactions, and known limitations the checks cover.

Review validation: `./test.sh` exited successfully: **2,076 tests, 42,747 assertions, two skipped tests**; PHPCS passed; inventory reported **323 items, 306 exercised, 17 excluded, zero missing**. `composer audit --locked --format=json` found no security advisories and flagged the abandoned dependency noted above. `diff -qr vendor/fruitui/fruitui/build public/vendor/fruitui` found no differences. FruitUI's source checkout matched the installed revision. No application changes, live provider calls, browser checks, or full FruitUI test run were performed for this review.
