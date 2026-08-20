# MIG-063F Report

## Task

- Issue: #315
- PR: Closeoutで確定
- Risk: R2
- Base: `fa32950041e9b49091aa5dd2a5a3881baeadaf10`
- Branch: `feat/MIG-063F-rank-presentation-asset-picker`
- Worktree: `/var/www/oripa-worktrees/MIG-063F-rank-presentation-asset-picker`

## Implementation

- Gacha Rank create/edit now loads candidates only from the existing Admin `rank-effects` endpoint, including its cursor pagination.
- Rank image candidates are filtered to `media_type=image`; draw-video candidates are filtered to `media_type=video`. The general Presentation Asset response remains limited to the unchanged Prize thumbnail control.
- The Picker reuses the existing Admin card selection pattern and presents a safe image thumbnail or lightweight video metadata preview alongside the Rank Effect title.
- Existing Rank asset references initialize as selected when they resolve in Rank Effects. An unresolved legacy reference stays as the submitted canonical Asset ID until an operator explicitly selects a replacement or unset value.
- Saving continues to use the existing `image_asset_id` and `video_asset_id` Rank request fields. The Picker references existing assets only and creates no assets.

## Impact

- Migration created/applied: 0/0.
- Admin API, Public API, OpenAPI, generated contract/artifact, database schema, Storefront, Draw, Inventory, Point, Payment, Refund, Chargeback, authentication, authorization, Preview, and Production changes: 0.
- Asset duplication: 0.

## Verification

- PASS: Admin focused `catalog-gacha-rank-prize` — 7 tests. It covers media filtering, exclusion of general assets, pagination, preview/title card candidates, canonical field submission, matching edit restoration, and unresolved legacy-ID preservation.
- PASS: Admin typecheck, lint, and production build.
- PASS: Policy Unit 144, Quality Unit 4, Security Unit 10; local Policy, Quality, and Security Gates; Composer, workspace pnpm, and legacy pnpm audits each reported zero findings; `git diff --check`.
- Not run: Browser/E2E, full Admin unit suite, repository-wide backend suite, and Storefront checks. This task changes only the Admin picker and runs its focused component coverage plus required local gates.

## Rollback

- Revert the three Admin-only source/test/style changes. No data, Asset object, migration, or contract rollback is required.
