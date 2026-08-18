# SEC-011 Dependency Advisory Baseline Fresh Security Review

## Task

- Task ID: SEC-011
- Issue: #293
- Risk: R4
- Base SHA: `1bd2cb5015ca50b1798bca4a83f9d6d5125e5dc6`
- Branch: `security/SEC-011-dependency-advisory-baseline-review`
- Worktree: `/var/www/oripa-worktrees/SEC-011`
- Integration Lock: acquired for the Task; Migration Allocation, Artifact, and Preview Locks are not acquired.

## Scope and Result

- Scope is limited to the expired empty `.ci/baselines/dependency-advisories.json` management metadata and required Task Worklogs.
- Fresh canonical audit results are zero findings: Composer 0, Root pnpm/V2 workspace 0, and Legacy pnpm 0.
- Since no advisory exists, no dependency, lockfile, gate, audit behavior, baseline array, ignore, or remediation was changed.
- Management metadata now records SEC-011, the fresh review reason, the unchanged empty-array removal condition, and bounded expiry `2026-08-25`.

## Required Safety

- The pre-update Security Gate fails closed only because the prior metadata expired on `2026-08-17`.
- Any future Composer, Root pnpm/V2 workspace, or Legacy pnpm finding requires remediation or a separately reviewed exact-fingerprint security task; date-only extension is not permitted.
- Application Domain, migration, Public Contract, Artifact, Storefront, Preview, Production, and Migration Allocation are unchanged.

## Verification

- Canonical Composer audit: 0 findings.
- Canonical Root pnpm/V2 workspace audit: 0 findings.
- Canonical Legacy pnpm audit: 0 findings.
- Security Unit 10 tests, local Security Gate, Policy Gate, Quality Gate, and `git diff --check` PASS. Exact-head GitHub checks are recorded during closeout.
- Local full test suite and full build are not run; the GitHub Integration Gate remains the required broad verification.
