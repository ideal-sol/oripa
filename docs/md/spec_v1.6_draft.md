# Luxe-pack Specification v1.6 Draft

This document records approved or proposed changes after `docs/md/spec_v1.5.1.md`.

Specification priority is:

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

## Additional Specifications

### v1.6-DRAFT-001: Gacha Category Description Column

Specification name: ガチャカテゴリ説明カラム追加

Formal specification:

- Add `gacha_categories.description`.
- Type: nullable `text`.
- Maximum length: 2,000 characters.
- Use in admin category registration and category editing.
- The admin category list may receive `description` through API data, but the description column is hidden by the latest explicit human decision.
- Return `description` from the admin API.
- Include `description` in public API `gacha.category.description`.
- Do not display category descriptions on user-facing pages at this stage.
- Showing category descriptions on user-facing pages is a separate future task.
- Only enter content that may safely be shown to users.
- Do not enter internal notes, supplier information, cost information, management-only notes, or personal information in `description`.

Implementation status:

- Migration applied.
- Admin API implemented.
- Admin screen implemented.
- Public API implemented.
- User-facing screen display not implemented.
- Target tests passed.

Notes:

- The current explicit human decision after implementation is that the category list should not display the description column.
- Registration and editing screens still use the description field.

### v1.6-DRAFT-002: Daily Point Balance Snapshots

Specification name: 日次残高スナップショット

Current status:

- `point_balance_snapshots` table and Model exist.
- Service, Command, Scheduler, and tests are implemented.
- Target tests passed.

Formal requirement:

- Store daily unused point balances separated by paid and free points.
- `snapshot_date` represents the end-of-day unused balance for that Asia/Tokyo date.
- The daily Scheduler runs at 00:10 JST and creates the snapshot for the previous Asia/Tokyo date.
- Example: execution at 2026-04-01 00:10 JST creates `snapshot_date = 2026-03-31`.
- Example: execution at 2026-10-01 00:10 JST creates `snapshot_date = 2026-09-30`.
- Use the stored daily balances as the basis for funds settlement law support and future reporting.
- Preserve the ability to identify reporting date balances such as March 31 and September 30.
- Keep implementation in Laravel; do not calculate or persist this logic in Next.js.
- Manual `--date=YYYY-MM-DD` execution stores the current `point_lots.remaining_amount` totals under the specified `snapshot_date`.
- Strict historical reconstruction for an arbitrary past timestamp is not provided by this command. If needed, reconstructing past balances from `point_ledgers` is a future separate feature.

Implementation scope for next task:

- Added a domain Service that calculates daily paid/free unused balances.
- Added an Artisan Command to create snapshots for a target date.
- Registered the Command in Scheduler for daily execution.
- Added tests for paid/free aggregation, idempotency, reruns for the same date, base date detection, default previous-day behavior, date option handling, invalid date handling, and Scheduler registration.
- Expired free lots are excluded from snapshot aggregation. The intended daily operation order remains: free point expiration, daily snapshot creation, then consistency checks.

Release status:

- Pre-release critical feature.
- Backend Service, Command, Scheduler, and target tests are completed.
- Backend admin read API for viewing snapshots is completed.
- Admin UI for viewing snapshots remains a separate future task.

Admin read API:

- `GET /admin/api/point-balance-snapshots/latest`
  - Returns the latest snapshot by `snapshot_date`.
  - Returns `data = null` when no snapshot exists.
- `GET /admin/api/point-balance-snapshots`
  - Returns paginated daily snapshots.
  - Supports `date_from=YYYY-MM-DD`, `date_to=YYYY-MM-DD`, `page`, and `per_page`.
  - `per_page` is capped at 100.
- `GET /admin/api/point-balance-snapshots/base-dates`
  - Requires `year=YYYY`.
  - Returns the March 31 and September 30 snapshots for the specified year.
  - If a base-date snapshot does not exist, returns `exists=false` and `snapshot=null`.

Admin API response fields:

- `id`
- `snapshot_date`
- `paid_balance`
- `free_balance`
- `total_balance`
- `is_base_date`
- `created_at`
- `updated_at`

Notes:

- The existing DB columns remain `paid_unused_balance` and `free_unused_balance`.
- The admin read API maps those columns to `paid_balance` and `free_balance`.
- The current table does not store `updated_at`, so `updated_at` is returned as `null`.

### v1.6-DRAFT-003: Sales Management Backend Read API

Specification name: 売上管理 Backend Read API

Current status:

- Backend read-only API, aggregation Service, and target tests are implemented.
- Admin UI is not implemented at this stage.
- No migration was added.
- No production payment provider connection was added.
- Refund/chargeback point reversal remains out of scope.

Formal requirement:

- Provide admin-only read APIs for monthly sales, daily payments, monthly point consumption, daily point consumption, and draw request details.
- Keep the APIs under existing admin authentication: `auth:sanctum` and `EnsureAdminUser`.
- Use Asia/Tokyo date boundaries.
- Do not use `whereDate`; use start-inclusive and end-exclusive datetime ranges.
- Monthly ranges are from the first day of the month at `00:00:00` to the first day of the next month at `00:00:00`.
- Daily ranges are from the target date at `00:00:00` to the next date at `00:00:00`.

Sales aggregation:

- Gross sales include payment amounts whose `paid_at` is in the target range and whose status is `succeeded`, `refunded`, or `chargeback`.
- `pending`, `failed`, and `canceled` are excluded from gross sales.
- Refund amount is the sum of payment amounts whose `refunded_at` is in the target range and whose status is `refunded`.
- Chargeback amount is the sum of payment amounts whose `chargeback_at` is in the target range and whose status is `chargeback`.
- Net sales is gross sales minus refund amount minus chargeback amount.
- Daily payment lists use `paid_at` as the date basis and show current payment status.
- Daily refund/chargeback adjustment lists use `refunded_at` / `chargeback_at` as the date basis.
- Monthly calendar refund and chargeback amounts are event-date based, not original payment-date based.
- Daily sales screens separate the `paid_at` payment list from the refund/chargeback adjustment list to avoid mixing original payment dates and event dates.

Payment method and purchase plan:

- No `payment_method` column is added in the initial implementation.
- Payment method is `payments.metadata.payment_method` when present; otherwise `payments.provider`.
- Purchase plan is resolved from `payments.metadata.point_purchase_plan_id`.
- If the purchase plan no longer exists, the API returns a fallback label instead of failing.
- Payment plan snapshot columns are future work if needed.

Point consumption:

- Point consumption uses `point_ledgers` as the source of truth.
- Only `ledger_type = spend`, `amount < 0`, and `related_type = draw_request` are included.
- `related_id` links to `draw_requests.id`.
- Displayed consumption uses `ABS(amount)`.
- Paid and free point consumption are separated by `point_type`.
- Admin deductions, expiration, exchange, compensation, and other non-draw ledger rows are excluded from gacha consumption.
- Daily point consumption rows are grouped by `draw_request`, not by individual ledger row.
- Draw request details include child `draw_results` with result type, rank, prize, consumed point, granted point, and draw sequence number.

Implemented endpoints:

- `GET /admin/api/sales/monthly`
- `GET /admin/api/sales/daily-payments`
- `GET /admin/api/sales/daily-adjustments`
- `GET /admin/api/sales/monthly-point-consumption`
- `GET /admin/api/sales/daily-point-consumption`
- `GET /admin/api/sales/draw-requests/{drawRequest}`

Tests:

- `backend/tests/Unit/SalesManagementReportServiceTest.php`
- `backend/tests/Feature/AdminSalesManagementApiTest.php`
- Target tests passed on 2026-06-29.

### v1.6-DRAFT-004A: Sales Management CSV Backend API

Specification name: 売上管理CSV Backend API

Current status:

- Backend CSV export API is implemented.
- Admin UI CSV download buttons are implemented in `v1.6-DRAFT-004B`.
- No DB, Migration, production payment, refund/chargeback reversal logic, or frontend UI changes are included.

Implemented endpoints:

- `GET /admin/api/sales/monthly.csv`
- `GET /admin/api/sales/daily-payments.csv`
- `GET /admin/api/sales/daily-adjustments.csv`
- `GET /admin/api/sales/monthly-point-consumption.csv`
- `GET /admin/api/sales/daily-point-consumption.csv`

CSV requirements:

- CSV responses include UTF-8 BOM.
- CSV headers are Japanese.
- `Content-Disposition` filenames include the target month or target date.
- Monthly sales CSV includes daily total sales, refund amount, chargeback amount, net sales, counts, and payment method summaries.
- Daily payments CSV uses `paid_at` as the date basis.
- Daily refund/chargeback CSV uses `refunded_at` / `chargeback_at` as the event date basis.
- Monthly point consumption CSV includes daily paid/free point consumption and gacha-level consumption rows.
- Daily point consumption CSV is grouped by `draw_request`.

Verification:

- `docker compose exec -T backend php artisan test tests/Feature/AdminSalesCsvExportTest.php` passed on 2026-07-01.
- `docker compose exec -T backend php artisan test tests/Feature/AdminSalesManagementApiTest.php` passed on 2026-07-01.

### v1.6-DRAFT-004B: Sales Management CSV Admin UI

Specification name: 売上管理CSVダウンロードUI

Current status:

- Admin UI CSV download buttons are implemented in the current stable `admin-dashboard.tsx` structure.
- Admin route-split refactor remains deferred.
- No Backend API, DB, Migration, production payment, or refund/chargeback logic changes are included in this UI phase.

Implemented UI behavior:

- Monthly sales view has a CSV download button.
- Daily sales payment list has a CSV download button.
- Daily refund/chargeback list has a CSV download button.
- Monthly point consumption view has a CSV download button.
- Daily point consumption list has a CSV download button.
- CSV download uses the currently selected month or date.
- CSV is fetched only when the admin clicks a download button.
- CSV APIs are not added to `refreshAll()`.
- Download buttons are disabled while the corresponding CSV is being downloaded.
- The frontend uses the Backend `Content-Disposition` filename when available.
- If `Content-Disposition` is unavailable, the frontend uses a fallback filename containing the selected month or date.
- Download errors are shown through the existing admin notice message.

Verification:

- `cd frontend && pnpm typecheck` passed on 2026-07-01.

### v1.6-DRAFT-004: Sales Management Admin UI

Specification name: 売上管理 管理画面UI

Current status:

- Admin UI is implemented in the current stable admin dashboard structure.
- The deferred admin route-split refactor remains deferred.
- No backend API, DB, Migration, production payment, or refund/chargeback point reversal changes were made for this UI task.
- Frontend typecheck passed.

Formal requirement:

- Rename the previous independent payment menu to `売上管理`.
- Place `売上管理` above `お知らせ` in the admin sidebar.
- Do not keep the old `決済` menu as an independent menu.
- Use `/admin/sales` as the primary admin URL.
- Treat `/admin/payments` as a compatibility URL that opens the sales management screen.
- Display monthly sales, daily sales, monthly point consumption, daily point consumption, and draw request details.
- Do not add sales APIs to `refreshAll()`.
- Do not call sales APIs from unrelated admin pages such as `/admin/guide` or `/admin/gachas`.
- Fetch sales data only when the sales screen is opened, when month/date controls change, or when the draw detail button is selected.

Implemented screen behavior:

- Monthly sales calendar displays date, gross sales, refund amount, chargeback amount, net sales, payment method/provider breakdown, and payment count.
- Monthly sales calendar date cells can be selected to open the daily sales screen for that date.
- Monthly sales summary displays refund and chargeback day-by-day breakdowns by toggle.
- Daily sales screen displays a daily summary, a `paid_at`-based payment list, and a `refunded_at` / `chargeback_at`-based adjustment list.
- Daily sales payment table displays payment date, payment method, purchase plan, amount, status, user, refund date, chargeback date, and provider.
- Daily refund/chargeback adjustment table displays event date, type, amount, original payment date, payment ID, user, purchase plan, payment method, and current status.
- Monthly point consumption calendar displays paid/free point consumption totals and gacha-level point consumption/draw counts.
- Daily point consumption table displays datetime, paid/free point consumption, user, gacha, draw count, status, and detail button.
- Draw request detail section displays draw request metadata and child draw results.

Verification:

- `cd frontend && pnpm typecheck` passed on 2026-06-29.
- Browser/manual QA remains to be completed by opening the admin screen in the running environment.

### v1.6-DRAFT-005: Refund / Chargeback Phase 1 Schema

Specification name: 返金・チャージバック基盤スキーマ

Current status:

- Phase 1 backend schema/model/relation implementation is added.
- Migration execution is not performed in this task.
- Service, API, tests, and admin UI are not implemented yet.
- Production payment integration remains out of scope.

Formal requirement:

- Normal refunds are possible only when all points granted by the target payment remain unused.
- If any target payment-origin point has been used, normal refund is not allowed.
- Chargeback is handled separately from normal refund.
- Chargeback point cancellation uses the confirmed order:
  1. Cancel `paid_point_amount` from current paid point lots.
  2. Cancel `free_point_amount` from current free point lots.
  3. Cancel remaining paid purchase shortfall from remaining free point lots.
  4. Record any remaining shortage as shortfall.
- `free_point_amount` must never be canceled from paid point lots.
- Chargeback holds all unshipped prizes for the target user.
- Shipped/delivered prizes are recorded as return-request targets.
- Draw results, draw sequence numbers, sold counts, and won counts are not rolled back.

Implemented schema/model additions:

- `payment_reversals`
- `payment_reversal_point_entries`
- `payment_reversal_prize_actions`
- `PaymentReversal`
- `PaymentReversalPointEntry`
- `PaymentReversalPrizeAction`
- Payment reversal enum classes for type, status, point bucket, prize action type, and prize action status.

Status additions:

- `user_prizes.status=held`
- `shipping_items.status=hold`
- `shipping_items.status=return_requested`
- `shipping_requests.status` is not changed.

Initial uniqueness policy:

- `payment_reversals.payment_id` is unique in the initial implementation.
- If future partial refunds, multiple adjustments, or chargeback reversal restoration require multiple rows per payment, this unique constraint must be changed in a separate approved migration.

### v1.6-DRAFT-006: Refund / Chargeback Phase 2A Point Reversal Core

Specification name: 返金・チャージバック ポイント取消中核Service

Current status:

- `RefundEligibilityService` is implemented.
- `PointReversalService` is implemented.
- Unit tests for the two services passed.
- Payment refund execution Service, chargeback execution Service, prize action Service, Admin API, Admin UI, notifications, and production payment webhook handling are not implemented in this phase.

Formal requirement:

- Normal refund eligibility requires all payment-origin purchase point lots to be fully unused.
- If any payment-origin paid/free point has been used, normal refund is not eligible.
- Normal refund point reversal cancels only the target payment-origin purchase lots.
- Chargeback point reversal cancels from the user's current point balance, not only from target payment-origin lots.
- Chargeback order is:
  1. Cancel `paid_point_amount` from current paid point lots.
  2. Cancel `free_point_amount` from current free point lots.
  3. Cancel any remaining paid purchase shortfall from remaining free point lots.
  4. Record any remaining shortage as shortfall.
- `free_point_amount` must not be canceled from paid point lots.
- Wallet balances and point lot balances must not become negative.
- `point_ledgers` are created only for actual deductions.
- Shortfall rows are recorded in `payment_reversal_point_entries` without point ledger rows.
- Point ledger `related_type` is `payment_reversal`.
- Buckets are:
  - `paid_purchase_from_paid`
  - `free_bonus_from_free`
  - `paid_purchase_shortfall_from_free`
  - `shortfall`

Tests:

- `backend/tests/Unit/RefundEligibilityServiceTest.php`
- `backend/tests/Unit/PointReversalServiceTest.php`
- Target tests passed on 2026-06-30.

### v1.6-DRAFT-007: Refund / Chargeback Phase 2B Execution Services

Specification name: 返金・チャージバック実行Service

Current status:

- `PaymentRefundService` is implemented.
- `ChargebackReversalService` is implemented.
- `ChargebackPrizeActionService` is implemented.
- Target unit tests passed.
- Admin API, Admin UI, frontend changes, actual Discord sending, actual mail sending, production payment webhook, and admin route refactor are not implemented in this phase.

Formal requirement:

- `PaymentRefundService` performs normal refund only when the target payment-origin points are fully unused.
- Normal refund creates a `payment_reversal`, cancels target payment-origin point lots, creates cancel ledgers for actual deductions, and marks the payment as `refunded` with `refunded_at`.
- If any target payment-origin point has been used, normal refund fails and does not mark the payment as refunded.
- Completed normal refund execution is idempotent and does not create duplicate reversal rows or ledgers.
- `ChargebackReversalService` creates a `payment_reversal`, performs current-balance point reversal, applies prize actions, marks the payment as `chargeback` with `chargeback_at`, and suspends the user.
- Chargeback point reversal keeps the Phase 2A order:
  1. paid purchase from paid lots.
  2. free bonus from free lots only.
  3. paid purchase shortfall from remaining free lots.
  4. remaining shortfall recorded without point ledger rows.
- `ChargebackPrizeActionService` holds all unshipped prizes for the target user.
- Shipping items in `requested` or `packing` are set to `hold`, and their user prizes are set to `held`.
- Shipping items in `shipped` or `delivered` are set to `return_requested`.
- Converted, expired, returned, or canceled prizes/items are recorded as no-action where applicable.
- Draw results, draw sequence numbers, sold counts, and won counts are not modified by these services.

Tests:

- `backend/tests/Unit/PaymentRefundServiceTest.php`
- `backend/tests/Unit/ChargebackReversalServiceTest.php`
- `backend/tests/Unit/ChargebackPrizeActionServiceTest.php`
- Target tests passed on 2026-06-30.

### v1.6-DRAFT-008: Refund / Chargeback Phase 3 Admin Backend API

Specification name: 返金・チャージバック管理Backend API

Current status:

- Admin Backend API, Request, Resource, and target Feature Tests are implemented.
- Admin UI, frontend changes, actual Discord sending, actual mail sending, production payment webhook, and admin route refactor are not implemented in this phase.
- Migration file exists but has not been executed in the real running environment by this task.

Implemented endpoints:

- `GET /admin/api/payments/{payment}/refund-eligibility`
- `POST /admin/api/payments/{payment}/refund`
- `POST /admin/api/payments/{payment}/chargeback`
- `GET /admin/api/payment-reversals`
- `GET /admin/api/payment-reversals/{paymentReversal}`
- `POST /admin/api/payment-reversals/{paymentReversal}/release-holds`
- `POST /admin/api/payment-reversal-prize-actions/{action}/mark-returned`

Formal requirement:

- All endpoints are under the existing admin auth middleware: `auth:sanctum` and `EnsureAdminUser`.
- Refund and chargeback endpoints call the backend Services and do not implement point reversal logic in Controllers.
- Used payment-origin points cause normal refund API to return validation error `422`.
- Chargeback API is idempotent after completion and does not duplicate point cancellation.
- Reversal list/detail APIs return payment, user, point entry, and prize action information where applicable.
- Hold release API restores held prizes/items to their previous statuses and marks hold actions as released.
- Mark returned API marks return-requested shipping item as returned and completes the prize action.
- Sales APIs are not changed and are not added to admin dashboard `refreshAll()`.

Tests:

- `backend/tests/Feature/AdminPaymentRefundChargebackApiTest.php`
- `backend/tests/Feature/AdminPaymentReversalApiTest.php`
- Target tests passed on 2026-06-30.

### v1.6-DRAFT-009: Refund / Chargeback Phase 4 Admin UI

Specification name: 返金・チャージバック管理UI

Current status:

- Admin UI is implemented in the existing stable `admin-dashboard.tsx` structure.
- Admin route-split refactor remains deferred.
- Real environment migration for `2026_06_30_000001_create_payment_reversal_tables.php` was executed successfully.
- Backend Services and APIs were not changed in this UI phase.
- Production payment webhook, actual Discord sending, normal refund completion mail, and Discord resend remain out of scope.

Implemented UI behavior:

- Daily sales payment rows show refund eligibility status.
- Admin can fetch refund eligibility per payment from the daily sales table.
- Admin can execute normal refund from a succeeded daily sales payment row after confirmation.
- Admin can register chargeback from a succeeded daily sales payment row after a danger confirmation.
- Sales management has a `返金/CB履歴` view.
- `返金/CB履歴` can be searched by period using `date_from` and `date_to`.
- The period search is based on `payment_reversals.occurred_at`.
- The period boundary uses Asia/Tokyo calendar dates and range conditions.
- Reversal list shows type, status, payment, user, amount, reversed points, shortfall, and occurred date.
- Reversal detail shows payment/user summary, point reversal entries, and prize actions.
- Hold release action is available from reversal detail with confirmation.
- Return-requested prize action can be marked returned with confirmation.
- Reversal APIs are not added to admin `refreshAll()`.
- Reversal APIs are fetched only from the sales management view or explicit user actions.

Verification:

- `docker compose exec -T backend php artisan migrate --force` completed successfully on 2026-06-30.
- `cd frontend && pnpm typecheck` passed on 2026-06-30.

### v1.6-DRAFT-010: Refund / Chargeback Phase 5 Return Request Mail

Specification name: チャージバック返送依頼メール

Current status:

- Chargeback return request mail is implemented.
- Return request mail send failure is recorded on `payment_reversal_prize_actions`.
- Admin resend API and Admin UI resend action are implemented.
- Discord notification, Discord resend, normal refund completion mail, production payment webhook, and admin route refactor are not implemented in this phase.

Implemented behavior:

- `ChargebackReturnRequestMail` sends an important notice to the affected user.
- One payment reversal sends at most one mail per attempt, even if multiple return-requested prizes exist.
- Multiple return-requested prizes are listed in one email body.
- The email includes recipient name, chargeback notice, target prize list, payment ID, reversal ID, return guidance note, contact link, and important notice wording.
- Legal wording, return deadline, and shipping cost responsibility remain intentionally non-final and avoid strong definitive language.
- `PaymentReturnRequestMailService` sends only `return_requested` actions whose `mail_sent_at` is null.
- On success, target actions receive `mail_sent_at` and `mail_last_attempted_at`, and `mail_last_error` is cleared.
- On failure, target actions receive `mail_last_error` and `mail_last_attempted_at`; payment, point, prize, and shipping changes are not rolled back.
- Chargeback processing calls return request mail sending after DB transaction commit.
- `POST /admin/api/payment-reversals/{paymentReversal}/send-return-request-mail` sends or resends unsent/failed return request mail.
- If a reversal has no `return_requested` action, the resend API returns validation error `422`.
- If all return request mails were already sent, the resend API does not send a duplicate mail and returns a skipped result.
- Admin detail UI shows mail status, sent date, last attempted date, last error, and send/resend action for return-requested prize actions.
- Return request mail APIs are not added to admin `refreshAll()`.

Verification:

- `docker compose exec -T backend php artisan test tests/Unit/ChargebackReturnRequestMailTest.php` passed on 2026-06-30.
- `docker compose exec -T backend php artisan test tests/Unit/PaymentReturnRequestMailServiceTest.php` passed on 2026-06-30.
- `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReturnRequestMailApiTest.php` passed on 2026-06-30.
- `docker compose exec -T backend php artisan test tests/Unit/ChargebackReversalServiceTest.php` passed on 2026-06-30.
- `cd frontend && pnpm typecheck` passed on 2026-06-30.

### v1.6-DRAFT-011: QA Test User Draw Phase 1 Schema

Specification name: QAテストユーザー指定景品抽選 Phase 1

Current status:

- Migration, Model, Enum, and Relation layer are implemented.
- The migration file is created but has not been executed in the real running environment by this task.
- DrawService, QaDrawResolver, Admin API, Admin UI, frontend changes, CSV changes, and existing draw behavior changes are not implemented in this phase.

Implemented schema:

- `qa_test_user_modes`
- `qa_draw_plans`
- `qa_draw_plan_items`
- `qa_draw_executions`
- `draw_requests.is_qa_draw`
- `draw_requests.qa_test_user_mode_id`
- `draw_requests.qa_draw_plan_id`
- `draw_results.is_qa_draw`
- `draw_results.qa_draw_plan_item_id`

Formal requirements:

- `qa_test_user_modes.ends_at` is required.
- `qa_test_user_modes.user_id` is unique.
- `qa_draw_plans` allows only one active plan per `user_id + gacha_id`.
- The active-plan uniqueness is enforced by a PostgreSQL partial unique index on `(user_id, gacha_id) WHERE status = 'active'`.
- `draw_requests.is_qa_draw` defaults to false.
- `draw_results.is_qa_draw` defaults to false.
- `qa_draw_executions.draw_request_id` is unique.
- `user_prizes` does not receive an initial QA column; QA origin is traceable through `user_prizes.draw_result_id -> draw_results`.
- QA-related foreign keys use restrictive delete behavior to avoid breaking historical draw records.
- Physical deletion of QA modes, plans, items, and execution history is not assumed.
- Completed plan lifecycle and reactivation prevention are handled by later Services, not by Phase 1 schema behavior.

Implemented models and enum:

- `QaTestUserMode`
- `QaDrawPlan`
- `QaDrawPlanItem`
- `QaDrawExecution`
- `QaDrawPlanStatus`

Implemented relations:

- `User` to QA mode, plans, and executions.
- `Gacha` to QA plans and executions.
- `AdminUser` to enabled/disabled QA modes and created/updated QA plans.
- `GachaPrize` to QA draw plan items.
- `RankAsset` to QA fixed image/video plan items.
- `DrawRequest` to QA mode, plan, and execution.
- `DrawResult` to QA plan item.

### v1.6-DRAFT-012: QA Test User Draw Phase 2A Owner Admin API

Specification name: QAテストユーザーモード Owner限定Admin API

Current status:

- Backend Owner-only API, Service, FormRequest, Resource, and target tests are implemented.
- QA draw plan API, QA prize setting API, QA execution history API, QaDrawResolver, DrawService changes, Admin UI, frontend changes, CSV changes, and real environment migration execution are not implemented in this phase.

Implemented endpoints:

- `GET /admin/api/users/{user}/qa-test-mode`
- `PUT /admin/api/users/{user}/qa-test-mode`
- `DELETE /admin/api/users/{user}/qa-test-mode`

Formal behavior:

- All endpoints require existing admin auth.
- Only `AdminRole::Owner` may read or operate QA test user mode.
- `admin` and `operator` receive 403 for both read and write.
- Unauthenticated requests receive 401.
- `GET` returns the current mode or `data=null` when none exists.
- `PUT` creates a new mode or updates the existing user mode.
- `reason` is required.
- `ends_at` is required.
- `starts_at` is optional.
- `ends_at` must be after `starts_at` when `starts_at` is present.
- If `starts_at` is absent, `ends_at` must be after the current time.
- QA mode duration may not exceed 24 hours.
- `DELETE` does not physically delete the mode.
- `DELETE` sets `is_enabled=false`, `disabled_at`, and `disabled_by_admin_user_id`.
- Expired or disabled modes are returned with `is_active=false`.
- Re-enabling updates the existing row.
- Audit logs are recorded for enable, update, and disable actions.

Resource fields:

- `id`
- `user_id`
- `is_enabled`
- `is_active`
- `reason`
- `starts_at`
- `ends_at`
- `enabled_by_admin_user_id`
- `disabled_by_admin_user_id`
- `disabled_at`
- `created_at`
- `updated_at`

Audit actions:

- `admin.qa_test_user.enabled`
- `admin.qa_test_user.updated`
- `admin.qa_test_user.disabled`

Tests:

- `backend/tests/Unit/QaTestUserModeServiceTest.php`
- `backend/tests/Feature/AdminQaTestUserModeApiTest.php`

### v1.6-DRAFT-013: QA Test User Draw Phase 2B Owner Plan Admin API

Specification name: QA排出プラン管理 Owner限定Admin API

Current status:

- Backend Owner-only QA draw plan API, Service, FormRequest, Resource, AuditLog integration, and target tests are implemented.
- QaDrawResolver, DrawService changes, normal draw API changes, point handling changes, inventory handling changes, Admin UI, frontend changes, CSV changes, and real environment migration execution are not implemented in this phase.

Implemented endpoints:

- `GET /admin/api/users/{user}/qa-draw-plans`
- `POST /admin/api/users/{user}/qa-draw-plans`
- `GET /admin/api/qa-draw-plans/{plan}`
- `PUT /admin/api/qa-draw-plans/{plan}`
- `DELETE /admin/api/qa-draw-plans/{plan}`
- `POST /admin/api/qa-draw-plans/{plan}/pause`
- `POST /admin/api/qa-draw-plans/{plan}/activate`

Formal behavior:

- All endpoints require existing admin auth.
- Only `AdminRole::Owner` may read or operate QA draw plans.
- `admin` and `operator` receive 403.
- Unauthenticated requests receive 401.
- `POST` creates a QA draw plan for the target user.
- `PUT` updates the target QA draw plan.
- `DELETE` does not physically delete the plan; it changes status to `disabled`.
- `pause` changes status to `paused`.
- `activate` changes status to `active` when the plan is valid.
- `completed` plans cannot be activated again.
- Only one `active` plan is allowed per `user_id + gacha_id`.
- Before activating or creating a new active plan, expired or fully consumed active plans for the same user and gacha are changed to `completed`.
- `gacha_prize_id` must belong to the target gacha.
- `quantity` must be greater than zero.
- `sort_order` must be unique inside a plan payload.
- `rank_image_asset_id` must reference a rank asset with `asset_type=image`.
- `draw_video_asset_id` must reference a rank asset with `asset_type=video`.
- Plan items are returned ordered by `sort_order` and `id`.

Resource fields:

- `id`
- `user_id`
- `gacha_id`
- `gacha`
- `status`
- `title`
- `reason`
- `starts_at`
- `ends_at`
- `created_by_admin_user_id`
- `updated_by_admin_user_id`
- `items`
- `created_at`
- `updated_at`

Item resource fields:

- `id`
- `sort_order`
- `gacha_prize_id`
- `gacha_prize`
- `quantity`
- `consumed_count`
- `remaining_count`
- `rank_image_asset_id`
- `rank_image_asset`
- `draw_video_asset_id`
- `draw_video_asset`
- `created_at`
- `updated_at`

Audit actions:

- `admin.qa_draw_plan.created`
- `admin.qa_draw_plan.updated`
- `admin.qa_draw_plan.paused`
- `admin.qa_draw_plan.activated`
- `admin.qa_draw_plan.disabled`
- `admin.qa_draw_plan.completed`

Tests:

- `backend/tests/Unit/QaDrawPlanServiceTest.php`
- `backend/tests/Feature/AdminQaDrawPlanApiTest.php`

### v1.6-DRAFT-014: QA Test User Draw Phase 3A Resolver

Specification name: QA抽選Resolver

Current status:

- `QaDrawResolver`, `QaDrawSelection`, `QaDrawSelectedItem`, and target Unit Test are implemented.
- DrawService integration, normal draw API changes, point consumption changes, inventory updates, consumed count updates, `qa_draw_executions` creation, Admin API changes, Admin UI, frontend changes, CSV changes, and real environment migration execution are not implemented in this phase.

Formal behavior:

- If the user has no active QA mode, resolver returns an inactive selection.
- If QA mode is disabled, resolver returns an inactive selection.
- If QA mode is expired, resolver returns an inactive selection.
- If QA mode is active and there is no active QA draw plan for the target gacha, resolver throws `DrawException`.
- If the active plan has fewer remaining configured items than `draw_count`, resolver throws `DrawException`.
- Plan items are expanded by `sort_order`, `id`, `quantity`, and `consumed_count`.
- The resolver can lock the QA mode, plan, and plan items with `lockForUpdate()`.
- Selected prize settings are validated before point consumption.
- `gacha_prize_id` must belong to the target gacha.
- Selected prizes must be active.
- Required quantity is aggregated per prize and checked against `max_win_count - won_count`.
- `rank_image_asset_id` must reference an active `image` asset.
- `draw_video_asset_id` must reference an active `video` asset.
- Invalid QA configuration never falls back to normal probability draw.

Resolver result:

- Inactive selection:
  - `active=false`
  - no plan
  - no selected items
- Active selection:
  - `active=true`
  - QA mode
  - QA draw plan
  - ordered selected items
  - selected `GachaPrize`
  - selected `QaDrawPlanItem`
  - optional fixed rank image asset and URL
  - optional fixed draw video asset and URL

Tests:

- `backend/tests/Unit/QaDrawResolverTest.php`

### v1.6-DRAFT-015: QA Test User Draw Phase 3B DrawService Integration

Specification name: QA抽選Resolverの通常DrawService統合

Current status:

- `QaDrawResolver` is integrated into the existing `DrawService`.
- Existing user draw API request and response format are unchanged.
- Admin UI, frontend changes, QA history browse API, CSV changes, sales UI changes, additional migrations, and production payment related changes are not implemented in this phase.

Formal behavior:

- The existing normal draw endpoint remains `POST /api/gachas/{gacha}/draw`.
- Completed idempotency-key lookup happens before QA resolver execution.
- QA resolver runs inside the existing draw DB transaction after gacha lock and before point consumption.
- If QA mode is inactive, disabled, or expired, existing normal probability draw behavior is used.
- If QA mode is active and the plan is missing or invalid, draw fails before point consumption and does not fall back to normal probability draw.
- During QA draw, selected prizes are locked by ID order and inventory is revalidated after lock.
- Points are consumed through the existing point consumption flow.
- Probability stage resolution still runs normally for each draw result.
- Normal probability range pick is not used for QA prize selection.
- `random_value` is still generated with backend CSPRNG for existing schema and audit shape.
- QA draw never creates point-back results.
- `won_count` is updated normally.
- `sold_count` is updated normally.
- `draw_results` are created normally with QA flags and QA plan item reference.
- `user_prizes` are created normally.
- Fixed QA rank image/video URLs are stored when configured.
- Existing rank presentation selection is used when no fixed QA asset is configured.
- `draw_requests.is_qa_draw`, `draw_requests.qa_test_user_mode_id`, and `draw_requests.qa_draw_plan_id` are saved for QA draws.
- `draw_results.is_qa_draw` and `draw_results.qa_draw_plan_item_id` are saved for QA draws.
- `qa_draw_plan_items.consumed_count` is updated in the same transaction as draw result creation.
- If all plan items are consumed, the plan status changes to `completed`.
- One `qa_draw_executions` row is created for each successful QA draw request.
- `qa_draw_executions.metadata.items` stores used QA plan item IDs, prize IDs, and fixed asset IDs.
- Failed QA draws roll back point consumption, inventory changes, sold count changes, won count changes, consumed count changes, draw requests, draw results, user prizes, and QA execution rows.

Tests:

- `backend/tests/Feature/QaTestUserDrawApiTest.php`
- `backend/tests/Feature/DrawApiTest.php`
- `backend/tests/Unit/QaDrawResolverTest.php`

Notes:

- `backend/tests/Unit/DrawServiceTest.php` does not currently exist in the repository.

### v1.6-DRAFT-016: QA Test User Draw Phase 4A Admin User Mode UI

Specification name: 管理画面ユーザー詳細 QAテストユーザーモード設定UI

Current status:

- Owner-only QA test user mode UI is implemented in the stable admin dashboard.
- QA draw plan UI, prize setting UI, fixed image/video selection UI, QA execution history UI, backend API changes, DrawService changes, CSV changes, sales management changes, additional migrations, and admin route-split refactoring are not implemented in this phase.

Implementation location:

- `frontend/src/app/admin-dashboard.tsx`
- User detail screen in the existing stable admin dashboard structure.

Formal behavior:

- The QA test user section is displayed only for `AdminRole::Owner`.
- Admin and operator roles do not see the QA section and do not call the QA mode API.
- The QA mode API is not included in `refreshAll()`.
- Owner fetches QA mode only when opening a user detail screen.
- Leaving the user detail screen clears QA mode state.
- The UI shows QA mode state as one of:
  - 有効
  - 無効
  - 開始前
  - 期限切れ
- The UI displays reason, start date, auto end date, enabled admin ID, disabled admin ID, disabled date, created date, and updated date.
- The form supports reason, optional start date, required auto end date, and mode intent.
- The UI displays that QA mode duration is capped at 24 hours.
- Enabling/updating requires a confirmation dialog.
- Disabling requires a confirmation dialog.
- Buttons are disabled while saving.
- API errors are shown through the existing admin message mechanism.
- The warning text explicitly states that QA mode is not a mock draw and affects real payment, point, inventory, sold count, won count, prize acquisition, exchange, and shipping data.

APIs used:

- `GET /admin/api/users/{user}/qa-test-mode`
- `PUT /admin/api/users/{user}/qa-test-mode`
- `DELETE /admin/api/users/{user}/qa-test-mode`

Verification:

- `docker compose exec -T backend php artisan migrate:status` confirmed `2026_07_14_000001_create_qa_test_user_draw_tables` is `[13] Ran`.
- `cd frontend && pnpm typecheck` passed.

### v1.6-DRAFT-017: QA Test User Draw Phase 4B-1-1 Admin Draw Plan Read-Only List UI

Specification name: 管理画面ユーザー詳細 QA排出プラン読み取り専用一覧

Current status:

- Owner-only read-only QA draw plan list UI is implemented in the stable admin dashboard user detail screen.
- QA draw plan creation, editing, pause, activation, disable, prize selection, fixed image/video selection, QA execution history UI, backend changes, CSV changes, and admin route-split refactoring are not implemented in this phase.

Implementation location:

- `frontend/src/app/admin-dashboard.tsx`
- User detail screen in the existing stable admin dashboard structure.
- The list is displayed directly below the QA test user mode section.

Formal behavior:

- The QA draw plan list section is displayed only for `AdminRole::Owner`.
- Admin and operator roles do not see the section and do not call QA draw plan APIs.
- QA draw plan APIs are not included in `refreshAll()`.
- Owner fetches the QA draw plan list only when opening a user detail screen.
- Leaving the user detail screen or switching users clears QA draw plan list/detail state.
- Stale responses from a previously selected user are ignored.
- Plan detail API is called only when the operator expands a plan's prize setting details.
- The UI shows a notice that QA draw plans are used only while the target user's QA test mode is active, and normal drawing is used when QA mode is disabled, not started, or expired.

List fields:

- plan ID
- target gacha name and gacha ID
- status badge
- title
- reason
- starts_at
- ends_at
- item row count
- total configured quantity
- total consumed count
- total remaining count
- created_at
- updated_at

Status labels:

- `active`: 有効
- `paused`: 一時停止
- `completed`: 完了
- `disabled`: 無効

Plan item detail fields:

- sort order
- prize name
- `gacha_prize_id`
- quantity
- consumed_count
- remaining_count
- fixed rank image title or ID
- fixed draw video title or ID

APIs used:

- `GET /admin/api/users/{user}/qa-draw-plans`
- `GET /admin/api/qa-draw-plans/{plan}`

API response fields confirmed from resources:

- `QaDrawPlanResource`: `id`, `user_id`, `gacha_id`, `gacha`, `status`, `title`, `reason`, `starts_at`, `ends_at`, `created_by_admin_user_id`, `updated_by_admin_user_id`, `items`, `created_at`, `updated_at`.
- `QaDrawPlanItemResource`: `id`, `sort_order`, `gacha_prize_id`, `gacha_prize`, `quantity`, `consumed_count`, `remaining_count`, `rank_image_asset_id`, `rank_image_asset`, `draw_video_asset_id`, `draw_video_asset`, `created_at`, `updated_at`.

Verification:

- `cd frontend && pnpm typecheck` should be run for this frontend-only phase.
- `git diff --check` should be run before completion.

### v1.6-DRAFT-018: QA Test User Draw Phase 4B-1-2 Admin Draw Plan Create Form Foundation

Specification name: 管理画面ユーザー詳細 QA排出プラン新規作成フォーム基礎

Current status:

- Owner-only QA draw plan create form foundation is implemented in the stable admin dashboard user detail screen.
- This phase implements form display and option-data loading only.
- POST saving, multiple prize rows, row sorting, quantity input, fixed image/video select controls, edit, pause, activation, disable, QA execution history UI, backend changes, DB changes, and admin route-split refactoring are not implemented in this phase.

Implementation location:

- `frontend/src/app/admin-dashboard.tsx`
- User detail screen below the QA draw plan read-only list.

Formal behavior:

- The "QA排出プランを新規作成" button is displayed only for `AdminRole::Owner`.
- Admin and operator roles do not see the button/form and do not call QA draw plan option APIs.
- Opening the form does not save a plan and does not call `POST /admin/api/users/{user}/qa-draw-plans`.
- The form can be closed with cancel, which resets the form and option state.
- The form displays a notice that this phase prepares the form only and the plan is not saved yet.
- QA draw plan create-related API calls are not included in `refreshAll()`.
- Existing `gachas` state is used for gacha choices.
- All gacha details are not fetched in bulk.
- Existing `rankAssets` state is reused when available.
- If `rankAssets` is empty, `GET /admin/api/rank-assets?per_page=100` is called only when the Owner opens the create form.
- Active rank assets are classified as image candidates and video candidates.
- Inactive rank assets are not counted as fixed presentation candidates.
- Gacha prizes are fetched only after selecting a target gacha.
- Existing `gachaPrizes` state is reused if it already contains rows for the selected gacha.
- If no cached prize rows are available for the selected gacha, `GET /admin/api/gacha-prizes?gacha_id={gachaId}&per_page=100` is called.
- Changing the target gacha clears the current prize candidate state before loading the next gacha's candidates.
- Switching users, leaving user detail, or canceling the form resets create form and option state.
- Request guards prevent stale option responses from overwriting the current state.

Create form fields in this phase:

- target gacha: required
- title: optional
- reason: required
- starts_at: optional
- ends_at: optional

Status handling:

- `StoreQaDrawPlanRequest` accepts nullable `status` with `active` or `paused`.
- `QaDrawPlanService` defaults omitted status to `active`.
- This phase does not add a status input because no POST is performed and no independent frontend default behavior should be introduced.

Displayed option summary:

- target gacha candidate count
- prize candidate loading state
- prize candidate count
- prize candidate empty state
- prize candidate fetch error
- active fixed image asset candidate count
- active fixed video asset candidate count

Existing API/resource fields confirmed:

- `StoreQaDrawPlanRequest`: `gacha_id`, nullable `status`, nullable `title`, required `reason`, nullable `starts_at`, nullable `ends_at`, and required `items`.
- `AdminGachaPrizeResource`: `id`, `gacha_id`, `rank_id`, `gacha`, `rank`, `name`, `max_win_count`, `won_count`, `remaining_win_count`, `is_active`, and other admin prize fields.
- `RankAssetResource`: `id`, `title`, `asset_type`, `url`, `is_active`, `created_at`, `updated_at`.

Verification:

- `cd frontend && pnpm typecheck` should be run before and after this frontend-only phase.
- `git diff --check` should be run before and after this frontend-only phase.

### v1.6-DRAFT-019: QA Test User Draw Phase 4B-1-3-1 Admin Draw Plan Item Input UI

Specification name: 管理画面ユーザー詳細 QA排出プラン景品設定行入力UI

Current status:

- Owner-only QA draw plan item input UI is implemented in the stable admin dashboard user detail create form.
- This phase implements input UI only.
- Confirmation dialog for save, POST saving, list refresh after creation, existing plan editing, pause, activation, disable, QA execution history UI, backend changes, DB changes, and admin route-split refactoring are not implemented in this phase.

Implementation location:

- `frontend/src/app/admin-dashboard.tsx`
- QA draw plan create form inside the user detail screen.

Formal behavior:

- The item input table is displayed only for `AdminRole::Owner`.
- Admin and operator roles do not see the form and do not call QA draw plan option APIs.
- Opening the create form shows one empty item row.
- Each row has:
  - `sort_order`
  - `gacha_prize_id`
  - `quantity`
  - nullable `rank_image_asset_id`
  - nullable `draw_video_asset_id`
- Rows can be added.
- Rows can be removed, but the last row cannot be removed.
- Rows can be moved up or down.
- After add, delete, or move operations, `sort_order` is normalized to one-based consecutive numbers.
- `quantity` accepts only positive integer input in the UI.
- No maximum quantity is enforced by frontend because `StoreQaDrawPlanRequest` has no upper bound.
- Selecting a target gacha controls the prize candidate list.
- Only prize candidates for the selected gacha are shown.
- All-gacha prize details are not fetched in bulk.
- If target gacha changes while any item row has input, the UI asks for confirmation before clearing the item rows.
- After confirmed target gacha change, item rows reset to one empty row and the previous gacha's prize candidates are cleared.
- While selected-gacha prize candidates are loading, prize select controls are disabled.
- Prize options display prize name, `gacha_prize_id`, rank name, active state, `max_win_count`, `won_count`, and `remaining_win_count`.
- Frontend does not add independent active/inactive prize selection restrictions beyond the existing backend rules.
- Fixed rank image options include only active `rank_assets` whose `asset_type=image`.
- Fixed draw video options include only active `rank_assets` whose `asset_type=video`.
- Both fixed asset selects include a normal presentation option.
- Image assets are not shown in the video select, and video assets are not shown in the image select.

Displayed input summary:

- item row count
- total configured quantity
- selected target gacha
- row count with fixed rank image
- row count with fixed draw video

Not saved yet:

- The UI explicitly states that this phase is input only and the QA draw plan is not saved.
- `POST /admin/api/users/{user}/qa-draw-plans` is not called in this phase.
- QA draw plan APIs are not added to `refreshAll()`.

State reset:

- Canceling the form resets item rows to one empty row.
- Switching users or leaving user detail resets item rows to one empty row.
- Confirmed target gacha change resets item rows to one empty row.
- Request guards continue to prevent stale option responses from overwriting the current form state.

Verification:

- `cd frontend && pnpm typecheck` should be run before and after this frontend-only phase.
- `git diff --check` should be run before and after this frontend-only phase.
