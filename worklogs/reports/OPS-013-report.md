# OPS-013 MIG-063F/G Admin Runtime Activation

## Task

- Issue: `#330`
- PR: `#331`
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

## Activation

- Activation evidence head `c0cc47fbaab37ff97df1c8a685277a909f5f4ddc` passed all five Required Checks and fresh fixed-head self-review `#issuecomment-5354341728` before Runtime mutation.
- OPS-013 acquired only the Preview Deployment Lock. Migration Allocation, Platform Integration, and Artifact Release Locks were not acquired.
- The already-loaded OPS-012 Admin image was re-verified and reused. No artifact download, image load, Preview-host build, GitHub image build dispatch, or image generation occurred under OPS-013.
- Canonical Compose used the existing environment sources, retained OPS-009 network/fixed Admin configuration, retained exact OPS-012 API override, and exact OPS-013 Admin image override.
- Only Admin was recreated with `--force-recreate --no-build --no-deps admin`.
- Active Admin is `oripa-v2-admin:preview-OPS-012-8c14b513393f`, image ID `sha256:d7d028c1f3f4ab9d8362c87e0d131edae7f3e16c17704af6440b2531728d3109`, OCI revision `8c14b513393f4cecea70a1516b2ebc2624944450`, container `9fda202bd6957d75bedbe8448d03ea8b58fea10e260edaed369f39f593a2f58f`.

## Runtime Verification

- Admin Docker health and internal `/api/health` returned healthy/200. API Docker health and loopback `/api/health` returned 200 with Application, PostgreSQL, Redis, and storage all `ok`.
- An attempted Admin host-loopback request to default port `3611` returned curl exit `7`; the Runtime intentionally publishes no Admin host port. The internal health probe is PASS and the unreachable host probe confirms the private-only boundary rather than a health defect.
- API container `b45a77e01564ae0ae1ad4b17a96316484697aea79a790e4914a9f3ebd97f5fd0` / start `2026-08-20T09:01:44.933731056Z`, PostgreSQL `9fed3dbad313b85c4ddafa0f4767fe3059a3cd47de511d7e45fd5664c15f892` / `2026-08-12T01:25:13.299493406Z`, and Redis `8bb6e8c4eacc9e7ff1d2647bf7c285fb314e4dd1a729ab45c8903c7a2ef0a334` / `2026-08-12T01:25:14.413361412Z` are unchanged.
- `mig061a-v2-preview_v2_private` remains `internal:true` with API, Admin, PostgreSQL, and Redis. Only API joins non-internal `mig061a-v2-preview_v2_api_egress` at `192.168.62.0/28`.
- Source readback confirms Rank image/video `rank-effects` pickers and their canonical `image_asset_id` / `video_asset_id`, Gacha Prize `CatalogBannerAssetPicker` and hidden `presentation_asset_id`, and `CatalogSectionNavigation` returning `null`.
- Stopped-container bundle readback found `ランク画像`, `抽選演出動画`, `Banner Category`, and Banner-to-existing-Presentation-Asset text in both server and static JavaScript chunks. The removed `catalog-tabs` class has zero JavaScript bundle matches. The temporary readback container was removed.
- Activation-window Admin, API, and Nginx HTTP 500/502/504 counts are all zero.
- Browser/E2E and real Admin operations were not executed. Final UI operation remains human-side as requested.
- New image builds, migrations created/applied, database/Asset mutations, Contract changes, Artifact publication, Storefront, Production, and Task ⑤ changes are all zero.
- Preview Deployment Lock was released after Runtime verification.

## Current State

- Preflight, exact Admin source/image verification, activation-head checks/self-review, Admin-only Runtime activation, read-only Runtime/bundle verification, and Preview Deployment Lock release: PASS.
- Issue, branch, dedicated worktree, Draft PR `#331`, Task Policy, initial evidence, and Runtime evidence: created.
- Final checks/self-review, merge, Issue closure, and branch/worktree/task-resource cleanup: pending.
