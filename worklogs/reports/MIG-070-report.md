# MIG-070 Canonical Gacha Admin Browser Regression Fix

## Task

- Issue `#352`, Pull Request `#353`, Risk `R4`, Base `8b8b39fe221d7030cfd2aaf788f0b8f3994ea4c7`.
- Branch `fix/MIG-070-canonical-gacha-admin-browser-regressions`, dedicated worktree `/var/www/oripa-worktrees/MIG-070`.
- OPS-019 is an ancestor. Shared Preview began with migrations `000056`, `000057`, `000060`, `000058`, and `000059` applied, migration count `60`, batch `27`, and healthy API/Admin/PostgreSQL/Redis.

## Root Causes and Fixes

- Status-inclusive updates failed at transaction commit with PostgreSQL `P0001`: `Public and Draw activation pointers require one Revision update`. The Draft core update incremented `catalog_gachas.revision`, then MIG-068 canonical activation changed pointers and incremented it again. The deferred MIG-067 guard rejected the resulting two-revision transaction, so the complete update rolled back. The raw `QueryException` bypassed catalog Problem mapping and reached the generic Japanese fallback.
- Initial Draft-to-Published updates now leave the single Gacha revision mutation to canonical activation while passing the edited category into that activation. State-only, state-plus-field, no-state-change, scheduled, pause/resume, and unpublish lifecycle paths retain canonical behavior. Existing stable codes `CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID` and `CATALOG_GACHA_SCHEDULE_CONFLICT` map to the approved Japanese lifecycle message; Request ID and internal code remain hidden.
- Prize create/update already saved the canonical `presentation_asset_id` to both `catalog_prizes` and `catalog_gacha_version_prizes`; the Admin list API also returned `presentation_asset`. Rendering failed because authenticated Admin preview preferred `/api/v2/content/assets/{id}`, while Admin Nginx proxies only `/admin/api/`. Preview now always uses the existing authenticated `/admin/api/v2/catalog/presentation-assets/{id}/content` endpoint and resets failure state when the asset id changes. Existing assets are reused and asset duplication remains zero.
- Banner registration already succeeded. Only the pre-save preview was broken: `URL.createObjectURL()` produced a `blob:` URL rejected by the existing Admin CSP. The preview now uses a `FileReader` data URL, updates on file replacement, and leaves upload/create API semantics unchanged without weakening CSP.

## Verification

- Focused API: `16` tests, `266` assertions PASS against isolated PostgreSQL, including forced deferred-constraint evaluation for status-only and status-plus-field publication.
- Focused Admin: `4` files, `45` tests PASS, including lifecycle Japanese mapping, hidden diagnostics, thumbnail create/update/list rendering with zero asset duplication, Banner immediate/replaced-file preview, unchanged Banner registration, and Probability/Preflight hidden.
- Changed PHP syntax, Admin typecheck, lint, production build, and `git diff --check` PASS. Task PostgreSQL/Redis/network resources were removed after tests.
- Activation source `2b55452abc4c1e43948ce0de4accd6c23e6c9e34` passed `policy-gate`, `quality-gate`, `security-gate`, `integration-gate`, and `ci-gate`, plus fresh fixed-head self-review `#issuecomment-5370801305` with SEV-0/1/2/3 all zero.

## Shared Preview Activation

- Canonical GitHub Build Run `32489913072`, Artifact `9449448240`, produced exact-source Linux/amd64 API/Admin images. Artifact digest is `sha256:435fbfffb670b01efe57a1939d9840629fecc91b117b113a4c87ff5e70383778`; Preview-host builds are zero.
- Under the Preview Deployment and OS locks, API was activated first and Admin second. API changed from `oripa-v2-api:preview-OPS-019-ba17d7197673` to `oripa-v2-api:preview-MIG-070-2b55452abc4c`; Admin changed from `oripa-v2-admin:preview-OPS-019-ba17d7197673` to `oripa-v2-admin:preview-MIG-070-2b55452abc4c`. Both OCI revisions are the activation source.
- API/Admin/PostgreSQL/Redis are healthy with restart count zero. Migration count/batch remain `60`/`27`; migration creation/application and historical rewrite are zero. Existing API-only egress and private-only Admin/PostgreSQL/Redis boundaries remain unchanged. Both locks were released after final readback.

## Acceptance

- Public Gacha list, Admin root, and Admin session routes returned `200`. The authenticated asset route returned the expected anonymous `401`; the former wrong public path on the Admin host returned `404`, confirming the corrected routing boundary. Deployed Banner chunks use `FileReader` and contain no `createObjectURL` call.
- Source and focused tests verify Probability/Preflight and Request ID/internal code remain hidden in normal UI. Activation-window API/Admin logs and Nginx show HTTP `500`/`502`/`504` zero and runtime errors zero.
- No safe existing authenticated Admin session was available. Password reset and new Admin creation were not performed, so authenticated Browser mutation checks for status saves, thumbnail create/update display, and Banner selection/registration remain Human Browser Verification.
- Public/Admin Contract, generated Artifact, migration, Storefront Runtime/source, Production, Payment/Coin/Draw Core, env, Nginx, network, credential, and unrelated business data changes are zero. No Runtime blocker remains; only the requested Human Browser mutation acceptance remains.
