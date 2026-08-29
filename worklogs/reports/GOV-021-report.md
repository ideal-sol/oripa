# GOV-021 Storefront Contract Release Ledger Reconciliation Report

## Task Governance

- Task: `GOV-021`
- Issue: `#420`
- Platform lane: `plat-contract / governance`
- Change lane: `Strict Change`
- Risk: `R2`
- Activation: `none`
- Base: `ad078ecd1eebd68cd2443b347d387433177fd686`
- Task Policy SHA-256: `f86d35f64eb45c1c2802659bc753985416548c2f77616186319747e0182b18b6`
- Allowed paths: the Release Ledger, its standard Artifact test, the exact
  Policy Unit that fixes the former pending alpha.31 expectation, canonical
  Storefront Artifact runbook, this report, and `worklogs/new_ver_main.md` only.

## Stage 0

- Local `main`, `origin/main`, and protected GitHub `main` were clean and equal
  at the exact MIG-098 squash commit. Root free space was 26 GiB.
- `GOV-021` was unused across GitHub Issue/PR search, Repository records,
  branches, worktrees, Task Policies, and root-only evidence before Issue
  creation.
- MIG-098 Issue `#416` was closed. Its live GitHub branch, local branch,
  worktree, and Task Policy were cleaned up; one stale remote-tracking ref was
  pruned after the live remote ref was confirmed absent.
- Migration 000067 Allocation Lock remains held by MIG-098 and is untouched.
  Storefront Artifact Lock was free and remains untouched. GOV-021 holds only
  the Platform Integration Lock through merge and cleanup.
- All open pull requests were read back; none changes
  `manifests/storefront-contract-releases.json`.

## Publication Authority And Readback

- Artifact version: `2.0.0-alpha.31`
- Artifact ID: `9715454247`
- Artifact name: `oripa-storefront-contract-2.0.0-alpha.31`
- Workflow Run: `33254741748`
- Workflow: `.github/workflows/storefront-contract-artifact-publish.yml`
- Event / branch / attempt: `workflow_dispatch` / `main` / `1`
- Run status: completed success
- Source Commit: `ad078ecd1eebd68cd2443b347d387433177fd686`
- Remote inventory: exactly one matching, available, unexpired Artifact;
  created and last-updated timestamps are equal.
- Outer ZIP SHA-256:
  `9e8921e7681abe52d3e5fba65e4fdf3186988df453fca4f39be86e811deb22f0`
- Manifest SHA-256:
  `c11894fbfadaf3dd4e00c7f94973ede1bb00f580ece5e109d0118c74c3b69f74`
- `SHA256SUMS` SHA-256:
  `1a0a4295106e8e7bc951b9caf907c9cf844a913bf820e896312889ca3749a127`
- Client SHA-256:
  `0caf5e8ac829a1f13d1790298ba4a2fef3c50fe6ae11cad63329ab327cea40cf`
- Testkit SHA-256:
  `932cc4cc6560aa595e01bb5d929320f8d2f70dda32d5a8dd70ec91e84acb8716`
- Public OpenAPI SHA-256:
  `60a14073f7ee52d91b919c69fbc7444bf6afe391a887121bb4af5e45fbb85626`

The canonical GOV-020 validator re-downloaded Artifact `9715454247`, matched
the GitHub digest to the downloaded outer ZIP, safely extracted the exact
five-file inventory, verified Manifest and `SHA256SUMS`, and matched Manifest
task, version, Source Commit, package disposition, and component digests. The
independent root-only summary SHA-256 is
`c02f321f11800cde9f0d438bb0068ed70f76d52633ae3e2ea53cc080de7a970b`;
the retained payload files were independently rehashed to the same values.

## Ledger Reconciliation

Before reconciliation, schema `2.0` contained 10 immutable records from
alpha.21 through alpha.30, `latest_immutable` alpha.30, and one pending alpha.31
candidate. The live pending candidate, rather than the Human-provided reference
value `candidate: null`, is authoritative and matches the canonical runbook:
publication consumes the pending candidate and this separate Task clears it.

After reconciliation:

- immutable history contains one new alpha.31 record after alpha.30;
- `latest_immutable` is byte-equivalent in JSON meaning to that alpha.31 record;
- `candidate` is `null`;
- alpha.30 canonical SHA-256 remains
  `180dffe8a85f3d45052ca9088af6f031bd23e7232c04695ea2ac710a712e7c69`;
- pre-alpha.31 history canonical SHA-256 remains
  `a03b7ca032dd7406ab117b2b2f59d6f8c10c8f0414a299cea469025297437f48`;
- compatibility remains `contract-additive`, `breaking_change: false`, with
  browser-compatible Client and Testkit packages.

The existing Ledger schema records Source, Manifest, Public OpenAPI, Client,
Testkit, compatibility, and package metadata. It does not define Artifact ID,
Workflow Run, outer ZIP, or `SHA256SUMS` fields, so those fields are not added;
their canonical publication provenance is recorded in the release runbook and
this report.

## Verification

- Focused Storefront Artifact tests: 14 passed.
- Settled Storefront Artifact source validator: passed for alpha.31.
- Ledger JSON parse and `git diff --check`: passed.
- The first Policy Unit run had one expected failure because its canonical
  assertion still fixed alpha.30 plus a pending alpha.31 candidate. Human
  approved adding only `tests/ci/policy/test_policy_gate.py`; the Task Policy
  was reissued at the unchanged Base with six exact paths before that test was
  changed to alpha.31 latest, `candidate: null`, and alpha.30 preserved.
- Full Release tests: 37 passed.
- Release validation: passed with 67 unchanged migrations and the existing
  Platform, Application, Contract, and package version inventory.
- Final Policy Unit: 181 passed.
- Local Policy Gate: passed for 1,608 tracked files.
- Changed Python compilation, exact JSON/history preservation, scope review,
  and final `git diff --check`: passed.
- Final-head Required Checks and fresh Strict self-review remain required before
  merge.

## Delivery Boundaries

- Artifact publication / workflow dispatch / version reservation: `0 / 0 / 0`
- API Build / API Activation: `0 / 0`
- Storefront Build / Activation: `0 / 0`
- Migration created / applied: `0 / 0`
- Provider / Webhook / Card / Payment / Coin / Mail mutation: `0`
- Runtime / Production / Secret mutation: `0`

Artifact Ledger becomes GO after gate-compliant merge. Artifact adoption and
SITE-048 remain HOLD until Human configures and read-only verifies
`customers.payment_methods.updated` in TEST/SANDBOX and a separate Platform
Activation Task applies Migration 000067, performs an API-only Build and Shared
Preview API Activation, and passes Runtime Acceptance.
