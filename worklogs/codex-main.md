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
