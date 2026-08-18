# MIG-062Z Coin Read Contract and Platform Presentation

## Task

- Issue: #295 `https://github.com/ideal-sol/oripa/issues/295`
- Risk: R3
- Base SHA: `4c5e241aeb7331ae27c3720862ccbf74edc41339`
- Branch: `feat/MIG-062Z-coin-read-contract-presentation`
- Worktree: `/var/www/oripa-worktrees/MIG-062Z`
- Task Policy SHA-256: `f5e5adc617eb06c206f67de4298c8ee5ddd1aa20417b6ca92460da38a4226db4`
- Human-allocated Artifact Version: `2.0.0-alpha.21`
- Integration Lock and Artifact Release Lock: held. Preview Lock: not acquired.

## Contract

- `GET /api/v2/me/wallet` preserves `paid_points`, `free_points`, and `total_points`, and additively returns UTC `as_of` plus exact-timestamp `expiring_within_7_days` Buckets.
- Available balances and Buckets exclude expired and reserved amounts. The boundary is `as_of < expires_at <= as_of + 7 days`; only identical `expires_at` values aggregate. Legacy no-expiry Lots remain in available totals and never enter a Bucket.
- The GET performs no Wallet, Lot, Operation, or Ledger mutation. Point Ledger paths and response fields remain unchanged.
- A GET-specific `CurrentUserWalletBalance` Schema avoids changing the shared `WalletBalance` used by Draw Mutation responses.
- Storefront Client exports the Backend-canonical Wallet type. Testkit fixes paid/free, Legacy No Expiry, expired/reserved exclusion, less/equal/over seven days, `expires_at == as_of`, same-expiry aggregation, and exact-timestamp separation without Site-side inference.
- Admin User Detail adds total, paid, bonus, next-expiring amount, and next-expiry presentation. The expiry timestamp is rendered in JST date/time; the existing Admin list and Point adjustment wording remain unchanged.

## Version And Release Source

- Root, Admin, Platform, Storefront Client, Site Schema, Storefront Testkit, Public/Admin/Webhook OpenAPI, compatibility metadata, lockfile, Policy Gate, and release source are synchronized to `2.0.0-alpha.21`.
- `release:validate` reports Public SHA-256 `103b8d8ccb1312fecf3013a531102faf5d73cdeb667a7f8d705d6aaf581a1299`, Admin `c66a7ac5029a421c60a089211630e52681aacfc8b12fe8f0f1f34a2b5fa5a9a4`, Webhook `4aac6a0638e4c035340fb0887699dbb43542a9b5bb4c6a3135d8a38a0ed678c2`, and the unchanged 54-migration set `64e701b86fe16fa24be7994d1304c627d9e4e7e7ef5dc923c7b6e3020b050389`.
- Immutable Artifact Workflow ID, Artifact ID, Manifest SHA-256, package SHA-256 values, and Storefront handoff remain pending fixed-head GitHub issuance.

## Focused Verification

- Laravel isolated PostgreSQL: Current User Point Read 6 tests / 68 assertions; Admin User Read 4 tests / 112 assertions. Existing Point Ledger pagination/compatibility and Admin list/detail/history regression are included.
- C1a read-compatibility regressions: Payment reservation release 1 test / 5 assertions and Point due-lot read/spend 1 test / 8 assertions PASS against a fresh image built from the complete Task source.
- Public GET mutation assertion covers Wallet, Lot, Operation, and Ledger state. Legacy Lot fixture uses the established test-only trigger-disable boundary and restores the trigger before the request.
- OpenAPI bundle/check and 7 OpenAPI unit tests: PASS. Public 54 / Admin 212 / Webhook 1 operations.
- Storefront Client generate/typecheck/lint/build and 27 tests: PASS.
- Site Schema generate/typecheck/lint/build and 10 tests: PASS; Schema shape unchanged.
- Storefront Testkit generate/typecheck/lint/build, 34 tests, exports, and network boundary: PASS.
- Admin generate/check, typecheck, lint, focused User Read UI 5 tests, and Production build: PASS.
- Policy focused unit 2 tests, local Policy Gate, Release unit 10 tests, and release source validation: PASS.
- PHP syntax and `git diff --check`: PASS.

## Initial CI Correction

- Initial head `952c42402e5b9c6a8d0598182b187c408bd20938` passed Policy, Quality, and Security but Integration detected two C1a tests still asserting the pre-additive three-field Wallet array; `ci-gate` failed as the required aggregate.
- Only those read-contract expected arrays were updated with deterministic `as_of` and Bucket fields. Payment/Reservation/Expiry/Spend behavior and assertions were not weakened or changed.

## Scope Impact

- Migration created: 0. Task/Preview/Production migration applied: 0. Only the isolated synthetic test database applied the existing 54 V2 migrations.
- No C1a Expiry/FEFO/Reservation/Restore Mutation, Worker, Payment, Chargeback, Draw, Inventory, Limited Bonus, Runtime, Nginx, Production, Payment Review, or Storefront Repository change.
- Preview/browser verification, Required Checks, CodeQL, Dependency Review, Artifact issuance, Fresh Self-review, merge, lock release, and branch/worktree cleanup remain pending the fixed application head.
