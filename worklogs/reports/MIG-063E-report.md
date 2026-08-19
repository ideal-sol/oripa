# MIG-063E Report

## Task

- Issue: #307
- PR: Closeoutで確定
- Risk: R3
- Base: `b528969e46bd6b2aed7085be89a2792db801d6af`
- Branch: `feat/MIG-063E-gacha-rank-code-scope`
- Worktree: `/var/www/oripa-worktrees/MIG-063E-gacha-rank-code-scope`

## Implementation

- Canonical Rank parent is Gacha. `catalog_ranks.gacha_id` scopes Rank `code` uniqueness to one Gacha.
- The old global `catalog_ranks_code_unique` constraint is replaced by `catalog_ranks_gacha_id_code_unique`. A partial unique index preserves safe uniqueness for unattached legacy Rank masters.
- Existing ownership is resolved from Gacha Version Rank and Prize references. Ambiguous ownership or an existing scoped duplicate fails closed without deleting, renaming, or reassigning business data.
- Gacha Rank creation stores the parent Gacha ID. Existing Rank code update rejection remains unchanged.
- Cross-Gacha Rank association is rejected at the database boundary. Fixture references resolve Rank by Gacha and code.

## Impact

- Migration created: `2026_09_12_000057_scope_v2_gacha_rank_codes.php`.
- Public/Admin OpenAPI, generated Admin contract, Admin UI, Artifact, Storefront Client, Site Schema, and Storefront Repository changes: 0.
- Rank display name, ordering, Draw selection, Point, Payment, Refund, Chargeback, Inventory, authentication, authorization, Preview, and Production behavior: unchanged.

## Verification

- PASS: Backend focused — 27 tests / 341 assertions.
- PASS: Task-local migration apply, rollback, and reapply with an existing owned Rank.
- PASS: Existing Rank row count and identity/code/display hash remained `1|1b491b48d1864a4906cd4ae525c4a9e6`; ownership was restored on reapply. The required ownership backfill incremented revision through the existing guard.
- PASS: Two Gachas stored `code=existing-rank`; a second identical code in one Gacha failed on `catalog_ranks_gacha_id_code_unique`.
- PASS: With a cross-Gacha duplicate present, `down()` failed before schema or data mutation and retained the scoped constraint and both rows.
- PASS: Task-source API Docker image build, changed PHP syntax, Policy Unit 137, Quality Unit 4, Security Unit 10, Local Policy/Quality/Security Gates, three dependency audits with zero findings, and `git diff --check`.
- FAIL (superseded head `a0b06da62ce49788093cda7fe384eb3ffbf2eb9a`): Integration Gate found the existing Draw load fixture cloned Gachas and Prizes without cloning their now-Gacha-owned Ranks. The fixture helper now clones the same immutable Rank codes into each Gacha and updates only its expected record count; Draw Core is unchanged. CI-equivalent load verification passed with 1 test / 36 assertions.
- Not run: Admin checks, Public/OpenAPI/Artifact checks, Browser/E2E, and repository-wide backend suite; those surfaces are unchanged and the task uses focused backend and required GitHub checks.

## Rollback

- `down()` restores the prior global `catalog_ranks_code_unique` only when all Rank codes are globally unique. If cross-Gacha duplicate codes exist, rollback fails closed without deleting or renaming data.
