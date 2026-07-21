# QA Test User Draw Migration Plan

## Position

This plan describes the schema changes required for QA test user draw mode. No migration is created by this design task.

## New Tables

### 1. `qa_test_user_modes`

Purpose:

- Store temporary QA mode for each normal user.

Columns:

- `id`
- `user_id` FK `users`
- `is_enabled` boolean default false
- `reason` text
- `starts_at` timestamp nullable
- `ends_at` timestamp
- `enabled_by_admin_user_id` FK `admin_users`
- `disabled_by_admin_user_id` nullable FK `admin_users`
- `disabled_at` timestamp nullable
- timestamps

Indexes/constraints:

- unique `user_id`
- index `is_enabled, ends_at`
- check `starts_at IS NULL OR ends_at > starts_at`
- optional DB check or application validation to limit duration to 24 hours

Rollback caution:

- If active QA modes exist, rollback should either fail or require disabling them first.

### 2. `qa_draw_plans`

Purpose:

- Store user/gacha-specific forced prize plans.

Columns:

- `id`
- `user_id` FK `users`
- `gacha_id` FK `gachas`
- `status` string default `active`
- `title` string nullable
- `reason` text
- `starts_at` timestamp nullable
- `ends_at` timestamp nullable
- `created_by_admin_user_id` FK `admin_users`
- `updated_by_admin_user_id` nullable FK `admin_users`
- timestamps

Indexes/constraints:

- index `user_id, gacha_id, status`
- index `gacha_id, status`
- check `status IN ('active', 'paused', 'completed', 'disabled')`
- recommended PostgreSQL partial unique index:
  - unique `(user_id, gacha_id)` where `status = 'active'`

Alternative:

- If partial unique indexes are avoided, enforce one active plan in Service with `lockForUpdate()`.

Lifecycle requirements:

- Before activating a new plan, expired or fully consumed active plans for the same user/gacha must be marked `completed`.
- A plan whose items are fully consumed must be marked `completed` in the successful draw transaction.
- `completed` plans must not be reactivated.
- A new QA sequence requires a new plan.

### 3. `qa_draw_plan_items`

Purpose:

- Store ordered prize selections and fixed presentation assets.

Columns:

- `id`
- `qa_draw_plan_id` FK `qa_draw_plans`
- `sort_order` unsigned integer
- `gacha_prize_id` FK `gacha_prizes`
- `quantity` unsigned integer
- `consumed_count` unsigned integer default 0
- `rank_image_asset_id` nullable FK `rank_assets`
- `draw_video_asset_id` nullable FK `rank_assets`
- timestamps

Indexes/constraints:

- unique `qa_draw_plan_id, sort_order`
- index `qa_draw_plan_id, sort_order`
- index `gacha_prize_id`
- check `quantity > 0`
- check `consumed_count >= 0 AND consumed_count <= quantity`

Application validations:

- `gacha_prize_id` must belong to the plan gacha.
- `rank_image_asset_id` must reference active image asset.
- `draw_video_asset_id` must reference active video asset.

### 4. `qa_draw_executions`

Purpose:

- Required structured trace of QA draw usage.

Columns:

- `id`
- `qa_test_user_mode_id` FK `qa_test_user_modes`
- `qa_draw_plan_id` FK `qa_draw_plans`
- `draw_request_id` FK `draw_requests` unique
- `user_id` FK `users`
- `gacha_id` FK `gachas`
- `draw_count` unsigned integer
- `reason` text nullable
- `metadata` json nullable
- `created_at`

Indexes:

- unique `draw_request_id`
- index `user_id, created_at`
- index `gacha_id, created_at`
- index `qa_draw_plan_id, created_at`

## Existing Table Changes

### `draw_requests`

Add:

- `is_qa_draw` boolean default false
- `qa_test_user_mode_id` nullable FK `qa_test_user_modes`
- `qa_draw_plan_id` nullable FK `qa_draw_plans`

Indexes:

- index `is_qa_draw, created_at`
- index `qa_draw_plan_id`

### `draw_results`

Add:

- `is_qa_draw` boolean default false
- `qa_draw_plan_item_id` nullable FK `qa_draw_plan_items`

Indexes:

- index `is_qa_draw, created_at`
- index `qa_draw_plan_item_id`

### `user_prizes`

No initial schema change is required.

Reason:

- `user_prizes.draw_result_id` already exists and is unique.
- QA origin can be traced by joining to `draw_results`.
- `draw_results.is_qa_draw` and `draw_results.qa_draw_plan_item_id` identify QA origin.

Future optional change:

- Add `user_prizes.is_qa_draw` only if direct filtering without joins becomes necessary.

## Model Additions

Add:

- `QaTestUserMode`
- `QaDrawPlan`
- `QaDrawPlanItem`
- `QaDrawExecution`

Relations:

- `User hasOne QaTestUserMode`
- `User hasMany QaDrawPlan`
- `Gacha hasMany QaDrawPlan`
- `QaDrawPlan hasMany QaDrawPlanItem`
- `QaDrawPlan belongsTo User`
- `QaDrawPlan belongsTo Gacha`
- `QaDrawPlanItem belongsTo GachaPrize`
- `QaDrawPlanItem belongsTo RankAsset as rankImageAsset`
- `QaDrawPlanItem belongsTo RankAsset as drawVideoAsset`
- `DrawRequest belongsTo QaDrawPlan`
- `DrawResult belongsTo QaDrawPlanItem`

## Enum Additions

Suggested:

- `QaDrawPlanStatus`
  - `active`
  - `paused`
  - `completed`
  - `disabled`

## Migration Phases

### Phase 1: Schema Only

- Create new QA tables.
- Add nullable QA columns to draw tables.
- Add Model relations.
- Add enum.
- No DrawService behavior change yet.

### Phase 2: Admin API

- Owner-only QA mode, plan, and execution-history APIs.
- Validation for gacha/prize/asset consistency.
- Audit logs for configuration changes.
- Auto-complete expired or fully consumed active plans before activating a new plan.

### Phase 3: DrawService Integration

- Add `QaDrawResolver`.
- Add QA selection before point consumption.
- Store QA flags and execution record.
- Mark plans `completed` when all items are consumed.
- Add full backend tests.

### Phase 4: Admin UI

- Add QA controls to current stable `admin-dashboard.tsx`.
- Do not restart admin route-split refactor.

## Rollback Considerations

- Rolling back after QA draws exist is risky because `draw_requests` and `draw_results` will reference QA tables.
- Recommended rollback policy:
  - Do not rollback after production QA draws unless data is archived.
  - If rollback is required, first ensure no rows exist in `qa_draw_executions`, `draw_requests.is_qa_draw=true`, or `draw_results.is_qa_draw=true`.

## Index Considerations

Initial indexes should be enough because QA plans are small and user/gacha scoped.

Potential future indexes:

- `qa_draw_plan_items(qa_draw_plan_id, consumed_count, sort_order)`
- `draw_requests(user_id, is_qa_draw, created_at)`

Add only if observed query volume requires them.
