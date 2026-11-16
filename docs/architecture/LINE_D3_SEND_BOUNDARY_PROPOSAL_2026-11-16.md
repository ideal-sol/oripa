# D3 delivery foundation and approved send boundary

Change LINE-DELIVERY-20261109-D3; Issue none; R3; Strict Change;
Application Runtime Activation deferred. Human authorization ends at Draft PR.
Base: `2810ad2fd5a59b6e8160a4b3a910d21e635b6816` (D2 PR690 merged).

## Authority

The Human decision in this conversation on 2026-11-16 approves a D3-only
exception to specification1.4 section7.3: bounded delivery HTTP runs while the
D2 singleton/member/identity row locks remain held. This supersedes the earlier
pending-boundary proposal. No additional approval of this design is required.
It does not authorize Ready, merge, operational migration, ENV changes,
runtime activation, workers or real LINE transmission.

## Durable request and serialization

```text
outside transaction: fresh quota before the first broadcast attempt
A (one DB attempt): singleton -> batch -> D2 eligibility -> skip ineligible
  -> deduplicate recipients -> expand messages once -> encrypted envelope
  -> UUIDv4 retry key + conservative first-attempt time + lease -> COMMIT
outside transaction: warm encrypted token cache (no recipient transmission)
B (one DB attempt): singleton -> batch/lease -> BOT authentication revision
  -> D2 final eligibility + member/identity locks -> authenticate frozen envelope
  -> exact JSON HTTP / at most one 401 renewal -> record outcome -> COMMIT
outside transaction: update refreshed token cache; classify 429 by fresh quota
```

The public internal dispatch entry rejects an enclosing transaction: phase A
cannot silently be a savepoint. The retry key is created with the batch and
cannot change. Phase A's timestamp intentionally precedes the actual request;
after a crash this shortens the available retry window, never extends it.
Clock comparisons use PostgreSQL clock_timestamp, independent of worker clocks.
Neither phase retries its DB transaction. If B's commit fails after acceptance,
A's original encrypted envelope and key survive. Reclaim requires lease expiry
and reuses that exact envelope. The envelope authenticates BOT, authentication
revision, method, key and serialized JSON. No recipient or body is rebuilt.

Expired requests become unknown after23 hours, with no automatic replacement
key. A changed BOT/revision, damaged envelope, or changed attempted recipient
holds the entire attempted batch as unknown; no subset is resent under the old
key. Unattempted ineligible recipients may be skipped before freezing. Manual
resend requires fresh Owner authorization and the explicit Japanese duplicate
delivery warning; it creates a new batch/UUID with a link to the immutable old
terminal record and rechecks eligibility. Old history is retained.

The settings singleton serializes D1 BOT operations, webhook and identity
coordination with delivery. Member and identity locks additionally fence member
state and revocation. Send lock acquisition has a2-second timeout, individual
DB statements5 seconds, final validation a5-second elapsed budget, and delivery
HTTP a10-second aggregate budget including at most one3-second token renewal.
PostgreSQL17 transaction_timeout is locally20 seconds for each send phase, so
a stopped/paused client cannot retain the singleton indefinitely. This is a
transaction-local source setting, not a server/ENV change. A synthetic paused
client test proves server termination, lock release and original-key recovery.
Redis cache access and quota requests do not run inside the send transaction.
Pacing is persisted scheduling, never sleep under a transaction.
The D2 single-reference predicate delegates to its bounded batch equivalent:
one canonical classifier, ordered member/identity locks and the same ciphertext
binding checks. This avoids thousands of queries under the singleton for500
recipients without weakening the5-second validation budget.
HTTP timeouts cannot undo an accepted request; retry identity is the recovery
authority.200/409 indicate API acceptance, never recipient delivery.

## Persistence and interfaces

Migration000111 adds line_deliveries, line_delivery_batches,
line_delivery_recipients and line_delivery_settings. CHECK/FK/unique constraints
and immutable-history triggers guard states, retry identity, frozen payload,
recipient bindings and terminal history. No existing000107–000110 migration is
edited. Apply111 before any separately authorized activation; retain schema
and history for rollback, with a reviewed forward correction instead of down.

V2LineDeliveryService owns internal draft/schedule/cancel, pacing defaults,
preview estimate, resolution and explicit manual resend. Admin mutations use
the existing Owner line.bot.manage permission plus fresh MFA; no new HTTP
contract is exposed. Draft and scheduled content remain editable using revision
checks; defaults are copied, not live-linked. D2 resolves all three recipient
groups with identical transactional/promotional eligibility. No preferences,
nonproduction allowlist, invented approval flow or daily cap is added.

V2LineDeliveryMessages validates up to5 ordered text/image/video/imagemap/
image-carousel messages. Tags are restricted to text/altText, and expanded once
with username/rank/points fallbacks; UTF-16-safe truncation preserves codepoints.
Four imagemap layouts produce LINE areas, and HTTPS destinations are preserved
without a domain allowlist or query rewriting. Media dispatch requires the D5
server-side immutable-asset verifier. The default verifier rejects media;
caller flags or unvalidated URLs cannot authorize delivery. D3 does not upload,
fetch, transcode or validate real media files.

Multicast is limited to500 references for identical nonpersonalized messages;
personalized broadcast and trusted automated/chat calls use individual Push.
The source UUID deduplicates D4/D6 internal enqueue calls. These entry points
are trusted domain integration, not public permission to send.

## Quota and pacing

Estimate and resolve use fresh provider quota, and the first broadcast dispatch
checks again. Quota compares recipients, not HTTP requests. Reservations are
serialized under the singleton. Active deliveries and potentially consumed
current-month local attempts are counted conservatively even if the provider
may already include them, protecting against consumption-report lag. This can
block earlier than the actual monthly limit; it must never be represented as
an exact provider remaining count. Insufficient admission sends no broadcast
batch. Provider/account-manager consumption outside this application cannot be
atomically reserved, so an unexpected refusal after some acceptance is recorded
honestly rather than claimed as an atomic provider-wide delivery.

429 classification ignores provider prose. Fresh remaining quota below the
batch's recipient count produces failed/quota_exceeded. Otherwise retrying uses
the same key and finite23-hour backoff window (30s,120s,600s,1800s,then3600s).
Failed quota lookup remains conservatively uncertain; no new key is minted.
400/other definitive4xx fail,401 refreshes once,5xx/timeouts retry unchanged.

Per-delivery pacing uses push_every10/push_wait_seconds5/multicast_wait_seconds0
defaults, validated ranges and revision/actor/audit. Pending overdue work is
rebased from resume time in original order; attempted retry backoff is excluded.
Only broadcasts receive pacing. The two Artisan commands are explicit one-pass
entry points; Compose line-delivery profile is off by default and has only the
existing private/egress networks. No scheduler or existing worker was changed.

## Verification and handoff

Final-head test totals, Required5 and Fresh Strict evidence belong to the PR.
Local coverage includes durable visibility from a second connection, timeout
and409 byte identity,401 bounds, definitive errors, quota admission and500/501
batching, lease ownership, actual BOT/webhook/link/member-writer contention,
commit failure before and after external acceptance, expiry, media and tags,
fresh MFA/manual resend and migration upgrade/history preservation.
Synthetic schema fixtures isolate commit tests from all existing test data.

- D4: use enqueuePush with durable event UUID, resolve current D2 reference and
  choose approved purpose; rank promotion/outbox/templates remain D4. Shipping
  tags require an explicit D4 event-scoped extension, currently rejected.
- D5: wire fresh-MFA internal services, media verifier and immutable storage,
  detailed filters and UI; surface possible duplicate delivery before resend.
- D6: use individual Push, no broadcast pacing; media3-second processing and
  unsend/retention official-guidance decision remain separate and unresolved.
- D7: use D2 recovery registry and captured same-BOT generation/timestamp. Stale
  observations are not delivery proof and never grant rewards by themselves.

Browser/login/Admin MFA/real LINE: NOT RUN — Human Browser Acceptance Pending.
Ready/Merge/runtime/operational migrations/ENV/worker startup/real sends:0.
The dedicated task branch/worktree are retained for Draft review; CONTACT and
other worktrees are untouched. Allocation111 remains unavailable for reuse;
physical lock releases only after recorded Draft handoff.

Official references checked2026-11-16:
[retry requests](https://developers.line.biz/en/docs/messaging-api/retrying-api-request/),
[Messaging API](https://developers.line.biz/en/reference/messaging-api/index.html.md).
They confirm original-key/content retries,24-hour validity,200/409 acceptance,
Push5 messages,Multicast500 users/5 messages, quota endpoints, multiple429
causes, UTF-16 text counting and1500-character altText. No conflicting provider
decision was inferred.

[PostgreSQL17 transaction timeout](https://www.postgresql.org/docs/17/runtime-config-client.html#GUC-TRANSACTION-TIMEOUT)
defines the server-enforced transaction duration, including an idle client.
