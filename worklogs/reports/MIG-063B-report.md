# MIG-063B Limited Bonus Admin / Public Contract / Artifact

## Task

- Issue: #299 `https://github.com/ideal-sol/oripa/issues/299`
- PR: #300 `https://github.com/ideal-sol/oripa/pull/300`
- Risk: R3
- Base SHA: `f2ecce33f8552e89dcda95fc0ac531d832a1c29e`
- Branch: `feat/MIG-063B-limited-bonus-admin-public-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-063B`
- Task Policy SHA-256: `954576ffd175b2268110b71e35cf92887e2aa2ca39145c6e2f557671d0b858d9`
- Artifact Version: `2.0.0-alpha.23`
- Artifact Release Lock: held through immutable Artifact readback. Integration／Migration Allocation／Preview Lock: not acquired.

## Implementation

- Admin exposes list/create/update for Limited Bonus Campaigns under an exact Point Purchase Plan public ID/version. Mutations support ON/OFF, start, end, and additional Bonus Coin amount, require existing plan permissions/fresh MFA, and preserve idempotency and audit behavior.
- The Admin adapter delegates semantic validation, overlap exclusion, locking, and transactions to C2a `V2LimitedBonusCampaignService`; it maps existing Domain errors to stable 422/409 Problem codes without reimplementing Domain Core rules.
- Admin-facing Limited Bonus wording is `期間限定ボーナスコイン`; internal point-domain names remain unchanged.
- Public Point Product additively returns `limited_bonus.amount`, `starts_at`, `ends_at`, Backend UTC `as_of`, canonical `active`/`upcoming`/`inactive` state, and presentation. Start is inclusive and end is exclusive. Existing paid/bonus/total grant fields are unchanged.
- Public selection and presentation are computed once per response by Backend. Storefront Client receives generated types only and does not calculate campaign time windows or Bonus stacking.

## Contract And Artifact

- Root/Admin/Platform/Client/Site Schema/Testkit/OpenAPI/compatibility/policy/release source are synchronized to `2.0.0-alpha.23`; existing `2.0.0-alpha.21` and the retired non-handoff `2.0.0-alpha.22` artifact were not overwritten.
- OpenAPI source/bundles expose Admin 215, Public 54, and Webhook 1 operations. Release validation reports Public SHA-256 `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`, Admin `a51d6c915730b0ab7c670bec016d2aee754e817f5bc01075c09b99bac1528bcd`, Webhook `3f50a861f180e75a9315780ec3d201f74a39501c99336a32710b4ea92f7b256c`, and unchanged 55-migration set `7c6887b2547e52f092f4b45af559395277b396511a7e4fa4a449af604c82eaed`.
- Corrected Application Head `633b41f347083c82028229d6e238842118635feb` passed all five Required Checks. Workflow Run `32147032173` issued Artifact ID `9328364646` from that exact source commit.
- Artifact outer/GitHub SHA-256 is `a4e7fde91c4148971723778b847d0f1a43d4b58b3716fb1c9f4b1eceeb06818c`; Manifest SHA-256 is `556eaf59e9c5128cb9b93cf9000a5aee3ff4eb56f86ee8bc549c392d55bd77fe`.
- Package SHA-256 values are Client `28a7b3558329eed9c608f828948befe2034e86c0add1511bd48db1ed437f58d9`, Site Schema `b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2`, Testkit `dc0bf6c16af439bf5a364955e8add936e8842096ca295a136a0f15a86e4102b0`, and Public OpenAPI `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`.
- Storefront handoff is fixed to the three `2.0.0-alpha.23` tarballs, `public.openapi.json`, `artifact-manifest.json`, and `SHA256SUMS`. Registry publish and direct Storefront Repository installation were not performed.

## Focused Verification

- Isolated PostgreSQL/PHP 8.4: Admin Campaign and Public Point Product contract 8 tests / 103 assertions PASS, including list/create/update, ON/OFF, start/end/amount validation, overlap, exact Plan Version separation, active/upcoming/inactive, exact start/end boundaries, Backend state/as_of, and unchanged grant fields.
- Admin generated contract check, lint, typecheck, Production build, and 161 tests: PASS.
- Storefront Client generated contract check, lint, typecheck, build, and 27 tests: PASS.
- Site Schema generated type check, lint, typecheck, build, and 10 tests: PASS.
- Storefront Testkit generated contract check, lint, typecheck, build, and 34 tests: PASS.
- OpenAPI bundle/check and 7 tests, Release unit 10 tests/source validation, Policy 135 tests/local Gate, Local Quality Gate, PHP syntax, secret/path/binary review, and `git diff --check`: PASS.
- GitHub Required Checks on corrected Application Head: `policy-gate`, `quality-gate`, `security-gate`, `integration-gate`, and `ci-gate` PASS. Initial Policy rejected non-bulleted Allowed Paths. A later PR-native Quality run rejected required `limited_bonus` as breaking; the field was made optional additive, generated types were synchronized, and the already-issued alpha.22 artifact was retired from handoff without overwrite.

## Scope Impact

- Migration created: 0. Task/Preview/Production migration applied: 0. Only the isolated synthetic test database fresh-applied the existing 55 V2 migrations.
- No Payment Snapshot, `provider_occurred_at`, single Grant, Expiry, Refund, Chargeback, financial Concurrency, Draw, Inventory, Provider, Runtime, Nginx, Preview, Production, or Storefront Repository change.
- No Browser/E2E/visual verification, Repository-wide Backend/full suite, or all-repository build was run because each is explicitly out of scope. GitHub Integration Gate independently passed its configured integration suite.
- Rollback is the squash commit revert plus reverting consumers to the previous fixed `2.0.0-alpha.21` contract; the immutable `2.0.0-alpha.22` artifact and prior artifacts remain retained and are never overwritten.
- Final docs-only Head Required Checks, Fresh Self-review, squash merge, Issue/branch/worktree cleanup, lock release, and local/Remote main synchronization remain pending.
