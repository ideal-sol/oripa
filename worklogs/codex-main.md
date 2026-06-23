# Codex Main Worklog

Date: 2026-06-19
Repository: `/var/www/oripa`
Branch: `main`

## Current Status

- Latest pushed commit: `e596521 Split admin screens into URL routes`
- Remote: `origin/main`
- Working tree at last verification: clean
- `git status --short`: no output
- `git diff --stat`: no output

## Recent Work Completed

- 2026-06-22:
  - Committed the previous free-point expiry highlight work:
    - Commit: `7acfe4d Highlight expiring free point lots`
  - Added multi-rank presentation asset support.
    - A rank can now attach multiple rank image assets and multiple draw video assets.
    - DrawService selects one active image and one active video with Laravel-side `random_int` when a prize result is created.
    - Selected presentation URLs are stored on `draw_results` so old draw results remain reproducible after rank settings change.
    - Existing single `rank_image_asset_id` / `draw_video_asset_id` values are migrated into the new pivot table.
    - Admin rank forms now use multiple-select controls for rank images and draw videos.
  - Applied migration:
    - `2026_06_22_000003_add_multiple_rank_presentation_assets`
    - `2026_06_23_000001_add_daily_draw_limit_to_gachas`
  - Added limited gacha daily draw cap support.
    - `gachas.daily_draw_limit` is nullable. Null means no daily cap.
    - Admin gacha create/edit now has a "1日の規定回数" field.
    - Public gacha detail displays the daily cap when configured.
    - DrawService enforces user/gacha/day draw counts in Laravel during the locked draw transaction.
    - Over-limit draws return a validation error without consuming points or creating results.
  - Verification:
    - `docker compose exec -T backend php artisan test --filter=AdminGachaRankApiTest` succeeded
    - `docker compose exec -T backend php artisan test --filter=DrawServiceTest` succeeded
    - `docker compose exec -T backend php artisan test --filter=DrawApiTest` succeeded
    - `docker compose exec -T backend php artisan test --filter=AdminGachaApiTest` succeeded
    - `pnpm typecheck` succeeded in `frontend/`
  - Note:
    - Laravel tests must be run sequentially against `oripa_test`; parallel test commands collide because RefreshDatabase resets the same PostgreSQL schema.
  - User point history now highlights free point lots expiring within one month in red.
  - Changed only frontend display files:
    - `frontend/src/app/mypage/points/point-history-client.tsx`
    - `frontend/src/app/globals.css`
  - Verification:
    - `pnpm typecheck` succeeded in `frontend/`
- Admin screens were split into URL routes.
  - Added `frontend/src/app/admin/[[...segments]]/page.tsx`
  - Admin root on the admin subdomain redirects to `/admin/guide`
  - Admin navigation now uses `/admin/...` paths instead of `/?tab=...`
  - Direct URL restore was added for main list/detail/edit/new screens
  - Browser back/forward behavior is now aligned with real Next.js routes
- Admin route examples now supported:
  - `/admin/gachas`
  - `/admin/gachas/new`
  - `/admin/gachas/{id}/edit`
  - `/admin/gachas/ranks/{id}/edit`
  - `/admin/users/{id}`
  - `/admin/shipping/{requestId}/items/{itemId}/edit`
  - `/admin/settings/rank-assets/{id}/edit`
- Verification for the URL route work:
  - `pnpm typecheck` succeeded in `frontend/`
  - `pnpm lint` still fails because of pre-existing React Hooks lint issues in existing files
- The URL route work was committed and pushed:
  - Commit: `e596521 Split admin screens into URL routes`
- Previous infrastructure/UI work completed before the route split:
- Backend image was rebuilt and only the `backend` container was recreated.
- PHP upload settings are now reflected in the running backend container:
  - `upload_max_filesize = 64M`
  - `post_max_size = 72M`
- Effective application upload limits:
  - Images: 5MB
  - Videos: 50MB
- API health was confirmed:
  - `https://luxe-pack.biz/api/health` returned `200`
  - `app/db/redis/storage` were all `ok`
- Admin upload UI now shows size limits beside upload labels:
  - Images: `画像は5MBまで`
  - Videos: `動画は50MBまで`
- Public gacha detail UI changes were completed:
  - Recommended gacha section added
  - Prize grid set to PC/tablet 2 columns and mobile 1 column
  - Prize probability display removed
- Public top gacha cards were updated toward the reference layout.
- Public logo now loads `/logo.png` directly in the header.

## Notes

- No `.env` files were committed.
- Secrets remain environment-managed.
- Docker was not touched during the admin URL route split.
- `docker compose build backend` initially failed because Compose tried to use Buildx Bake with an unsupported `--allow` flag.
- The backend image was successfully rebuilt with:
  - `COMPOSE_BAKE=false docker compose build backend`
- Backend was updated with:
  - `docker compose up -d --no-deps backend`

## Git Verification

At the time this worklog was requested:

```text
git status --short
<no output>
```

```text
git diff --stat
<no output>
```
