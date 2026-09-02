# SEC-016 — Superseding Repository Security Advisory Remediation

## Result

`PENDING REQUIRED CHECKS`

The combined dependency candidate is scope-complete and locally verified. Merge acceptance remains pending until every Required and available GitHub check passes on the exact final head and a fresh Strict self-review records zero SEV-0 and SEV-1 findings.

## Base Authority

- Starting local, `origin`, and live protected main: `94c92f000997049eca65c197c99e6fb8bdde1416`
- Protected branch: `main`, protected `true`
- Migration state: `70` files through `000070`
- Migration content-set SHA-256: `8bc21870df410bf08b8753aed102cba06351e95c3ae2ab1a8e945d99f8206861`
- Dependency conflict with protected main: `no`
- Base manifests and authorities inspected: `apps/api/composer.json`, `apps/api/composer.lock`, root `package.json`, root `pnpm-lock.yaml`, `legacy/v1-frontend/package.json`, and `legacy/v1-frontend/pnpm-lock.yaml`

## CommonMark

- Before: `league/commonmark 2.9.0`
- After: `league/commonmark 2.10.0`
- Before advisories: `4 High`, `0 Critical`
- After advisories: SEC-014 canonical Composer audit reported `0`; SEC-016 final authoritative audit is Required CI
- Resolved GitHub advisories: `GHSA-8rr7-cvq3-gmfh`, `GHSA-jjv6-8j6v-6j52`, `GHSA-f8fg-pg57-v4j8`, and `GHSA-j8pm-gj4c-rq4x`
- Composer files changed: `apps/api/composer.lock` only
- `apps/api/composer.json` changed: `no`
- Lock diff: `6` insertions / `6` deletions
- Unrelated Composer package updates: `0`
- Laravel remains `13.15.0`; the transitive parent constraint `league/commonmark ^2.8.1` remains compatible
- SEC-016 lock is byte-identical to the validated SEC-014 lock

## Browserslist

- Before: legacy `browserslist 4.28.2`
- After: legacy `browserslist 4.28.7`
- Before advisories: `2 High`, `0 Critical`
- After advisories: `0 High`, `0 Critical`
- Resolved GitHub advisories: `GHSA-c83g-rgw3-j3cx` and `GHSA-73wf-gq98-2v4g`
- pnpm files changed: `legacy/v1-frontend/pnpm-lock.yaml` only
- Package manifests and root `pnpm-lock.yaml` changed: `no`
- Minimal resolver closure: `baseline-browser-mapping 2.10.44`, `caniuse-lite 1.0.30001806`, `electron-to-chromium 1.5.393`, and `node-releases 2.0.51`
- `update-browserslist-db 1.2.3` version remains unchanged; only its peer snapshot binding follows Browserslist 4.28.7
- Lock diff: `25` insertions / `25` deletions
- Next.js, React, package manager, and unrelated updates: `0`
- SEC-016 lock is byte-identical to the validated SEC-015 lock

## Repository Security

- Composer High / Critical: pending canonical exact-head CI; the identical SEC-014 CommonMark lock audited `0 / 0`
- Root pnpm High / Critical: `0 / 0`
- Legacy pnpm High / Critical: `0 / 0`
- Repository aggregate: pending canonical exact-head CI
- Dependency advisory baseline changes: `0`
- Ignore, bypass, severity, or workflow changes: `0`
- New independent advisory observed locally: `no`

## Regression

- SEC-014 CommonMark smoke and focused Rich Text, Content, Mail Template, Page, Notice, sanitize/render evidence: `26 tests / 231 assertions PASS`
- SEC-014 full Feature/API evidence: `286 tests / 1,596 assertions PASS`
- SEC-016 exact-head CommonMark/API authority: pending Required `integration-gate`; local host has no PHP/Composer and the Docker daemon remains stopped
- Browserslist package resolution and default target CLI: `PASS`, exact installed version `4.28.7`
- Legacy frozen install: `PASS`
- Legacy typecheck: `PASS`
- Legacy Next.js 16.2.11 production build: `PASS`, `24` routes
- Root frozen install: `PASS`
- Admin Vitest: `36` files / `201` tests `PASS`
- Admin typecheck / lint / Next.js 16.2.11 production build: `PASS / PASS / PASS`
- Security Unit / Quality Unit / Policy Unit: `10 / 4 / 203 PASS`
- Local Policy Gate: `PASS`, `1,637` tracked files
- Release source validation: `PASS`, migration count and content-set exact
- Browser E2E and visual verification: not run; lockfile-only change with no UI source mutation

## Migration Guard

- Before / after count: `70 / 70`
- Before / after content-set SHA-256: `8bc21870df410bf08b8753aed102cba06351e95c3ae2ab1a8e945d99f8206861` / same
- Migration creation / modification / Shared Preview or Production application: `0 / 0 / 0`

## Governance

- Issue: `#449`
- Risk / Lane / Activation: `R4 / Strict Change / none`
- Branch: `security/SEC-016-superseding-advisory-remediation`
- Dedicated worktree: `/var/www/oripa-worktrees/SEC-016`
- Base SHA: `94c92f000997049eca65c197c99e6fb8bdde1416`
- Task Policy SHA-256: `1d9094e7d77ffa11a3aa11c898c734ad25b4b4f130ac03f11934cfed90a6d4c0`
- Final Head, PR, Required Checks, fresh self-review, and merge SHA: pending

## Superseded Changes

- PR `#446` and Issue `#445`: preserve until SEC-016 merge, then close without merge / as superseded
- PR `#448` and Issue `#447`: preserve until SEC-016 merge, then close without merge / as superseded

## OPS-028

- PR `#444`: untouched
- Branch / worktree: preserved at `584fc0ebea65c28fee8f1063a6584f637c99511c`, clean
- `apps/api/bootstrap/app.php` and `apps/api/tests/Feature/HealthTest.php`: unchanged by SEC-016

## Production Mutation

- RDS / SQL / Migration / Seeder: `0`
- API / Admin / Redis / Storefront Activation: `0`
- Nginx / AWS / Secret: `0`
- Stage 6A resume: `0`

## Rollback

Revert the SEC-016 squash commit to restore the two prior lockfile resolutions. No database, runtime, contract, provider, infrastructure, or Production rollback is required.
