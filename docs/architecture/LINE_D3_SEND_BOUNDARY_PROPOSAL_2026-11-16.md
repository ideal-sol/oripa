# D3 delivery foundation and approved send boundary

> Authorization update (2026-11-18): [latest Human decision](LINE_D1_D7_AUTHORIZATION_OVERRIDE_2026-11-18.md) supersedes all LINE-specific Fresh MFA/TOTP/WebAuthn requirements below. Existing roles, login/session, CSRF and D7 Human/provider attestations remain required.

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

The latest Human decision adopts exclusive Oripoke management of paid
Push/Multicast. OA Manager remains for settings such as rich menus; paid direct,
scheduled/step sends and independent API senders must be disabled operationally.
The prior overlap-based quota design and its review conclusion are superseded.
Additive000112 introduces BOT accounts, immutable JST monthly opening balances
and unique (BOT, month, batch) charges.000107–000111 remain byte-identical.
The existing immutable UUIDv4 Retry Key identifies each batch.

Admission is `effective_limit - opening_used - sum(charges) - uncarried_unknown`.
Charges are disjoint reserved/unknown/accepted states, counted once per key per
possible billing month. Provider totalUsage is never added to local use or
credited against local history. Opening0/cap200/local accepted100 leaves100
whether provider usage is0 or100. Exact opening100 with no managed sends also
leaves100. Accepted counts are recipient upper bounds, not delivered counts.

Broadcast, automated, chat and explicit manual resend all reserve through
insertBatch/cover under the existing D2 singleton. Resolve admits the entire
campaign transactionally. Before its first HTTP, prepare covers all outstanding
batches through the last planned dispatch plus23h and30s boundary margin in
every possible month. Missing baseline or insufficient capacity stops the
campaign before any send (C1). Plans beyond the two certifiable months wait.
Later unexpected suspension or plan changes may stop an already-started
campaign; this does not promise atomic provider delivery. D4/D6 must retain
their durable source events on quota refusal and use enqueuePush exclusively.

Phase A commits unknown exposure together with the frozen request. Phase B
checks committed coverage immediately before HTTP and records acceptance in
the result transaction. Rollback/crash leaves unknown; a same-key retry never
adds a second same-month charge. Unknown includes conservative cases such as a
crash before HTTP. No timer,400/401/429, retry expiry or month reset releases it.
Skipped unsent reservations also remain held in their reserved months in this
minimal version. Reclamation needs a separately reviewed evidence-based change.

Near-boundary requests reserve both months. Unknowns carry into every later
month, even after23h and even if that month's baseline preceded the uncertainty.
Missing next-month coverage blocks the boundary send. BOTs never share accounts
or balances; authentication-generation mismatch fails closed too.

Provider reads remain outside send transactions. A lower provider limit only
lowers the effective ceiling; increases never automatically grant capacity.
totalUsage above opening plus exposed charges halts the BOT. This detector
cannot prove exclusivity or detect external sends hidden within conservative
exposure: Human reporting and exclusive sender ownership are essential.

### Human baseline and incident procedure

These steps are required before later authorized activation. This PR establishes
no real BOT baseline and introduces no HTTP/CLI activation entry.

1. Keep paid sends disabled. Inventory the exact channel/BOT generation, all
   previous API clients, credentials, queued requests/retries and OA Manager
   campaigns/schedules/step messages. Establish exclusive Oripoke paid sending.
2. Close the accounting period used for the opening balance: stop all paid
   sends through the observation cut and prove no previous external request
   can still be accepted. A23h/24h wait, two equal approximate reads, or a
   screenshot alone does not prove this. Unresolved external outcomes require
   authoritative provider resolution; keep HOLD. A verified never-used BOT
   without outstanding requests can establish exact zero.
3. Human obtains the correct monthly paid usage from OA Manager's authoritative
   usage view, corroborates finalized per-day paid-source statistics through
   the closed cut, and verifies no later activity or unresolved earlier work.
   `unready`, missing source coverage, disagreement, stale data or unknown billing
   categories means no baseline. Never mechanically sum message-object counts
   or assume an aggregate identifies a request. A used BOT may start nonzero
   only with this evidence; otherwise use a proven clean boundary/unused BOT.
4. Privately record channel/BOT/generation, JST month, exact pre-managed usage,
   cut and evidence timestamps, paid-source coverage, absence of external
   in-flight work, verified monthly limit and Human actor. Store only the
   evidence UUID in application audit/period rows; no credentials/PII in GitHub.
5. A fresh-MFA Owner invokes confirmBaseline with the explicit Japanese
   attestation. D5 must expose this management flow; no direct SQL initialization.
   Missing periods fail closed. Existing attempts lacking ledger coverage are
   rejected for separate audited reconciliation. Baselines cannot be overwritten.
   Each next month requires Human verification of zero external opening use,
   continuing exclusivity and the approved limit; local unknown carry is separate.
   Only current and immediately following month may be certified; a future
   baseline must be zero. It is a commitment to exclusive operation, not an API
   forecast. The upper limit remains conservative if no provider cap is set.
6. Known/suspected external paid sends or ownership breach: Human immediately
   calls halt with evidence UUID, stops the independent source, retains all
   history and reconciles authoritative usage and outstanding requests. halt
   shares the singleton: it waits for the bounded current request and fences
   subsequent ones. An accepted HTTP call cannot be retracted. Never subtract
   an estimated external amount or mint automatic replacement Retry Keys.
7. Minimal recovery has no same-month rebase. After proving all external
   uncertainty resolved, a fresh-MFA Owner certifies an unused later month
   (current/next only). usable_from prevents future confirmation from reopening
   the incident month. Previously confirmed periods cannot be rewritten: retain
   HOLD until a new certifiable month or a separately reviewed recovery extension.
   Local unknowns remain charged. If exclusivity or exact opening usage cannot
   be proven, do not activate; report the specific missing evidence to Human.

The official [consumption reference](https://developers.line.biz/en/reference/messaging-api/#get-consumption)
describes totalUsage as approximate and points to OA Manager/sent-message
statistics for correct counts. Finalized daily counts corroborate a closed cut;
they are not per-request coverage or proof of in-flight completion.

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
  Add audited quota baseline/halt management and display missing months,
  reserved/unknown/accepted upper bounds, incident HOLD and safe evidence UUID.
- D6: use individual Push, no broadcast pacing; media3-second processing and
  unsend/retention official-guidance decision remain separate and unresolved.
- D7: use D2 recovery registry and captured same-BOT generation/timestamp. Stale
  observations are not delivery proof and never grant rewards by themselves.

Browser/login/Admin MFA/real LINE: NOT RUN — Human Browser Acceptance Pending.
Ready/Merge/runtime/operational migrations/ENV/worker startup/real sends:0.
The dedicated task branch/worktree are retained for Draft review; CONTACT and
other worktrees are untouched. Allocations111/112 remain unavailable for reuse;
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
