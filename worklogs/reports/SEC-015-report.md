# SEC-015 — Browserslist Security Advisory Remediation

## Result

- `HOLD`: Browserslist remediation is complete and minimal, but the protected-main base still contains the four `league/commonmark` High advisories owned by unmerged SEC-014. The canonical repository `security-gate` therefore fails closed with `Composer advisory baseline mismatch`.
- This Change does not carry SEC-014 into SEC-015, alter the advisory baseline, bypass a gate, or expand dependency scope.

## Governance

- Issue: `#447`
- Branch: `security/SEC-015-browserslist-advisory-remediation`
- Worktree: dedicated `/var/www/oripa-worktrees/SEC-015`
- Base SHA: `94c92f000997049eca65c197c99e6fb8bdde1416`
- Risk / Lane / Activation: `R4` / `Strict Change` / `none`
- Task Policy: exact three paths, root-owned mode `0600`, SHA-256 `5fb6286284179242ff48e888538130bb7faaef5105a5f7191199731c529f148a`
- PR / Final Head / Merge SHA: pending / not fixed / not merged
- Required Checks and formal fresh exact-head self-review are withheld because the independent Composer blocker prevents acceptance. No bypass or merge is attempted.

## Advisory Authority

- `GHSA-c83g-rgw3-j3cx` / `CVE-2026-73089`: High, CVSS 7.5, affected `<=4.28.6`, patched `>=4.28.7`, published `2026-09-01T16:42:13Z`.
- `GHSA-73wf-gq98-2v4g` / `CVE-2026-73088`: High, CVSS 7.5, affected `<=4.28.6`, patched `>=4.28.7`, published `2026-09-01T16:41:54Z`.
- Canonical source: pnpm audit data sourced from the GitHub Advisory Database. The pre-change lock resolved `browserslist 4.28.2`; both findings recommended `4.28.7` or later.
- Before: legacy pnpm High 2 / Critical 0. After: legacy pnpm High 0 / Critical 0, with `browserslist 4.28.7`.

## Dependency Authority

- `browserslist` is transitive, not a direct package constraint.
- Exact path: `legacy/v1-frontend` -> `eslint-config-next 16.2.11` -> `eslint-plugin-react-hooks 7.1.1` -> `@babel/core 7.29.7` -> `@babel/helper-compilation-targets 7.29.7` -> `browserslist`.
- Authority is the standalone `legacy/v1-frontend/pnpm-lock.yaml`. The legacy package is excluded from the root `pnpm-workspace.yaml`; root authority remains `apps/admin` and `packages/*`.
- `legacy/v1-frontend/package.json` has no Browserslist constraint and is unchanged. No package manifest, parent dependency, Next.js, React, or package-manager version changes are required.

## Files And Lockfile

- Changed: `legacy/v1-frontend/pnpm-lock.yaml` only for the implementation.
- Evidence: this report and `worklogs/new_ver_main.md`.
- Package manifests changed: 0. Root `pnpm-lock.yaml` changed: 0. Composer files changed: 0.
- Resolution changes: `browserslist 4.28.2 -> 4.28.7`, `baseline-browser-mapping 2.10.35 -> 2.10.44`, `caniuse-lite 1.0.30001797 -> 1.0.30001806`, `electron-to-chromium 1.5.371 -> 1.5.393`, and `node-releases 2.0.47 -> 2.0.51`.
- `update-browserslist-db` remains `1.2.3`; only its peer snapshot binding changes from Browserslist `4.28.2` to `4.28.7`. The Babel helper reference moves to the same fixed resolution.
- These are the pnpm-generated minimum versions satisfying Browserslist 4.28.7's published dependency ranges. A naive targeted `pnpm update` probe caused broad manifest/lock churn and was rejected; none of that probe is retained.
- Final implementation diff is 25 insertions / 25 deletions. Unrelated package upgrades: 0. `git diff --check`: PASS.
- Frozen install with repository Node `22.22.3` and pnpm `10.12.1`: PASS for the standalone legacy lock and root workspace lock.

## Security

- Before: legacy Browserslist advisories 2 High / 0 Critical; root workspace pnpm findings 0; protected-main Composer findings 4 High / 0 Critical.
- After: legacy Browserslist advisories 0; root workspace pnpm findings 0; protected-main Composer findings remain 4 High / 0 Critical because SEC-014 is intentionally not included.
- Final canonical repository gate result: `security-gate: FAIL: Composer advisory baseline mismatch`.
- Advisory baseline, ignore, exception, severity, workflow, security assertion, and bypass mutations: 0.
- Repository-wide High cannot be claimed as zero until the independent SEC-014 dependency change and SEC-015 ordering are resolved on protected main.

## Regression

- Standalone legacy frozen install: PASS.
- Browserslist 4.28.7 direct resolution and default browser-target CLI evaluation: PASS.
- Legacy TypeScript typecheck: PASS.
- Legacy Next.js 16.2.11 production build: PASS, 24 routes generated.
- Root workspace frozen install: PASS.
- Admin Vitest: PASS, 36 files / 201 tests.
- Admin typecheck: PASS. Admin lint: PASS. Admin Next.js 16.2.11 production build: PASS.
- Security CI unit: 10 PASS. Policy CI unit: 203 PASS. Quality CI unit: 4 PASS. Release source validation: PASS. `git diff --check`: PASS.
- Browser E2E and visual verification were not run because this is a lockfile-only build-tool remediation; no UI source changed.

## Isolation And Migration Guard

- `apps/api/composer.json` and `apps/api/composer.lock`: unchanged from base. SEC-014 PR `#446`, branch, worktree, and head `c6725ac919a4f57f498c47678760ec1314a35dee`: untouched and preserved.
- OPS-028 PR `#444`, branch, worktree, `apps/api/bootstrap/app.php`, `apps/api/tests/Feature/HealthTest.php`, and head `584fc0ebea65c28fee8f1063a6584f637c99511c`: untouched and preserved.
- Migration count before / after: 70 / 70.
- Migration content-set SHA-256 before / after: `8bc21870df410bf08b8753aed102cba06351e95c3ae2ab1a8e945d99f8206861` / same.
- Migration creation / modification / application: 0 / 0 / 0.

## Production Mutation

- RDS / SQL / Migration / Seeder: 0.
- API / Admin / Redis / Storefront Activation: 0.
- Nginx / AWS: 0.
- Secret access, mutation, or exposure: 0.
- Stage 6A was not resumed.

## Hold Resolution

- Human sequencing decision is required. On the current protected-main base, SEC-015 cannot pass the Required `security-gate` without either including the forbidden SEC-014 Composer change or weakening the forbidden advisory baseline.
- Keep SEC-015 draft and unmerged. Do not resume SEC-014 or OPS-028 inside this Change.
