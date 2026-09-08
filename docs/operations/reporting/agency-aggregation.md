# Agency User And Sales Aggregation

AGENCY-003 implements read-only advertising-code aggregates on Admin and Agency
surfaces. It does not use the general reporting service's net-sales definition.

## Authority

- User counts select `users.created_at` within the requested period.
- Sales select `payments.status = succeeded` and `payments.succeeded_at` within
  the period. User registration date does not restrict sales.
- Current active verified `user_phone_numbers` determines full registration.
  Email verification with no active verified phone determines temporary
  registration. Users satisfying neither definition are excluded.
- Classification is queried on every read. Later SMS verification changes the
  classification of earlier registration and payment periods without rewriting
  those records.
- Paying users are distinct successful-payment `user_id` values, including
  users whose payments were fully refunded.
- Refunds are `payment_adjustments.type = refund` and `status = succeeded`,
  summed per payment before joining payments. All confirmed refunds reduce the
  original payment's period regardless of refund date. Chargebacks and other
  adjustment types are not deducted.

## Contract And Query

Admin paths are `/admin/api/v2/agencies/aggregates/users` and
`/admin/api/v2/agencies/aggregates/sales`. Owner, Admin and Operator use existing
`agency.read` permission. Suspended agencies retain historical and zero rows.

Agency paths are `/agency/api/v2/aggregates/users` and
`/agency/api/v2/aggregates/sales`. The existing Agency guard supplies internal
identity and denies suspended sessions. Request identifiers never determine
scope. Responses contain only own code and aggregate metrics; no company,
User/Payment identifier, contact field, individual history or Admin memo.

Omitting period parameters selects the current business month. Specify either
`month=YYYY-MM` or both `start_date` and `end_date`. `V2ReportingPeriod` supplies
canonical `Asia/Tokyo` boundaries. End dates include that whole day; database
comparisons use `[start, next-day-end)` as explicit UTC ISO-8601 strings to
preserve offsets even when PostgreSQL's session timezone is Asia/Tokyo.
Unknown query fields, GET bodies and invalid combinations/ranges return 422.

Each request executes one set-based aggregate query. Code ID ascending gives
stable order with the existing opaque reporting cursor; default page size 50,
maximum 100. Pagination keeps the returned period fixed. Codes are the base
rows and metrics are left joined, preserving codes with no data.

Existing indexes cover code agency/ID, attribution user primary key, phone user
uniqueness, payment status/success time, payment user, and refund payment and
type/status. Attribution code and user creation time do not have dedicated
indexes. Current Preview volume and isolated PostgreSQL execution plans do not
justify another migration. Reassess plans at larger attribution volumes before
adding an allocated, forward index migration.

## Validation And Activation

`AgencyAggregationTest` covers the locked A/B/C fixture, current classification
movement, full/multiple refunds, unsuccessful payments/adjustments, chargeback
exclusion, zero codes, scope, permissions, realm separation, dates and actual
`EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` over isolated synthetic data.

Frontend tests and mocked browser flows cover nonzero data. Shared Preview may
legitimately show all zeros until AGENCY-004 writes attributions. Do not create
payments, refunds or points there to populate this report.

After gate-compliant merge, activate only API, Admin and Agency from verified
immutable images. No migration application, Nginx, DNS, TLS or Storefront change
is needed. Rollback restores the previous compatible three images; reporting
does not mutate domain rows. Human Browser Acceptance remains pending after
technical verification. Attribution capture/hooks and full nonzero browser
attribution flow remain AGENCY-004; Production mutation remains zero.
