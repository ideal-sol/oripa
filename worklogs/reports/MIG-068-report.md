# MIG-068 Report

## Task

- Issue: #336
- PR: closeoutで確定
- Risk: R4
- Base: `7434709fcbf2faf93cd7e22fce51d1badc3411b9`
- Branch: `fix/MIG-068-canonical-gacha-publish`
- Worktree: `/var/www/oripa-worktrees/MIG-068`

## Canonical Publish

- Publish Preflight and execution no longer require an administrator-selected Probability Version or a separate Probability validation/publish/preflight operation.
- Immediate Publish locks the Gacha and Draft Version, preserves an already-selected valid Published Snapshot when available, otherwise chooses another valid historical Published Snapshot or creates canonical internal metadata. Probability publish/selection, Version publish, Draw State, Inventory attachment, Gacha activation, Audit, and Outbox remain in one transaction.
- Schedule creation pins one canonical internal Probability Draft without selecting or publishing it. Due Worker activation publishes/selects that exact pinned row and commits the MIG-067 first-publication lifecycle atomically. Scheduled edits refresh and reuse the same internal Draft.
- Worker 5xx internal publish failures remain retryable. Row locks, expected revisions, the pinned schedule reference, and transactional rollback prevent duplicate Probability Versions or Draw States across retry and concurrency.

## Probability Compatibility

- Internal metadata uses one reserved `__canonical_inventory_v1` stage. Prize entry ppm values are deterministic initial-inventory proportions totaling exactly 1,000,000; the required minimum guarantee is a zero-ppm, zero-point point-back record.
- This metadata exists only for existing Legacy Probability history/display references. `V2DrawService` is unchanged and continues selecting from current remaining inventory with the existing cryptographic random source.
- Legacy Probability tables, API endpoints, Published Versions, snapshots, historical metadata, Draw results, Inventory/Ledger/Audit history, and Public probability presentation remain present and unchanged.

## Problems And Schema

- Stable publish failures distinguish `CATALOG_GACHA_PUBLISH_INPUT_REQUIRED`, `CATALOG_GACHA_PUBLISH_PRIZE_INSUFFICIENT`, `CATALOG_GACHA_PUBLISH_LIFECYCLE_INVALID`, `CATALOG_GACHA_PUBLISH_INVENTORY_INVALID`, and `CATALOG_GACHA_PUBLISH_INTERNAL_FAILURE`.
- Migration `2026_09_14_000059_internalize_v2_canonical_probability_publish.php` adds only the internal Draft schedule allowance and the exact pinned selection allowance during Worker publication. It rewrites no historical row and modifies no existing migration.
- Public/Admin OpenAPI and generated/bundled contracts are unchanged. Storefront, Admin UI, Point, Payment, Refund, Coin, and infrastructure runtime are out of scope and unchanged.

## Verification

- PASS: isolated normal application of all 59 V2 migrations; MIG-068 rollback and reapply; no `migrate:fresh` and no trigger disablement.
- PASS: focused Catalog compatibility — 56 tests / 744 assertions, including Lifecycle canonical publish and Worker retry — 6 tests / 147 assertions.
- PASS: Policy Unit — 152 tests; OpenAPI Unit — 7 tests; OpenAPI bundle check — admin 215 paths, public 54 paths, webhook 1 path.
- PASS: changed PHP syntax and `git diff --check`.
- Not run locally: existing fork concurrency test class because it invokes prohibited `migrate:fresh`; exact-head Required Checks provide repository CI evidence.
- Not applied: shared Preview or Production migration/runtime. Browser/E2E and visual verification are not part of T2.

## Rollback

- Rollback fails closed while an active schedule pins an internal Draft. Otherwise it restores the MIG-067 schedule and scheduled-Draft guards without deleting or rewriting data.
- Complete Legacy Probability removal and Admin UI wording/visibility changes remain separate follow-up work, including T3.
