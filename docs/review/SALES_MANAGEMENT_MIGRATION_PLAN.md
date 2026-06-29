# Sales Management Migration Plan

## Position

売上管理は v1.6 追加予定機能として設計する。現時点では実装しない。

管理画面リファクタリングは延期中のため、実装時は現行安定構成へ追加する。

## Phase 0: Design Only

Status: current task

Actions:

- 既存DB、Model、Service、API、管理画面構成を調査
- 設計書、影響レビュー、移行計画、テスト計画を作成

No changes:

- Code
- Migration
- DB
- Docker
- Dependencies

Completion:

- `docs/design/SALES_MANAGEMENT_DESIGN.md`
- `docs/review/SALES_MANAGEMENT_IMPACT.md`
- `docs/review/SALES_MANAGEMENT_MIGRATION_PLAN.md`
- `docs/review/SALES_MANAGEMENT_TEST_PLAN.md`

## Phase 1: Backend Read API

Target files:

- `backend/app/Domain/Admin/Services/SalesManagementReportService.php`
- `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`
- `backend/app/Http/Resources/*Sales*Resource.php`
- `backend/routes/admin.php`

Planned endpoints:

- `GET /admin/api/sales/monthly`
- `GET /admin/api/sales/daily-payments`
- `GET /admin/api/sales/monthly-point-consumption`
- `GET /admin/api/sales/daily-point-consumption`
- `GET /admin/api/sales/draw-requests/{drawRequest}`

Rules:

- Read-only API only.
- Do not change payment status.
- Do not grant or consume points.
- Do not implement refund/chargeback point reversal.
- Use Asia/Tokyo date range.

Rollback:

- Remove added routes, controller, service, resource files.
- No DB rollback required if no Migration is added.

## Phase 2: Backend Tests

Target tests:

- `backend/tests/Feature/AdminSalesManagementApiTest.php`
- `backend/tests/Unit/SalesManagementReportServiceTest.php`

Coverage:

- monthly payment summary
- daily payment list
- provider breakdown
- payment status handling
- monthly point consumption
- daily point consumption grouped by draw_request
- paid/free split
- draw result detail
- authorization
- empty data
- Asia/Tokyo boundary

Execution:

- Run targeted tests only.
- Do not run parallel tests.
- Do not run destructive migrate commands against production data.

## Phase 3: Optional Index Migration

Initial implementation can start without new tables.

Add index Migration only if query performance requires it.

Candidate migration:

- `add_sales_management_indexes.php`

Candidate indexes:

- `payments(status, paid_at)`
- `payments(provider, paid_at)`
- `point_ledgers(ledger_type, related_type, created_at)`
- `point_ledgers(point_type, ledger_type, created_at)`
- `draw_requests(status, created_at)`

Execution rule:

- Create Migration only after reviewing query plans or observed slowness.
- Do not run `php artisan migrate --force` without human approval.

Rollback:

- Drop only added indexes.
- No data deletion.

## Phase 4: Admin UI On Stable Dashboard

Target files:

- `frontend/src/app/admin-dashboard.tsx`
- `frontend/src/app/admin/[[...segments]]/page.tsx`

Actions:

- Add `sales` tab or rename existing `payments` tab to sales management.
- Move menu entry above announcements.
- Add monthly/daily mode controls.
- Add sales calendar table.
- Add daily payment table.
- Add monthly point consumption calendar table.
- Add daily point consumption table.
- Add draw result detail section.

Important:

- Do not restart route split refactor.
- Do not add route-level feature files under `frontend/src/app/admin/*`.
- Do not add sales APIs to `refreshAll()`.
- Fetch sales data only when the sales view is opened or filters change.

Rollback:

- Restore modified sections of `admin-dashboard.tsx`.
- Restore route mapping in `[[...segments]]/page.tsx`.
- Backend API can remain if unused, but may be removed if no longer needed.

## Phase 5: Manual QA

Manual checks:

- `/admin/sales` opens without 504.
- Left menu opens売上管理.
- Month switch updates calendar.
- Date selection updates day list.
- Payment rows show user and purchase plan.
- Point consumption rows show paid/free split.
- Detail button shows draw results.
- Existing purchase plan page still works.
- Existing point page still works.
- Existing payment list behavior is preserved or intentionally replaced.

Performance checks:

- Sales APIs are not called on `/admin/guide`.
- Sales APIs are not called on `/admin/gachas`.
- Sales APIs are called only on sales page.
- Initial admin load does not become heavier than current stable state.

## Phase 6: Documentation Update

Update after implementation:

- `docs/md/spec_v1.6_draft.md`
- `docs/review/AS_BUILT_IMPLEMENTATION_MATRIX.md` if audited
- `docs/review/TEST_COVERAGE_GAP.md` if tests are added
- `worklogs/codex-main.md`

Do not mark as VERIFIED unless implementation and tests are confirmed.

## No Schema Table Required Initially

Initial sales management can be derived from existing data:

- payment sales from `payments`
- point consumption from `point_ledgers`
- draw details from `draw_requests` and `draw_results`

Therefore no new sales table is required for the first implementation.

Future options:

- daily sales aggregate table
- daily point consumption aggregate table
- CSV export table/log
- payment provider fee table
- payment plan snapshot columns

These are not part of the initial implementation.
