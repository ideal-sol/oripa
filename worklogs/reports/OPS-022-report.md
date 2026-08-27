# OPS-022 MIG-094 Shared Preview API Activation

## Task

- Issue `#402`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base and initial latest main are `053379b41ad5ad640a01c1a62410b9a121ac2f3f`, the MIG-094 squash merge.
- Human Scope Correction integrated the Canonical Preview API-only Build blocker into OPS-022. GOV-019 / Issue `#403` closed with commit, PR, and merge all zero; its Remote/local branch, worktree, Task Policy, and evidence were cleaned.

## Stage 0

- Root free was `22,116,044,800` bytes, above the required 20 GiB gate. Preview Deployment, Platform Integration, and Storefront Artifact locks were idle.
- Shared Preview API ran MIG-092 source `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`; API, Admin, PostgreSQL, and Redis were healthy with restart count zero.
- Migration ledger was 66 rows, batch 32, through `2026_09_22_000066_add_v2_verification_failed_user_state`.
- fincode Sandbox base, Public/Secret/Webhook inputs, and Payment Enable were present and ready. Secret value readback was zero. Payment/Payment Point Grant baseline was `3/0`.

## API-only Build Capability

- Workflow input `image_mode` accepts only `normal` and `api-only`; invalid values fail before checkout or build.
- `normal` preserves the API and Admin builds. `api-only` always builds API and keeps the sole Admin Docker build and Admin package argument inside the `normal` guard, so Admin build execution is zero.
- Artifact packaging, checksum, safe extraction, verification, and load accept exactly ordered API-only or API+Admin inventories. Admin-only, reversed, missing-API, duplicate, unknown, and tampered inventories fail closed.
- Image tags, exact source SHA, archive/image digests, OCI revision/source/version/created/title labels, outer GitHub digest, and one-day publication remain intact.
- Successful task-branch workflow runs remain non-Canonical. The merged-main workflow may use the same exact checked/reviewed head from its merged internal PR; closed-unmerged PRs remain rejected.

## Verification

- Preview image pipeline: 23 tests PASS.
- Policy Unit: 167 tests PASS.
- Storefront workflow regression: 1 test PASS.
- Local Policy Gate: PASS, 1,584 tracked files.
- Workflow YAML, Python compile, and `git diff --check`: PASS.
- No real Preview image build or Runtime activation was performed during source validation.

## Merge-first Operational Boundary

Human Decision requires the source PR to merge before the API image build. After squash merge, only the workflow definition on `main` may be dispatched with `image_mode=api-only`; the exact reviewed source head must belong to the same merged internal PR and retain successful Required Checks. Final Build, artifact, Activation, Runtime, mutation, and cleanup evidence will be recorded on Issue `#402` and in root-only repository-external evidence because the single Task PR is already merged at that point.

## Mutation Boundary

- MIG-094 Runtime image Build, Runtime Activation, Admin/Storefront Build or Activation: zero before merge.
- Migration created/applied, DB mutation, Payment/Coin mutation, Provider communication, fincode configuration change, and Production mutation: zero.
- OPS-021 remains open. OPS-022 restart decision remains pending post-merge API-only Build and Runtime verification.
