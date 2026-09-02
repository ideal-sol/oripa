# Security Dependency Remediation — league/commonmark

## Result

`HOLD`

The scoped `league/commonmark` remediation is complete and locally verified, but the canonical repository `security-gate` now detects two independent high-severity `browserslist` advisories in `legacy/v1-frontend/pnpm-lock.yaml`. The Task scope explicitly prohibits absorbing a separate root dependency remediation, so SEC-014 must not merge until that independent finding is resolved on protected `main` by a separate Change.

## Advisory

Before the update, `apps/api/composer.lock` fixed `league/commonmark` at `2.9.0` and `composer audit --locked --format=json` reported four high-severity advisories:

| Packagist ID | GitHub ID | Affected versions | Fixed versions | Published |
| --- | --- | --- | --- | --- |
| `PKSA-zyf5-hrxv-hrd7` | `GHSA-8rr7-cvq3-gmfh` | `>=1.5.0,<2.10.0` | `>=2.10.0` | `2026-09-01T20:28:40+00:00` |
| `PKSA-nv44-1b4d-6gjg` | `GHSA-jjv6-8j6v-6j52` | `>=1.5.0,<2.9.1` | `>=2.9.1` | `2026-09-01T20:21:45+00:00` |
| `PKSA-kr3s-894t-g5w2` | `GHSA-f8fg-pg57-v4j8` | `>=2.7.0,<2.9.1` | `>=2.9.1` | `2026-09-01T20:18:29+00:00` |
| `PKSA-9q1p-3s19-bp1q` | `GHSA-j8pm-gj4c-rq4x` | `>=0.6.0,<2.9.1` | `>=2.9.1` | `2026-09-01T20:17:59+00:00` |

All four are naturally resolved by the minimum common safe version `2.10.0`. The advisory authority is Packagist's Composer audit feed with the linked GitHub Security Advisories. No advisory was ignored, downgraded, or added to a baseline.

## Dependency

- Previous version: `league/commonmark 2.9.0`
- Final scoped version: `league/commonmark 2.10.0`
- Authority: transitive dependency
- Parent: `laravel/framework v13.15.0`
- Parent constraint: `league/commonmark ^2.8.1`
- Application constraint: `laravel/framework ^12.0 || ^13.0`
- Compatibility: `composer prohibits --locked league/commonmark 2.10.0` reported no blocker, and PHP 8.4 frozen install/platform checks passed.
- Direct source use: no direct `league/commonmark`, `CommonMarkConverter`, `Str::markdown`, or `Markdown::` use was found under `apps/api/app` or `apps/api/tests`.

## Lockfile

The targeted command was:

`composer update league/commonmark --with-dependencies --minimal-changes --no-install --no-scripts --no-interaction --no-progress`

- Changed package: `league/commonmark 2.9.0 -> 2.10.0`
- Changed transitive packages: `0`
- Unrelated dependency updates: `0`
- `composer.json` mutation: `0`
- Lock diff: `12` lines (`6` insertions, `6` deletions)
- Frozen install: PASS
- `composer validate --strict`: PASS
- `composer check-platform-reqs --lock`: PASS on PHP `8.4.23`

## Security

- Composer advisories before: `4 high`, `0 critical`
- Composer advisories after: `0`
- Workspace pnpm advisories after: `0`
- Ignore / baseline additions: `0`
- Security workflow changes or bypass: `0`
- Canonical local `security-gate`: FAIL with `pnpm advisory baseline mismatch`

The independent current findings are `browserslist 4.28.2` through the legacy frontend development dependency chain. The audit reports `GHSA-c83g-rgw3-j3cx` and `GHSA-73wf-gq98-2v4g`, both high severity and fixed in `browserslist >=4.28.7`. SEC-014 does not change `legacy/v1-frontend/pnpm-lock.yaml`, the empty advisory baseline, or any pnpm dependency.

## Regression

- CommonMark/Laravel smoke: PASS; installed version `2.10.0`, Markdown heading rendered, and an unsafe JavaScript link was not emitted.
- Focused V2 Rich Text, Content, Mail Template, Page, Notice, sanitizer/render tests: `26 passed`, `231 assertions`.
- Full `tests/Feature` API suite: `286 passed`, `1,596 assertions`.
- Policy unit tests: `203 passed`.
- Security unit tests: `10 passed`.
- Quality unit tests: `4 passed`.
- `git diff --check`: PASS.
- Full local `quality_gate.py`: not accepted as PASS because the host lacks PHP; GitHub CI remains authoritative.

Two earlier focused-test attempts failed before meaningful assertions because the isolated PostgreSQL database name did not match the repository's forced PHPUnit database name. The same unchanged tests passed after correcting only the ephemeral test database setup.

## Migration Guard

- Before count: `70`
- After count: `70`
- Before content-set SHA-256: `8bc21870df410bf08b8753aed102cba06351e95c3ae2ab1a8e945d99f8206861`
- After content-set SHA-256: `8bc21870df410bf08b8753aed102cba06351e95c3ae2ab1a8e945d99f8206861`
- Migration file mutation: `0`
- Migration application to Shared Preview / Production: `0`

## Governance

- Issue: `#445`
- Risk: `R4`
- Lane: `Strict Change`
- Application Runtime Activation: `none`
- Branch: `security/SEC-014-commonmark-advisory-remediation`
- Worktree: `/var/www/oripa-worktrees/SEC-014`
- Base SHA: `94c92f000997049eca65c197c99e6fb8bdde1416`
- Task Policy SHA-256: `6ecdaf8247cd1b21823ac39e960f240e34e9e3f0b2dc1cc3af19d594a6670815`
- Required checks: not all PASS; `security-gate` is expected to fail on the independent legacy pnpm findings.
- Fresh self-review: not issued because merge acceptance is blocked before exact-head review.
- Merge: not performed.

## Production Mutation

- RDS / Migration / Seeder / Manual SQL: `0`
- API / Admin / Redis / Storefront activation: `0`
- Nginx / AWS mutation: `0`
- Secret mutation or exposure: `0`

## OPS-028

- PR `#444`: untouched
- Branch `fix/OPS-028-api-up-health-route`: preserved at `584fc0ebea65c28fee8f1063a6584f637c99511c`
- Worktree `/var/www/oripa-worktrees/OPS-028`: preserved and clean

## Rollback

Revert the SEC-014 lockfile commit if the dependency candidate must be abandoned. No database, contract, runtime, or production rollback is required. SEC-014 must remain unmerged while the independent legacy pnpm advisories block the canonical security gate.
