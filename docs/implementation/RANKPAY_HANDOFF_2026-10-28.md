# RANKPAY-20261028: classified prelaunch activation

Status: Source Draft preparation. Risk R3; Strict Change; Application Runtime Activation deferred. No Ready, merge, auto-merge, runtime deployment, operational migration, authorization INSERT, or real Activation is authorized by this Change.

## Source and authority

Base is protected main `c15eea6887c9fd16f3096cfb27ed78b599262c7e`, containing PAY1 #620, PAY2 #622, PAY4 #623 and PAY3 #624. Activation #604 remains the publication protocol. Migration097 and099 are unchanged. Migration100 was allocated after checking open PRs, worktrees and reservations while holding the migration allocation lock.

PAY1 migration099 serializes the existing-payment classification and records existing Fincode origins as immutable `test / pay1_existing_fincode`. New payment creation records the configured provider test/live mode. This Change never infers commercial status from missing classification, provider name, or a timestamp. Future Fincode `commercial / provider_live_mode` follows the same M2/M4/M8 path as other commercial providers. Future test payments retain ordinary payment behavior but have no rank points, promotions or purchase bonuses.

## Bounded HOLD exceptions

- Every initial origin must have a valid test classification. Any commercial or unknown origin holds publication; this is the site-specific no-backfill launch, not a generic commercial-site migration.
- Only historical Fincode `test / pay1_existing_fincode` payments in `requires_action` bypass the unfinished-payment check. A Fincode attempt must belong to that payment and both statuses must be `requires_action`. Calling, prepared, pending and uncertain work still holds.
- An unprocessed event must have a verified signature, matching payment/provider, a recognized payment success/status/cancellation type, no adjustment link, and no failed or unfinished processing attempt. Unassociated, foreign-provider and unknown events still hold. Normal successful completed replays retain the existing inspection behavior.
- A historical rank adjustment is allowed only with `effect=none`, zero requested/applied rank and purchase-bonus amounts, null rank entry/levels, no rank origin or purchase bonus, and consistent succeeded full refund/chargeback, grant, reversal operation, links, ledger and paid/free impact. Financial shortfalls are preserved as recorded history; this does not waive unresolved reconciliation or wallet/lot/ledger checks.
- Protected preregistration users with any payment, draw or unrelated point operation still hold. No member-count-zero requirement is introduced.

Migration100 binds classification and reason into the manifest, adds classification writes to the financial admission fence, rejects late classification after grant/cutover and prohibits creating new historical classification evidence. Deferred publication constraints share the exception predicates with inspection. New grants after cutover require explicit classification; old excluded origins cannot gain a new grant. Existing grants can receive idempotent notification replay. A late success for an old ungranted origin fails closed without points, rank, bonus, receipt or purchase mail creation; it is not automatically acknowledged as a new successful settlement.

## Verification

The dedicated PostgreSQL harness has no network or published ports, uses a Unix socket and synthetic credentials, and mounts only the dedicated source and task socket. No operational credentials or provider calls are used. Final exact-head results, Required Checks and fresh self-review are recorded on the Draft PR.

The mandatory classified activation test upgrades 29 synthetic old origins through098→099→100, including13 unsettled payments/attempts,67 unprocessed events and3 valid zero-effect adjustments. It verifies unchanged financial history, protected applicants, unknown/commercial/provider-event/attempt/adjustment HOLD cases, real commit and replay, late legacy notifications, future Fincode commercial promotion/purchase bonus/refund/chargeback and future Fincode test exclusion. Existing activation tests retain authentication/CSRF/Fresh MFA, stale authority, all financial writers, old snapshots, crash rollback and single-commit assertions. Existing focused payment/rank regressions remain applicable.

Past isolated0/10/1000-origin measurements are context only, not operational latency or downtime guarantees. No new large benchmark is required. Provider network behavior, operational load, runtime deployment, Owner browser session, real MFA, operational classification rows and actual activation remain unmeasured/unexecuted in this Source task.

## Shortest later OLD Test rollout

1. Obtain separate Source Merge GO for this Draft. Payment rollout owner selects a fixed merged source containing PAY1–4 and this Change, with verified ancestry, required checks and compatible artifact manifest. Latest main alone is not runtime authority.
2. Reconcile the OLD Test release authority, API/Admin payload and OCI revisions, patched PHP gate, current ledger, release locks and active writers using the existing canonical read-only runbook. Preserve worker/timer/image pins and existing catalog/schedules. Do not access NEW Production.
3. Under a separately approved deployment/migration window, apply only missing forward migrations in order. If ledger is98,099 then100 are required.099 requires its table lock and classification scope confirmation;100 changes functions/triggers only. Do not repeat097/098 or use rollback/restore. Coordinate application compatibility: new inspection requires100, and new explicit commercial queries require099 classification. Deploy the reviewed combined payload through the existing canonical path; no provider enablement or worker startup is implied.
4. Repeat the read-only preflight against deployed source and actual DB. Recheck classification provenance, protected users, zero adjustments, reconciliation, pending work, generation0, OFF/null, no run/exclusion, and current health. Recompute the **new** manifest: pre100 authorization digests cannot be reused. Keep site UUIDs, actual counts and authority records in root-private evidence.
5. Return exact site-bound information for a separate Human Activation GO. Only after that GO may the authorized DB operator register the098/099/100-compatible site-bound authorization using the current settings UUID, revision, human-spec SHA and manifest. Codex must not impersonate the Owner, create a session or synthesize MFA.
6. Human uses a genuine Owner Admin session, fresh MFA within five minutes and valid CSRF to call `POST /admin/api/v2/settings/member-ranks/prelaunch-activation` with the approved authorization UUID, expected revision and stable idempotency key. The API closes admission, reinspects, checks digest and commits enabled/activated_at, run, exclusion origins, generation and audit atomically.
7. Verify the single run, exact exclusions including null grants, generation1, enabled/activated_at, audit and financial invariants read-only, then API/Admin/Storefront health. On busy/changed authority/data HOLD, stop and re-inspect. On uncertain response, inspect the committed state and use the same idempotency identity; do not create another activation. After a successful ON, no OFF rollback: HOLD and reviewed forward recovery only.

No Source merge, operational DB write, OLD/NEW deployment, provider call, worker/timer mutation or real Activation occurs in this Change. Retain the Draft branch and dedicated worktree for review.
