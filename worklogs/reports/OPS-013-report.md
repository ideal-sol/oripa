# OPS-013 MIG-063F/G Admin Runtime Activation

## Task

- Issue: `#330`
- PR: pending
- Risk: `R4`
- Base SHA: `79ed6ef61d8aa0e7f0d825d1e7b9962608f0e8a0`
- Branch: `chore/OPS-013-mig-063fg-admin-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-013`
- Task Policy SHA-256: `d467d3db3346fda9aff8fd0fa34c8b7c0dc945bd5d4b0ed9f3900a56875ca4a4`

## Scope

- Activate only the already-merged MIG-063F/G Admin UI on the existing non-Production V2 Preview Admin service.
- Reuse the OPS-012 verified standard Admin image as the only permitted image source.
- Preserve the existing private-only Admin/PostgreSQL/Redis boundary and API-only egress boundary.
- Do not change Application source, build a new image, migrate or mutate the database, mutate Assets, publish an Artifact, or begin Task ⑤.

## Preflight

- Clean local `main`, `origin/main`, and Remote `main` all equal `79ed6ef61d8aa0e7f0d825d1e7b9962608f0e8a0`.
- OPS-012 source `8c14b513393f4cecea70a1516b2ebc2624944450` and latest Canonical `main` have the same `apps/admin` tree `7b0a38fc9aa2c16246bbb67c68d33639fd9c3a92` with no changed Admin path.
- MIG-063F commit `3699526536c6ecaee8e09aa5da5a28d3b1d744a1` and MIG-063G commit `7d2f85d2a4e2dadf993594c559f3ffc6c6add04d` are included in the OPS-012 source.
- OPS-012 Artifact `9399533827` / Run `32349836316` re-verifies successfully with outer digest `sha256:e37ee4389aea14c626505620510c447882e694bfe8d1a1b064a06b170d9e1c62`, manifest SHA-256 `65ffc234b488e82e71dae3a1cbe566e62e7c62cf7d8627e60e00167f4c20f046`, and Admin archive SHA-256 `9054a5635dcfa94fa238b0ea997ecd29307da0549f4361feb0532857d527b40f`.
- Verified Admin image is `oripa-v2-admin:preview-OPS-012-8c14b513393f`, image ID `sha256:d7d028c1f3f4ab9d8362c87e0d131edae7f3e16c17704af6440b2531728d3109`, `linux/amd64`, OCI revision `8c14b513393f4cecea70a1516b2ebc2624944450`.
- Current Admin is healthy on OPS-009 image. API, PostgreSQL, and Redis are healthy. `v2_private` remains `internal:true`; only API joins `v2_api_egress` `192.168.62.0/28`.
- New image builds, migrations created/applied, database or Asset mutations, Contract changes, and Artifact publication are all `0`.

## Planned Activation Gate

- Open a Draft PR from this exact evidence head and pass all five Required Checks.
- Create fresh fixed-head machine-readable self-review evidence before Runtime mutation.
- Acquire only the Preview Deployment Lock, reuse the already-loaded verified image, and recreate only Admin with canonical Compose plus repository-external exact-image overrides and `--force-recreate --no-build --no-deps admin`.
- Perform read-only container, network, health, log-window, source, and bundle verification.
- Record final evidence on a new fixed closeout head, pass all checks and fresh self-review again, then squash merge and clean all task resources.

## Rollback

- Retain `oripa-v2-admin:preview-OPS-009-d8374dc91824`. Rollback is Admin-only recreate with the unchanged Compose/network configuration; no API, PostgreSQL, Redis, migration, database, Asset, or egress operation is required.

## Current State

- Preflight and exact Admin source/image verification: PASS.
- Issue, branch, dedicated worktree, Task Policy, and initial evidence: created.
- PR, activation-head checks/self-review, Preview Deployment Lock, Runtime activation, final checks/self-review, merge, Issue closure, and cleanup: pending.
