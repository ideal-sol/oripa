# MIG-071 Gacha Unpublish Lifecycle Regression Fix

## Task

- Issue `#354`, Pull Request `#355`, Risk `R4`, Base `0cfff7d9c20ae28ff11e6ab55398d85ce5560419`.
- Branch `fix/MIG-071-gacha-unpublish-lifecycle`, dedicated worktree `/var/www/oripa-worktrees/MIG-071`.
- Shared Preview began on MIG-070 API/Admin source `2b55452abc4c1e43948ce0de4accd6c23e6c9e34`, migration count `60`, batch `27`, with healthy API/Admin/PostgreSQL/Redis and restart count zero.

## Root Cause and Fix

- Admin correctly sent `management_status: unpublished` with the full current-presentation form and expected Gacha/Version revisions to `PUT /admin/api/v2/catalog/gachas/{id}`.
- The current-presentation update queued a deferred activation trigger event. Direct Published unpublish then persisted the required sales pause before terminal deactivation; paused unpublish proceeded to deactivation after the presentation update. At commit, the deferred MIG-067 trigger compared the final row with the earlier event's OLD revision and raised SQLSTATE `P0001`, `Public and Draw activation pointers require one Revision update`.
- The existing source test stayed inside an outer test transaction and did not force the deferred trigger after unpublish, so it reported PASS without exercising the commit failure.
- Before clearing activation pointers, the service now forces pending non-activation Gacha row events through the existing constraint, restores deferred mode, and performs terminal deactivation as one revision from that operation's own OLD row. The required persisted pause and immutable public-deactivation guard remain unchanged.
- `published -> unpublished` and `sales_paused -> unpublished` pass. `unpublished -> published` remains forbidden with `CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID`. Its existing Japanese mapping remains correct and hides Request ID/internal code; no mapping source change was needed.

## Verification

- Isolated PostgreSQL lifecycle focused test: `9` tests / `216` assertions PASS, covering publish, pause, resume, both unpublish sources, state-only/full-form, state-plus-title, normal edit, terminal republish rejection, forced deferred validation, revision behavior, and unchanged inventory/draw/user history.
- Direct Admin lifecycle mapping test: `1` file / `6` tests PASS. The Admin unit suite also passed `33` files / `184` tests when initially selecting the focused test.
- Changed PHP syntax and `git diff --check` PASS. Full API suite was intentionally not run; Required Checks own repository-wide validation.
- Activation source `66825e5a58f815155fd009deb36b8eb8b173d2b2` passed all five Required Checks and fresh fixed-head self-review `#issuecomment-5372227179` with no findings.

## Shared Preview Activation

- Canonical GitHub Build Run `32500569379`, Artifact `9453458501`, produced and verified exact-source Linux/amd64 API/Admin images. Only the API archive was loaded; Preview-host builds are zero.
- Under Preview Deployment and OS locks, API changed from `oripa-v2-api:preview-MIG-070-2b55452abc4c` to `oripa-v2-api:preview-MIG-071-66825e5a58f8`. Admin stayed on `oripa-v2-admin:preview-MIG-070-2b55452abc4c` with its original start time and revision.
- API/Admin/PostgreSQL/Redis are healthy with restart count zero. Migration count/batch remain `60`/`27`; migrations created/applied, schema/data rewrite, and business-data mutation are zero. Existing private and API-only egress network boundaries remain unchanged. Both locks were released.

## Acceptance

- API health, Public Gacha list, and Admin session routes returned `200`; deployed API source contains the exact lifecycle fix. Activation-window exact HTTP `500`/`502`/`504` and runtime errors are zero.
- No safe existing authenticated Admin session was available to Codex. Password reset and Admin creation were not performed. Human Browser Verification remains for `published -> unpublished`, `sales_paused -> unpublished`, and terminal `unpublished -> published` rejection only.
- Migration, Public/Admin Contract, generated contract artifact, Storefront source/runtime, Production, Payment/Coin/Draw Core, env, credential, Nginx, network, and unrelated business-data changes are zero. No source or runtime blocker remains.
