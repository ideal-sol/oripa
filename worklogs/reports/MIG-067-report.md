# MIG-067 Report

## Task

- Issue: #334
- PR: closeoutで確定
- Risk: R4
- Base: `411ea6593fa67cae618ed8a16ec3b8fa2253aaba`
- Branch: `fix/MIG-067-gacha-lifecycle-edit-integrity`
- Worktree: `/var/www/oripa-MIG-067`

## Implementation

- Scheduled initial publication remains a Draft lifecycle until its due Worker transaction succeeds. Reservation stores no publication history or active Draw State.
- Before first publication, the Gacha body remains fully editable and the active Schedule tracks the latest Gacha/Version revisions and start time.
- After first publication, only title, thumbnail, category, tags, description, notices, management status, and publish end are mutable. Sale, draw, audience, start-time, and other non-whitelist conditions remain immutable.
- Published category association is stored on `catalog_gachas.category_id`; the Published Version snapshot remains unchanged, and existing Admin/Public category response fields resolve the current association.
- Administrator-entered Gacha `total_count` remains authoritative. Backend checks and deferred PostgreSQL constraint triggers reject aggregate snapshot or operational Prize inventory above that capacity.

## Compatibility

- Migration: `2026_09_13_000058_canonicalize_v2_gacha_lifecycle_inventory_capacity.php`.
- Existing migrations and business history are not rewritten. Legacy activated scheduled rows or pre-existing over-capacity inventory fail migration closed for explicit reconciliation.
- Existing published Version snapshots, Draw/Inventory history, Prize adjustment history, and total-count input semantics are preserved.
- Public/Admin contracts, Storefront, Admin UI, Probability API, Coin, Payment, Draw Core, Artifact, Preview Runtime, and Production are unchanged.

## Verification

- PASS: isolated migration apply / rollback / reapply without `migrate:fresh` or trigger disablement.
- PASS: focused Catalog tests — 32 tests / 469 assertions.
- PASS: changed PHP syntax, Policy Unit — 151 tests, and `git diff --check`.
- Not applied: shared Preview migration. Runtime activation and Production deployment were not performed.
- Pending final head: Required five checks and fixed-head self-review.

## Rollback

- Rollback fails closed while an active pre-publication Schedule exists. Otherwise it removes only MIG-067 functions/triggers and restores the prior lifecycle/category/schedule guards without rewriting data.
- Legacy Probability removal and internal generation remain explicitly deferred to T2.
