# Member Rank Platform M7 handoff

## Authority and current state

Source implementation record. Human Change `MEMBERRANK-M7-20261017`, formal Task
`RANKADMIN-20261017` under the unchanged naming gate, Issue none, Risk R3,
Strict Change, Application Runtime Activation deferred. Base protected main
`ec30f2616108388d430ea4ef8eaf94483acb4762`, tree
`d5988c4b02a86a2393a168ae6ec23b2f929408ef`; M1 #575, M2 #577 and M3 #579
are merged. Specification v1.5 SHA-256
`688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc` and
phased plan REV2 SHA-256
`b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`
plus the M7 execution prompt dated 2026-10-17 govern this Change.
Final Source SHA/Tree, PR URL, executed final-head counts, Required Checks and
machine-readable Draft HOLD self-review belong to the canonical Draft PR. Only
that live evidence establishes technical completion; this document never
authorizes Ready, Source merge, activation or starting M4.

## Design and verification checklist

| Requirement | Source boundary | Required evidence |
| --- | --- | --- |
| List/filter/detail/summary | M3 resolver projection, existing Admin users | Read-only rank data, pre-pagination filter, all states, month boundary |
| Manual rank/transfer/skip | Admin authorization, User lock, M3 materialization | Revision, preview equality, explicit second-transfer confirmation, audit, replay |
| Point increases/bonuses | Shared M2 entitlement/grant boundary | Current JST month, zero bonus, manual-high rank, one grant per generation |
| Point decreases/recovery | Shared correction domain and canonical Point lots | Baseline reversal, full/partial/zero recovery, shortfall list, no duplicate recovery |
| Closed account preservation | Closure materialization and frozen projection | Closure rank remains fixed, worker excludes closed accounts |
| Migration | Forward-only 000091, schema inventory and guards | Fresh twice, exact restore schema/ledger, unchanged 000001–000090 |
| Admin UI/contracts | Admin-only OpenAPI, generated types, existing navigation | Desktop/mobile real API E2E, Operator read-only, existing preregistration UI |
| Final gates | Exact final head | Full V2, five Required Checks, Draft HOLD self-review, no functional defects |

Manual writes own Idempotency -> User -> Rank -> Totals/Entitlements -> Wallet
-> Lots -> Audit. No Payment or Plan lock follows User. They materialize pending
monthly judgments before changing the monthly baseline, without rewinding the
cursor. Read-only preview computes the authoritative difference and binds it to
the effective month, state, setting, entitlement and spendable free balance;
save rechecks under locks. All mutations and audit share one retryable transaction.
Recovery uses currently spendable free lots in the existing Point allocation
order, excluding reserved and expired amounts. It never deducts paid points.
Each achievement has one immutable recovery result. Full recovery releases its
slot; any shortfall retains the slot and appears in the summary. Original grant,
achievement, ledger and monthly decisions remain immutable.
The post-Wallet clock is checked against the locked JST month. A boundary crossed
while waiting for Wallet requires a fresh preview, rolling back the original
request. Replayed successful requests retain their original recorded result.

## Implemented surfaces and verification record

Six Admin operations provide summary, manual history, User history, read-only
preview, manual rank PUT and signed point-adjustment POST. Existing User list
and detail expose effective ranks through the same M3 evaluator. Rank filtering
precedes cursor pagination. Active summaries include missing-state beginners and
project unmaterialized monthly decisions; they remain explicitly provisional
while the M3 progress boundary reports pending members. Suspended/closed details
remain readable; closed ranks freeze and no future settlement/worker changes
their rank history. Transfer confirmation binds to the latest immutable change.

Manual rank and positive point changes share M2 entitlement consumption and
canonical free/180-day grants. Negative entries invoke the common correction
service even when a manually assigned higher displayed rank remains unchanged.
The database requires full-recovery proof before a consumed slot can be released.
Zero bonuses consume eligibility, can be fully cancelled at zero, and retain
generation history. Partial recovery never reopens the slot or repeats recovery.
Current free lots can fund recovery, including other bonuses; reservations and
expired amounts are excluded. Rank-point amounts never directly debit paid/free
wallet balances; only actual bonus grant/recovery operations change free funds.

Pre-freeze local execution: core M7 service/API40 tests/263 assertions and real
five-process payment/manual replay/worker/read concurrency1 test/39 assertions
PASS. Expanded boundary/input and final-head regressions, UI/E2E and all final
results are recorded on the PR rather than represented by these earlier counts.
DB unit71 and Admin contract43 PASS. Fresh twice,91 migrations/145 tables,
forward-only rejection and exact dump/restore schema plus ledger PASS; previous
000001–000090 bytes unchanged. Schema SHA-256
`29f02d52fb62835d25b8dbafe6cd1408421b5ff2ee6e48ed2044f084ca02089b`;
ledger SHA-256
`370b8e6f2235a9f1e6cbaa9e1547a1e28179e3937afb76fe97d1373c03c843e3`.

Initial failures and corrections are retained in
[the defect record](MEMBER_RANK_M7_DEFECTS_2026-10-17.md). Local UI unit runs
also encountered unchanged5-second limits under measured71–72% host CPU steal;
those runs remain FAIL, never PASS. No timeout, assertion, hash cost, skip or
security control is relaxed. Final-head CI and actual browser execution must
establish UI acceptance before technical handoff.

Browser validation uses a task-owned local Admin build and actual Laravel HTTP
server/PostgreSQL with synthetic Admin/MFA sessions. A test-only relay maps the
local browser origin to the synthetic canonical Admin origin while preserving
actual browser CSRF/idempotency headers. This proves UI/API/domain integration,
not deployed TLS, provider integration or Human browser acceptance. Desktop1440
and mobile390/360 scenarios, screenshots and final results belong to the PR.

## Parallel work and allocation

Primary dirty CONTACT Worklog and all historical worktrees are protected.
Open #572 owns DB execution/timing; #576/#578 own diagnostic documents; #573 is
superseded. M7 owns migration inventory/forward-only additions and its own
regressions, separate from diagnostic hunks. No Source Lock is selected solely
for a shared path. Dedicated branch `feat/RANKADMIN-20261017`, worktree
`/var/www/oripa-task-worktrees/MEMBERRANK-M7-20261017`.
Exclusive allocation flock acquired before inspecting every local ref/worktree,
open PR and reservation. Number `000091` belongs to this Change and cannot be
reused. Process lock remains held pending canonical handoff.

## Performance and activation limits

OPEN: M2-PERF-01 extra121.102ms/50ms guideline; M3 payment extra83.912ms/50ms;
M3-WORKER-PERF-01 10,000 users728.087s/600s. Performance optimization belongs to
an independent Change after all feature Source, OLD Test activation and Human
browser acceptance. Functional defects cannot be deferred.

Source merge, Ready, auto-merge, operational migration, OLD Test/Production,
worker activation and feature ON are forbidden. Public alpha.45, its immutable
ledger/artifacts, Public API and Storefront remain unchanged. No final PASS,
test execution, CI result, review or M4 readiness is asserted by this design.

## M4 and M8 integration contract

M4 must take the materialized pre-increment rank at payment grant time for its
purchase bonus, retaining Event/Payment -> User/Rank -> Plan/Wallet/Lot order.
Use the effective setting for that JST grant month and integer basis-point
arithmetic before the current payment's rank-point addition. M7 manual baseline
and skip updates are immediately visible through `V2MemberRankResolver`; do not
read stale physical levels. Summary already aggregates actual free-grant
operations with `source_type=member_rank_purchase` by occurrence month, allowing
M4 to supply real purchase bonuses without a new summary calculation. The
current zero count does not imply a purchase-bonus implementation.
M8 must call the M7 shared correction/recovery domain inside its existing
successful-adjustment transaction; no duplicate recovery algorithm. M9 remains
manual refund/chargeback recording; first activation/backfill belongs to Final
Gate. M4 implementation starts only after Human-approved M7 source merge.
`V2MemberRankCorrectionService::applyDecrease(userId, entryId, version, instant)`
requires the owning transaction/User lock and an already-recorded current-month
negative applied entry with its total updated. It performs baseline-limited
promotion reversal and one recovery per achievement; repeat invocation cannot
recover twice. M8 owns its future forward extension for adjustment origins,
requested-versus-clipped applied deltas (including zero), past-month review and
purchase-bonus cancellation. It must not reuse M7's manual-only ledger shape for
an unimplemented refund hook. `consumeReachedEntitlements` remains the common
positive/manual grant primitive, not a permission bypass or standalone commit.

Rollback retains additive schema, frozen records and immutable financial history.
Use a reviewed compatible source change/forward migration; never clear slots,
rewind monthly cursors, down the migration or toggle a started feature OFF.
