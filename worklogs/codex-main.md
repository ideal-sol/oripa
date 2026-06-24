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
  - Updated runtime timezone handling so new DB timestamps are stored as Japan time.
    - Added `DB_TIMEZONE` to PostgreSQL connection config.
    - Added `APP_TIMEZONE`, `DB_TIMEZONE`, and `TZ` wiring to Docker services.
    - Set current root `.env` and `backend/.env` timezone values to `Asia/Tokyo`.
    - Set PostgreSQL database/role timezone defaults to `Asia/Tokyo`.
    - Recreated only backend/queue/scheduler and cleared Laravel config cache.
    - Verified Laravel DB session `SHOW timezone` returns `Asia/Tokyo`.
    - Verified a temporary DB insert using Laravel `now()` stores JST local time.
    - `docker compose exec -T backend php artisan test --filter=DrawApiTest` succeeded.
  - Updated the admin operation guide to match the current implementation.
    - Added latest gacha workflow notes for daily draw limits, immutable published probability versions, and percentage-to-ppm probability handling.
    - Added rank presentation master workflow: upload reusable image/video assets in Settings, then select multiple assets on rank registration/edit screens.
    - Added current notes for user management, individual shipping item handling, purchase plans, free point expiration lots, announcements, contacts, and settings.
    - Verification: `pnpm typecheck` succeeded in `frontend/`.
    - Updated the gacha management manual with detailed explanations for:
      - Profit simulation
      - Product design planner
      - Probability settings and probability version publishing
  - Added backend/admin support for gacha tags.
    - Added `gacha_tags` and `gacha_tag_assignments` tables.
    - Added admin tag CRUD API under `/admin/api/gacha-tags`.
    - Added `tag_ids` support to admin gacha create/update and admin gacha resources.
    - Added admin UI pages for tag list/register/edit under gacha management.
    - Added multi-select tag assignment to the admin gacha register/edit form.
    - Added demo tag seed data and assigned tags to demo gachas.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=AdminGachaTagApiTest` succeeded.
      - `docker compose exec -T backend php artisan test --filter=AdminGachaApiTest` succeeded.
      - `pnpm typecheck` succeeded in `frontend/`.
    - Incident:
      - A parallel test run caused `oripa_test` migration conflicts.
      - `migrate:fresh --env=testing` also affected the current local `oripa` database in this Docker configuration.
      - No SQL backup was found under `/var/www/oripa` or `/home/ec2-user`.
      - Recreated local demo data with `AdminDemoDataSeeder`; current local DB has 3 users, 1 admin user, 2 categories, 5 gachas, 3 tags, and 6 tag assignments.
  - Restored frontend SSR API connectivity.
    - Cause: Next dev was running on the host while `frontend/.env.local` used `INTERNAL_API_BASE_URL=http://backend:8000/api`, which only resolves inside Docker.
    - Stopped the host-side Next dev process and started only the `frontend` Docker service with `docker compose up -d --no-deps frontend`.
    - Verified `backend` resolves from inside the frontend container.
    - Verified `https://luxe-pack.biz/` SSR output includes gacha and announcement data again.
  - User point history now highlights free point lots expiring within one month in red.
  - Changed only frontend display files:
    - `frontend/src/app/mypage/points/point-history-client.tsx`
    - `frontend/src/app/globals.css`
  - Verification:
    - `pnpm typecheck` succeeded in `frontend/`
  - Added public gacha tag APIs for the user-facing frontend.
    - `GET /api/gacha-tags` returns active tags sorted by `sort_order`, then `id`.
    - `GET /api/gachas` now includes active tags attached to each gacha.
    - `GET /api/gachas/{gacha}` now includes active tags attached to the gacha.
    - Inactive tags are excluded from public responses.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=GachaApiTest` succeeded.
      - The filter also matched `AdminGachaApiTest`; total result was 24 passed tests and 174 assertions.
      - `curl https://luxe-pack.biz/api/gacha-tags` returned active tag data.
      - `curl https://luxe-pack.biz/api/gachas` returned gacha data with `tags`.
  - Added top banner management.
    - Added `top_banners` table and `TopBanner` model.
    - Added admin API:
      - `GET /admin/api/top-banners`
      - `POST /admin/api/top-banners`
      - `GET /admin/api/top-banners/{topBanner}`
      - `PUT /admin/api/top-banners/{topBanner}`
    - Added public API:
      - `GET /api/top-banners`
      - Returns active banners sorted by `sort_order`, then `id`.
    - Added admin UI under gacha management:
      - `トップバナー一覧`
      - `バナー登録`
      - `トップバナー編集`
      - Top banner list supports checkbox selection and bulk enable/disable.
    - Banner image upload reuses the existing admin image upload endpoint with `top-banner` context.
    - Added admin bulk status API:
      - `PATCH /admin/api/top-banners/status`
    - Applied migration:
      - `2026_06_23_000003_create_top_banners_table`
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=TopBannerApiTest` succeeded with 5 tests and 25 assertions.
      - `docker compose exec -T backend php artisan test --filter=AdminTopBannerApiTest` succeeded with 5 tests and 25 assertions.
      - `curl https://luxe-pack.biz/api/top-banners` returned a valid JSON response.
      - `pnpm typecheck` succeeded in `frontend/`.
  - Added sale period support to point purchase plans.
    - Added nullable `starts_at` and `ends_at` to `point_purchase_plans`.
    - Both fields null means the purchase plan is unlimited.
    - Admin purchase plan create/edit now accepts start and end datetimes.
    - Admin purchase plan list shows the configured sale period.
    - Public `GET /api/point-purchase-plans` returns only active plans whose sale period is currently valid.
    - Payment creation rejects expired or not-yet-started point purchase plans.
    - User-facing purchase page display was not changed.
    - Applied migration:
      - `2026_06_23_000004_add_sale_period_to_point_purchase_plans_table`
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=AdminPointPurchasePlanApiTest` succeeded with 3 tests and 11 assertions.
      - `docker compose exec -T backend php artisan test --filter=PointPurchasePlanApiTest` succeeded; the filter also matched `AdminPointPurchasePlanApiTest`, total 3 tests and 16 assertions before the end-only case was added.
      - `docker compose exec -T backend php artisan test --filter=expired_point_purchase_plan_cannot_be_used_for_payment` succeeded with 1 test and 2 assertions.
      - `pnpm typecheck` succeeded in `frontend/`.
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
