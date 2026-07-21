# QA Test User Draw Design

## Purpose

通常ユーザーを一時的にQAテストユーザーへ切り替え、通常抽選APIを使ったまま、管理者が指定した景品を順番・回数付きで排出できるようにする。

この文書は設計のみであり、コード、Migration、DB、Docker、依存関係は変更しない。

## Source Of Truth

優先順位:

1. 人間による最新の明示決定
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`
4. 本設計文書

今回の確定要件:

- 管理画面のユーザー詳細でテストユーザーを一時ON/OFFする。
- 自動終了日時と理由を保持する。
- ユーザー別・ガチャ別に複数景品を順番・回数付きで設定する。
- 任意のランク画像・ガチャ動画も固定可能にする。
- 専用抽選APIは作らず、通常抽選APIを使用する。
- 景品選択だけ管理者設定へ切り替える。
- 決済、ポイント購入、ポイント消費、在庫、`sold_count`、`won_count`、`user_prizes`、交換、発送依頼は通常どおり処理する。
- 設定不足や在庫不足はポイント消費前にエラーにする。
- 通常確率抽選へフォールバックしない。
- QA抽選フラグと監査ログを残す。
- テストモード解除後は通常抽選へ戻る。
- 過去の処理は巻き戻さない。
- スーパー管理者のみ設定可能にする。

## Current Implementation Summary

### Normal Draw Flow

Current `DrawService::draw()` flow:

1. Validate `draw_count` and `idempotency_key`.
2. Start DB transaction.
3. Lock existing `draw_requests` row by idempotency key.
4. Lock `gachas` row with `lockForUpdate()`.
5. Validate gacha status, published probability version, remaining draw count, and daily draw limit.
6. Check spendable points.
7. Create `draw_requests` with status `processing`.
8. Consume points through `PointConsumptionService`.
9. For each draw:
   - Resolve probability stage by sequence number.
   - Build probability range.
   - Generate CSPRNG value.
   - Pick probability entry.
   - If prize: lock prize, validate inventory, increment `won_count`, create `draw_results`, create `user_prizes`.
   - If point back: create point-back `draw_results`, grant minimum guarantee.
   - Increment `gachas.sold_count`.
10. Mark gacha `sold_out` when sold out.
11. Mark `draw_requests` as `completed`.

### Important Existing Constraints

- `draw_sequence_number` is based on locked `gachas.sold_count`.
- `draw_results.result_type` is either `prize` or `point_back`.
- `draw_results.random_value` must be between `0` and `999999`.
- `draw_results` already stores selected presentation URLs:
  - `selected_rank_image_url`
  - `selected_draw_video_url`
- `user_prizes` are created only for prize results.
- Point consumption, inventory update, sold count update, draw result creation, and user prize creation are already in one DB transaction.
- Rank image/video can currently be selected from `rank_assets` via rank asset relations.

## Proposed Behavior

### QA Mode Scope

QA mode is a temporary state attached to a normal user.

When a user is in active QA mode and has an active QA draw plan for the target gacha:

- The normal user-facing draw endpoint is used.
- The normal `draw_count`, idempotency, point consumption, daily limit, inventory, sold count, user prize, exchange, and shipping flows remain active.
- Only the prize selection step is replaced by the QA draw plan.
- The draw must produce the configured prize results in configured order.
- The draw must not fall back to normal probability if the QA plan cannot satisfy the requested draw count.

When QA mode is disabled or expired:

- Normal probability draw is used.

When QA mode is active but no active plan exists for the target gacha:

- The draw fails with validation error 422 before point consumption.
- The draw must not fall back to normal probability.

### Error Timing

The QA plan must be resolved and validated before point consumption.

If any of the following is true, the draw fails before points are consumed:

- QA mode is active but the target gacha has no active QA plan.
- The active plan does not have enough remaining configured prize entries for the requested draw count.
- A configured prize does not belong to the target gacha.
- A configured prize is inactive.
- A configured prize does not have enough remaining inventory for the number of configured entries needed.
- A configured presentation asset is inactive or has the wrong asset type.
- The current admin configuration is inconsistent.

No fallback to normal probability is allowed in these cases. All of these errors
must be returned as 422 validation errors by the normal draw API.

## Proposed Data Model

### `qa_test_user_modes`

Stores temporary QA mode for a normal user.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `user_id` | FK users | target normal user |
| `is_enabled` | boolean | current QA mode |
| `reason` | text | required reason |
| `starts_at` | timestamp nullable | optional start |
| `ends_at` | timestamp | required automatic end |
| `enabled_by_admin_user_id` | FK admin_users | owner only |
| `disabled_by_admin_user_id` | FK admin_users nullable | who disabled |
| `disabled_at` | timestamp nullable | manual off timestamp |
| `created_at`, `updated_at` | timestamps |  |

Recommended constraints/indexes:

- unique `user_id`
- index `is_enabled, ends_at`
- check: `starts_at IS NULL OR ends_at > starts_at`

Active condition:

- `is_enabled = true`
- `disabled_at IS NULL`
- `starts_at IS NULL OR starts_at <= now()`
- `ends_at > now()`

Operational rule:

- `ends_at` is required.
- Recommended maximum duration is 24 hours.
- Initial Request validation should reject `ends_at` values more than 24 hours after activation unless a future explicit exception policy is approved.

### `qa_draw_plans`

Stores a user/gacha-specific QA draw plan.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `user_id` | FK users | target normal user |
| `gacha_id` | FK gachas | target gacha |
| `status` | string | `active`, `paused`, `completed`, `disabled` |
| `title` | string nullable | admin label |
| `reason` | text | admin reason |
| `starts_at` | timestamp nullable | optional start |
| `ends_at` | timestamp nullable | optional end |
| `created_by_admin_user_id` | FK admin_users | owner only |
| `updated_by_admin_user_id` | FK admin_users nullable | latest updater |
| `created_at`, `updated_at` | timestamps |  |

Recommended constraints/indexes:

- index `user_id, gacha_id, status`
- index `gacha_id, status`
- check status in `active`, `paused`, `completed`, `disabled`

Only one active plan per `user_id + gacha_id` should be allowed. PostgreSQL partial unique index is recommended:

- unique `(user_id, gacha_id)` where `status = 'active'`

If partial unique indexes are avoided initially, enforce this in Service with a locked query.

Plan lifecycle:

- Before activating a new plan, expired or fully consumed active plans for the same user/gacha are marked `completed`.
- When all items are consumed by successful QA draws, the plan is automatically changed to `completed`.
- Completed plans are immutable for draw use and must not be reactivated.
- If the same user/gacha needs another QA sequence, create a new plan.
- `paused` and `disabled` plans are not used by the draw resolver.

### `qa_draw_plan_items`

Stores ordered prize entries with repeat counts.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `qa_draw_plan_id` | FK qa_draw_plans | parent |
| `sort_order` | integer | sequence order |
| `gacha_prize_id` | FK gacha_prizes | forced prize |
| `quantity` | integer | total configured count |
| `consumed_count` | integer | used count |
| `rank_image_asset_id` | FK rank_assets nullable | fixed result image asset, must be image |
| `draw_video_asset_id` | FK rank_assets nullable | fixed draw video asset, must be video |
| `created_at`, `updated_at` | timestamps |  |

Recommended constraints/indexes:

- index `qa_draw_plan_id, sort_order`
- index `gacha_prize_id`
- check `quantity > 0`
- check `consumed_count >= 0 AND consumed_count <= quantity`
- unique `qa_draw_plan_id, sort_order`

Selection rule:

- Expand rows by `sort_order`, consuming each item until `consumed_count < quantity` is exhausted.
- For a draw count of `N`, reserve the next `N` entries under DB lock before point consumption.
- Increment `consumed_count` only inside the same transaction that creates `draw_results`.

### `qa_draw_executions`

Required execution record for audit, history, and reporting.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `qa_test_user_mode_id` | FK qa_test_user_modes | active mode |
| `qa_draw_plan_id` | FK qa_draw_plans | used plan |
| `draw_request_id` | FK draw_requests unique | generated draw request |
| `user_id` | FK users | denormalized |
| `gacha_id` | FK gachas | denormalized |
| `draw_count` | integer | request count |
| `reason` | text nullable | copied from mode/plan |
| `created_at` | timestamp |  |

This is separate from `audit_logs`. It is required so QA draw filtering does not
depend only on JSON metadata.

### Existing Table Changes

#### `draw_requests`

Add:

- `is_qa_draw` boolean default false
- `qa_draw_plan_id` nullable FK
- `qa_test_user_mode_id` nullable FK

Purpose:

- Allows sales/admin/draw history to identify QA draws at request level.

#### `draw_results`

Add:

- `is_qa_draw` boolean default false
- `qa_draw_plan_item_id` nullable FK

Purpose:

- Identifies each forced result.
- Preserves per-result linkage to the configured item.

Existing `selected_rank_image_url` and `selected_draw_video_url` should store the final URLs used for QA output, as they already do for random rank assets.

#### `user_prizes`

No initial QA column is required on `user_prizes`.

Reason:

- `user_prizes.draw_result_id` is already unique and required.
- QA origin can be traced by joining `user_prizes.draw_result_id` to `draw_results.id`.
- `draw_results.is_qa_draw` and `draw_results.qa_draw_plan_item_id` provide the QA identification.

If future performance or reporting needs require direct filtering without a join, add `user_prizes.is_qa_draw` in a separate approved migration.

## Service Design

### `QaTestUserModeService`

Responsibilities:

- Enable QA mode for a user.
- Disable QA mode.
- Determine whether a user currently has active QA mode.
- Enforce owner-only permissions at admin Service/Request level.
- Record audit logs:
  - `admin.qa_test_user.enabled`
  - `admin.qa_test_user.disabled`
  - `admin.qa_test_user.updated`

### `QaDrawPlanService`

Responsibilities:

- Create/update/delete/pause QA draw plans.
- Validate user/gacha/prize consistency.
- Validate fixed asset types:
  - image asset for rank image
  - video asset for draw video
- Enforce one active plan per user/gacha.
- Complete expired or fully consumed active plans before activating a new plan for the same user/gacha.
- Automatically mark a plan `completed` when all configured item quantities have been consumed.
- Reject reactivation of `completed` plans.
- Record audit logs:
  - `admin.qa_draw_plan.created`
  - `admin.qa_draw_plan.updated`
  - `admin.qa_draw_plan.disabled`
  - `admin.qa_draw_plan.completed`

### `QaDrawResolver`

Used by `DrawService`.

Responsibilities:

- Check whether the user is in active QA mode.
- Load active QA plan for the user/gacha.
- If QA mode is active and no active plan exists for the gacha, throw `DrawException`.
- Lock plan and plan items with `lockForUpdate()`.
- Resolve the next `draw_count` forced prize entries.
- Validate all selected prizes and assets before point consumption.
- Validate aggregate inventory needs per prize before point consumption.
- Return a `QaDrawSelection` DTO:
  - active or not active
  - mode id
  - plan id
  - ordered selected item list
  - prize id
  - fixed image URL if set
  - fixed video URL if set

Important:

- If QA mode is active but a valid plan cannot satisfy the draw, throw `DrawException`.
- Do not return inactive selection and allow normal probability fallback in that case.

## DrawService Integration

### High-Level Flow

The normal endpoint remains unchanged:

- `POST /api/gachas/{gacha}/draw`

`DrawService::draw()` changes internally:

1. Start transaction.
2. Lock idempotency record if any.
3. Lock gacha.
4. Validate normal drawable conditions and daily limit.
5. Resolve active QA state and QA selection before point consumption.
   - If QA mode is disabled or expired, continue with normal probability flow.
   - If QA mode is active and the plan is missing or invalid, return 422 before point consumption.
6. Check spendable points.
7. Create `draw_requests` with `is_qa_draw` and QA references if QA is active.
8. Consume points normally.
9. For each draw:
   - Always resolve probability stage by sequence number so `probability_version_stage_id` remains populated.
   - If QA active:
     - Use selected QA prize instead of probability range pick.
     - Do not call probability `pick()` for prize selection.
     - Generate a CSPRNG `random_value` only to satisfy existing schema and audit shape. It does not determine the QA result.
     - Lock prize and update `won_count` normally.
     - Store `draw_results.is_qa_draw=true` and `qa_draw_plan_item_id`.
     - Store fixed `selected_rank_image_url` / `selected_draw_video_url` if configured, otherwise use existing rank presentation selection.
     - Create `user_prizes` normally.
     - Increment QA item `consumed_count`.
     - If all QA plan items are consumed, mark the plan `completed`.
   - If QA inactive:
     - Use existing probability flow unchanged.
10. Increment `sold_count` normally.
11. Mark sold out normally.
12. Complete request normally.
13. Create `qa_draw_executions` and audit metadata for QA draw.

### Point Back Handling

The confirmed requirement is “管理者指定景品を排出する機能”. Therefore QA plan items should be prize-only in the first implementation.

Point-back/minimum guarantee remains only for normal probability flow.

If point-back QA testing is needed later, add a separate item type with explicit approval.

### Presentation Asset Handling

Fixed image/video selection:

- If `qa_draw_plan_items.rank_image_asset_id` is set:
  - Use that asset URL as `draw_results.selected_rank_image_url`.
- If not set:
  - Use existing `selectRankPresentation()` image behavior for the prize rank.
- If `qa_draw_plan_items.draw_video_asset_id` is set:
  - Use that asset URL as `draw_results.selected_draw_video_url`.
- If not set:
  - Use existing `selectRankPresentation()` video behavior for the prize rank.

The final selected URLs must be stored on `draw_results`, so past QA draw results remain reproducible after plan or asset changes.

## Admin API Design

All APIs are under existing admin auth and owner-only authorization.

Suggested endpoints:

- `GET /admin/api/users/{user}/qa-test-mode`
- `PUT /admin/api/users/{user}/qa-test-mode`
- `DELETE /admin/api/users/{user}/qa-test-mode`
- `GET /admin/api/users/{user}/qa-draw-plans`
- `POST /admin/api/users/{user}/qa-draw-plans`
- `GET /admin/api/qa-draw-plans/{plan}`
- `PUT /admin/api/qa-draw-plans/{plan}`
- `DELETE /admin/api/qa-draw-plans/{plan}`
- `POST /admin/api/qa-draw-plans/{plan}/pause`
- `POST /admin/api/qa-draw-plans/{plan}/activate`

Owner-only rule:

- `AdminRole::Owner` is the project’s super administrator equivalent.
- `admin` and `operator` must receive 403 for reads and writes in the initial implementation.
- QA settings, QA plans, and QA execution history are all Owner-only in the initial implementation.

## Admin UI Design

User detail page additions:

- QAテストユーザー section.
- Toggle ON/OFF.
- Reason textarea.
- Auto end datetime.
- Active status badge.
- Warning text that QA draws consume real points and real inventory.

Per user/gacha QA draw plan UI:

- Select gacha.
- Ordered rows:
  - Prize select.
  - Quantity.
  - Remaining count display.
  - Fixed rank image asset select.
  - Fixed draw video asset select.
  - Sort controls.
- Save / pause / disable.
- Show execution history:
  - draw request id
  - gacha
  - draw count
  - consumed plan items
  - created at

Danger warning:

- This is not a mock draw.
- Points, inventory, sold count, prize ownership, exchange, and shipping are real.
- Past results are not rolled back by disabling QA mode.

## Admin History, Reporting, And CSV

QA draws must be identifiable in admin-facing views and exports.

Required policy:

- Admin draw request history should expose `is_qa_draw`.
- Admin draw result history should expose `is_qa_draw`.
- User detail draw history should be able to show a QA badge for QA draw requests/results.
- Sales management point consumption screens should be able to identify QA draws.
- Sales management CSV exports should include an `is_qa_draw` column when draw-request based rows are exported.
- QA execution history should be available through Owner-only admin APIs.

User-facing draw response should not expose internal QA configuration details.

## Audit Requirements

Record audit logs for:

- QA mode enabled.
- QA mode disabled.
- QA mode auto-expired if a scheduled cleanup command is later added.
- QA mode settings changed.
- QA draw plan created.
- QA draw plan updated.
- QA draw plan disabled/paused.
- QA draw executed through normal draw API.

Recommended metadata for QA draw execution audit:

- `user_id`
- `gacha_id`
- `draw_request_id`
- `draw_count`
- `qa_test_user_mode_id`
- `qa_draw_plan_id`
- consumed `qa_draw_plan_item_ids`
- prize ids
- admin reason snapshot

## Security And Operational Rules

- Only owner/super admin can configure QA mode and QA draw plans.
- Only owner/super admin can view QA settings, plans, and QA execution history in the initial implementation.
- Normal users must not see that a QA plan exists except through ordinary draw results.
- QA mode must have an `ends_at` value.
- Recommended maximum duration: 24 hours.
- Recommended maximum configured quantity per plan: operationally capped in Request validation.
- QA draw plans should be marked `completed` automatically when all items are consumed.
- Completed plans must not be reactivated. Create a new plan instead.
- Before activating a new plan, expired or fully consumed active plans for the same user/gacha should be marked `completed`.
- QA draw must not bypass:
  - account active check
  - point balance check
  - daily draw limit
  - gacha active check
  - gacha remaining count
  - prize inventory
  - idempotency

## Open Decisions

- Whether sales reports should include QA draws by default or add a QA filter. Because points and inventory are real, default inclusion is recommended.
