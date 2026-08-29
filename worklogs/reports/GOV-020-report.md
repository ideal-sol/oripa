# GOV-020 Report

## Task

- Task: `GOV-020 — Contract-only Artifact Publication from Exact Merged Main`
- Issue: `#417`
- Pull request: `#418`
- Lane: `plat-contract / governance`
- Change: `Strict Change`
- Risk: `R4`
- Activation: `none`
- Base: `59e776922b30a6a4f3dce4c9135f81a12649b728`
- Branch: `chore/GOV-020-contract-only-artifact-publication`
- Worktree: `/var/www/oripa-worktrees/GOV-020`

## Stage 0

- Clean local `main`, `origin/main`, public remote `main`, and GitHub protected
  `main` were equal at the Base immediately before Policy issuance.
- `GOV-020` was unused in GitHub Issue/PR search, repository worklogs/reports,
  deployment/evidence records, local/remote/GitHub branches, worktrees, and the
  Task Policy directory.
- The root-owned Task Policy mode is `0600`, SHA-256 is
  `38e3f4f38a663caa2eb59faeecebd1728d01e5b100b6b595a43e25f601744db7`,
  and its scope is the Human-approved exact 13 paths.
- MIG-098 remained open and frozen in its dedicated clean worktree at checkpoint
  `231a9a0b7bcb3ba450bf72b0669adca3c550ad2f`; uncommitted and untracked changes
  were zero. The only overlap was the two committed Policy Gate paths.
- Platform Integration Lock was free and is held by GOV-020 for its source
  lifecycle. Migration Allocation Lock `000067` remains held by MIG-098 and is
  not acquired, changed, or released by GOV-020.
- Latest immutable Storefront Artifact was `2.0.0-alpha.30`, candidate was
  `null`, and root free disk was 26 GiB.

## Implementation

- Dedicated workflow: `.github/workflows/storefront-contract-artifact-publish.yml`.
- Source authority: exact current GitHub protected `main` head, confirmed by the
  Human-provided expected squash SHA, workflow event SHA, checkout `HEAD`, one
  exact merged internal PR, identical reviewed/merged trees, and Required 5.
- Contract-only responsibility: API image build/push/Activation, Admin build,
  Storefront application build, Migration, and runtime mutation are all zero.
- Immutability: next-candidate validation, repository-wide serialized runs,
  version-only Artifact identity, remote duplicate detection including expired
  Artifacts, and `overwrite: false`.
- Readback: exact uploaded Artifact ID/run/name/digest, outer ZIP SHA-256, safe
  five-file extraction, existing bundle validator, Manifest Source/version,
  Client/Testkit/Public OpenAPI, Manifest, and `SHA256SUMS` digests.
- Preview image workflow retains normal and `api-only` image behavior and no
  longer contains Storefront contract publication steps.
- Release Ledger reconciliation is a separate post-publication metadata Task and
  PR. The workflow never commits to `main` and GOV-020 does not change the ledger.

## Verification And Closeout

Executed and passed on the task source:

- Publication authority/readback unit: 11 tests.
- Full release unit: 36 tests.
- Preview image pipeline and runbook regression: 26 tests.
- Policy unit: 179 tests.
- Local Policy Gate: PASS across 1,603 tracked files.
- Platform release source validator: PASS with 66 unchanged migrations.
- Changed Python compile, both workflow YAML parses, and staged diff check: PASS.

The first publication unit run failed seven tests because test API mocks did not
accept the token argument and the readback helper called a nonexistent wrapper
name instead of the existing `verify_manifest`. Both test harness and production
call were corrected before the 11/11 and full 36/36 PASS reruns. Ruby YAML parse
and `actionlint` were unavailable locally; PyYAML parse passed and GitHub workflow
validation remains part of Required Checks.

Final-head Required Checks, fresh self-review, squash merge, and cleanup are
pending. GOV-020 publishes no MIG-098 Artifact, reserves no Artifact version,
creates or applies no Migration, and performs no publication or Runtime Build or
Activation.

Pre-final source head `3a19d42d31ac557ee765cd7c7e87c322ad802b0d`
passed all five GitHub Required Checks: `policy-gate`, `quality-gate`,
`security-gate`, `integration-gate`, and `ci-gate`. The first automatic PR run
failed at lane resolution because the PR body used descriptive Change/Activation
labels instead of the exact machine-readable `Lane`, `Application Runtime
Activation`, and `UI Verification` labels. The body was corrected without a
source-head change; one governed Strict workflow dispatch rerun then passed all
five checks. This evidence is pre-final because recording it changes the report
and requires a new exact-head run.

No contract publication workflow was dispatched. API image Build, API push,
API Activation, Admin/Storefront runtime Build, Artifact publication, Migration,
and runtime mutation counts remain zero. Standard Required Check source builds
ran once on the pre-final head and are verification only.
