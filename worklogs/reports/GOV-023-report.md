# GOV-023 Storefront Contract Release Ledger Reconciliation Report

## Change Governance

- Change: `GOV-023`
- Issue: `none`
- Risk: `R2`
- Lane: `Strict Change` because the ledger is immutable release history
- Application Runtime Activation: `none`
- Base: `fe1153af23c9b11fae9d58ebae5e4683f2fa93ff`
- Branch: `docs/GOV-023-storefront-contract-alpha-32-ledger-reconciliation`
- Worktree mode: current clean worktree
- Source Lock: none; live open-PR scope readback found no conflicting path
- Migration Allocation Lock: not applicable; no migration is created or applied

The source phase uses Git Lite without an Issue, dedicated Worktree, or Source
Lock. The approved GitHub App wrapper requires a Task Policy for privileged
delivery, so GOV-023 selected one transient exact six-path policy only for push,
PR, self-review, merge, and branch cleanup. It is root-owned mode `0600`, has
SHA-256 `4caa102adf830d52a421b27b5a8a38cae82c6373ecb7a51c6177e2647cb64941`,
and must be removed at closeout.

## Stage 0

- Local `HEAD`, local `main`, `origin/main`, and protected GitHub `main` were
  clean and equal at the exact GOV-022 squash commit
  `fe1153af23c9b11fae9d58ebae5e4683f2fa93ff`.
- `GOV-023` was unused in Repository text, local and Remote branches, and
  GitHub Issue/PR title search before branch creation.
- Fifteen open Dependabot PRs were inspected by their live changed-file lists;
  none intersects the six GOV-023 paths, so no real Source Lock risk exists.
- The release ledger was schema `2.0` with 11 immutable records from alpha.21
  through alpha.31, `latest_immutable` alpha.31, and one pending alpha.32
  candidate. Its pre-reconciliation immutable-history canonical SHA-256 was
  `5e286877a462d29e643b2fc4e2a0040221e42be9687e31f378e857b28a51026c`.

## Canonical Publication Readback

- Artifact version: `2.0.0-alpha.32`
- Artifact name: `oripa-storefront-contract-2.0.0-alpha.32`
- Artifact ID: `9730828197`
- Workflow Run: `33307134531`
- Workflow run number / attempt: `2 / 1`
- Event / status / conclusion: `workflow_dispatch / completed / success`
- Source Commit: `4147487f8f1474d5261a12aa8a0ad124cebe922f`
- Remote inventory: exactly one matching, available, unexpired Artifact
- Publication jobs: authorize, publish, and readback all completed successfully

A fresh remote download used the current canonical publication validator. It
matched GitHub Artifact identity and outer digest, safely extracted the exact
five-file inventory, validated Manifest task/version/source/package metadata,
verified `SHA256SUMS`, and independently rehashed every retained payload file.

Canonical SHA-256 values:

- GitHub outer Artifact: `e891ea105a03bc3e484d06ff837730d2c0f24ab5d7df887a3a7040011b8a6744`
- Manifest: `263955a5521a863635bf6ad23d604e52b1319e84052178288bad7b7c308de564`
- `SHA256SUMS`: `755c4a752250edc77da01d6dd7c2b7ef781aa3cfc55b696be47c760517bd4237`
- Public OpenAPI: `9670bc769080da605c97cb9849b61f342cf0111bc39e91c09dbbf62fc4bcc720`
- Storefront Client: `5d00dd111914d4bd6da248c99b98fcc697eb1507092fe6757015745e73856ad8`
- Storefront Testkit: `6124a6ac5837984eda60fdada0dae98fa24f28285ed674b7197f3b64bd7095be`

The Manifest fixes a `contract-additive`, non-breaking bundle with Public
OpenAPI alpha.29 and 74 operations, browser-compatible Client/Testkit alpha.32,
and referenced immutable Site Schema alpha.23.

## Ledger Reconciliation

Before:

- immutable history count: 11
- latest immutable: `2.0.0-alpha.31`
- candidate: `2.0.0-alpha.32`, state `pending`

After:

- immutable history count: 12
- alpha.32 is appended immediately after unmodified alpha.31
- latest immutable: `2.0.0-alpha.32`
- `immutable_history[-1]` and `latest_immutable` are identical in JSON meaning
- candidate: `null`
- pre-alpha.32 immutable history canonical SHA-256 remains
  `5e286877a462d29e643b2fc4e2a0040221e42be9687e31f378e857b28a51026c`
- alpha.32 canonical ledger-record SHA-256 is
  `87a07a2005b426acdff0e6eded8a8e10e9282861b58efeb0657dae4ab57d9852`

The existing ledger schema records Source Commit, Manifest digest, Public
OpenAPI, Client, Testkit, compatibility, and package metadata. It does not
define Artifact ID, Workflow Run, outer ZIP digest, or `SHA256SUMS` digest, so
GOV-023 does not expand the schema. Those publication values remain canonical
release metadata in the runbook and this report.

## Validation

- Live Artifact identity, run, and three publication jobs: PASS
- Fresh canonical download, outer digest, five-file inventory, and Manifest: PASS
- `SHA256SUMS` plus independent Public OpenAPI, Client, and Testkit hashes: PASS
- Ledger-to-Manifest and ledger-to-live-readback comparison, 25 checks: PASS
- Settled Storefront Artifact source validator: PASS
- Focused Storefront Artifact tests: 14 passed
- Full Release tests: 37 passed
- Release source validation: PASS with 68 unchanged migrations
- Policy Unit: 201 passed
- Local Policy Gate: PASS across 1,623 tracked files
- Changed Python compilation, ledger JSON parse, exact six-path scope, text-only
  inventory, current-source contradiction search, and `git diff --check`: PASS
- Exact-head Strict lane and high-confidence added-diff secret scan remain
  required after the final source commit is fixed.
- Final-head Required Checks and fresh Strict self-review are required before
  squash merge.

## Delivery Boundaries

- Artifact publication / overwrite / new version / workflow dispatch: `0 / 0 / 0 / 0`
- Public OpenAPI / Client / Testkit source changes: `0 / 0 / 0`
- API / Admin / worker / Storefront application changes: `0`
- Build / Runtime Activation: `0 / 0`
- Migration created / applied and database mutation: `0 / 0 / 0`
- Payment / Save Card / Account Security behavior mutation: `0`
- Artifact Registry / Production / Secret / Credential mutation: `0`

After a gate-compliant GOV-023 squash merge, Storefront may exact-pin immutable
`2.0.0-alpha.32` for a separate Account Security UI Change. This reconciliation
does not itself implement or activate that UI. Rollback is a separate PR that
reverts only the ledger metadata commit; the published Artifact remains
immutable and is never deleted, overwritten, rebuilt, or republished.
