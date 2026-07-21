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
  - Added email verification for normal user registration.
    - Registration now creates the user/profile/wallet but does not issue an access token.
    - A 24-hour signed email verification URL is sent via Laravel Mail.
    - `GET /api/email/verify/{user}/{hash}` verifies the signed URL and sets `email_verified_at`.
    - `POST /api/email/verification-notification` resends the verification email when the account exists and is still unverified.
    - Login now rejects active users whose `email_verified_at` is still null.
    - Registration email validation now rejects email local parts containing `+`.
    - Frontend layout files were not changed for this task.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 12 tests and 58 assertions.
  - Added referral code foundation.
    - Users now have a unique `referral_code`.
    - Registration accepts an optional referral code and records a pending `user_referrals` row when valid.
    - Referral reward settings are stored in `referral_settings`.
    - Admin APIs were added for referral history and referral reward settings.
    - Actual reward point grant is intentionally not implemented yet; the current business decision is to grant after SMS verification.
    - Frontend layout files were not changed for this task.
    - Verification:
      - `docker compose exec -T backend php artisan migrate --force` succeeded.
      - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 14 tests and 66 assertions.
      - `docker compose exec -T backend php artisan test --filter=AdminReferralApiTest` succeeded with 2 tests and 11 assertions.
  - Adjusted email verification UX.
    - Registration and resend emails now show a frontend URL under `/email/verify` instead of exposing `/api/email/verify`.
    - The frontend verification route calls the signed Laravel API URL server-side and redirects users to `/login`.
    - Successful verification redirects to `/login?email_verified=success`.
    - Invalid or expired verification links redirect to `/login?email_verified=invalid`.
    - The login page displays a Japanese status message for the verification result.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 14 tests and 66 assertions.
      - `docker compose exec -T frontend pnpm typecheck` succeeded.
  - Updated admin settings navigation.
    - The settings page now first shows three links: page settings, rank asset settings, and referral point settings.
    - Page settings and rank asset settings now open as separate settings subviews.
    - Referral point settings now display the current reward configuration and referral history.
    - Referral point settings can be updated from the admin UI via the existing Laravel admin API.
    - Verification:
      - `docker compose exec -T frontend pnpm typecheck` succeeded.
  - Added admin referral history visibility.
    - User detail pages now show referral history where the selected user is either the referrer or referred user.
    - Referral point settings now include a user ID search for referral history.
    - The admin referral API now accepts `user_id` and searches both `referrer_user_id` and `referred_user_id`.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=AdminReferralApiTest` succeeded with 3 tests and 15 assertions.
      - `docker compose exec -T frontend pnpm typecheck` succeeded.
  - Refined admin referral display.
    - The page settings list now uses the full admin section width.
    - User detail pages now show the user's own referral code in the profile grid.
    - If the user registered with a referral code, the referrer's code and name are shown in the profile grid.
    - User detail referral history now lists only users referred by the selected user, hiding the redundant referrer and referral code columns.
    - Verification:
      - `docker compose exec -T frontend pnpm typecheck` succeeded.
  - Added SMS verification DB state foundation.
    - Added `users.sms_verified_at` to track SMS verification completion.
    - Added `sms_verification_codes` for pending/verified/expired/canceled verification attempts.
    - SMS verification codes store `code_hash`, not the plain code.
    - Added attempt count, max attempts, resend count, last sent time, expiry, verified time, purpose, status, and metadata fields.
    - Added `SmsVerificationCode` model and `User::smsVerificationCodes()` relation.
    - `UserResource` now exposes `sms_verified_at` and `sms_verified`.
    - Verification:
      - `docker compose exec -T backend php artisan migrate --force` succeeded.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationStateTest` succeeded with 3 tests and 10 assertions.
      - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 14 tests and 66 assertions.
  - Added SMS sending abstraction.
    - Added `SmsSender` interface for future SMS providers.
    - Added `SmsMessage` and `SmsSendResult` DTOs.
    - Added `LogSmsSender` for local/development use without an external provider.
    - Added `services.sms.driver` config and `SMS_DRIVER=log` to `.env.example`.
    - Bound `SmsSender` in `AppServiceProvider` based on `services.sms.driver`.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=SmsSenderTest` succeeded with 2 tests and 5 assertions.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationStateTest` succeeded with 3 tests and 10 assertions.
  - Added SMS verification API.
    - Added authenticated endpoints for SMS verification status, send, resend, and verify.
    - SMS verification codes are generated as 6-digit numeric codes using `random_int`.
    - Plain SMS codes are not stored; only `code_hash` is saved.
    - Incorrect verification attempts now persist attempt counts and cancel the code after the configured max attempts.
    - Resending cancels previous pending codes and creates a new pending code.
    - Verification:
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationApiTest` succeeded with 5 tests and 33 assertions.
      - `docker compose exec -T backend php artisan test --filter=SmsSenderTest` succeeded with 2 tests and 5 assertions.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationStateTest` succeeded with 3 tests and 10 assertions.
  - Added SMS verified phone ownership rules.
    - Added `user_profiles.normalized_phone_number` for duplicate checks.
    - Duplicate unverified phone numbers are allowed.
    - SMS send is rejected when another active or suspended user has already verified the same phone number.
    - Withdrawn users do not block phone number reuse.
    - Updating a verified user's phone number resets `sms_verified_at`, releasing the old phone number for reuse.
    - Verification:
      - `docker compose exec -T backend php artisan migrate --force` succeeded.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationApiTest` succeeded with 10 tests and 49 assertions.
      - `docker compose exec -T backend php artisan test --filter=UserProfileApiTest` succeeded with 3 tests and 14 assertions.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationStateTest` succeeded with 3 tests and 11 assertions.
  - Added duplicate unverified email handling.
    - Removed the database unique constraint from `users.email` and replaced it with a normal index.
    - Registration now allows duplicate unverified email addresses.
    - Registration rejects an email already verified by an active or suspended user.
    - Withdrawn users do not block email reuse.
    - Email verification links become invalid if another active/suspended user has already verified the same email.
    - Login and password reset now select the verified active user explicitly to avoid duplicate-email ambiguity.
    - Verification:
      - `docker compose exec -T backend php artisan migrate --force` succeeded.
      - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 18 tests and 80 assertions.
      - `docker compose exec -T backend php artisan test --filter=SmsVerificationApiTest` succeeded with 10 tests and 49 assertions.
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

## 2026-06-25 SMS Verification Continuation

- Continued backend SMS verification work as Main Codex.
- Implemented and verified the policy that referral reward points are granted after SMS verification.
  - Added `ReferralRewardService`.
  - Added `referral` to `PointLotSourceType`.
  - Added a migration to allow `point_lots.source_type = referral`.
  - SMS verification completion now rewards the referrer once for a pending referral.
  - If the referral setting produced `0` reward points, the referral is canceled instead of granting points.
- Verified phone number ownership rules.
  - Duplicate unverified phone numbers are allowed.
  - Active/suspended users with a verified phone number block reuse.
  - Withdrawn users do not block reuse.
  - Changing a verified user's phone number resets SMS verification and releases the old number.
- Verified duplicate-unverified email handling.
  - Duplicate unverified emails are allowed.
  - Active/suspended verified email owners block new registration.
  - Withdrawn users do not block email reuse.
  - Once one user verifies an email, other pending verification links for the same email become invalid.
  - Login and password reset select the active verified user explicitly.
- Verification:
  - `docker compose exec -T backend php artisan migrate --force` succeeded with nothing pending.
  - `docker compose exec -T backend php artisan test --filter=SmsVerificationApiTest` succeeded with 11 tests and 58 assertions.
  - `docker compose exec -T backend php artisan test --filter=SmsVerificationStateTest` succeeded with 3 tests and 11 assertions, but the command was mistakenly run with duplicate `--filter`; Laravel emitted a warning and returned exit code 1 despite the target test passing.
  - `docker compose exec -T backend php artisan test --filter=SmsSenderTest` succeeded with 2 tests and 5 assertions.
  - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 18 tests and 80 assertions.
  - `docker compose exec -T backend php artisan test --filter=UserProfileApiTest` succeeded with 3 tests and 14 assertions.
- Current caution:
  - There are unrelated uncommitted frontend changes under `frontend/` from another worker. Main Codex did not edit those files in this SMS work.

## 2026-06-25 Google Login Backend Foundation

- Added backend-only Google login foundation. Frontend files were not edited.
- Confirmed `backend/.env` contains:
  - `GOOGLE_CLIENT_ID`
  - `GOOGLE_CLIENT_SECRET`
  - `GOOGLE_REDIRECT_URI`
  - Values were not printed to logs.
- Added Google OAuth config keys to `config/services.php` and `.env.example`.
- Added social authentication persistence:
  - `social_accounts`
  - `social_login_sessions`
- Added Google auth API routes:
  - `GET /api/auth/google/redirect`
  - `POST /api/auth/google/callback`
  - `POST /api/auth/google/register`
- Implemented the initial SNS login flow:
  - Callback exchanges the Google auth code for an access token.
  - User profile is fetched from Google userinfo.
  - Existing linked Google account logs in and receives a Sanctum token.
  - First-time Google login returns a temporary registration token plus Google-provided name/email.
  - Registration token completion creates an active user with `email_verified_at` set immediately.
  - The next step after Google registration is `sms_verification`.
- Implemented email conflict rules for Google first login:
  - Existing active/suspended verified email is rejected with `既に登録済みのメールアドレスです。`
  - Existing unverified duplicate email does not block Google registration.
  - Once Google registration creates a verified user for that email, old unverified email verification links become invalid through the existing verified-owner check.
  - Withdrawn verified users do not block reuse, matching the normal registration rule.
- Implemented referral code support during Google registration:
  - Optional referral code is accepted on `/api/auth/google/register`.
  - A pending `user_referrals` row is created.
  - Actual reward grant remains tied to SMS verification.
- Verification:
  - `docker compose exec -T backend php artisan migrate --force` applied `2026_06_25_000002_create_social_auth_tables`.
  - `docker compose exec -T backend php artisan test --filter=GoogleAuthApiTest` succeeded with 6 tests and 38 assertions.
  - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 18 tests and 80 assertions.
  - `docker compose exec -T backend php artisan route:list --path=auth/google` showed the 3 Google auth routes.
- Current caution:
  - There are unrelated uncommitted frontend changes under `frontend/` from another worker. Main Codex did not edit those files in this Google login backend work.

## 2026-06-25 LINE Friend Link Backend Foundation

- Added backend-only LINE friend-add point reward foundation. Frontend files were not edited.
- Added user LINE fields:
  - `line_link_code`
  - `line_user_id`
  - `line_linked_at`
- User creation now auto-generates a unique `line_link_code`.
- `UserResource` now exposes:
  - `line_link_code`
  - `line_linked_at`
  - `line_linked`
  - `line_friend_add_url`
- Added LINE friend setting persistence:
  - `line_friend_settings`
  - `friend_add_url`
  - `reward_point_amount`
  - `reward_expiration_days`
  - `is_active`
  - `auto_reply_message`
- Added LINE friend link/event persistence:
  - `line_friend_links`
  - `line_friend_link_events`
- Added `line_friend` to `PointLotSourceType` and the `point_lots.source_type` DB constraint.
- Added admin API routes:
  - `GET /admin/api/line-friend-settings`
  - `PUT /admin/api/line-friend-settings`
- Added public LINE webhook:
  - `POST /api/line/webhook`
- Implemented LINE webhook behavior:
  - Verifies `X-Line-Signature` using `LINE_CHANNEL_SECRET`.
  - `follow` event creates/updates a friend row and replies with the configured auto-response message.
  - `unfollow` event marks the LINE user as blocked.
  - Text message event treats message text as the user's LINE link code.
  - Matching code links `line_user_id` to the user and grants free points once.
  - Duplicate LINE/user linkage is ignored or rejected without double granting points.
- Added `.env.example` keys:
  - `LINE_CHANNEL_SECRET`
  - `LINE_CHANNEL_ACCESS_TOKEN`
  - `LINE_FRIEND_ADD_URL`
- Verification:
  - `docker compose exec -T backend php artisan migrate --force` applied:
    - `2026_06_25_000003_create_line_friend_tables`
    - `2026_06_25_000004_add_line_friend_to_point_lot_source_type`
  - `docker compose exec -T backend php artisan test --filter=LineFriendApiTest` succeeded with 5 tests and 28 assertions.
  - `docker compose exec -T backend php artisan test --filter=UserAuthApiTest` succeeded with 18 tests and 80 assertions.
  - `docker compose exec -T backend php artisan config:clear` succeeded.
  - `docker compose exec -T backend php artisan route:list --path=line` showed the admin setting routes and public webhook route.
- Frontend handoff:
  - Added a LINE settings button/page under the existing admin Settings UI.
  - Connected it to `/admin/api/line-friend-settings`.
  - Remaining frontend handoff: display the user `line_link_code` and `line_friend_add_url` in the user profile page.
- Admin UI addition:
  - Added `LINE設定` button to the admin settings page.
  - Added `/admin/settings/line` route handling.
  - Added form fields for friend add URL, reward points, free point expiration days, auto-reply message, and active flag.
  - Added display for current friend count and blocked count.
  - Verification:
    - `pnpm typecheck` in `frontend/` succeeded.
- Current caution:
  - There are unrelated uncommitted frontend changes under `frontend/` from another worker. Main Codex did not edit those files in this LINE backend work.

## 2026-06-26 Admin Refactor Rollback

- ADMIN-REF-001 route-split refactor was deferred.
- Confirmed `main` HEAD before rollback work:
  - `0af553b Add LINE friend reward settings`
- Backed up the route-split working tree:
  - Branch: `backup/admin-refactor-deferred-20260626-0847`
  - Commit: `e0a8537 backup: defer admin route refactor`
- Reason:
  - The route conflict was fixed, but 504 timeouts continued.
  - Next.js dev server compile/cache work remained too heavy for the current server.
  - `/admin/guide` local rendering had taken tens of seconds, while external requests reached the proxy timeout.
- Active admin structure after returning to `main`:
  - `frontend/src/app/admin-dashboard.tsx`
  - `frontend/src/app/admin/[[...segments]]/page.tsx`
- Deferred route-split files are not active on `main`.
- New admin features should be added to the current stable admin structure until the refactor is reopened after feature completion and server capacity review.

## 2026-06-26 Gacha Category Description

- Added optional gacha category `description`.
- DB:
  - Added nullable `text` column `gacha_categories.description`.
  - Existing rows keep `description = null`.
  - Applied migration `2026_06_26_000001_add_description_to_gacha_categories_table`.
- Backend:
  - Added `description` to `GachaCategory` fillable fields.
  - Added admin validation: nullable string, max 2,000 characters.
  - Added `description` to admin category resources.
  - Added `category.description` to public gacha list/detail resources.
- Admin UI:
  - Added description to category registration and category edit in the stable `admin-dashboard.tsx` structure.
  - The project owner confirmed browser manual testing and then requested the category list description column be hidden.
  - User-facing pages do not display category descriptions in this task.
- Verification:
  - `docker compose exec -T backend php artisan test tests/Feature/AdminGachaCategoryApiTest.php` passed.
  - `docker compose exec -T backend php artisan test tests/Feature/GachaApiTest.php` passed.
  - `pnpm typecheck` in `frontend/` passed.

## 2026-06-29 Specification Priority And v1.6 Draft

- Documentation-only update before daily point balance snapshot work.
- No code, migration, DB, Docker, or dependency operations were performed.
- Restored specification priority in:
  - `AGENTS.md`
  - `TASK_BOARD.md`
  - `docs/SHARED_CONTEXT.md`
- Created `docs/md/spec_v1.6_draft.md`.
- Recorded the gacha category description column as `v1.6-DRAFT-001`.
- Current specification priority:
  1. Latest explicit human decision
  2. `docs/md/spec_v1.5.1.md`
  3. `docs/md/spec_v1.6_draft.md`
  4. `docs/md/spec_v1.5.md`
  5. `docs/decisions/APPROVED_AS_BUILT_SPECIFICATIONS_2026-06-25.md`
  6. `docs/md/spec_v1.4.md`
  7. `AGENTS.md`
  8. `TASK_BOARD.md`
  9. `docs/SHARED_CONTEXT.md`
  10. `docs/md/all_check.md`

## 2026-06-29 Daily Snapshot Preparation Notes

- Documentation-only update before implementation.
- No code, migration, DB, Docker, or dependency operations were performed.
- `spec_v1.5.1.md` remains the current aligned v1.5 master specification.
- Category description column is not mixed into the `spec_v1.5.1.md` body and remains recorded in `docs/md/spec_v1.6_draft.md`.
- Admin refactoring remains deferred:
  - Reason: Next.js dev server route-split compile/cache load caused 504 under current server specs.
  - Current priority is the stable admin structure.
  - Full refactor should be retried after all feature additions are complete and server specs are upgraded.
  - New admin features should be added to the current stable `admin-dashboard.tsx` structure.
- Daily point balance snapshots are recorded as the next pre-release critical feature:
  - `point_balance_snapshots` table and Model exist.
  - Service, Command, Scheduler, and tests are missing or unconfirmed.
  - Daily storage of paid/free unused point balances is required for funds settlement law support.

## 2026-06-29 Daily Point Balance Snapshots

- Implemented backend daily point balance snapshots.
- Scope kept to backend Service, Command, Scheduler, tests, and documentation.
- Did not implement sales management, refund/chargeback cancellation logic, production payment, admin refactor, or frontend display.
- Existing DB state:
  - `point_balance_snapshots.snapshot_date` already had a unique constraint.
  - No new migration was required.
- Added:
  - `backend/app/Domain/Point/Services/PointBalanceSnapshotService.php`
  - `backend/app/Console/Commands/CreatePointBalanceSnapshotCommand.php`
  - `points:snapshot-balances` Artisan command
  - Scheduler entry at `00:10` Asia/Tokyo with `withoutOverlapping`
  - `backend/tests/Unit/PointBalanceSnapshotServiceTest.php`
  - `backend/tests/Feature/PointBalanceSnapshotCommandTest.php`
- Behavior:
  - Aggregates all paid point lots with `remaining_amount > 0`.
  - Aggregates free point lots with `remaining_amount > 0` and `expire_at` in the future.
  - Uses the previous Asia/Tokyo date when the command is run without `--date`.
  - Manual `--date=YYYY-MM-DD` stores current `point_lots.remaining_amount` totals under the specified date.
  - Strict historical reconstruction for an arbitrary past timestamp is a future separate feature if needed.
  - Marks March 31 and September 30 as base dates.
  - Uses `updateOrCreate` so rerunning the same date updates one row instead of creating duplicates.
- Operational order:
  - Existing `points:expire` remains scheduled hourly.
  - Daily snapshot runs at `00:10` Asia/Tokyo after the intended free-point expiration step.
- Verification:
  - `docker compose exec -T backend php artisan list | grep points` showed `points:snapshot-balances`.
  - `docker compose exec -T backend php artisan test tests/Unit/PointBalanceSnapshotServiceTest.php` passed: 5 tests, 15 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/PointBalanceSnapshotCommandTest.php` passed: 5 tests, 13 assertions.
- Remaining follow-up:
  - Admin/API display and CSV export for latest value, daily trend, and base date value remain separate future tasks.
  - Concurrent double execution of the same date can be considered later if operating multiple scheduler instances.

## 2026-06-29 Sales Management Backend Read API

- Implemented the backend read-only foundation for the v1.6 sales management feature.
- Scope kept to backend API, aggregation Service, tests, and documentation.
- Did not implement admin UI, frontend changes, admin refactor, production payment provider connection, refund/chargeback point reversal, CSV export, new DB tables, or migrations.
- Added:
  - `backend/app/Domain/Admin/Services/SalesManagementReportService.php`
  - `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`
  - `backend/app/Http/Requests/Admin/SalesMonthlyRequest.php`
  - `backend/app/Http/Requests/Admin/SalesDailyRequest.php`
  - Admin routes under `/admin/api/sales/*`
  - `backend/tests/Unit/SalesManagementReportServiceTest.php`
  - `backend/tests/Feature/AdminSalesManagementApiTest.php`
- Implemented API endpoints:
  - `GET /admin/api/sales/monthly`
  - `GET /admin/api/sales/daily-payments`
  - `GET /admin/api/sales/monthly-point-consumption`
  - `GET /admin/api/sales/daily-point-consumption`
  - `GET /admin/api/sales/draw-requests/{drawRequest}`
- Behavior:
  - Uses Asia/Tokyo date ranges with start-inclusive/end-exclusive boundaries.
  - Gross sales include `succeeded`, `refunded`, and `chargeback` payments by `paid_at`.
  - Refund amount uses `refunded_at`; chargeback amount uses `chargeback_at`.
  - Net sales is gross minus refunds and chargebacks.
  - Payment method uses `metadata.payment_method` when present and falls back to `provider`.
  - Purchase plan is resolved from `metadata.point_purchase_plan_id`; missing plans return a fallback label.
  - Point consumption uses `point_ledgers` with `ledger_type=spend`, `amount < 0`, and `related_type=draw_request`.
  - Daily point consumption is grouped by `draw_request`, not individual ledger row.
  - Draw request detail returns child draw results with rank/prize and point fields.
- Verification:
  - `docker compose exec -T backend php artisan test tests/Unit/SalesManagementReportServiceTest.php` passed: 4 tests, 27 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php` passed: 6 tests, 45 assertions.
- Remaining follow-up:
  - Admin sales UI must be added later to the current stable `admin-dashboard.tsx` structure.
  - Index additions can be considered later if real data volume makes sales queries slow.

## 2026-06-29 Sales Management Admin UI

- Implemented the admin sales management UI in the current stable admin dashboard structure.
- Did not restart the deferred admin route-split refactor.
- Did not change backend, database, migrations, API response contracts, Docker, dependencies, production payment integration, or refund/chargeback point reversal logic.
- Changed:
  - `frontend/src/app/admin-dashboard.tsx`
  - `frontend/src/app/admin/[[...segments]]/page.tsx`
  - `frontend/src/app/globals.css`
  - sales management documentation entries
- Behavior:
  - Sidebar now shows `売上管理` above `お知らせ`.
  - The old independent `決済` menu is not shown.
  - `/admin/sales` opens sales management.
  - `/admin/payments` is treated as a compatibility URL that opens sales management.
  - Monthly sales, daily sales, monthly point consumption, daily point consumption, and draw request detail sections are implemented.
  - Sales APIs are not added to `refreshAll()`.
  - Sales APIs are fetched only from the sales management screen or its controls/detail button.
- Verification:
  - `cd frontend && pnpm typecheck` passed.
- Remaining follow-up:
  - Browser/manual QA is still needed for visual display and Network confirmation.

### 2026-06-29 Monthly Sales Summary Layout Adjustment

- Adjusted the monthly sales summary cards in the admin sales management screen.
- Changed the summary area to keep four blocks in one row with horizontal overflow on narrow screens.
- Made the refund and chargeback summary blocks toggleable.
- When refund or chargeback is selected, the screen shows a date-by-date breakdown with the amount beside each date.
- Made each monthly sales calendar date cell clickable so it switches to the daily sales view for that date.
- Did not change backend APIs, database, migrations, Docker, or sales aggregation logic.
- Verification:
  - `cd frontend && pnpm typecheck` passed.

### 2026-06-29 Sales Daily Adjustments

- Added an event-date based daily refund/chargeback API:
  - `GET /admin/api/sales/daily-adjustments?date=YYYY-MM-DD`
- Kept `GET /admin/api/sales/daily-payments` as `paid_at` based.
- Added daily sales summary values for total sales, refund amount, chargeback amount, and net sales.
- Added refund/chargeback adjustment rows with event date, type, negative amount display, original payment date, payment ID, user, purchase plan, payment method, and current status.
- Added refund date and chargeback date to the daily payment list rows.
- Updated monthly refund/chargeback aggregation to exclude `pending`, `failed`, and `canceled`.
- Did not implement refund/chargeback point reversal, production payment integration, migrations, or admin route-split refactoring.
- Verification:
  - `docker compose exec -T backend php artisan test tests/Unit/SalesManagementReportServiceTest.php` passed: 5 tests, 42 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php` passed: 8 tests, 77 assertions.
  - `cd frontend && pnpm typecheck` passed.

### 2026-06-29 Monthly Sales Google Calendar Layout

- Changed the monthly sales calendar to a Google Calendar-like month grid.
- Added weekday headers and leading/trailing blank cells based on the month start weekday.
- Kept day-cell click behavior that opens the daily sales view for the selected date.
- PC layout uses a 7-column calendar without horizontal scrolling.
- Narrow screens use horizontal scrolling for the calendar grid.
- Did not change backend APIs, database, migrations, Docker, or sales aggregation logic.
- Verification:
  - `cd frontend && pnpm typecheck` passed.

### 2026-06-29 Monthly Point Consumption Google Calendar Layout

- Changed the monthly point consumption calendar to the same Google Calendar-like month grid.
- Added weekday headers and leading/trailing blank cells based on the month start weekday.
- Made each point consumption date cell clickable so it switches to the daily point consumption view for that date.
- PC layout uses a 7-column calendar without horizontal scrolling.
- Narrow screens use horizontal scrolling for the calendar grid.
- Did not change backend APIs, database, migrations, Docker, or point aggregation logic.
- Verification:
  - `cd frontend && pnpm typecheck` passed.

## 2026-06-29 Refund / Chargeback Design

- Created design-only refund/chargeback documents.
- No code, Migration, DB operation, Docker operation, dependency change, admin route refactor, or production payment connection was performed.
- Created:
  - `docs/design/REFUND_CHARGEBACK_DESIGN.md`
  - `docs/review/REFUND_CHARGEBACK_IMPACT.md`
  - `docs/review/REFUND_CHARGEBACK_MIGRATION_PLAN.md`
  - `docs/review/REFUND_CHARGEBACK_TEST_PLAN.md`
- Refined confirmed design points:
  - Chargeback point cancellation order is paid purchase from paid lots, free bonus from free lots, then paid purchase shortfall from remaining free lots, then shortfall.
  - `free_point_amount` must never be canceled from paid point lots.
  - Bucket values are `paid_purchase_from_paid`, `free_bonus_from_free`, `paid_purchase_shortfall_from_free`, and `shortfall`.
  - Chargeback holds all unshipped prizes for the target user.
  - Recommended statuses are `user_prizes.status=held`, `shipping_items.status=hold`, and `shipping_items.status=return_requested`.
  - Discord and return-request mail are sent after DB commit; failures do not rollback DB work and must be retryable from admin.

## 2026-06-30 Refund / Chargeback Phase 1

- Implemented Phase 1 only: Migration, Models, Enums, and Relations.
- Added migration:
  - `backend/database/migrations/2026_06_30_000001_create_payment_reversal_tables.php`
- Added models:
  - `PaymentReversal`
  - `PaymentReversalPointEntry`
  - `PaymentReversalPrizeAction`
- Added payment reversal enums for type, status, point bucket, prize action type, and prize action status.
- Added relations from Payment, User, AdminUser, PointLot, PointLedger, UserPrize, and ShippingItem.
- Extended status enums and DB constraints for:
  - `user_prizes.status=held`
  - `shipping_items.status=hold`
  - `shipping_items.status=return_requested`
- Kept `shipping_requests.status` unchanged.
- Kept `payment_reversals.payment_id` unique for the initial one-final-reversal-per-payment policy.
- Not performed:
  - Migration execution
  - Service implementation
  - API implementation
  - Backend tests
  - Admin UI
  - Docker build
  - Dependency changes

## 2026-06-30 Refund / Chargeback Phase 2A

- Implemented only the requested backend core services:
  - `RefundEligibilityService`
  - `PointReversalService`
- Added unit tests:
  - `backend/tests/Unit/RefundEligibilityServiceTest.php`
  - `backend/tests/Unit/PointReversalServiceTest.php`
- Covered rules:
  - Normal refund requires all payment-origin purchase point lots to be unused.
  - Normal refund is rejected if even 1 payment-origin point is used.
  - Chargeback cancels `paid_point_amount` from paid lots first.
  - Chargeback cancels `free_point_amount` from free lots only.
  - Remaining paid purchase shortfall is canceled from remaining free lots.
  - Remaining shortage is recorded as shortfall.
  - Shortfall rows do not create point ledgers.
  - `point_ledgers.related_type=payment_reversal`.
- Verification:
  - `php -l` passed for the added services and tests.
  - Host `php artisan test` could not run because `backend/vendor/autoload.php` does not exist on host.
  - After Docker state check, targeted tests were run inside the backend container only.
  - `docker compose exec -T backend php artisan test tests/Unit/RefundEligibilityServiceTest.php` passed: 3 tests, 10 assertions.
  - `docker compose exec -T backend php artisan test tests/Unit/PointReversalServiceTest.php` passed: 4 tests, 34 assertions.
- Additional Phase 2A test reinforcement:
  - Added explicit coverage that normal refund does not alter lots from other payments or campaign lots.
  - Added explicit coverage for paid FIFO and free expiration-order lot reversal.
  - Added explicit coverage that expired free lots are excluded from chargeback reversal.
  - Re-ran `docker compose exec -T backend php artisan test tests/Unit/PointReversalServiceTest.php`; passed: 7 tests, 55 assertions.
- Not performed:
  - PaymentRefundService
  - ChargebackReversalService
  - ChargebackPrizeActionService
  - Admin API
  - Admin UI
  - frontend changes
  - Discord notification
  - Mail sending
  - Production payment webhook
  - Docker build/restart
  - Dependency changes

## 2026-06-30 Refund / Chargeback Phase 2B

- Implemented only the requested backend execution services:
  - `PaymentRefundService`
  - `ChargebackReversalService`
  - `ChargebackPrizeActionService`
- Added unit tests:
  - `backend/tests/Unit/PaymentRefundServiceTest.php`
  - `backend/tests/Unit/ChargebackReversalServiceTest.php`
  - `backend/tests/Unit/ChargebackPrizeActionServiceTest.php`
- Covered rules:
  - Normal refund succeeds only when payment-origin points are fully unused.
  - Normal refund rejects used payment-origin points.
  - Normal refund marks payment as `refunded` and sets `refunded_at`.
  - Chargeback marks payment as `chargeback`, sets `chargeback_at`, and suspends the user.
  - Chargeback uses the Phase 2A current-balance point reversal rules.
  - Unshipped stored/requested/packing prizes are held.
  - Shipped/delivered items are marked return-requested.
  - Draw results, draw sequence numbers, sold counts, and won counts are not modified.
  - Completed refund and chargeback operations are idempotent.
- Verification:
  - `php -l` passed for the added services and tests.
  - Docker state was checked before container test execution.
  - `docker compose exec -T backend php artisan test tests/Unit/PaymentRefundServiceTest.php` passed: 3 tests, 13 assertions.
  - `docker compose exec -T backend php artisan test tests/Unit/ChargebackPrizeActionServiceTest.php` passed: 2 tests, 13 assertions.
  - `docker compose exec -T backend php artisan test tests/Unit/ChargebackReversalServiceTest.php` passed: 2 tests, 13 assertions.
- Not performed:
  - Admin API
  - Admin UI
  - frontend changes
  - Discord notification actual sending
  - Mail sending
  - Production payment webhook
  - Admin route refactor
  - Docker build/restart
  - Dependency changes

## 2026-06-30 Refund / Chargeback Phase 3 Backend API

- Implemented only the requested backend API layer:
  - Admin Controllers
  - Request classes
  - Resource classes
  - Backend Feature Tests
- Added/updated endpoints:
  - `GET /admin/api/payments/{payment}/refund-eligibility`
  - `POST /admin/api/payments/{payment}/refund`
  - `POST /admin/api/payments/{payment}/chargeback`
  - `GET /admin/api/payment-reversals`
  - `GET /admin/api/payment-reversals/{paymentReversal}`
  - `POST /admin/api/payment-reversals/{paymentReversal}/release-holds`
  - `POST /admin/api/payment-reversal-prize-actions/{action}/mark-returned`
- Controller behavior:
  - Refund/chargeback core logic remains in Services.
  - Controllers convert domain/runtime failures to validation `422` where appropriate.
  - Refund/chargeback POST APIs return `PaymentReversalResource` with HTTP 200.
  - Admin auth remains the existing `auth:sanctum` + `EnsureAdminUser` group.
- Verification:
  - `php -l` passed for changed controllers, requests, resources, and new feature tests.
  - Docker state was checked before container test execution.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentRefundChargebackApiTest.php` passed: 5 tests, 39 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReversalApiTest.php` passed: 4 tests, 22 assertions.
  - `docker compose exec -T backend php artisan route:list --path=admin/api` confirmed the new routes.
- Not performed:
  - Admin UI
  - frontend changes
  - Discord notification actual sending
  - Mail sending
  - Production payment webhook
  - Admin route refactor
  - Docker build/restart
  - Dependency changes
  - Real environment migration execution

## 2026-06-30 Refund / Chargeback Phase 4 Admin UI

- Executed real environment migration after user approval:
  - `docker compose exec -T backend php artisan migrate --force`
  - Migration completed: `2026_06_30_000001_create_payment_reversal_tables`
- Implemented Admin UI only in the existing stable admin dashboard structure:
  - `frontend/src/app/admin-dashboard.tsx`
  - `frontend/src/app/globals.css`
- Added sales management UI features:
  - Refund eligibility display in daily sales payment rows.
  - Per-payment refund eligibility fetch button.
  - Normal refund action with confirmation and reason prompt.
  - Chargeback registration action with danger confirmation and reason prompt.
  - `返金/CB履歴` view.
  - Payment reversal list.
  - Payment reversal detail with point reversal entries and prize actions.
  - Hold release action with confirmation.
  - Return-requested action mark-returned operation with confirmation.
- Kept reversal APIs out of `refreshAll()`.
- Reversal APIs are called only from sales management view or explicit operations.
- Verification:
  - `cd frontend && pnpm typecheck` passed.
- Not performed:
  - Backend Service changes
  - Backend API changes
  - frontend route split/refactor
  - Discord actual sending
  - Mail sending
  - Production payment webhook
  - Docker build/restart
  - Dependency changes
  - Next.js build
  - Browser/E2E manual verification

## 2026-06-30 Refund / Chargeback History Period Search

- Added period search for the sales management `返金/CB履歴` view.
- Backend API:
  - `GET /admin/api/payment-reversals` now accepts `date_from=YYYY-MM-DD` and `date_to=YYYY-MM-DD`.
  - Filtering is based on `payment_reversals.occurred_at`.
  - Date boundaries are evaluated as Asia/Tokyo calendar dates with range conditions.
  - Invalid ranges such as `date_to < date_from` return validation errors.
- Admin UI:
  - Added start date and end date inputs to the `返金/CB履歴` tab.
  - Added search and clear buttons.
  - Search resets to page 1 and keeps reversal APIs out of `refreshAll()`.
- Verification:
  - `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReversalApiTest.php` passed: 6 tests, 29 assertions.
  - `cd frontend && pnpm typecheck` passed.
- Not performed:
  - Browser/E2E manual verification.
  - Next.js build.
  - Docker build/restart.

## 2026-06-30 Refund / Chargeback Phase 5 Return Request Mail

- Implemented chargeback return request mail.
- Added:
  - `backend/app/Mail/ChargebackReturnRequestMail.php`
  - `backend/resources/views/mail/chargeback_return_request.blade.php`
  - `backend/app/Domain/Payment/Services/PaymentReturnRequestMailService.php`
- Updated chargeback processing:
  - `ChargebackReversalService` now calls return request mail sending after the DB transaction commits.
  - Mail failure does not roll back payment, point, prize, or shipping changes.
- Added Admin API:
  - `POST /admin/api/payment-reversals/{paymentReversal}/send-return-request-mail`
  - Reversals without return-requested actions return `422`.
  - Already sent return request mails are not sent twice.
  - Unsent or failed actions can be sent/resend.
- Updated Admin UI:
  - Return-requested prize actions now show mail status, sent/attempted dates, last error, and send/resend button.
  - Resend action has a confirmation dialog.
  - Return request mail API is not added to `refreshAll()`.
- Verification:
  - `docker compose exec -T backend php artisan test tests/Unit/ChargebackReturnRequestMailTest.php` passed: 1 test, 6 assertions.
  - `docker compose exec -T backend php artisan test tests/Unit/PaymentReturnRequestMailServiceTest.php` passed: 3 tests, 17 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReturnRequestMailApiTest.php` passed: 4 tests, 16 assertions.
  - `docker compose exec -T backend php artisan test tests/Unit/ChargebackReversalServiceTest.php` passed: 3 tests, 19 assertions.
  - `cd frontend && pnpm typecheck` passed.
- Not performed:
  - Browser/E2E manual verification.
  - Next.js build.
  - Docker build/restart.
  - Discord notification/resend.
  - Normal refund completion mail.
  - Production payment webhook.

## 2026-07-01 Sales Management CSV Backend API

- Implemented Backend CSV export API only.
- No Admin UI changes were made.
- Added:
  - `backend/app/Domain/Admin/Services/SalesManagementCsvService.php`
  - `backend/tests/Feature/AdminSalesCsvExportTest.php`
- Updated:
  - `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`
  - `backend/routes/admin.php`
  - `docs/md/spec_v1.6_draft.md`
- Added endpoints:
  - `GET /admin/api/sales/monthly.csv`
  - `GET /admin/api/sales/daily-payments.csv`
  - `GET /admin/api/sales/daily-adjustments.csv`
  - `GET /admin/api/sales/monthly-point-consumption.csv`
  - `GET /admin/api/sales/daily-point-consumption.csv`
- CSV behavior:
  - UTF-8 BOM is prepended.
  - Japanese headers are used.
  - Download filenames include the target month or date.
  - Daily payment CSV uses `paid_at`.
  - Daily refund/chargeback CSV uses `refunded_at` / `chargeback_at`.
  - Daily point consumption CSV is grouped by `draw_request`.
- Verification:
  - `php -l` passed for the new CSV service, updated sales controller, and new CSV feature test.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminSalesCsvExportTest.php` passed: 3 tests, 45 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php` passed: 8 tests, 77 assertions.
- Not performed:
  - Admin UI implementation.
  - Frontend changes.
  - Docker build/restart.
  - Dependency changes.
  - Migration/DB schema changes.

## 2026-07-01 Sales Management CSV Admin UI

- Implemented Admin UI CSV download buttons only.
- Updated:
  - `frontend/src/app/admin-dashboard.tsx`
  - `frontend/src/app/globals.css`
  - `docs/md/spec_v1.6_draft.md`
- Added CSV download buttons to:
  - Monthly sales
  - Daily payment list
  - Daily refund/chargeback list
  - Monthly point consumption
  - Daily point consumption
- Download behavior:
  - CSV APIs are called only when a CSV button is clicked.
  - CSV APIs are not added to `refreshAll()`.
  - The selected month or selected date is used.
  - Buttons are disabled while the matching CSV request is running.
  - Backend `Content-Disposition` filename is preferred.
  - A frontend fallback filename is used if `Content-Disposition` is missing.
  - Errors are shown through the existing admin notice message.
- Verification:
  - `cd frontend && pnpm typecheck` passed.
- Not performed:
  - Backend changes.
  - Admin route-split refactor.
  - Browser manual verification.
  - Next.js build.
  - Docker build/restart.
  - Dependency changes.

## 2026-07-01 Sales Management Performance Review

- Performed static performance review only.
- Created:
  - `docs/review/SALES_MANAGEMENT_PERFORMANCE_REVIEW.md`
- Reviewed:
  - `SalesManagementReportService`
  - `SalesManagementCsvService`
  - Sales management routes/controller
  - Relevant migrations for `payments`, `point_ledgers`, `draw_requests`, `draw_results`, `gachas`, and `point_purchase_plans`
- Findings:
  - No obvious N+1 issue was found in the sales-management query paths.
  - `payments` lacks reporting-oriented indexes for `paid_at`, `refunded_at`, and `chargeback_at` range scans.
  - `point_ledgers` lacks a reporting-oriented index for global draw spend scans by `ledger_type`, `related_type`, and `created_at`.
  - CSV output is generated in memory and daily payment / daily point CSVs currently request up to 10,000 rows.
- Not performed:
  - Production DB load testing.
  - `EXPLAIN ANALYZE`.
  - Index migration.
  - Backend API changes.
  - Admin UI changes.
  - Docker build/restart.
  - Dependency changes.

## 2026-07-03 Point Balance Snapshot Admin Read API

- Implemented backend read API only for daily point balance snapshots.
- Added:
  - `backend/app/Http/Controllers/Admin/Point/AdminPointBalanceSnapshotController.php`
  - `backend/app/Http/Requests/Admin/IndexPointBalanceSnapshotRequest.php`
  - `backend/app/Http/Requests/Admin/PointBalanceSnapshotBaseDateRequest.php`
  - `backend/app/Http/Resources/PointBalanceSnapshotResource.php`
  - `backend/tests/Feature/AdminPointBalanceSnapshotApiTest.php`
- Updated:
  - `backend/routes/admin.php`
  - `docs/md/spec_v1.6_draft.md`
- Added admin endpoints:
  - `GET /admin/api/point-balance-snapshots/latest`
  - `GET /admin/api/point-balance-snapshots`
  - `GET /admin/api/point-balance-snapshots/base-dates`
- Behavior:
  - Latest API returns the newest `snapshot_date`, or `data=null` if no snapshot exists.
  - List API supports `date_from`, `date_to`, `page`, and `per_page`.
  - `per_page` is capped at 100.
  - Base-date API returns March 31 and September 30 for the specified year.
  - Missing base-date snapshots return `exists=false` and `snapshot=null`.
  - API fields expose `paid_balance`, `free_balance`, and `total_balance` while preserving existing DB columns.
  - `updated_at` is returned as `null` because the current table does not store it.
- Verification:
  - `php -l` passed for the new controller, requests, resource, and feature test.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminPointBalanceSnapshotApiTest.php` passed: 6 tests, 45 assertions.
- Not performed:
  - Admin UI implementation.
  - Migration/DB schema changes.
  - Docker build/restart.
  - Dependency changes.

## 2026-07-14 QA Test User Draw Design Update

- Updated QA test user draw design documents only.
- Updated:
  - `docs/design/QA_TEST_USER_DRAW_DESIGN.md`
  - `docs/review/QA_TEST_USER_DRAW_IMPACT.md`
  - `docs/review/QA_TEST_USER_DRAW_MIGRATION_PLAN.md`
  - `docs/review/QA_TEST_USER_DRAW_TEST_PLAN.md`
- Confirmed design decisions:
  - Normal draw fallback happens only when QA mode is disabled or expired.
  - Active QA mode without a target-gacha active plan returns 422 before point consumption.
  - Setting shortage, inventory shortage, and invalid QA configuration return 422 before point consumption.
  - QA mode `ends_at` is required, with maximum 24 hours recommended for initial validation.
  - `qa_draw_executions` is required.
  - Fully consumed plans become `completed` automatically.
  - Completed plans are not reactivated; a new sequence requires a new plan.
  - Before new activation, expired or fully consumed active plans are completed.
  - Initial QA setting and history read/write access is Owner-only.
  - Admin draw history, management screens, and draw-request based CSV should expose QA identification.
  - `user_prizes` can trace QA origin through `draw_result_id -> draw_results`, so no initial `user_prizes` QA column is required.
- Not performed:
  - Code changes.
  - Migration creation.
  - DB operations.
  - Docker build/restart.

## 2026-07-14 QA Test User Draw Phase 1

- Implemented Migration, Model, Enum, and Relation layer only.
- Added migration:
  - `backend/database/migrations/2026_07_14_000001_create_qa_test_user_draw_tables.php`
- Added enum:
  - `backend/app/Domain/Gacha/Enums/QaDrawPlanStatus.php`
- Added models:
  - `backend/app/Models/QaTestUserMode.php`
  - `backend/app/Models/QaDrawPlan.php`
  - `backend/app/Models/QaDrawPlanItem.php`
  - `backend/app/Models/QaDrawExecution.php`
- Updated model relations:
  - `AdminUser`
  - `User`
  - `Gacha`
  - `GachaPrize`
  - `RankAsset`
  - `DrawRequest`
  - `DrawResult`
- Schema behavior:
  - `qa_test_user_modes.ends_at` is required.
  - `qa_test_user_modes.user_id` is unique.
  - `qa_draw_plans` uses a PostgreSQL partial unique index to allow only one active plan per user/gacha.
  - `draw_requests.is_qa_draw` and `draw_results.is_qa_draw` default to false.
  - `qa_draw_executions.draw_request_id` is unique.
  - `user_prizes` is unchanged; QA origin remains traceable through `draw_result_id -> draw_results`.
  - QA-related foreign keys use restrictive delete behavior.
- Not performed:
  - Migration execution against the real environment.
  - DrawService changes.
  - QaDrawResolver implementation.
  - Admin API/UI.
  - Frontend changes.
  - CSV changes.
  - Docker build/restart.

## 2026-07-14 QA Test User Draw Phase 2A

- Implemented Owner-only Admin API for QA test user mode.
- Added:
  - `backend/app/Domain/Gacha/Services/QaTestUserModeService.php`
  - `backend/app/Http/Controllers/Admin/User/AdminQaTestUserModeController.php`
  - `backend/app/Http/Requests/Admin/UpsertQaTestUserModeRequest.php`
  - `backend/app/Http/Resources/QaTestUserModeResource.php`
  - `backend/tests/Unit/QaTestUserModeServiceTest.php`
  - `backend/tests/Feature/AdminQaTestUserModeApiTest.php`
- Updated:
  - `backend/routes/admin.php`
  - `docs/md/spec_v1.6_draft.md`
- Added endpoints:
  - `GET /admin/api/users/{user}/qa-test-mode`
  - `PUT /admin/api/users/{user}/qa-test-mode`
  - `DELETE /admin/api/users/{user}/qa-test-mode`
- Behavior:
  - All endpoints require Owner role.
  - Admin/operator receive 403 for reads and writes.
  - Unauthenticated requests receive 401.
  - `PUT` creates or updates the existing row.
  - `reason` and `ends_at` are required.
  - `starts_at` is optional.
  - `ends_at` must be after `starts_at` or current time.
  - Duration is capped at 24 hours.
  - `DELETE` disables the row without physical deletion.
  - Expired or disabled rows are returned as `is_active=false`.
  - Audit logs are recorded for enable/update/disable.
- Verification:
  - `php -l` passed for the new Service, Controller, Request, Resource, and test files.
  - `docker compose exec -T backend php artisan test tests/Unit/QaTestUserModeServiceTest.php` passed: 4 tests, 22 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminQaTestUserModeApiTest.php` passed: 6 tests, 52 assertions.
- Not performed:
  - Real environment migration execution.
  - QA draw plan API.
  - QaDrawResolver.
  - DrawService changes.
  - Admin UI/frontend.
  - CSV changes.
  - Docker build/restart.

## 2026-07-15 QA Test User Draw Phase 2B

- Implemented Owner-only Admin API for QA draw plan management.
- Added:
  - `backend/app/Domain/Gacha/Services/QaDrawPlanService.php`
  - `backend/app/Http/Controllers/Admin/User/AdminQaDrawPlanController.php`
  - `backend/app/Http/Requests/Admin/StoreQaDrawPlanRequest.php`
  - `backend/app/Http/Requests/Admin/UpdateQaDrawPlanRequest.php`
  - `backend/app/Http/Resources/QaDrawPlanResource.php`
  - `backend/app/Http/Resources/QaDrawPlanItemResource.php`
  - `backend/tests/Unit/QaDrawPlanServiceTest.php`
  - `backend/tests/Feature/AdminQaDrawPlanApiTest.php`
- Updated:
  - `backend/routes/admin.php`
  - `docs/md/spec_v1.6_draft.md`
- Added endpoints:
  - `GET /admin/api/users/{user}/qa-draw-plans`
  - `POST /admin/api/users/{user}/qa-draw-plans`
  - `GET /admin/api/qa-draw-plans/{plan}`
  - `PUT /admin/api/qa-draw-plans/{plan}`
  - `DELETE /admin/api/qa-draw-plans/{plan}`
  - `POST /admin/api/qa-draw-plans/{plan}/pause`
  - `POST /admin/api/qa-draw-plans/{plan}/activate`
- Behavior:
  - All endpoints require Owner role.
  - Admin/operator receive 403.
  - Unauthenticated requests receive 401.
  - `completed` plans cannot be activated again.
  - Only one active plan is allowed per user/gacha.
  - Expired or fully consumed active plans are completed before a new active plan is created or activated.
  - `gacha_prize_id` must belong to the target gacha.
  - `quantity` must be greater than zero.
  - `sort_order` must be unique in a plan payload.
  - `rank_image_asset_id` must be an image asset.
  - `draw_video_asset_id` must be a video asset.
  - `DELETE` changes status to `disabled` without deleting the plan row.
  - Audit logs are recorded for create/update/pause/activate/disable/auto-complete.
- Verification:
  - `php -l` passed for the new Service, Controller, Request, Resource, and test files.
  - `docker compose exec -T backend php artisan test tests/Unit/QaDrawPlanServiceTest.php` passed: 4 tests, 21 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/AdminQaDrawPlanApiTest.php` passed: 5 tests, 52 assertions.
- Not performed:
  - Real environment migration execution.
  - QaDrawResolver.
  - DrawService changes.
  - Normal draw API changes.
  - Point or inventory processing changes.
  - Admin UI/frontend.
  - CSV changes.
  - Docker build/restart.

## 2026-07-15 QA Test User Draw Phase 3A

- Implemented QA draw resolver only.
- Added:
  - `backend/app/Domain/Gacha/DTO/QaDrawSelection.php`
  - `backend/app/Domain/Gacha/DTO/QaDrawSelectedItem.php`
  - `backend/app/Domain/Gacha/Services/QaDrawResolver.php`
  - `backend/tests/Unit/QaDrawResolverTest.php`
- Updated:
  - `docs/md/spec_v1.6_draft.md`
- Behavior:
  - No active QA mode, disabled QA mode, and expired QA mode return inactive selection.
  - Active QA mode with no active plan throws `DrawException`.
  - Insufficient remaining configured items throws `DrawException`.
  - Plan items are expanded by `sort_order`, `id`, `quantity`, and `consumed_count`.
  - Mode, plan, and plan items can be read with `lockForUpdate()`.
  - Prize gacha ownership, prize active state, aggregate inventory, image asset type, and video asset type are validated before point consumption.
  - Invalid QA configuration does not fall back to normal probability draw.
- Verification:
  - `php -l` passed for the new DTO, Resolver, and test files.
  - `docker compose exec -T backend php artisan test tests/Unit/QaDrawResolverTest.php` passed: 8 tests, 25 assertions.
- Not performed:
  - DrawService integration.
  - Normal draw API changes.
  - Point consumption changes.
  - Inventory updates.
  - QA plan `consumed_count` updates.
  - `qa_draw_executions` creation.
  - Admin API/UI changes.
  - Frontend changes.
  - CSV changes.
  - Real environment migration execution.
  - Docker build/restart.

## 2026-07-15 QA Test User Draw Phase 3B

- Integrated `QaDrawResolver` into the existing `DrawService`.
- Added:
  - `backend/tests/Feature/QaTestUserDrawApiTest.php`
- Updated:
  - `backend/app/Domain/Gacha/Services/DrawService.php`
  - `docs/md/spec_v1.6_draft.md`
- Integration points:
  - Completed idempotency-key lookup remains before QA resolver execution.
  - QA resolver runs inside the existing DB transaction after gacha lock, drawable checks, and daily limit checks.
  - QA resolver runs before point spendability check and point consumption.
  - QA active draw stores QA fields on `draw_requests` and `draw_results`.
  - QA active draw creates `qa_draw_executions`.
- QA draw behavior:
  - Inactive, disabled, or expired QA mode uses the existing normal probability draw flow.
  - Active QA mode with missing/invalid plan throws `DrawException` and does not fall back to normal probability draw.
  - Selected QA prizes are locked by ID order and inventory is revalidated after lock.
  - Probability stage is still resolved for each result.
  - Normal probability `pick()` is not used for QA prize selection.
  - `random_value` still uses backend CSPRNG.
  - QA draw creates prize results only, no point-back result.
  - Existing point consumption, `won_count`, `sold_count`, `draw_results`, and `user_prizes` flows are used.
  - Fixed image/video asset URLs are stored when configured.
  - `qa_draw_plan_items.consumed_count` updates inside the same transaction as result creation.
  - Fully consumed QA plans are marked `completed`.
  - Failed QA settings/inventory validation rolls back point, inventory, sold count, won count, consumed count, draw request/result, user prize, and QA execution changes.
- Verification:
  - `php -l` passed for `DrawService` and `QaTestUserDrawApiTest`.
  - `docker compose exec -T backend php artisan test tests/Feature/QaTestUserDrawApiTest.php` passed: 6 tests, 53 assertions.
  - `docker compose exec -T backend php artisan test tests/Feature/DrawApiTest.php` passed: 5 tests, 24 assertions.
  - `backend/tests/Unit/DrawServiceTest.php` is not present in the repository.
  - `docker compose exec -T backend php artisan test tests/Unit/QaDrawResolverTest.php` passed: 8 tests, 25 assertions.
- Not performed:
  - Admin UI/frontend changes.
  - QA history browse API.
  - CSV changes.
  - Sales management UI changes.
  - Additional migrations.
  - Docker build/restart.
  - Dependency changes.
  - Full concurrent draw test.

## 2026-07-15 QA Test User Draw Phase 4A

- Implemented Owner-only QA test user mode UI in the existing stable admin dashboard user detail screen.
- Migration pre-check:
  - `docker compose exec -T backend php artisan migrate:status` confirmed `2026_07_14_000001_create_qa_test_user_draw_tables` is `[13] Ran`.
- Updated:
  - `frontend/src/app/admin-dashboard.tsx`
  - `docs/md/spec_v1.6_draft.md`
- UI location:
  - Admin dashboard > ユーザー管理 > ユーザー詳細.
- Behavior:
  - QA section is shown only when `session.admin.role === "owner"`.
  - Admin/operator do not see the section and do not call QA APIs.
  - QA API is not added to `refreshAll()`.
  - Owner fetches QA mode only when opening user detail.
  - QA state is reset when selecting another user or leaving user detail.
  - UI displays state, reason, start/end dates, enabled/disabled admin IDs, disabled date, created date, and updated date.
  - Enable/update uses `PUT /admin/api/users/{user}/qa-test-mode`.
  - Disable uses `DELETE /admin/api/users/{user}/qa-test-mode`.
  - Enable/update/disable show confirmation dialogs.
  - Save buttons are disabled while saving/loading.
  - Existing admin message handling is used for API errors.
  - Warning text states that QA mode is not mock behavior and affects real point, inventory, draw, prize, exchange, and shipping data.
- Verification:
  - Static search confirmed `qa-test-mode` calls are only in user-detail flow and not in `refreshAll()`.
  - `cd frontend && pnpm typecheck` passed.
- Not performed:
  - Browser manual verification.
  - QA draw plan UI.
  - Prize setting UI.
  - Fixed image/video selection UI.
  - QA execution history UI.
  - Backend API changes.
  - DrawService changes.
  - CSV changes.
  - Sales management changes.
  - Additional migrations.
  - Docker build/restart.
  - Admin route-split refactoring.

## 2026-07-15 QA Test User Draw Phase 4B-1-1

- Implemented Owner-only read-only QA draw plan list UI in the existing stable admin dashboard user detail screen.
- Confirmed actual API response shape from:
  - `backend/app/Http/Resources/QaDrawPlanResource.php`
  - `backend/app/Http/Resources/QaDrawPlanItemResource.php`
  - `backend/app/Http/Resources/RankAssetResource.php`
- Updated:
  - `frontend/src/app/admin-dashboard.tsx`
  - `docs/md/spec_v1.6_draft.md`
  - `worklogs/codex-main.md`
- UI location:
  - Admin dashboard > ユーザー管理 > ユーザー詳細 > QAテストユーザー section直下.
- Behavior:
  - QA draw plan list is shown only when `session.admin.role === "owner"`.
  - Admin/operator do not see the list and do not call QA draw plan APIs.
  - QA draw plan APIs are not added to `refreshAll()`.
  - Owner fetches `GET /admin/api/users/{user}/qa-draw-plans` only when opening a user detail screen.
  - Plan detail fetch `GET /admin/api/qa-draw-plans/{plan}` runs only when expanding a plan's prize setting row.
  - QA draw plan list/detail state is reset when switching users or leaving user detail.
  - A request id guard prevents stale responses from overwriting the currently selected user's state.
  - The read-only list displays plan ID, gacha name/ID, status, title, reason, start/end, item row count, total quantity, consumed count, remaining count, created date, and updated date.
  - The expanded prize settings display sort order, prize name, gacha prize ID, quantity, consumed count, remaining count, fixed rank image, and fixed draw video.
  - No create/edit/pause/activate/disable/delete buttons were added.
- Not performed:
  - Backend API changes.
  - DB or Migration changes.
  - DrawService changes.
  - QA draw plan creation/editing UI.
  - Fixed image/video selection UI.
  - QA execution history UI.
  - CSV changes.
  - Sales management changes.
  - Docker build/restart.
  - Admin route-split refactoring.

## 2026-07-15 QA Test User Draw Phase 4B-1-2

- Implemented Owner-only QA draw plan create form foundation in the existing stable admin dashboard user detail screen.
- Start checks:
  - `cd frontend && pnpm typecheck` passed before implementation.
  - `git diff --check` passed before implementation.
- Confirmed actual backend request/resource shape from:
  - `backend/app/Http/Requests/Admin/StoreQaDrawPlanRequest.php`
  - `backend/app/Domain/Gacha/Services/QaDrawPlanService.php`
  - `backend/app/Http/Resources/QaDrawPlanResource.php`
  - `backend/app/Http/Resources/QaDrawPlanItemResource.php`
  - `backend/app/Http/Resources/AdminGachaPrizeResource.php`
  - `backend/app/Http/Resources/RankAssetResource.php`
  - `backend/app/Http/Controllers/Admin/Gacha/AdminGachaPrizeController.php`
  - `backend/app/Http/Controllers/Admin/Gacha/AdminRankAssetController.php`
- Updated:
  - `frontend/src/app/admin-dashboard.tsx`
  - `docs/md/spec_v1.6_draft.md`
  - `worklogs/codex-main.md`
- UI location:
  - Admin dashboard > ユーザー管理 > ユーザー詳細 > QA排出プラン一覧 section下.
- Behavior:
  - The "QA排出プランを新規作成" button and create form are shown only when `session.admin.role === "owner"`.
  - Admin/operator do not see the form and do not call QA draw plan option APIs.
  - The form displays target gacha, title, reason, starts_at, and ends_at fields.
  - `StoreQaDrawPlanRequest` allows nullable `status`, and `QaDrawPlanService` defaults omitted status to active; this phase does not add a status input because no POST is performed.
  - Existing `gachas` state is used for target gacha choices.
  - Existing `rankAssets` state is reused when present.
  - If `rankAssets` is empty, `GET /admin/api/rank-assets?per_page=100` runs only when opening the create form.
  - Active image assets and active video assets are counted separately.
  - Existing `gachaPrizes` state is reused if it already contains rows for the selected gacha.
  - If selected-gacha prizes are not cached, `GET /admin/api/gacha-prizes?gacha_id={gachaId}&per_page=100` runs only after target gacha selection.
  - Gacha change clears current prize candidates and uses a request guard to avoid stale response overwrite.
  - Switching users, leaving user detail, or canceling the form resets create form and option state.
  - QA draw plan option API calls are not added to `refreshAll()`.
  - Save API is not called in this phase.
- Not performed:
  - `POST /admin/api/users/{user}/qa-draw-plans`.
  - Plan saving.
  - Multiple prize setting rows.
  - Prize row add/delete/sorting.
  - Quantity input.
  - Fixed image/video select controls.
  - Existing plan editing.
  - Pause/activate/disable operations.
  - QA execution history UI.
  - Backend API changes.
  - DB or Migration changes.
  - DrawService changes.
  - CSV changes.
  - Sales management changes.
  - Docker build/restart.
  - Admin route-split refactoring.

## 2026-07-15 QA Test User Draw Phase 4B-1-3-1

- Implemented Owner-only QA draw plan item input UI in the existing stable admin dashboard create form.
- Required start checks:
  - Read `docs/design/QA_TEST_USER_DRAW_DESIGN.md`.
  - Read `docs/review/QA_TEST_USER_DRAW_IMPACT.md`.
  - Read `docs/review/QA_TEST_USER_DRAW_TEST_PLAN.md`.
  - Read `docs/md/spec_v1.6_draft.md`.
  - Read `worklogs/codex-main.md`.
  - `cd frontend && pnpm typecheck` passed before implementation.
  - `git diff --check` passed before implementation.
- Confirmed backend behavior:
  - `StoreQaDrawPlanRequest` requires `items.*.sort_order`, `items.*.gacha_prize_id`, and `items.*.quantity`.
  - `StoreQaDrawPlanRequest` allows nullable `items.*.rank_image_asset_id` and `items.*.draw_video_asset_id`.
  - `quantity` has `min:1` and no explicit upper bound.
  - `QaDrawPlanService` validates selected prize gacha ownership and fixed asset type.
  - `QaDrawPlanService` does not add a create-time inactive-prize selection restriction, so the UI does not independently disable inactive prizes.
- Updated:
  - `frontend/src/app/admin-dashboard.tsx`
  - `docs/md/spec_v1.6_draft.md`
  - `worklogs/codex-main.md`
- Behavior:
  - Opening the QA draw plan create form shows one empty item row.
  - Item rows include `sort_order`, `gacha_prize_id`, `quantity`, `rank_image_asset_id`, and `draw_video_asset_id`.
  - Rows can be added.
  - Rows can be moved up/down.
  - Rows can be deleted except the last remaining row.
  - Add/delete/move operations normalize `sort_order` to one-based consecutive numbers.
  - `quantity` and `sort_order` inputs accept only positive integer strings.
  - Target gacha change asks for confirmation when item rows have input.
  - Confirmed target gacha change clears old prize candidates and resets item rows to one empty row.
  - Prize select is disabled until a gacha is selected and while selected-gacha candidates are loading.
  - Prize options show prize ID, name, rank name, active state, max win count, won count, and remaining win count.
  - Fixed image select contains only active image assets plus normal presentation.
  - Fixed video select contains only active video assets plus normal presentation.
  - Form summary shows item row count, total configured quantity, selected gacha, fixed-image row count, and fixed-video row count.
- Not performed:
  - `POST /admin/api/users/{user}/qa-draw-plans`.
  - Plan saving.
  - Save confirmation dialog.
  - List refresh after creation.
  - Existing plan editing.
  - Pause/activate/disable operations.
  - QA execution history UI.
  - Backend API changes.
  - DB or Migration changes.
  - DrawService changes.
  - CSV changes.
  - Sales management changes.
  - Docker build/restart.
  - Admin route-split refactoring.

## 2026-07-16 QA Test User Draw Phase 4B-1-3-2

- Implemented QA draw plan create form save flow in the existing stable admin dashboard.
- Scope:
  - Frontend only.
  - `frontend/src/app/admin-dashboard.tsx` only.
  - No backend/API/DB/Migration changes.
- Required start checks:
  - Read `docs/md/spec_v1.6_draft.md`.
  - Read `docs/design/QA_TEST_USER_DRAW_DESIGN.md`.
  - Read `worklogs/codex-main.md`.
  - `cd frontend && pnpm typecheck` passed before implementation.
  - `git diff --check` passed before implementation.
- Behavior:
  - Added save button to the QA draw plan create form.
  - Added save-in-progress state and disables the save button while saving or while required option data is loading.
  - Added pre-submit validation for selected gacha, required reason, and item rows with prize and positive quantity.
  - Added confirmation dialog before saving.
  - Connected save to `POST /admin/api/users/{user}/qa-draw-plans`.
  - Payload uses existing backend field names:
    - `gacha_id`
    - `title`
    - `reason`
    - `starts_at`
    - `ends_at`
    - `items[].sort_order`
    - `items[].gacha_prize_id`
    - `items[].quantity`
    - `items[].rank_image_asset_id`
    - `items[].draw_video_asset_id`
  - `status` is not sent; backend default remains responsible for the initial status.
  - Uses existing `showMessage` notice/error handling.
  - On success, resets the create form and option state.
  - On success, reloads the selected user's QA draw plan list with `GET /admin/api/users/{user}/qa-draw-plans`.
  - QA draw plan save API is not added to `refreshAll()`.
- Verification:
  - `cd frontend && pnpm typecheck` passed after implementation.
  - `git diff --check` passed after implementation.
  - Static search confirmed save API is called only from the submit handler and not from `refreshAll()`.
- Not performed:
  - Browser manual verification.
  - Backend changes.
  - DB or Migration changes.
  - DrawService changes.
  - Existing plan editing.
  - Pause/activate/disable operations.
  - QA execution history UI.
  - CSV changes.
  - Sales management changes.
  - Docker build/restart.
  - Admin route-split refactoring.
