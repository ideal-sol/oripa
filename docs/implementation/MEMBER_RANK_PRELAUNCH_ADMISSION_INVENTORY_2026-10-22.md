# MEMBERRANK-ACTIVATION — admission and exact-origin implementation

R3 / Strict Change / Runtime Activation deferred. Issue603 / Draft604.
This document records Source implementation, not Runtime permission. The
prelaunch-only Human REV2 exception is identified by SHA256
`e1e38332b160cd978fbac106387d6f07b046088cf98cd9fc57af4f810bce1248`.
The historical [A/B/C comparison](MEMBER_RANK_ACTIVATION_DESIGN_GATE_2026-10-22.md)
remains intact; general backfill is outside this Change.

## Publication protocol

1. Validate Owner, actual five-minute Fresh MFA, Admin session and input.
2. Begin a dedicated READ COMMITTED transaction. Try exclusive database-scoped
   advisory key `(740022,1)` and lock the generation singleton NOWAIT. Any
   admitted transaction prevents entry; no forceful draining or cancellation.
3. Lock Admin/session FOR SHARE NOWAIT, reauthorize, claim idempotency, lock
   settings NOWAIT and check expected revision and irreversible OFF state.
4. Validate a previously provisioned immutable authorization bound to this
   database's settings UUID, the fixed prelaunch site code, Human document hash,
   revision and exact payment/grant manifest. There is no HTTP provisioning
   endpoint, arbitrary boolean, ENV override, or session-variable bypass.
   An obsolete approval is never overwritten: a separately reviewed approval
   may be appended for the same settings UUID. The singleton immutable run,
   exclusive gate and irreversible settings still permit only one publication.
5. Under the same exclusive fence, inspect protected applicants, pending
   payments/events/provider operations/attempts/adjustments, rank business data,
   paid origins and Wallet/Lot/Ledger totals. Do not repair or ignore anomalies.
   The common provider service records successful immutable attempts while its
   receipt may still say `received`: only a completed latest `success` attempt
   can establish completion in that case. Unknown/unassociated events HOLD.
6. Try the existing audit-chain advisory lock, never wait on that chain while
   holding the exclusive gate. Insert the complete excluded payment-ID set and
   exact grant/operation references. The run FK is deferred, not disabled.
7. Recheck MFA, obtain the DB clock near publication, insert the immutable run,
   change generation0→1 and settings OFF→ON, write audit and idempotency result.
   Commit deferred origin/publication constraints atomically. Failures leave
   OFF and no run, exclusions, audit or financial effects from this request.

The timestamp is a near-publication database instant, not PostgreSQL's actual
commit timestamp. Classification never depends on that timestamp. Deferred
checks and commit still take time; their duration and the remaining skew must
be measured before Runtime acceptance. No month-specific backfill is staged or
published, so crossing a JST boundary cannot publish old-month financial work.
New payments use the effective settings for their actual grant month as before.

## Admission and lock graph

Normal financial work:

```text
outer TX → shared advisory gate → generation FOR SHARE → shared admission marker
         → existing User / Payment / Settings / Wallet-Lot order → audit → commit
```

Activation:

```text
outer RC TX → exclusive gate TRY → generation UPDATE NOWAIT
            → Admin/session SHARE NOWAIT → idempotency → Settings UPDATE NOWAIT
            → aggregate checks → audit TRY → immutable origins/run → ON → commit
```

All shared gates are transaction scoped; a connection crash releases them.
Normal writers are not serialized against each other by the shared marker.
Gate contention raises40001 and existing outer Point/Draw runners retry a
bounded number of times. Nested runners never independently retry an old
snapshot. An old REPEATABLE READ snapshot must lock the changed generation row
and receives40001 before it can publish stale-OFF financial effects. Retry means
restarting the entire outer transaction, not retrying its nested savepoint.

Statement-level guards cover financial DML including zero-row statements and
TRUNCATE. Before publication they participate in the shared fence; afterwards
strict tables additionally require explicit admission. Receipt/intent tables
auto-participate but do not acquire the strict-writer marker. An unknown writer
therefore cannot silently bypass admission. A user-state transition also takes
admission; ordinary signup, email/SMS verification and immutable preregistration
identity reads are not globally gated. Delayed reward work in an existing
identity transaction uses nonblocking admission and rolls back/retries if stale;
this is not a claim that every identity callback begins at transaction depth0.

No new Settings→User lock edge is introduced by Activation. It never locks User
or Wallet rows. The100ms local lock timeout is a defensive rejection bound for
unexpected secondary contention, not an approved business outage budget.
`inspectQuiescent()` remains a separate observational table-lock experiment;
its locks and zero counts are not a publication permit.

## Financial entry inventory

| Entry / actual Source authority | Admission and residual boundary |
| --- | --- |
| Payment create, verified event receipt, settlement, status/replay, refunds, CB/reversal (`V2PaymentService`) | Common Point runner before callback; event/user/payment lock order retained; M4 precedes M2. |
| Fincode intent preparation/claim/result and provider-operation records | Receipt-table guards fence direct/autocommit writes. In-flight pending payments, operations and attempts HOLD publication. External provider calls remain outside the activation TX. |
| Admin M9 (`V2AdminPaymentAdjustmentService`) | Existing Point runner and common payment services; provider confirmation conditions unchanged. |
| Point initialize/grant/consume/expiry/reconciliation and Admin manual adjustment | Point runner. Reconciliation/snapshot tables are guarded too. Wallet reads which initialize a wallet are financial writes. |
| Draw, login/free draw, batch point-back (`V2DrawService`) | Draw runner before callback, including G2 paths; nested retry cannot retain a stale snapshot. |
| Prize exchange/shipping (`V2PrizeShippingService`) | Direct transaction wrapper now delegates to Point runner; existing idempotency/error translation retained. |
| Rank manual adjustment, lazy materialization, monthly work | Existing Point/Draw owning runners; direct rank DML is guarded. Resolver methods still require their owning transaction. No operational Worker is launched. |
| Rank settings PUT/cancel | Explicit admission before idempotency/settings lock. Initial409 and ON→OFF refusal unchanged. |
| Admin user-state change / anonymization | Explicit admission before callback business locks; user-state DB guard covers direct transitions. |
| Preregistration reward application | Admission at per-user reward TX entry; batch qualification and signup are not globally stopped. Existing500/750 amounts and immutable history retained. |
| Referral reward and LINE follow/reconciliation | Admission only in potentially financial reward paths; no-reward identity paths remain ungated. A late nested entry fails rather than waiting with inverse locks. Receipt retry semantics remain the provider's contract. |
| Legacy non-V2 services/routes | Not converted into V2 financial authorities. V2 schema/strict guards reject unparticipating mutations. Their unavailability on the eventual deployment must be independently verified; this Change does not certify legacy endpoints. |
| Raw SQL / unrecognized batch | Covered table mutation acquires fence and, after cutover, requires admission. DDL/superuser disabling of triggers is not authorized or part of this protocol. |
| Public/Admin read-only projections | No rank financial rows are created by initial publication. Reads see their transaction's committed snapshot; any subsequent write must pass generation admission. They are not activation permits. |

Migration097 contains the exact guarded table inventory. Table guards are a
fail-closed fallback, not a claim that every arbitrary SQL client is a supported
writer. New financial entry points must adopt the owning runner and be tested.

## Exact cutover and shared authority

Every existing payment ID is captured under the exclusive gate, including
terminal failed/canceled/expired payments; succeeded origins additionally bind
the immutable grant ID, operation ID and grant timestamp. IDs are membership,
not a high-water mark, and sequence allocation/commit order is irrelevant.
Pending payments are never silently excluded. An excluded terminal payment
cannot later acquire a new grant: unexpected provider success fails closed for
reconciliation rather than losing its rank origin or rewriting history.

`v2_member_rank_prelaunch_eligible()` is the shared classification authority:
NULL means legacy site semantics, false means exact excluded origin, true means
a new origin. M2, M4 and M8 use one PHP policy backed by it. M4's DB origin
constraint uses the same classification; all grant/amount/effective-setting,
Point/Lot/Ledger and immutable checks remain. Only the old timestamp eligibility
predicate is replaced for the explicitly authorized site. Legacy sites keep it.
Deferred new-grant checks require atomic M2 and M4 records after this cutover.

Old test-payment refund/CB has rank effect0; genuine post-ON missing origins
still fail closed. Preregistration eligibility, auth, phone HMAC, created_at,
free rewards and Wallet/Lot history are not an exclusion classifier or modified
by Activation. Ordinary users can start at beginner with their free rewards.

## Acceptance status

The subsequent zero-origin/free500 run passes151 assertions, including real
publication-backend termination after settings UPDATE, full rollback verified
from another connection, retry, stale-approval refusal and append-only
reauthorization. Its protected applicant was registered on2026-10-21. Publication
including commit0.804785s, occupied-gate refusal0.063498s, injected failure
including rollback0.167793s; total38.480s/77MiB. The97-migration custom backup and
restore have identical normalized schema and migration ledger. These are not
proofs of exhaustive process/server failure or operational capacity. Final-head
1000-origin results and Required Checks are recorded on Draft604.

Focused real-commit/API tests pass with10 origins/free500 (149 assertions) and
1000 origins/free750 (1139 assertions). The latter uses two payment users and
one protected applicant; every existing Origin is explicitly checked excluded.
All33 guarded tables independently block publication. In the constrained
network-none synthetic environment, the1000 run took521.208454s to create real
Source payment/grant fixtures,0.227360s to refuse an occupied gate,5.597162s for
an injected failed publication including rollback, and10.174331s for successful
publication including commit. PHPUnit total612.176s, peak83MiB. Ten-origin
equivalents were3.417138s/0.006105s/0.326749s/0.717969s, peak77MiB.

These are single samples, not medians/maxima or isolated DB lock/rollback time.
The seed time is not financial backfill: initial ON grants no historical points.
The10.17s publication is not an approved maintenance time. WAL, memory growth,
bloat, exact TX-only hold time, commit-timestamp skew and live-writer impact at
site-equivalent load remain unmeasured. Larger runs and concentrated-user
comparison are not claimed. Do not infer PASS from successful functional checks.
Status remains `MEMBERRANK_ACTIVATION_PRELAUNCH_SOURCE_HOLD`; final exact-head
checks/review belong to Draft604, not the old bef04c CI result.

Capacity/writer-wait acceptance remains OPEN: no Human maximum origin count,
member count or allowable delay has been approved. No inferred limit is PASS.
Full multi-server/provider recovery, replacement-provider M2/M4/M8 integration,
legacy endpoint exclusion and all remaining concurrency/failure cases require
their own evidence. Source preparation does not provision real authorization.

Runtime changes0; operational migrations0; real Feature ON0; real backfill0;
operational Worker starts0. Hotfix Technical Acceptance, old-PHP rollback
prevention and explicit Source Merge restart precede any Ready/merge. Release
artifact, OLD Runtime, NEW Runtime, site authorization provisioning, actual
Activation and Production each require independent GO and fresh read-only
site/provider/data checks. No cleanup or financial rollback by history deletion.
