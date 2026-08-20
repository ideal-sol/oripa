# GOV-016 Report

## Task

- Issue: #332 `https://github.com/ideal-sol/oripa/issues/332`
- Risk: R4
- Base SHA: `906a5ae5189dd0b895afcf371ab074783860e2d1`
- Branch: `chore/GOV-016-package-only-artifact-governance`
- Worktree: `/var/www/oripa-worktrees/GOV-016`
- Task Policy SHA-256: `2f4e4b99fc824b7c5b4f4fced3e2097aa623f13b3aac983b84ea60bc4ca4396a`
- Locks: Platform Integration and Artifact Release held; Migration Allocation and Preview Deployment not acquired.

## Phase A Decision

Option A is adopted. The finalized Package Version Compatibility Policy requires
Core Family major alignment, but explicitly permits component Minor/Patch
versions and OpenAPI documents to advance independently. Its release manifest
example records Platform, each contract, and each package version separately.

The current alpha.23 monoversion requirement came from the initial Platform
artifact builder, hard-coded Policy Gate identities, and inline workflow
packaging. It was not a consumer-contract invariant.

## Immutable Evidence

- Latest bundle: `2.0.0-alpha.23`
- Manifest SHA-256: `556eaf59e9c5128cb9b93cf9000a5aee3ff4eb56f86ee8bc549c392d55bd77fe`
- Source: `633b41f347083c82028229d6e238842118635feb`
- Client SHA-256: `28a7b3558329eed9c608f828948befe2034e86c0add1511bd48db1ed437f58d9`
- Site Schema SHA-256: `b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2`
- Testkit SHA-256: `dc0bf6c16af439bf5a364955e8add936e8842096ca295a136a0f15a86e4102b0`
- Public OpenAPI SHA-256: `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`

Alpha.21, retired alpha.22, and alpha.23 are immutable history. The next
candidate must be exactly alpha.24. Existing or lower bundle/package versions
cannot be published.

## Release Model

- Bundle: `2.0.0-alpha.24`
- Published: Storefront Client `2.0.0-alpha.24`; Storefront Testkit `2.0.0-alpha.24`
- Referenced, not repacked: Site Schema `2.0.0-alpha.23`
- Platform/Application: `2.0.0-alpha.23`
- Public/Admin/Webhook OpenAPI: `2.0.0-alpha.23`
- Site Manifest schema version: unchanged `2.0.0-alpha.1`

The validator requires exact next-alpha progression, a fixed published set,
published version equal to bundle version, immutable reference version/digest/
source tree, exact compatibility metadata, and exact artifact/checksum
inventory. Full Platform artifact build still requires monoversion.

## Verification

- Frozen pnpm install: PASS.
- Client generate/typecheck/lint/build and 27 tests: PASS.
- Site Schema generate/typecheck/lint/build and 10 tests: PASS.
- Testkit generate/typecheck/lint/build, 34 tests, exports, network guard: PASS.
- Release Unit 19 tests: PASS.
- Policy Unit 145 tests: PASS.
- Security Unit 10 tests: PASS.
- Local Policy, Quality, and Security Gates: PASS.
- Composer, workspace pnpm, and legacy pnpm fresh audits: 0 findings.
- Positive package-only source/manifest validation: PASS.
- Negative arbitrary mismatch, immutable version reissue, alpha.23 reference
  repackage, digest mismatch, file inventory, and full Platform mixed-version
  regressions: PASS.
- Python compile and `git diff --check`: PASS.
- GitHub Required 5 and fresh fixed-head self-review: pending final head.

## Scope

- Runtime deployment: 0.
- Artifact publication: 0.
- Migration created/applied: 0/0.
- Database, Payment, Point, Draw, inventory changes: 0.
- Admin/API behavior and OpenAPI/Site Schema content changes: 0.
- STORE-SITE-034 Issue #324, PR #326, branch, worktree, and exact head: unchanged.

## Resume Boundary

After GOV-016 merges, STORE-SITE-034 must synchronize with latest `main`, use
bundle and published package version `2.0.0-alpha.24`, rerun Required 5 and a
fresh fixed-head self-review, acquire Artifact Release Lock, and invoke the
canonical workflow. It must not reuse alpha.23 or add a local validator bypass.
