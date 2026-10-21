# MEMBERRANK-M8-20261020 — Draft Source handoff

## Authority and delivery boundary

- Risk R4; Lane Strict Change; Application Runtime Activation deferred; Issue none.
- Base: `17b138a2738c5595b6aabf62fa5a427df7e7b52b`, protected main at implementation
  preflight. M9 #596 (`3fdeebbbee7049f00101085e013f0c31fb43afc0`) is already merged;
  intervening changes are documentation only. Other tasks/worktrees are untouched.
- Human specification v1.5 SHA256:
  `688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`.
- REV2 phased plan SHA256:
  `b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`.
- Formal M8 handoff SHA256:
  `790baa5ef0f26e82bd62b9a439479fcd28589c8e580c645550f00eb98c4fcbcf`.
- Execution prompt v1.2 SHA256:
  `07dec7ba7d9cc9d0538d680c6b5411b798626ab29b82022dd57cff729a7468b5`.
- Investigation v1.2 SHA256:
  `97b6776f84846c8ac0fd5441a6ea1959b4cd44ee2a0542c51cc6fa29d8b2aa7c`.
- Delivery stops at one Draft PR. Ready=false; Merge=false; Runtime=unchanged;
  OperationalMigration=not_applied; FeatureActivation=deferred;
  ContractPublish=false; ProviderRefundCall=false.

## Accounting boundary

`V2PaymentAdjustmentLots` resolves immutable payment/grant/M4 snapshots into the
actual paid lot, combined normal-plus-limited free lot and optional separate M4
free lot. Amounts must be nonnegative and addition must not overflow. Positive
M4 grants remain exactly one operation/lot/grant ledger; zero bonus has none.
No old migration, grant operation or grant ledger is modified.

Refund preparation requires all related lots to be fully unused, unexpired and
unreserved. Each real lot receives one reservation. Completion consumes those
exact reservations once, including reservations whose lots expired after
preparation. Explicit cancellation releases reservations without rank accounting,
even if the rank is subsequently frozen. Provider refund requests are not sent.

Chargeback preview and execution share the same planner. Preview fixes one
instant; execution takes a fresh instant after wallet/lot locks. Lots expiring
at or before that instant are excluded, even before the expiry worker runs.
Origin lots are preferred, paid lots retain FIFO, and reservations are excluded.
The free reversal is assigned first to the immutable payment-origin free
requirement, then to M4. M4 shortfall is an inner component of total free
shortfall, never an additional liability.

Point debit, impact, rank entry/month total, M7 recovery, adjustment success,
audit and outbox are inside the existing outer transaction. Provider events and
Admin chargebacks converge on `executeChargeback`. Existing event verification,
replay, RBAC, fresh MFA, CSRF and no-store controls are preserved.

## Rank accounting and revisions

Migration 000094 was reserved under the Migration Allocation Lock after checking
the complete source inventory. The new immutable adjustment table and signed
entry shape preserve the original positive `payment_id UNIQUE` entry. Separate
`adjustment_id` and `origin_payment_id` uniqueness plus the new immutable
adjustment table's unique payment key prevent repeat cancellation. Every new
success requires this accounting; historical partial-refund rows are preserved
without a global uniqueness index on their original table. Deferred constraints check
origin, month, time, amount, impact and terminal success. Month total consistency
continues to be enforced by the existing sum-of-applied-deltas constraint.

The original JST grant month loses `min(payment.amount, month_total_before)`.
Requested and applied amounts are distinct. Applied zero records immutable
evidence but never calls M7 `applyDecrease`, never creates a reversal/recovery,
and does not increment revision when materialization has no actual work.

Current-month adjustment materializes pending judgments under the user lock,
then reuses M7 baseline/reached-rank and entitlement rules. A positive actual
decrease increments revision once, coalescing lazy materialization into that
same increment. Full recovery releases entitlement; partial/zero recovery with
a positive shortfall retains it. A zero-valued promotion bonus can be fully
recovered without a point operation. Direct manual-rank grants are excluded;
self-reached entitlements remain recoverable even if visible rank is unchanged.
The completing Admin, not merely the refund preparer, owns the rank audit.

Past-month adjustment changes only original-month accounting and review evidence.
It does not mutate current state/revision/entitlements. Resolver judgment inputs
exclude later cancellation deltas, so an unprocessed monthly worker cannot
retroactively change rank because of a later refund.

Initial-OFF payments without rank origins can cancel without a rank decrease.
Later initial-month backfill excludes succeeded refunds/chargebacks, with a DB
origin guard as well as a writer guard. Missing post-activation origins, positive
M4 without rank accounting, inconsistent snapshots, frozen/closed rank state and
unsupported disabled-with-entry data remain 409 fail-closed. Activation remains
irreversible under the existing DB constraint; tests do not disable that guard.

User serialization precedes payment/adjustment, rank state/month/entitlement,
wallet and ordered lots. The existing outer transaction runner alone retries
40001/40P01, at most three attempts. No inner savepoint retry is added.
An actual CB/manual-decrease race exposed a stale-preview 422 before revision
validation. The Admin writer now checks the locked revision before recalculating
the delta; its later token/revision checks remain. Stale increments and decrements
both return 409 without changing accounting.

## Admin and contract

The unpublished Admin contract alpha.42 candidate adds optional typed purchase-bonus and rank impacts,
`payment_adjustment` history reason, public payment/adjustment links and a separate
`payment_adjustment_reviews` list. Existing recovery `reviews` retains its
achievement/rank shape. UI provides classification/search, explicit zero applied
amount, preview-versus-execution notice, bonus shortfall as an inner amount, and
links to the original adjustment.

Human explicitly approved integrating M8 into pending bundle alpha.46's existing
Admin alpha.42 candidate under the artifact release lock. Published alpha.45 and
immutable history are untouched. Public alpha.42 /
83 operations, Public client/testkit, Storefront, dependencies, provider contracts,
infrastructure and CI gate decision logic are unchanged. The Admin skeleton
inventory registers only the two new M8 test files, with exact-path, missing-file
and unknown-path rejection tests. Version/inventory fixtures are updated without
broadening the historical M9 exception.

## Verification and remaining gates

Development checkpoints: initial focused M8 12 tests / 147 assertions PASS;
OpenAPI 57 tests PASS; DB script 80 tests PASS; focused Admin 18 tests PASS on
retry with a 30-second CLI timeout. The first Admin run was 16 PASS / 2 timeout,
not a clean first-run PASS. Backend development failures (audit metadata shape,
test reservation FK and an invalid irreversible-OFF fixture) were corrected,
not suppressed by weakening controls.

Final-head full V2, Admin build/lint/tests, browser, concurrency, fresh ×2,
backup/restore, security, required checks and exact-head self-review must be
reported in the Draft PR. These checkpoints alone are not final completion.
Browser fixtures are synthetic and do not establish operational acceptance.
Historical M9 browser failure/retry evidence is not relabeled as M8 evidence.

Human approved keeping the unpublished Admin alpha.42 candidate, rather than
the rejected alpha.43 proposal. No release validator change or publication is
authorized. Human also approved a normal merge of protected main
`4dd573749fd7d3c5aa1b3d8dac4292017272f81d` into this task branch. Its DBFASTPATH
CI changes remain separately attributable, without overwriting another task.

## Rollback and operations

This is a forward-only accounting change. New schema rejects an older writer
that omits adjustment evidence; rollback is a reviewed compatible forward fix,
not deletion of immutable history or schema down. Source merge, deployment,
operational migrations, workers, feature ON and artifact publication each need
separate Human authority. Retain the task branch/worktree and reservations while
the PR remains Draft; do not perform normal post-merge cleanup.
