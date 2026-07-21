# Sales Management Performance Review

Review date: 2026-07-01

This review is based on static code and migration inspection only. No production
database load test, `EXPLAIN ANALYZE`, migration, API change, UI change, Docker
operation, or dependency change was performed.

## Reviewed Files

- `backend/app/Domain/Admin/Services/SalesManagementReportService.php`
- `backend/app/Domain/Admin/Services/SalesManagementCsvService.php`
- `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`
- `backend/routes/admin.php`
- `backend/database/migrations/2026_06_10_000002_create_point_tables.php`
- `backend/database/migrations/2026_06_10_000003_create_payments_table.php`
- `backend/database/migrations/2026_06_10_000004_create_gachas_table.php`
- `backend/database/migrations/2026_06_10_000007_create_draw_tables.php`
- `backend/database/migrations/2026_06_16_000003_create_point_purchase_plans_table.php`

## Existing Indexes

### payments

- Primary key: `id`
- Unique: `webhook_event_id`
- Unique: `provider, provider_payment_id`
- Index: `user_id, created_at`

No current index directly supports sales-management range scans on `paid_at`,
`refunded_at`, or `chargeback_at`.

### point_ledgers

- Primary key: `id`
- Foreign key indexes from `user_id`, `wallet_id`, `point_lot_id`
- Index: `user_id, created_at`
- Index: `related_type, related_id`

No current index directly supports global sales-management scans by
`ledger_type`, `related_type`, `amount < 0`, and `created_at`.

### draw_requests

- Primary key: `id`
- Unique: `user_id, gacha_id, idempotency_key`
- Index: `user_id, created_at`
- Index: `gacha_id, created_at`

### draw_results

- Primary key: `id`
- Unique: `gacha_id, draw_sequence_number`
- Index: `draw_request_id, created_at`
- Index: `user_id, created_at`

### gachas

- Primary key: `id`
- Unique: `slug`
- Index: `status, start_at`
- Index: `current_probability_version_id`

### point_purchase_plans

- Primary key: `id`
- Index: `is_active, sort_order`

## Query Review

### Monthly Sales

Code path:

- `SalesManagementReportService::monthlySales()`
- `SalesManagementReportService::applyPaymentEventAmounts()`
- `SalesManagementCsvService::monthlySales()`

Queries:

- Gross sales: `payments.status IN (succeeded, refunded, chargeback)`,
  `paid_at >= month_start`, `paid_at < next_month`
- Refund amounts: `status = refunded`, `refunded_at >= month_start`,
  `refunded_at < next_month`
- Chargeback amounts: `status = chargeback`,
  `chargeback_at >= month_start`, `chargeback_at < next_month`

Findings:

- Date boundaries use range predicates instead of `whereDate`, which is good for
  index use.
- Aggregation is done in PHP after loading the target month rows.
- There is no obvious N+1 issue because no relations are loaded per row.
- Current `payments` indexes do not directly support these monthly scans.

Concern:

- As payment volume grows, monthly sales may require sequential scans or broad
  index scans over `payments`.

Index candidates:

- `payments(paid_at, id)`
- `payments(status, paid_at)`
- `payments(status, refunded_at)`
- `payments(status, chargeback_at)`
- PostgreSQL partial indexes can be considered later, for example filtered by
  `refunded_at IS NOT NULL` or `chargeback_at IS NOT NULL`.

Current action:

- No migration is added in this review. Index addition should be approved as a
  separate task after checking expected payment volume and query plans.

### Daily Payment List

Code path:

- `SalesManagementReportService::dailyPayments()`
- `SalesManagementCsvService::dailyPayments()`

Query:

- `payments.paid_at >= day_start`, `payments.paid_at < next_day`
- `orderBy paid_at desc, id desc`
- Eager load: `user`
- Purchase plans are resolved in one `whereIn(id, planIds)` query from
  `payments.metadata.point_purchase_plan_id`.

Findings:

- `with('user')` avoids user N+1.
- `plansForPayments()` avoids purchase-plan N+1.
- Pagination exists for the JSON API.
- CSV calls the same report method with `perPage = 10000`.

Concern:

- Existing indexes do not directly support `paid_at` range plus
  `paid_at desc, id desc` ordering.
- CSV export is capped by the requested page size of 10,000 rows. If daily
  payments exceed 10,000 rows, the CSV can be incomplete.

Index candidates:

- `payments(paid_at, id)`
- If status filtering is added later, `payments(status, paid_at, id)` may also
  be useful.

CSV caution:

- Before high-volume production use, replace the 10,000-row CSV export approach
  with cursor/chunk based export or an explicit export row limit shown to admins.

### Daily Refund / Chargeback List

Code path:

- `SalesManagementReportService::dailyAdjustments()`
- `SalesManagementCsvService::dailyAdjustments()`

Queries:

- Refunds: `status = refunded`, `refunded_at >= day_start`,
  `refunded_at < next_day`
- Chargebacks: `status = chargeback`, `chargeback_at >= day_start`,
  `chargeback_at < next_day`
- Gross daily summary: same paid-at range as daily payment list

Findings:

- `with('user')` avoids user N+1.
- Purchase plans are resolved in one batched query.
- Refunds and chargebacks are fetched separately, concatenated, and sorted in
  PHP.

Concern:

- There is no pagination for the adjustment list.
- Existing indexes do not directly support `refunded_at` or `chargeback_at`
  event-day scans.

Index candidates:

- `payments(status, refunded_at, id)`
- `payments(status, chargeback_at, id)`
- PostgreSQL partial indexes for non-null event timestamps may be better if the
  event rows are sparse.

Current action:

- No code change. If daily adjustment volume becomes large, add pagination or
  chunking in a separate approved task.

### Monthly Point Consumption

Code path:

- `SalesManagementReportService::monthlyPointConsumption()`
- `SalesManagementReportService::pointConsumptionBaseQuery()`
- `SalesManagementCsvService::monthlyPointConsumption()`

Query shape:

- Base table: `point_ledgers`
- Join: `draw_requests` by `draw_requests.id = point_ledgers.related_id`
- Join: `users` by `draw_requests.user_id`
- Join: `gachas` by `draw_requests.gacha_id`
- Filters:
  - `point_ledgers.ledger_type = spend`
  - `point_ledgers.related_type = draw_request`
  - `point_ledgers.amount < 0`
  - `point_ledgers.created_at >= month_start`
  - `point_ledgers.created_at < next_month`
- Grouping: draw request, user, gacha, draw count, status, created date
- Aggregates paid/free consumption with conditional sums.

Findings:

- Joins are by primary keys after the matching ledger rows are selected.
- No application-level N+1 exists because the query uses joins.
- The monthly endpoint fetches all grouped draw requests for the month into
  memory and then aggregates by day/gacha in PHP.

Concern:

- Current `point_ledgers(user_id, created_at)` is not a good fit for global
  all-user monthly scans.
- Current `point_ledgers(related_type, related_id)` is useful for lookup by
  relation, but not enough for date-range consumption reporting.
- Monthly point consumption is the most likely sales-management query to become
  heavy as draw volume grows.

Index candidates:

- `point_ledgers(ledger_type, related_type, created_at)`
- `point_ledgers(ledger_type, related_type, created_at, related_id)`
- PostgreSQL partial index candidate:
  `point_ledgers(created_at, related_id) WHERE ledger_type = 'spend' AND related_type = 'draw_request' AND amount < 0`

Current action:

- No migration is added. This should be the first index area to validate on
  staging with realistic draw volume.

### Daily Point Consumption

Code path:

- `SalesManagementReportService::dailyPointConsumption()`
- `SalesManagementReportService::pointConsumptionBaseQuery()`
- `SalesManagementCsvService::dailyPointConsumption()`

Query shape:

- Same base query as monthly point consumption.
- Adds `orderByRaw('MAX(point_ledgers.created_at) DESC')` and
  `orderByDesc('draw_requests.id')`.
- JSON API paginates the grouped result.
- CSV calls the report method with `perPage = 10000`.

Findings:

- No obvious N+1 issue.
- Pagination does not remove the cost of grouping and sorting the matching daily
  ledger rows.

Concern:

- Daily high-volume draw events can make the grouping and aggregate sort heavy.
- CSV can be incomplete if the grouped draw-request count exceeds 10,000 rows in
  one day.

Index candidates:

- Same `point_ledgers` index candidates as monthly point consumption.
- If detail sorting becomes a problem, consider reviewing whether
  `draw_requests(id)` plus ledger grouping remains enough under real volume.

CSV caution:

- Use cursor/chunk based export or asynchronous export before relying on daily
  point consumption CSV for high-volume days.

### Draw Request Detail

Code path:

- `SalesManagementReportService::drawRequestDetail()`

Query behavior:

- Route model binding loads one `draw_requests` row by primary key.
- Eager loads: `user`, `gacha`, `results.rank`, `results.prize`
- Results are sorted by `draw_sequence_number` in PHP.

Findings:

- Eager loading avoids N+1 for rank and prize.
- Existing `draw_results(draw_request_id, created_at)` helps loading results for
  a draw request.

Concern:

- Sorting by `draw_sequence_number` is done in PHP. This is acceptable for the
  current draw-request size, but if very large multi-draw requests are allowed,
  an ordered relation or index may be preferable.

Index candidate:

- `draw_results(draw_request_id, draw_sequence_number)`

Current action:

- No immediate change required unless draw-request detail pages become slow with
  high draw counts.

## CSV Export Review

Code path:

- `SalesManagementCsvService`
- CSV endpoints in `AdminSalesManagementController`

Findings:

- CSV output includes UTF-8 BOM, which is suitable for Japanese Excel.
- CSV content is generated through `php://temp` and returned as one string.
- Monthly CSVs reuse monthly report arrays and are bounded by one month of
  grouped data.
- Daily payment and daily point CSVs request 10,000 rows from paginated report
  methods.
- Daily adjustment CSV fetches all matching refund/chargeback rows for the day.

Concerns:

- CSV generation is not streaming.
- Daily CSVs can use significant memory when row counts are large.
- Daily payment and daily point CSVs can be incomplete above 10,000 rows.
- No background export job exists.

Recommended future improvements:

- Use `streamDownload()` or streamed response generation.
- Use cursor/chunk based iteration for CSV rows.
- Add an explicit export row limit and display it in the admin UI if streaming
  is not implemented.
- Consider asynchronous CSV creation if production data grows quickly.

## N+1 Review

No clear N+1 query was found in the reviewed sales-management paths.

- Daily payments eager-load `user`.
- Purchase plans are resolved with a batched `whereIn`.
- Daily adjustments eager-load `user` and batch purchase plan lookup.
- Point consumption uses joins.
- Draw-request detail eager-loads user, gacha, results, rank, and prize.

## Priority Index Candidates

These are candidates only. No migration was created in this review.

### Priority 1

- `payments(paid_at, id)`
  - Supports daily paid-at list, monthly gross sales range scans, and ordering.
- `payments(status, refunded_at, id)`
  - Supports refund event-day/month scans.
- `payments(status, chargeback_at, id)`
  - Supports chargeback event-day/month scans.
- `point_ledgers(ledger_type, related_type, created_at, related_id)`
  - Supports sales-management point consumption scans.

### Priority 2

- `payments(status, paid_at, id)`
  - Useful if the planner benefits from status filtering for gross sales.
- Partial indexes on `payments` for non-null `refunded_at` and
  `chargeback_at`.
- Partial index on `point_ledgers` for spend draw-request ledgers only.
- `draw_results(draw_request_id, draw_sequence_number)`
  - Useful if draw detail sorting grows.

## Items Requiring Production or Staging Confirmation

Do not run heavy production `EXPLAIN ANALYZE` without explicit approval.

Recommended low-risk checks:

- Estimate monthly and daily row counts for `payments`.
- Estimate monthly and daily `point_ledgers` rows where
  `ledger_type = spend`, `related_type = draw_request`, and `amount < 0`.
- Estimate daily refund/chargeback counts.
- Estimate maximum draw results per draw request.
- Confirm whether daily payment or daily point consumption exports can exceed
  10,000 rows.
- If `pg_stat_statements` or slow-query logs are available, inspect them after
  normal admin use.

Recommended staging checks:

- Run `EXPLAIN` or `EXPLAIN (ANALYZE, BUFFERS)` only on staging or during an
  approved low-traffic maintenance window.
- Compare query plans before and after candidate indexes.
- Test CSV memory and response time with realistic high-volume fixtures.

## Conclusion

Current sales-management implementation is acceptable for low to moderate data
volume. The most important risks before high-volume production use are:

1. Missing range indexes on `payments.paid_at`, `payments.refunded_at`, and
   `payments.chargeback_at`.
2. Missing reporting-oriented index on `point_ledgers` for draw spend ledgers.
3. CSV generation is non-streaming and daily exports are effectively limited to
   10,000 rows for payments and point consumption.

No immediate code or migration change was made because this task is a review
only. Index additions and CSV streaming should be handled as separately approved
implementation tasks.
