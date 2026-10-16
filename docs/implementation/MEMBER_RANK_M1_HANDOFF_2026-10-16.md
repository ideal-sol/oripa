# Member Rank Platform M1 handoff

## Authority and delivery boundary

- Human Change `MEMBERRANK-M1-20261016`; machine Task `MEMBERRANK-20261016`.
- Risk R3, Strict Change, Application Runtime Activation deferred, Issue none.
- Functional authority: `会員ランク機能_仕様書_v1.5_最終統合版_2026-10-15.md`, SHA-256 `688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`.
- Delivery authority: phased implementation plan REV2 and M1 execution prompt REV2, both dated 2026-10-16, read after the specification.
- This delivery ends at Draft PR and exact-head technical self-review. Human approval is required before source merge. Ready, merge, auto-merge, operational migration, deployment, runtime changes and feature activation are not authorized.
- Base main `b5c28015ef94cb80f6702a61b1237cacd91d9863`; dedicated task branch/worktree. Existing primary worklog changes and other worktrees are preserved.

## Phase 0 and ownership

Confirmed PR #571 and canonical contract PR #574 merged; PR #573 remains superseded. Released alpha.45 and its immutable history are unchanged, candidate remains null. Admin-only contract additions pass the current source compatibility validator without a new Public candidate or publication.

PR #572 owns DB smoke measurement/execution and its workflow/tests. M1 owns only the three new schema inventory entries and the latest forward-only rejection registration in `scripts/db/v2_database.py`. These are distinct hunks and responsibilities; no diagnostic code or workflow is transferred. Source locks are not acquired merely for a shared pathname. Worklog additions must be preserved during any later authorized integration.

Migration Allocation Lock was acquired before reservation and creation. Allocation `000088` is `2026_10_16_000088_create_v2_member_rank_settings.php`; prior migrations including `000087` are unchanged. Reservation and lock ownership remain with this Draft Change until explicit closeout/handoff. No other task may reuse the allocation.

## Implemented contract and behavior

- Admin-only GET/PUT `/settings/member-ranks`, DELETE `/settings/member-ranks/scheduled`, GET `/settings/member-ranks/revisions`.
- Seven fixed ordered ranks; beginner values fixed, six editable ranks, integer basis points, bounded bonuses and skip count. Specification defaults are seeded by the additive migration.
- Singleton optimistic revision and row lock; append-only version/value history, deferred seven-row/order constraints, actor/time/before-version links and hash-chained redacted audit in the same transaction.
- Each save takes effect at next JST month start. Reads resolve by effective timestamp without a worker; later saves supersede earlier reservations without deleting history. Cancellation appends current values at that same future boundary. Historical timestamp lookup retains prior-month definitions.
- Idempotency is scoped to the Admin actor and operation. Replay returns the recorded snapshot; a new request with stale revision fails closed. Audit failures roll back settings and idempotency together.
- Initial disabled state and nullable activation timestamp; M1 refuses initial enabling via API. Started state can never be disabled or have its activation timestamp rewritten. No enabling UI exists.
- Owner/Admin edit; Operator reads. Future `member_rank.user.manage` and `payment.adjustment.manage` permission identifiers exist without corresponding operations.
- Admin displays current values, next-month draft, confirmation differences, cancellation and paginated history. Only existing prize-rank navigation wording changes to `景品ランク設定`; its URL/API/video behavior is unchanged.

## Test evidence and limitations

Executed local and exact-head CI results, retries, counts, checks and review URL are recorded in the PR and Worklog. Disposable PHP/PostgreSQL use network-none, task-owned Unix sockets, synthetic data, local Storage, read-only source and no operational credentials. Browser tests use an isolated local Admin server with mocked Admin API; they do not claim live browser-to-Laravel integration or deployed visual acceptance.

Migration creation and application are separate: one migration is created; test applications are only to disposable synthetic databases. Staging/Production/OLD/NEW/Shared database applications are zero. Backup/restore and forward-only rejection must pass before delivery; there is no `down` rollback.

## Mandatory M2 regression handoff

PR #571 behavior is intentionally untouched: pre-registration grants initially 500 **free** points (configurable, 180-day expiry), `operation_type=free_grant`, `source_type=pre_registration`, `point_type=free`. `activateUser` updates `users.created_at`; first-registration qualification deliberately uses that updated timestamp.

M2 must add an integration regression proving that this free grant and the created-at reset do not change rank points, accounting month, promotion or purchase history, while subsequent paid settlement increments exactly once. Include month crossing and retries. Rank accounting must use paid settlement/grant time and JST month with `payments.amount`, never `users.created_at` or free-point amounts. M1 has no per-user rank accounting, so this integration is **NOT IMPLEMENTED / NOT CLAIMED PASS**. Existing PreRegistration/Payment/Point regression results are not a substitute.

## Remaining M2–M9 and rollback

Rank-point ledger, payment integration, promotions and actual free bonuses, monthly worker/demotion, purchase bonus, eligibility and login-gacha changes, user manual operations, Public/Storefront APIs, refund/chargeback adjustments, first activation and activation-month backfill remain later phases. Gacha `会員ランク` to `対象ユーザー` wording remains M5. Undecided legal/accounting/provider matters remain Human decisions.

Before any operational rollout, obtain separate migration/runtime/activation approval and complete the applicable release gates. Roll back application source through a reviewed change while retaining additive disabled schema/history. Correct persisted data only by an approved forward migration; never delete history, rewrite released alpha.45, republish artifacts or toggle a started system off.
