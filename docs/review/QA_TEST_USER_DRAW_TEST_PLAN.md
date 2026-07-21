# QA Test User Draw Test Plan

## Scope

This test plan covers the future implementation of QA test user draw mode.

No tests are added by this design task.

## Unit Tests

### QaTestUserModeService

- Owner can enable QA mode with reason and `ends_at`.
- Enabling QA mode requires `ends_at`.
- Enabling QA mode rejects `ends_at` more than 24 hours ahead.
- Owner can disable QA mode.
- Admin/operator cannot view, enable, or disable QA mode.
- Expired QA mode is treated as inactive.
- Disabled QA mode is treated as inactive.
- Active QA mode requires enabled flag, no `disabled_at`, and valid time window.

### QaDrawPlanService

- Owner can create plan for user/gacha.
- Plan requires at least one item.
- Plan item prize must belong to the selected gacha.
- Plan item quantity must be greater than zero.
- Image asset must be active and `asset_type=image`.
- Video asset must be active and `asset_type=video`.
- Only one active plan per user/gacha is allowed.
- Expired active plan is completed before new plan activation.
- Fully consumed active plan is completed before new plan activation.
- Completed plan cannot be reactivated.
- Updating item order preserves unique sort order.
- Pausing/disabling a plan prevents QA draw use.

### QaDrawResolver

- Returns inactive selection when user has no active QA mode.
- Returns inactive selection when QA mode is disabled.
- Returns inactive selection when QA mode is expired.
- Throws when QA mode is active and no active plan exists for gacha.
- Throws when active plan has fewer remaining items than requested draw count.
- Expands ordered items by quantity and consumed count.
- Locks selected plan items.
- Aggregates selected items by prize and validates inventory before point consumption.
- Throws when prize is inactive.
- Throws when prize belongs to another gacha.
- Throws when fixed asset is inactive or wrong type.
- Does not allow fallback to normal probability when QA mode is active but configuration is invalid.
- Marks the plan completed when the selected draw consumes the final configured item.

## Feature Tests: Draw API

### Normal Behavior Preservation

- Non-QA user uses existing probability draw.
- QA mode disabled returns to normal probability draw.
- Expired QA mode returns to normal probability draw.
- QA mode active without a target-gacha plan returns 422 and does not draw normally.
- Existing idempotency behavior still returns completed draw.
- Daily draw limit still applies.
- Gacha inactive still fails.
- Insufficient points still fails.

### QA Forced Prize Draw

- Active QA user with active plan receives configured first prize.
- Multiple draw count consumes configured items in order.
- Plan item `quantity` allows repeated prize output.
- `consumed_count` increments only after successful draw.
- Plan status changes to `completed` when all items are consumed.
- Completed plans are not used for later draws.
- Draw creates `draw_requests.is_qa_draw=true`.
- Draw creates `draw_results.is_qa_draw=true`.
- Draw stores `qa_draw_plan_id`, `qa_test_user_mode_id`, and `qa_draw_plan_item_id`.
- Draw stores selected fixed rank image URL when configured.
- Draw stores selected fixed video URL when configured.
- Draw creates `user_prizes` normally.
- Draw increments `gachas.sold_count` normally.
- Draw increments `gacha_prizes.won_count` normally.
- Draw marks gacha sold out normally when count reaches total.
- Draw consumes points normally and creates point ledgers.
- Draw creates required `qa_draw_executions` row.
- `user_prizes` created from QA draw can be traced through `draw_result_id` to QA result flags.

### Failure Before Point Consumption

For each case, assert no point ledger, no wallet change, no draw request, no sold count change, and no won count change:

- QA mode active but no plan.
- Plan has insufficient remaining entries.
- Configured prize inactive.
- Configured prize inventory insufficient.
- Configured prize belongs to another gacha.
- Fixed image asset inactive or not image.
- Fixed video asset inactive or not video.

### No Probability Fallback

- QA mode active with invalid plan must fail.
- It must not call normal probability range selection as fallback.
- It must not create point-back result as fallback.
- It must return 422 for missing plan, setting shortage, inventory shortage, and invalid settings.

### Idempotency

- Retrying completed QA draw with same idempotency key returns existing result.
- Retrying completed QA draw does not increment `consumed_count` again.
- Retrying completed QA draw does not increment `sold_count` or `won_count` again.
- In-flight duplicate still fails with existing processing behavior.

### Concurrency

- Two concurrent QA draws for same user/gacha cannot consume the same plan item twice.
- Two concurrent QA draws cannot exceed configured prize inventory.
- Sequence numbers remain unique and gapless per gacha.
- `sold_count` remains consistent.

## Admin API Tests

### Authorization

- Unauthenticated request returns 401.
- Normal admin/operator returns 403 for create/update/delete.
- Normal admin/operator returns 403 for QA setting and history reads.
- Owner can create/update/delete QA mode and plans.
- Owner can read QA execution history.

### QA Mode API

- Can fetch current QA mode for user.
- Can enable QA mode.
- Can update reason and end date.
- Can disable QA mode.
- Invalid `ends_at` returns 422.
- `ends_at` more than 24 hours ahead returns 422.
- Missing reason returns 422.

### QA Plan API

- Can list plans for user.
- Can create plan for user/gacha.
- Can update plan items.
- Can pause/disable plan.
- Rejects duplicate active plan for same user/gacha.
- Auto-completes expired or fully consumed active plan before activating a new one.
- Rejects reactivation of completed plan.
- Rejects prize from different gacha.
- Rejects inactive/wrong-type assets.

### Audit

- Enabling QA mode creates audit log.
- Disabling QA mode creates audit log.
- Creating/updating plan creates audit log.
- Executing QA draw creates audit log or `qa_draw_executions` row.
- Executing QA draw always creates `qa_draw_executions` row.

### History / CSV Identification

- Admin draw request API exposes `is_qa_draw`.
- Admin draw result API exposes `is_qa_draw`.
- User detail admin draw history can identify QA draws.
- Sales management point consumption CSV includes `is_qa_draw` for draw-request rows.
- Existing `user_prizes` rows can be traced to QA origin through `draw_result_id`.

## Admin UI / Browser Checks

- User detail shows QA mode section.
- Owner sees controls.
- Admin/operator cannot see or cannot operate controls according to final policy.
- Toggle ON requires reason and auto end date.
- Toggle OFF disables QA mode.
- Plan editor can add multiple prize rows.
- Plan editor supports quantity and ordering.
- Plan editor supports fixed image/video asset selection.
- UI displays remaining configured count.
- UI warns that points, inventory, sold count, user prizes, exchange, and shipping are real.

## Regression Tests

- Existing normal draw tests still pass.
- StageResolver and ProbabilityRangeBuilder tests still pass.
- Point consumption tests still pass.
- User prize exchange tests still pass.
- Shipping request tests still pass.
- Sales management point consumption includes QA draw unless a future filter is added.

## Recommended Test Execution

Run target tests individually, not in parallel:

- `docker compose exec -T backend php artisan test tests/Unit/QaTestUserModeServiceTest.php`
- `docker compose exec -T backend php artisan test tests/Unit/QaDrawPlanServiceTest.php`
- `docker compose exec -T backend php artisan test tests/Unit/QaDrawResolverTest.php`
- `docker compose exec -T backend php artisan test tests/Feature/AdminQaTestUserDrawApiTest.php`
- `docker compose exec -T backend php artisan test tests/Feature/QaTestUserDrawApiTest.php`

Run broader draw regression only after target tests pass.
