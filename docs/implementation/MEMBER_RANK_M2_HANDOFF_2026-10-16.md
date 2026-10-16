# Member Rank Platform M2 handoff

## Authority and status

Human Change `MEMBERRANK-M2-20261016`, delivery Task `RANKPOINTS-20261016`.
Strict Change, Risk R3, Application Runtime Activation deferred, Issue none.
Base `bd4c1a3289fc11ce844805fe8ac0c6bb735b0b26`, tree
`2cad784b540eb7e7dc946af7c3e588b6c29a600d`, includes merged M1 PR #575.
Functional authority is v1.5, SHA-256
`688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`,
with phased plan REV2 and the M2 execution prompt dated 2026-10-16.
Source implementation is complete; exact-head CI and review results are recorded
on the Draft PR. Status: `MEMBER_RANK_M2_SOURCE_DRAFT_HUMAN_REVIEW_PENDING`,
not source-merged or operationally ready. Delivery stops at Draft PR;
Ready, merge, activation, operational migration and artifact publication require
new Human authorization.

## Pre-implementation design

Only verified payment settlement calls rank accounting, inside the existing
retryable payment transaction. Accounting reads `payments.amount` and the
persisted `payment_point_grants.granted_at`, never provider occurrence time or
user registration dates. The same internal accounting entry accepts an already
granted payment for future activation-month backfill, with a database-unique
payment ledger origin. It refuses pre-activation-month grants. No backfill
runner or activation route is introduced.

Use separate current state, monthly totals, immutable point entries, immutable
rank changes, immutable bonus achievements and current entitlement slots.
Monthly totals equal actual ledger deltas and remain nonnegative. Requested
and applied deltas are separate for future M7/M8 adjustments. Achievements
record setting revision, origin, basis, amount and generation independently of
actual rank changes. A slot identifies the currently consumed achievement;
future fully recovered cancellation can release it while preserving history.
Each generation has a new immutable grant key. Zero bonuses consume eligibility
without creating a point operation. M3 owns month judgment and demotion;
M2 state reserves a judgment cursor and monthly baseline without claiming that
judgment has run.

M1 effective settings resolve at the grant's JST month boundary. Promotion uses
the greatest reached level, never lowers an existing rank, and processes each
unconsumed reached entitlement even when the current rank does not change.
Point bonuses use the existing Point service's wallet, lot, ledger and expiry
calculations. Feature OFF returns before rank mutations. M1 activation refusal
and all existing APIs remain unchanged.

### Lock graph

Existing settlement: Provider Event -> Payment -> Plan -> Wallet -> Lots;
purchase creation: Idempotency -> User -> Plan; pre-registration activation:
settings/idempotency -> User -> Wallet -> Lots. Rank-enabled settlement must
take User before Plan and before Wallet, avoiding a new Plan -> User inversion
against purchase creation. Rank state and monthly totals are serialized by User.
Accounting reuses those owned locks after payment grant. Future backfill takes
Payment -> User -> Rank state/totals -> Wallet; future M3/M7 starts with User
and must never acquire Payment or Plan after User. Future M8 keeps its existing
Event/Payment prefix before User, Rank and Wallet. Existing wallet-only paths
do not acquire rank locks. All settlement exceptions roll back through
`V2PointTransactionRunner`, retaining its 40001/40P01 retries. Audit/outbox/mail
remain in the original transaction and no provider call is added.

### Regression and ownership

Disposable PostgreSQL tests must exercise real payment and pre-registration
services, including 500 free points and replay, paid transactions before/after
`users.created_at` reset, preserved `pre_registered_at`, JST month crossing,
provider-time disagreement, first-24h/signup qualification, concurrent payment
settlement, injected rollback, zero bonuses and synthetic manual rank states.
OFF regression must preserve payment grants, mail and outbox with zero rank
rows. M3 demotion, M4 purchase bonuses and M8 recovery are FUTURE.

PR #572 head `d74247babf2cabf4e334bfac8fe34451b5933b90` owns execution/timing
hunks; M2 owns additive inventory/forward-only registration and schema tests.
PR #576 head `106c0eb6b21136a819f3221dbc48c27bb10fe9cc` owns diagnostic
documentation. Both worktrees are clean. Their completion is not an M2 gate.
Old first-24h dirty settlement changes already exist in protected main; its
remaining differences are historical create-payment SMS additions. No changes
are imported from that worktree. Other dirty worklogs and sources are preserved.
Source Lock is not selected because no active semantic collision was found.

Migration Allocation flock was acquired before allocation; base source ends at
000088, no other open PR contains a new migration. Reserved migration is
`2026_10_16_000089_create_v2_member_rank_accounting.php`. Migration source, inventory,
forward-only registration and relevant tests belong exclusively to this task.
Public JSON and OpenAPI remain unchanged; released alpha.45, its immutable
history and candidate null remain unchanged. No Artifact Release Lock needed.

## Executed isolated verification

Task-owned PHP 8.4.23 and PostgreSQL 17 use network none, Unix sockets,
synthetic identities, local Storage, read-only source and no operational
credentials. Composer platform requirements pass against the unchanged lock.
These are disposable test applications, never operational migration approval.

- `MemberRankAccountingTest`: 22 tests / 289 assertions PASS. Thresholds
  9,999/10,000/29,999/30,000/99,999/100,000, accumulation, independent months,
  jump bonuses, 180-day lots, history label, zero qualification, manual-high
  state without false promotion, consumed qualification and generation keys.
- `test_real_pre_registration_500_and_created_at_reset_never_rewrite_paid_history`:
  four ON/OFF/order/boundary cases through the real activation service. 500 free
  and replay leave rank snapshots unchanged; prior payment/operation snapshots
  and `pre_registered_at` survive `created_at` reset. New paid settlement belongs
  to its grant month, not provider month or new registration origin. Existing
  first-24h and signup qualification deliberately uses the new origin.
- `test_nonpayment_grants_and_consumption_do_not_create_rank_rows` and
  `test_limited_bonus_keeps_provider_time_while_rank_month_uses_grant_time`:
  ordinary free/paid adjustment, referral, LINE, prize exchange, draw points,
  consumption, plan free and limited bonus are not rank-point sources.
- `ZMemberRankAccountingConcurrencyTest`: explicit disposable-DB opt-in,
  four real PostgreSQL connections, two same-user payments and duplicate
  notifications after actual pre-registration 500 grant; 1 test / 30 assertions
  PASS. Real commits enforce deferred checks. Live and internal replay converge
  to two entries, one promotion, one rank bonus and unchanged preregistration
  bonus. No operational DB or provider network is used.
- Injected failure during the second promotion bonus rolls back payment success,
  paid grant, rank entries/history/bonuses and lots; retry succeeds. OFF card,
  PayPay and konbini preserve grants, mail/outbox and zero rank writes.
- Two consecutive `migrate:fresh`, forward-only rejection without schema/ledger
  mutation, full inventory (142 tables / 89 migrations) and backup/restore exact
  schema plus migration-ledger comparison PASS. DB guard unit tests: 69 PASS.
  Schema SHA-256 `c9f40f615237f421037a4c48d2ef18d08973433f596888b8eb7e8a95ab98f67b`;
  ledger SHA-256 `c43fecedbb3330e6cddad49d1e2adcb0468ba96ddd5400cb779714442712b6d1`.
- Existing M1/settings, pre-registration, Payment, Point, limited-bonus and
  provider regressions, policy/quality/contract checks and final-head GitHub
  results are enumerated on the PR, with failures kept separate.

Initial fixture failures used an invalid provider method (`card` instead of the
existing `credit_card` enum); corrected the new fixture without weakening the
constraint. New migration functions use the existing CREATE OR REPLACE convention
for repeated disposable fresh runs. An explicit text-array CHECK normalizes
PostgreSQL dump/restore serialization without weakening allowed basis values.
Local OpenAPI bundle checking initially failed because this fresh worktree had
no Node dependencies; no bundle PASS is inferred from unchanged source alone.

### Performance limitation

The 50ms additional-settlement guideline is NOT MET in local measurement.
Latest 8-sample same-head OFF/ON comparison, including three separate promotion
bonuses: OFF median 39.382ms / max 226.511ms; ON median 160.484ms / max 320.960ms;
additional median 121.102ms. An earlier expanded run measured 60.003ms extra.
These shared-host, network-isolated synthetic runs include application/service
work but exclude the test's outer transaction commit; they are not production
capacity measurements or a pre-M2-head benchmark. No assertion or safety control
was weakened, and no repeated run is selected to manufacture a target PASS.
Representative isolated performance profiling remains a pre-activation item.

## Internal reuse and later phases

`accountGrantedPayment` requires the owning retryable transaction and takes
Payment before User. Future activation orchestration must coordinate its month
boundary with M3 and finish activation-month backfill before live month judgment;
the M2 primitive alone is not an activation runner or a late historical reranking
policy. It ignores pre-activation months and deduplicates by payment ID.

`consumeReachedEntitlements` is the common internal qualification/grant boundary
under the owning User lock, independent of changing current rank. Future M7
orchestration may reuse this boundary within the domain after its own approved
authorization/audit/selection policy; no manual UI/API or permission bypass is
provided here. `grantMemberRankPromotion` is the canonical Point boundary with
achievement validation, wallet locking, immutable generation-specific key,
lot/ledger and audit. Zero bonus still consumes the entitlement, not a Point
Operation. M7/M8 require new forward migrations to expand the payment-only entry
reason, nullable origins and sign constraints; requested/applied deltas are
already distinct. M8 must prove full recovery before releasing a slot. Synthetic
slot-release fixtures prove regrant-key feasibility only, not recovery behavior.

M3 must populate `last_judged_month` (null in M2), advance `baseline_month` and
`baseline_level`, and apply the approved skip/transfer policy under User locking.
The reserved default skip count of zero is not M3's initial judgment policy.
M3 must reconcile lazy/read/worker/payment paths and ordering before enabling.
M3 demotion, M4 purchase bonus, M5 eligibility, M6 Public exposure, M7 manual
operations, M8 refunds and M9 first activation remain NOT RUN / FUTURE.
Browser/E2E is not run locally because M2 adds no UI or endpoint. No Site,
operational database, deployment, ENV, provider call, object storage, artifact
publication or runtime activation is part of this Change.

At Draft handoff retain the task branch/worktree and logical allocation 000089;
release only the process allocation flock after publishing that reservation.
The PR is the canonical exact Base/HEAD/Tree, checks and machine-readable HOLD
self-review record. New Human authorization is required for any further lifecycle.

## Rollback and M3 boundary

Source rollback is a separately reviewed change retaining additive inactive
schema and immutable records. Data correction requires forward migration.
M3 must add lazy/monthly judgment before promotion, maintain monthly baseline,
consume skip counts and prove worker/read/write equivalence. Source completion
alone never authorizes operation: M3–M9 and first activation gates remain.
