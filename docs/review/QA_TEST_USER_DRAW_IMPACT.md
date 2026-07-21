# QA Test User Draw Impact Review

## Scope

This review covers the impact of temporarily marking normal users as QA test users and forcing configured prizes through the normal draw API.

No implementation, migration, DB operation, Docker operation, or dependency change is performed by this document.

## Existing Areas Affected

### DrawService

Impact:

- `DrawService` must branch internally after gacha/daily-limit validation and before point consumption.
- QA selection must be resolved before point consumption.
- QA mode disabled or expired is the only case that returns to normal probability draw.
- QA mode active with no valid plan must fail with 422 before point consumption.
- QA mode active with insufficient configuration, inventory shortage, or invalid assets must fail with 422 before point consumption.
- QA mode must not create a separate user-facing draw API.
- Existing transaction boundary should remain around point consumption, sequence generation, inventory update, draw result creation, user prize creation, and sold count update.

Risk:

- If QA validation is done after point consumption, failed QA settings can consume points incorrectly.
- If normal probability fallback is allowed accidentally, QA tests become unreliable.

Mitigation:

- Introduce a dedicated `QaDrawResolver`.
- Add tests proving configuration/inventory failures happen before point ledger creation.

### Probability

Impact:

- QA draws bypass probability prize picking only.
- Stage resolution should still run to populate `probability_version_stage_id`.
- Published probability versions remain immutable and unchanged.
- `random_value` can still be generated for schema compatibility, but it is not used to select QA prizes.

Risk:

- QA draws can distort probability analytics if not flagged.

Mitigation:

- Add `is_qa_draw` flags to `draw_requests` and `draw_results`.
- Keep normal draw history but make QA filtering possible.

### Points And Wallets

Impact:

- Point consumption remains normal.
- Free-first and paid-second consumption order remains unchanged.
- Point ledgers use the same `related_type=draw_request`.

Risk:

- QA tests consume real points.

Mitigation:

- Admin UI warning.
- Test user point grants should be managed explicitly.
- QA mode does not bypass balance checks.

### Inventory And Sales Counts

Impact:

- `gachas.sold_count` increments normally.
- `gacha_prizes.won_count` increments normally.
- Sold-out transition remains normal.

Risk:

- QA draws consume real inventory and can sell out a gacha.
- If a configured prize is nearly exhausted, multi-draw QA can overrun inventory unless aggregate validation is performed.

Mitigation:

- Before point consumption, aggregate selected QA entries by prize and compare against locked prize remaining counts.
- Lock selected prizes with `lockForUpdate()`.

### User Prizes, Exchange, Shipping

Impact:

- Prize results create `user_prizes` normally.
- Exchange and shipping request flows remain unchanged.
- QA-earned prizes are operationally real unless later filtered by admin policy.
- QA origin can be traced from `user_prizes.draw_result_id` to `draw_results.is_qa_draw` and `draw_results.qa_draw_plan_item_id`.

Risk:

- Test users can request shipping or exchange QA prizes like normal prizes.

Mitigation:

- This is an accepted confirmed requirement.
- QA flag remains in draw history for audit.
- No direct `user_prizes.is_qa_draw` column is required initially because the current schema has a required unique `draw_result_id`.
- If operational exclusion is needed later, add explicit policy; do not silently change existing exchange/shipping logic.

### Presentation Assets

Impact:

- QA plan can override rank image/video per plan item.
- Existing `draw_results.selected_rank_image_url` and `selected_draw_video_url` should store final URLs.

Risk:

- Asset changes after draw could otherwise change past result display.

Mitigation:

- Snapshot selected URLs on draw result as currently done.

### Admin Users And Authorization

Impact:

- New owner-only admin functionality is needed.
- Current `AdminRole::Owner` maps to the super administrator requirement.
- Initial implementation limits both QA setting operation and QA setting/history viewing to Owner.

Risk:

- Admin/operator users could manipulate QA draw results if authorization is weak.

Mitigation:

- Enforce owner-only authorization in FormRequest/policy/service.
- Add tests for owner success and admin/operator forbidden.

### Audit Logs

Impact:

- Existing `AuditLogService` can record QA setting changes and execution.

Risk:

- Runtime draw execution is user-triggered, not admin-request-triggered, so request context differs.

Mitigation:

- For draw execution, record audit metadata with no admin user or with original configuring admin id in metadata.
- Add `qa_draw_executions` for structured runtime traceability.
- Treat `qa_draw_executions` as required, not optional.

### Sales Management

Impact:

- QA draws consume points and inventory, so current sales/point consumption reports will include them naturally.
- Draw-history admin screens and draw-request based CSV exports should expose `is_qa_draw`.

Risk:

- QA test activity may distort operational sales/point consumption analysis.

Mitigation:

- Because QA draws are real operational actions, default inclusion is acceptable.
- Add future filter by `draw_requests.is_qa_draw` if reporting needs separation.
- Add `is_qa_draw` columns to relevant admin CSV exports when the underlying row is draw-request based.

## API Impact

### User API

No new user API.

Existing endpoint remains:

- `POST /api/gachas/{gacha}/draw`

Response impact:

- Existing response can remain unchanged.
- Optionally include `is_qa_draw` only in admin resources, not public user response, to avoid exposing internal QA state.

### Admin API

New admin-only owner endpoints are needed for:

- QA mode ON/OFF.
- QA draw plan CRUD.
- QA plan activation/pause/disable.
- QA execution history.

All of these are Owner-only in the initial implementation.

## Data Integrity Impact

Required:

- `qa_draw_plan_items.consumed_count` must be updated in the same transaction as draw result creation.
- QA selection and prize inventory must be locked before consumption.
- `draw_requests` and `draw_results` must carry QA flags.
- Idempotent retry must not consume extra QA plan item counts. Existing idempotency handling should return completed request before any new QA reservation.

## Failure Mode Impact

| Failure | Expected result |
| --- | --- |
| QA mode active, no plan | 422 before point consumption |
| Not enough QA items | 422 before point consumption |
| Prize inactive | 422 before point consumption |
| Prize inventory shortage | 422 before point consumption |
| Prize belongs to another gacha | 422 before point consumption |
| Asset inactive/wrong type | 422 before point consumption |
| Point shortage | existing point error |
| Gacha inactive | existing draw error |
| Daily limit exceeded | existing draw error |
| Idempotent completed retry | returns existing draw |

## Operational Impact

- QA mode can create real shipping obligations if test users request shipping.
- QA draws should be limited to controlled accounts and short time windows.
- QA mode requires `ends_at`; maximum duration should be 24 hours in initial validation.
- Production use should require explicit owner approval and reason.
- Fully consumed plans should become `completed` automatically and must not be reactivated.
- Before a new plan is activated, expired or fully consumed active plans for that user/gacha should be completed.

## Compatibility

- Existing completed draws are unchanged.
- Existing draw results without QA flags should be treated as `false`.
- Existing reporting remains compatible if default values are added.

## Recommendation

Implement in phases:

1. Migration + Models + Relations.
2. Admin owner-only API for QA mode and plans.
3. `QaDrawResolver` and DrawService integration.
4. Tests for point-before-error, inventory, idempotency, and no fallback.
5. Admin UI in current stable admin dashboard, without route-split refactor.
