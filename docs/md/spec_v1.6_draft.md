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
- Management display/API for viewing snapshots remains a separate future task.

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
