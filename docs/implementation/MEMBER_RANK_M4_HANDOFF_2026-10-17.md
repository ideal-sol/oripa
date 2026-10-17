# Member Rank Platform M4 handoff

Source implementation record, not a merge or activation authorization.
Human Change MEMBERRANK-M4-20261017; Task RANKPURCHASE-20261017; Issue none;
R3 / Strict Change / Application Runtime Activation deferred. Base
e18b2885fca7c49a3f85ac79c9fad45dbcad742d, tree
93265d8541392c32fc5c840426da245fe08c2de1. M7 #580 is merged.

Specification v1.5 SHA256
688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc and phased
plan REV2 SHA256
b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633, together
with the M4 execution prompt and latest Human decision, govern this Change.
Final Source HEAD/Tree, Draft URL, test counts, Required Checks, failed attempts
and machine-readable Draft HOLD self-review are bound in the canonical PR.
This document does not independently establish final-head technical acceptance.

## Transaction and immutable origin

Retain Event/Payment -> User/Rank -> Plan/Wallet locking. After the original
payment grant establishes its actual timestamp, materialize the rank at that
timestamp, snapshot the pre-increment rank and effective setting, create any
positive purchase free grant, then account the current payment's rank points.
All operations share the existing retryable transaction. Zero records retain
the rank/rate/amount without a point Operation. Feature OFF creates no snapshot.
No backfill or activation hook invokes purchase rewards. Existing succeeded
payment replay exits before purchase calculation, including pre-activation grants.
Provider occurred_at remains the original limited-campaign authority, not the
purchase-rank/rate/month authority. Card, PayPay and konbini share this path.

`V2MemberRankPurchaseBonusService::calculate` decomposes quotient/remainder;
no float or potentially overflowing amount-times-rate intermediate is used.
Invalid negative amount/rate and rate above10000 fail closed. Wallet addition
also checks integer overflow. The base is payments.amount only. Neither original
product free/limited bonuses nor purchase/promotion rewards increase rank points.

| Record | Invariant |
| --- | --- |
| member_rank_purchase_bonuses | payment_id primary FK to payment_point_grants, same user/amount/granted_at, immutable update/delete/truncate |
| Snapshot setting | effective revision, code/level/rate, JST grant month, exact integer-floor amount |
| Positive bonus | unique point_operation_id; source_type member_rank_purchase, free_grant, business_key member_rank.purchase:{internal payment id} |
| Zero bonus | snapshot with amount0 and NULL operation; no zero lot/ledger/operation |
| OFF or pending | no snapshot; Public null, different from confirmed0 |
| Lot/ledger | exactly one matching free lot and grant ledger, same user/wallet/amount/time,180-day expiry; origin fields immutable |

Deferred constraints validate the final transaction after all inserts. Additional
later lot/ledger inserts referencing the same purchase operation are rejected.
Ordinary lot remaining/reserved mutation remains available for spending and M8.
Snapshots do not change after subsequent rank/settings changes or refunds.

The payment-to-bonus snapshot is an immutable one-to-one relation, with a unique
positive point-operation origin. M8 must extend its cancellation selection using
that origin: existing paymentLots selects only payment_point_grants and does
not yet include the purchase bonus. M8 adjustment execution is out of M4 scope
and cannot be reported as implemented by M4. Specifically, M8 selects the original
payment_point_grants.point_operation_id and, when non-NULL, the immutable
member_rank_purchase_bonuses.point_operation_id. It must preserve separate
adjustment/idempotency/recovery histories rather than update or delete the M4
snapshot. A NULL snapshot means not applied; a row with amount0 means confirmed
zero. Historical setting values and the source payment/operation remain FK-backed.
M7 promotion recovery and monthly purchase summary stay distinct; purchase
summary already counts actual member_rank_purchase grant operations.

## Contract and following phases

Canonical pending bundle alpha.46 / Public alpha.42 after verifying no
existing alpha.46 artifact or competing local candidate. Published alpha.45
and all25 history entries remain immutable. Optional purchase fields preserve
existing grant totals. M5 integrates rank-limited gachas only after Human M4
Source Merge GO; M6 integrates the new rank endpoints into the same canonical
pending candidate. No Storefront source or pin changes. Client and Testkit source
versions are alpha.46; Site schema alpha.23 is an immutable reference. The
manifest package disposition publish describes a possible future authorized
release, not publication in this Change. No source_commit/publication digest is
invented for the unmerged candidate. Bundle SHA256 is
0249f195926c1bffea808ba4d5806afe8716af29b520b347be6d5c570bd82e35.

| Public surface | Additive field / behavior |
| --- | --- |
| GET /point-products | optional nullable member_rank_purchase_bonus estimate, pre-increment projected rank/rate/free amount, is_estimate/as_of, explicit payment-completion notice |
| GET /payments/{paymentId} | optional nullable confirmed snapshot with safe public Operation ID; owner predicate before presentation |
| GET /me/payments | same confirmed snapshot in existing cursor-ordered payment rows |
| Point history | separate purchase Operation row, label 購入ボーナス, optional point_type free |

Verified-user product estimation reuses one read-only rank/setting context per
response; guest/SMS-unverified/OFF is null. Existing private no-store, Vary Cookie,
offer/eligibility/CTA, grant.bonus_points and grant.total_points remain unchanged.
New labels say 購入ボーナス, never redefine existing gacha point-return labels.
Public responses contain no internal payment/user/setting revision or provider data.

M5 must consume canonical rank authority for rank-limited gachas/daily login
limits, not purchase snapshots or wallet points. Do not use this unmerged worktree
as protected main. Await separate Human Source Merge GO and re-preflight main,
reservations, active PRs and candidate ownership. M6 owns new member-rank endpoints
and final thin-client/testkit integration; no private endpoint is introduced here.

## Isolation and status

Migration000092 reserved under allocation flock, existing000001–000091 retained.
Dedicated feat/RANKPURCHASE-20261017 worktree. PR572 timing/diagnostics and
PR576/578 documents remain independently owned. Historical dirty primary and
POINT-FIRST-24H worktrees are preserved. No Ready/merge, operational migration,
runtime/worker activation, feature ON, provider communication or publication.
Dependency PR overlaps are package-version/lockfile paths only; external
dependency versions are unchanged. Superseded573 is not a release candidate and
must not be merged. Worklog append conflicts must preserve each Change's record.

## Verification and rollback

Pre-freeze focused calculation/settlement28 tests/217 assertions PASS. Expanded
test attempts and corrections remain in the defect record; those earlier counts
are not final-head evidence. DB72, contract48, policy291 and release95 tests PASS
in local pre-freeze execution. Client38 and Testkit51 tests, generated parity,
typecheck/build/lint/export/network guards PASS. No Browser/E2E is claimed for
these API-only changes; actual Laravel HTTP responses are validated separately.

Isolated PostgreSQL fresh2,92 migrations/146 tables, forward-only rejection,
pg_dump/pg_restore exact schema plus migration ledger PASS. Existing000001–000091
migration bytes are unchanged. Schema SHA256
9ab53ea06ea9b03d4449fd360a1ef9eb7719688e18a28c55d09dd912d52c9ca9;
ledger SHA256
f29cd71399bf6144309e969e577b5b6412a3f36cc4e6de82172f7cbe80c46cd5.
Test containers have no network or host ports, use synthetic Unix-socket DB,
read-only source/non-root API, no operational credentials/config cache, and are
task-owned. Concurrent scenarios use an additional separately isolated fixture DB.

Retain additive schema and all records on rollback. Before feature activation,
the previous source can run with the retained schema. After final activation,
older source cannot preserve purchase-bonus behavior: use an authorized forward
fix rather than silently turning the feature OFF or deleting history. No migration
down, operational rollback, or restore of real data is executed in M4.

## Performance and Human handoff

Existing M2-PERF-01 additional121.102ms/50ms, M3 additional83.912ms/50ms and
M3-WORKER-PERF-01 10k728.087seconds/600seconds remain OPEN/PERF. M4 service
latency samples exclude original payment/rank accounting/commit and are not a
representative capacity certification. Measured final values belong to the PR.
Only processing-speed optimization may be deferred; no functional failure is
accepted under a performance label. Optimize in an independent Change after all
Source phases, OLD Test bulk reflection and Human browser acceptance.

Handoff retains branch/worktree and logical000092/alpha46 reservations. Release
only task-owned process locks and disposable containers after canonical review
evidence is posted and read back. Strict review must be renewed for any later
separately authorized merge; Draft HOLD is not MERGE approval. Final gate requires
all five Required Checks and applicable tests on the unchanged final HEAD, no
SEV-0/1 and no unresolved functional defect. No Ready/Source merge/activation GO.
See [defect record](MEMBER_RANK_M4_DEFECTS_2026-10-17.md).

## Human-authorized diagnostic overlap with572

After CI39030121649 failed with its actual cause truncated, Human explicitly
authorized only CalledProcessError diagnostic extraction/priority/display and
focused regression in M4. Protected main remained e18b2885;572 remained Draft at
d74247babf2cabf4e334bfac8fe34451b5933b90. Its run() safe_error_only/metrics hunks
were compared before editing. M4 prioritizes PHPUnit failure identifiers,
assertions and exception lines over generic performance JSON, retains2400-character
total output and existing exclusion filters, and additionally excludes common
email/IP/phone shapes. Exit/exception behavior, test conditions and all gates
remain unchanged. This is diagnostics, not processing-speed optimization.

PR572 Source/branch/worktree/policy/CI are untouched. On future integration,
preserve572's safe_error_only guard, metrics observer, timing schema and privacy
controls while retaining M4's bounded failure prioritization. Do not overwrite
either independently owned implementation. Human GO does not authorize Source
Merge, operational migrations, activation or artifact publication.

Diagnostic run39032230710 exposed the real CI cause: SQLSTATE55006 while the
existing CurrentUserPointReadContractTest restored its legacy-expiry fixture
trigger with a new deferred M4 lot-origin event still pending. The exact case
was reproduced in isolated PostgreSQL before correction. The fixture now executes
SET CONSTRAINTS ALL IMMEDIATE before ALTER ENABLE, retaining all constraints,
all wallet/API assertions and the finally-based guard restoration. Production
behavior and migration000092 are unchanged by this correction. The Task Policy
adds only that one existing test path; all final-head gates must be rerun.
