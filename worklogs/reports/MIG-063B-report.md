# MIG-063B Limited Bonus Admin / Public Contract / Artifact

## Task

- Issue: #299 `https://github.com/ideal-sol/oripa/issues/299`
- PR: #300 `https://github.com/ideal-sol/oripa/pull/300`
- Risk: R3
- Base SHA: `f2ecce33f8552e89dcda95fc0ac531d832a1c29e`
- Branch: `feat/MIG-063B-limited-bonus-admin-public-contract`
- Worktree: `/var/www/oripa-worktrees/MIG-063B`
- Task Policy SHA-256: `954576ffd175b2268110b71e35cf92887e2aa2ca39145c6e2f557671d0b858d9`
- Artifact Version: `2.0.0-alpha.22`
- Artifact Release Lock: held through immutable Artifact readback. Integration／Migration Allocation／Preview Lock: not acquired.

## Implementation

- Admin exposes list/create/update for Limited Bonus Campaigns under an exact Point Purchase Plan public ID/version. Mutations support ON/OFF, start, end, and additional Bonus Coin amount, require existing plan permissions/fresh MFA, and preserve idempotency and audit behavior.
- The Admin adapter delegates semantic validation, overlap exclusion, locking, and transactions to C2a `V2LimitedBonusCampaignService`; it maps existing Domain errors to stable 422/409 Problem codes without reimplementing Domain Core rules.
- Admin-facing Limited Bonus wording is `期間限定ボーナスコイン`; internal point-domain names remain unchanged.
- Public Point Product additively returns `limited_bonus.amount`, `starts_at`, `ends_at`, Backend UTC `as_of`, canonical `active`/`upcoming`/`inactive` state, and presentation. Start is inclusive and end is exclusive. Existing paid/bonus/total grant fields are unchanged.
- Public selection and presentation are computed once per response by Backend. Storefront Client receives generated types only and does not calculate campaign time windows or Bonus stacking.

## Contract And Artifact

- Root/Admin/Platform/Client/Site Schema/Testkit/OpenAPI/compatibility/policy/release source are synchronized to `2.0.0-alpha.22`; existing `2.0.0-alpha.21` was not overwritten.
- OpenAPI source/bundles expose Admin 215, Public 54, and Webhook 1 operations. Release validation reports Public SHA-256 `ba00b46d34d0889bc883c86551c85ea2322d4f354723c3a2a68636e27cf5374a`, Admin `c4cc7dbe3a18c50e6b93870f969e9901d2775f42e6421d8f5b2822c86f87edc9`, Webhook `a50fd84bbcf7a31623f9c399f14011d30e7bd5ec49c37598187109bc2f4c4470`, and unchanged 55-migration set `7c6887b2547e52f092f4b45af559395277b396511a7e4fa4a449af604c82eaed`.
- Application Head `1ee95268b145713e5df31dfe7f4b1c8158df7414` passed all five Required Checks. Workflow Run `32141593541` issued Artifact ID `9326245788` from that exact source commit.
- Artifact outer/GitHub SHA-256 is `e8b5598ce0eacc0bef032dbca141e91da9d110db816f916813218083b209087d`; Manifest SHA-256 is `fcda62708350fab1249bdb0b6ab2fab8440ca89b2681edf8d587e60c27027d9c`.
- Package SHA-256 values are Client `d642a64afff5b310b4997ed63fb1fad3780eeb6b1e6b5dfef49f32dfb20b0c42`, Site Schema `94a4c2032a0ffd95b7931a8628031935e5f6b463703d5f7339e5ebe35bf4d6a7`, Testkit `0d19f288c5fe74722585a7896998df722946953f3b22d0f49559d22a8d41ca6c`, and Public OpenAPI `ba00b46d34d0889bc883c86551c85ea2322d4f354723c3a2a68636e27cf5374a`.
- Storefront handoff is fixed to the three `2.0.0-alpha.22` tarballs, `public.openapi.json`, `artifact-manifest.json`, and `SHA256SUMS`. Registry publish and direct Storefront Repository installation were not performed.

## Focused Verification

- Isolated PostgreSQL/PHP 8.4: Admin Campaign and Public Point Product contract 8 tests / 103 assertions PASS, including list/create/update, ON/OFF, start/end/amount validation, overlap, exact Plan Version separation, active/upcoming/inactive, exact start/end boundaries, Backend state/as_of, and unchanged grant fields.
- Admin generated contract check, lint, typecheck, Production build, and 161 tests: PASS.
- Storefront Client generated contract check, lint, typecheck, build, and 27 tests: PASS.
- Site Schema generated type check, lint, typecheck, build, and 10 tests: PASS.
- Storefront Testkit generated contract check, lint, typecheck, build, and 34 tests: PASS.
- OpenAPI bundle/check and 7 tests, Release unit 10 tests/source validation, Policy 135 tests/local Gate, Local Quality Gate, PHP syntax, secret/path/binary review, and `git diff --check`: PASS.
- GitHub Required Checks on Application Head: `policy-gate`, `quality-gate`, `security-gate`, `integration-gate`, and `ci-gate` PASS. The initial Policy failure was only the non-bulleted PR Allowed Paths section; it was corrected without changing the source head.

## Scope Impact

- Migration created: 0. Task/Preview/Production migration applied: 0. Only the isolated synthetic test database fresh-applied the existing 55 V2 migrations.
- No Payment Snapshot, `provider_occurred_at`, single Grant, Expiry, Refund, Chargeback, financial Concurrency, Draw, Inventory, Provider, Runtime, Nginx, Preview, Production, or Storefront Repository change.
- No Browser/E2E/visual verification, Repository-wide Backend/full suite, or all-repository build was run because each is explicitly out of scope. GitHub Integration Gate independently passed its configured integration suite.
- Rollback is the squash commit revert plus reverting consumers to the previous fixed `2.0.0-alpha.21` contract; the immutable `2.0.0-alpha.22` artifact and prior artifacts remain retained and are never overwritten.
- Final docs-only Head Required Checks, Fresh Self-review, squash merge, Issue/branch/worktree cleanup, lock release, and local/Remote main synchronization remain pending.
