# Matrix channel

Implement a small Matrix client inside Tallport. Each mailbox connects to its
own existing Matrix account. Customer messages become normal Tallport
conversations; agents reply from the existing conversation screen.

We own the Matrix HTTP, event handling and encryption implementation. No Matrix
SDK, Olm/Megolm library, bridge, sidecar or homeserver. Use PHP's Sodium, OpenSSL
and hash functions for cryptographic primitives; do not implement those
primitives ourselves. The difficult part is implementing the standard Olm and
Megolm protocols correctly. Prove that part works before building the channel.

## Scope

- One account and one persistent Tallport device per mailbox. Different
  mailboxes have independent credentials, keys, sessions and sync positions.
- Customer-initiated, private, one-to-one rooms. Accept direct-message invites;
  check membership and room privacy after joining. The `is_direct` flag is only
  a hint.
  Import messages only when the mailbox account and that customer are the only
  joined/invited users. Ignore pre-existing unsupported rooms; leave a newly
  accepted invite if its room turns out to be public or a group.
- Receive text, notices, emotes and files; send plain text and files. Images,
  audio and video use ordinary attachments. Use the plain-text message body,
  escaping it for Tallport; no Matrix HTML renderer or remote thumbnails.
- Support ordinary and encrypted rooms. Once encryption is enabled, never send
  plaintext into that room, even when keys are missing. Unexpected plaintext in
  an encrypted room is reported, not treated as an authenticated customer reply.
- Normal assignment, notes, notifications, search, closing and reopening.
  Apply the mailbox's shared Chat settings to the room's current conversation.
  Start a new conversation when that policy requires it, in the same room.

No public/group rooms, room directory, outgoing conversation creation, calls,
spaces, reactions, typing indicators, read receipts, message editing/deletion,
profile management or Matrix history import. Unsupported customer message types
get a visible indication; control events do not become customer messages.
Agents' replies send immediately, with no Undo, following Nostr's existing UI.

Encryption ends at Tallport: decrypted messages and files enter its usual
storage, search and access-control system. This is not encryption between the
customer and each agent's browser.

## Connecting a mailbox

Add **Mailbox Settings > Matrix**, using the existing FruitUI Blade/form pattern
and mailbox settings authorization.

1. Enter the HTTPS homeserver URL, full Matrix ID and password. Supplying the
   server explicitly avoids automatic discovery in this first version.
2. Check `/versions` and the advertised `/login` flows. Support
   `m.login.password`; keep the returned user ID, device ID and access token.
   Discard the password immediately, including on validation failures: never
   flash it back, log it or put it in a queued job.
3. Initialize and upload the device's encryption keys. If refresh tokens are
   issued, store and rotate them with the access token. Authentication failure
   pauses the channel and asks the administrator to reconnect.
4. Verify the Tallport device from an existing trusted client for this account
   before marking encrypted messaging ready; see below. Recommend a dedicated
   support account, initialized in Element before connecting it here.
5. Show the Matrix ID, device fingerprint, connection/verification status,
   last successful sync and actionable errors. Offer enable/disable, reconnect
   and disconnect. Disabling preserves the device and keys; disconnecting
   revokes this device's session, not every session on the account.

Password login is an explicit first-version boundary. The
[Matrix.org login endpoint](https://matrix-client.matrix.org/_matrix/client/v3/login)
advertised it when this plan was written, but SSO-only/OAuth-only accounts need
a later login flow. Setup must check the actual server's capabilities; do not
claim support for every homeserver. Do not offer copying another client's
access token: its device ID and private keys belong together.

Reconnect to the same account/device only while its original keys survive and
the server accepts that device. If device state was lost or revoked, create and
verify a new device; never put fresh keys under the old device ID. Prevent the
same Matrix account being connected to two mailboxes. An explicit account
replacement starts a new local identity; existing conversations remain bound
to the original identity and cannot accidentally reply as the replacement.
Replacing only the device preserves the account's room/conversation mappings.

## Small implementation and data model

Follow the built-in code in `app/Nostr` and `app/Telegram`. Keep Matrix code in
`app/Matrix`, with a thin HTTP client, sync handler, incoming/outgoing message
handlers and separate crypto classes. Use the common chat operations already
extracted from Telegram and Nostr. Keep these limited to code the channels
actually share.

### Shared chat operations

Telegram and Nostr already use `Conversation::create()`,
`Thread::createExtended()`, customer channel helpers and the existing reply
interface. These provide the common model lifecycle, attachments and events.
However, the channel handlers still repeat conversation queries, incoming
message orchestration and delivery-status updates.

Two shared classes now sit beside `app/Misc/ConversationReplies.php` and are
used by Telegram and Nostr:

| Class | Responsibility |
| --- | --- |
| `App\Misc\ChatConversations` | Common conversation lookup, creating or appending an incoming customer message, and reopening through the existing model methods. Takes a resolved customer, mailbox/channel, prepared message and explicit conversation-selection context; returns the conversation/thread. |
| `App\Misc\ChatDelivery` | Loading a sendable reply and recording accepted, retrying or failed delivery in Tallport, including the common error/status data and reopening behavior. Channel jobs supply the delivery result. |

The flow is: **channel validates/decrypts/parses → shared chat operation →
existing Tallport models**. Channel handlers should not each recreate the
queries and writes for common conversation/thread operations. Keep using the
existing customer lookup/creation helpers; channel-specific identity resolution
stays with the channel (Telegram account/username, Nostr keys, Matrix ID).

Chat behavior is a shared mailbox policy, not a channel choice. Mailbox
settings have a **Chat** section containing the common settings and links to
Telegram, Nostr and, when implemented, Matrix connection settings:

- Use the latest conversation by creation date, then ID to break ties.
- The existing option to start a new conversation for closed/deleted chats
  applies to every channel. Otherwise a customer reply reopens/restores it.
- The shared activity window defaults to 30 days and applies even to open
  conversations. Existing Nostr values migrate to the mailbox's setting.
- Delivery failures reopen published conversations unless they are spam.
  Reopening updates counters without adding an agent status-change event.

`ChatConversations::latest()` scopes lookup to mailbox, customer and channel
and applies `canContinue()`, the shared policy. Matrix resolves its stored room
mapping and applies that same `canContinue()` check. `receive()` accepts the
selected conversation, or null to start one. Keep formatting, source metadata,
auto replies, hooks and notifications with the existing channel integration.
`ChatDelivery::findReply()`, `recordStatus()` and `reopenConversation()` provide
the same delivery-state operations for all chat channels; email also uses the
guarded reopening operation through its existing compatibility entry point.

Protocol HTTP, formatting, encryption, downloaded-file preparation, event
deduplication, retry timing, partial-send tracking and Undo support stay with
their channels. They still persist their own protocol records. Matrix's event
claim, shared conversation write and crypto/cursor updates must participate in
the same local transaction, with notifications after commit; the shared classes
must not commit independently or perform network calls.

Telegram and Nostr use these shared operations before Matrix is wired into
them. Existing channel tests and focused shared-operation tests cover the
extraction and common policies. Keep only differences required by channel
capabilities. No base-channel inheritance tree, generic
repository layer, new chat tables or extensible provider framework.

### Matrix-specific storage

| Storage | Purpose |
| --- | --- |
| `matrix_mailboxes` | Mailbox/account identity, active flag, server, device, encrypted tokens, sync cursor, initial sync boundary and status. Retain an inactive identity when replacing an account. |
| `matrix_rooms` | Identity + room ID, exact customer Matrix ID, customer/current conversation IDs, membership/encryption state and any pending timeline gap. |
| `matrix_crypto` | Identity-scoped, versioned encrypted records for device keys, known remote devices/identity trust, Olm sessions, Megolm sessions and temporary verification state. Use explicit record types and lookup keys; no general-purpose storage framework. |
| `matrix_events` | Incoming event deduplication, pending undecryptable messages, replay records and durable outgoing room/to-device requests, including transaction ID, exact payload, result, thread and attachment part. |

Use database uniqueness for room mappings, incoming event IDs and outgoing
thread parts, always scoped to the local Matrix identity. Also persist Megolm
session/message-index bindings to their original event ID so a second event
cannot replay the same ciphertext. Keep protocol identifiers case-sensitive
on MariaDB as well as SQLite/PostgreSQL.

Use existing customer channel lookup with the full `@user:server` ID. Never
identify a customer by display name or an unverified email address. Preserve
room/customer associations through customer merges, including rooms belonging
to different Matrix IDs. Reply routing always comes from the conversation's
stored room and original mailbox identity. Block cross-mailbox moves or merges
that would break that binding; never select a destination by customer alone.
Store that binding in each conversation's metadata, so starting a new
conversation for a room does not break replies from an older conversation.

Encrypt tokens, private keys, session records and queued sensitive payloads
with Tallport's existing encryption facilities. Hide them from serialization
and logs. Retain only the pending payloads and protocol records needed for
recovery/deduplication, not a second permanent copy of every message body.
Apply the existing conversation/mailbox deletion lifecycle to these records.
Keep minimal deduplication tombstones while an identity is active so replayed
events cannot recreate deleted conversations.

## Encryption: the necessary subset

Implement the standard wire formats and state machines, with small classes
under `app/Matrix/Crypto`. Sodium supplies Curve25519/Ed25519 operations;
OpenSSL supplies AES; PHP supplies HKDF/HMAC, secure randomness and constant-time
comparison. Check the required extensions before enabling Matrix. No fallback
to home-written curve arithmetic.

- **Encoding and keys:** strict binary parsing, unpadded base64, canonical JSON
  signing and signature validation. Keep signing keys separate from agreement
  keys. [Matrix signing formats](https://spec.matrix.org/latest/appendices/#signing-json)
- **Olm:** account identity keys, signed one-time/fallback keys, inbound and
  outbound session establishment, pre-key messages and the double ratchet.
  Handle out-of-order messages with bounded skipped-key storage; reject invalid
  keys, MACs, counters and oversized input before committing new state.
  [Olm specification](https://spec.matrix.org/latest/olm-megolm/olm/)
- **Megolm:** inbound/outbound sessions, signed session-key import, message
  encryption/decryption, authentication and replay detection. Preserve the
  earliest usable inbound state. Rotate outgoing sessions on membership/device
  removal and at the room's message/time limits; use 100 messages or seven days
  when no limit is supplied. Rotate before sharing with a newly accepted device
  so it does not automatically receive earlier conversation keys.
  [Megolm specification](https://spec.matrix.org/latest/olm-megolm/megolm/)
- **Matrix integration:** upload/replenish keys, query device lists, claim keys,
  exchange room keys through encrypted to-device messages and refresh changed
  device lists. Validate the sender, recipient, device keys and room binding of
  decrypted payloads. Support several devices for the customer even though
  Tallport itself has only one device per mailbox.
  [Encryption implementation guide](https://matrix.org/docs/matrix-concepts/end-to-end-encryption/)
- **Attachments:** implement the Matrix encrypted-file format, including
  AES-256-CTR and the ciphertext hash. Upload ciphertext; put its key/IV/hash
  inside the encrypted room event. Verify before exposing a downloaded file.
  Download `mxc://` media through the configured homeserver's authenticated media
  API, with existing file-size/type limits; never fetch an arbitrary URL from a
  message or substitute a public Tallport attachment link.
  [Encrypted attachments](https://spec.matrix.org/latest/client-server-api/#sending-encrypted-attachments)

### Verification and missing keys

Include one verification method: `m.sas.v1`, using the decimal comparison and
current MAC algorithm. Limit the UI to verifying Tallport with another device
on the same account. Check commitments, exact keys, transaction/device binding,
MACs, cancellation and expiry; only complete after explicit user comparison.
The existing trusted client can cross-sign the Tallport device. Re-query and
check that signature chain before showing it as ready. Keep verification
responsive by requesting short syncs while this settings flow is open.

Read and validate public cross-signing chains. Pin a customer's initially seen
master identity as trust-on-first-use, without labelling the customer as manually
verified. Accept its correctly cross-signed devices; pause on identity changes,
reused device IDs with changed keys, or unsigned devices, with a clear reason.
Allow an administrator to acknowledge an identity change after checking the
displayed fingerprints; discard the old outgoing sessions before resuming.
Do not silently trust replacements or bypass a peer's refusal to share keys.
This deliberately excludes accounts/devices without the required trust setup.
[Device verification and cross-signing](https://spec.matrix.org/latest/client-server-api/#device-verification)

Do not implement cross-signing identity creation, secret storage, QR verification
or server-side key backup. The existing client manages the account's identity.
For a missing room key, retain the encrypted event, show a pending/unavailable
message and retry when its key arrives. Support bounded key requests to the
original sender and safe re-sharing of our own outbound keys only to devices
already entitled to them. Do not automatically forward imported keys or grant
new devices old history. Missing keys must not stop other messages syncing.

Back up the database and Tallport encryption key together. A password reset
does not recover lost Matrix keys. After restoring an older database snapshot,
create a fresh Tallport device for sending and keep restored inbound keys for
reading; do not resume rolled-back sending ratchets. Recovery from Matrix key
backups and importing pre-connection history are outside this version.

## Receiving and replying reliably

Use Tallport's scheduler and existing queue workers. Schedule one bounded sync
job per enabled mailbox each minute, using classic `/sync` with an immediate
response. Incoming delivery can therefore take about a minute plus queue time.
Replies dispatch immediately. No new daemon or service is needed.

Sync, sending, verification and account changes share a lock per Matrix identity.
Use a lock store shared by all workers, with the job timeout shorter than the
lock lifetime and queue reservation. Database transactions and unique indexes
provide durable correctness as well as that worker lock.

**Receive:** establish the initial sync boundary without importing the account's
old timeline. Persist room state and to-device keys from that first response;
accept customer traffic after the boundary. Process later device/membership
changes and key messages before dependent room messages. For limited timelines,
page `/messages` back to the last recorded boundary; save pagination progress
and expose unresolved gaps rather than silently dropping customer messages.

Commit received events, crypto changes, pending work and the new sync cursor
together. Do not acknowledge a batch by using its next cursor until its work
is durable. Create the Tallport thread and mark its event handled in one
transaction; dispatch notifications after commit. Store undecryptable events
for retry and update their existing placeholder when resolved. Repeated syncs,
restarts and replayed events must not create duplicate threads or notifications.

**Send:** validate the current room membership, account binding, encryption and
device eligibility. Refuse to send if the room gained another participant or
its state cannot be established. Persist the advanced crypto state, exact
ciphertext and stable Matrix transaction ID in one transaction *before* HTTP.
Use the same transaction ID and payload on every retry, including room-key
delivery. Reconcile our own sync echoes with that outgoing record. Messages
sent manually from another device on the mailbox account are not customer
messages and must not trigger replies; record an external mailbox reply without
attributing it to a Tallport agent.

Track text and each file separately so a partial failure only retries unsent
parts. Reuse a successful media upload; an ambiguous upload may leave an unused
blob, but must not duplicate a customer-visible message. Back off on network
errors, 429s and temporary server failures. Permanent failures use Tallport's
existing not-sent status, Retry action and conversation reopening. "Sent" means
accepted by the homeserver, not read by the customer. After a device/session
replacement, reconcile ambiguous sends before offering a deliberate resend;
do not assume transaction deduplication survives that replacement.

The API surface is limited to connection/session management, sync, room
join/leave/state/history, sending events, device/key exchange and media.
Follow the server's advertised versions and stable endpoints, with bounded
timeouts and response sizes. Reuse Tallport's safe-URL checks; validate server
addresses and redirects, and never forward credentials to a different host.
[Client–Server API](https://spec.matrix.org/latest/client-server-api/)

## Tallport integration

- Register the channel/name/formats in `AppServiceProvider`, following the
  existing Eventy hooks. Use `ChatConversations` and `ChatDelivery`, shared with
  Telegram and Nostr, for Tallport conversation/thread operations.
- Add `MatrixController`, mailbox settings views/routes, `SyncMatrixMailbox`
  and `SendReplyToMatrix`. Route replies in `SendReplyToCustomer`; skip email
  auto replies for this channel. No Matrix-specific auto reply in this version.
- Add the settings navigation, channel identity/icon, send/retry status, no-Undo
  behavior and a small last-sync/error indication in System Status. Reuse the
  existing conversation interface and attachments. Link Matrix's connection
  settings from the mailbox's Chat section; do not duplicate chat policies.
- Add translations in every supported locale, schema migrations compatible
  with all supported databases, and route/job inventory coverage. Rebuild the
  committed autoloader after adding classes; do not edit vendor files.

## Implementation order and acceptance

1. **Extract the common chat operations (implemented).** `ChatConversations`
   and `ChatDelivery` are used by Telegram and Nostr, with consistent mailbox
   policies and a shared Chat settings section. Keep protocol-specific
   formatting, attachment handling, retries and module hooks in the channels.
2. **Prove encryption interoperability before building the channel.** Implement
   the narrow PHP Olm/Megolm core and persistence format. Test with independent reference
   vectors/transcripts, not only our own encrypt/decrypt round trips. Exchange
   encrypted messages in both directions with Element, verify the Tallport
   device and survive a process restart. A reference client is a test peer,
   never a shipped dependency. Resolve incompatibilities here before UI work.
3. **Connect and sync.** Add identity storage, password login, verification,
   invitations, sync cursor/gap handling and mailbox status. Test two mailboxes
   independently, pause/reconnect and device replacement.
4. **Deliver support conversations.** Add customer/room mapping, incoming
   threads, outgoing replies, encrypted files, failure/retry UI and the small
   integration points above, using the same shared chat operations. Encryption
   is required for completion; a plaintext-only milestone is not a shippable
   substitute.
5. **Validate the failure cases.** Cover tampered ciphertext/signatures,
   replayed indexes, wrong sender/room, key arrival after a message, exhausted
   one-time keys, new/removed devices, membership changes, identity changes,
   lost HTTP responses, concurrent jobs and crashes around each durable commit.
   Cover mailbox authorization/isolation, customer merges, attachment integrity,
   upload limits and credentials absent from logs/forms/job payloads.
6. **Finish through the normal checks.** PHPUnit feature tests extend
   `Tests\FeatureTestCase` and use the existing model builders. Fake the HTTP
   boundary; use real crypto and database state. Run focused tests, MariaDB
   checks for constraints/locking, then full `./test.sh` for style, static
   analysis, tests and inventory. Record manual Element/homeserver versions
   and results, including password login, verification, multiple customer
   devices, encrypted files and offline catch-up. Have the custom crypto and
   key-sharing paths reviewed before enabling the channel for production.

Done means two mailboxes can independently receive and answer customer DMs,
including encrypted text and files, with durable keys, no duplicate delivery
after retries, and visible failures when communication cannot be completed.
