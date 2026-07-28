# V2 Reporting and Export

## Responsibility

This boundary provides read-only Admin reporting and private CSV export over the
V2 Payment, Adjustment, Point Ledger, Draw, QA, and Point Snapshot sources.
These operational event reports do not establish accounting recognition.

## Ownership

The API platform owns report definitions, JST business-date conversion, export
jobs, CSV generation, private object storage, and audit events. UI, payment
provider integration, and production deployment are outside this boundary.

## Sales Basis

- Gross sales use only `payments.status = succeeded` and `succeeded_at`.
- Refunds and chargebacks use only successful adjustments and their
  `succeeded_at`.
- Chargeback reversals remain a separate review category and never restore net
  sales automatically.
- Net sales are gross sales minus successful refunds and chargebacks.
- Pending, processing, and manual-review adjustments are reported separately.

## Point and Draw Basis

Point movement is reconstructed from immutable Point Operations and Ledger
entries. Wallet current balances are not used to infer historical movement.
Draw reporting uses completed Draw Requests and immutable Draw Results.
QA Draws remain included by default and can be filtered with `all`, `normal`,
or `qa`. QA reasons and Plan administration metadata are not exposed.

## Snapshot

Daily Point balances retain the existing ledger-cutoff rule
`occurred_at < cutoff`. March 31 and September 30 remain base dates. Snapshot
checksum and generation-run identity are reported; discrepancies are never
auto-repaired.

## Export

Exports use stable English headers, UTF-8 with BOM, CSV escaping, and formula
injection protection. Internal IDs, full PII, object keys, encrypted values,
provider secrets, cost data, and individual probability ppm are excluded.

Up to 10,000 rows may use `StreamedResponse`. Larger data sets use an Export Job
and Transactional Outbox. The Job fixes the query version and data cutoff.
Workers claim with `FOR UPDATE SKIP LOCKED`, generate outside a DB transaction,
store under a private site prefix, and finalize row count, byte size, and
SHA-256. The retry maximum is three and the lease is 120 seconds. Completed
files expire after 24 hours. A five-minute signed application URL is returned;
the private object key is never returned.

## Authorization

Owner and Admin roles may read reports and export. Operator is denied. Financial
export requires the shared five-minute Admin Fresh MFA boundary and an
Admin-scoped limit of five requests per hour. A rate-limiter outage fails
closed.

## Audit

Report view, export request, start, success, failure, download, expiry, rate
limit, Fresh MFA requirement, and snapshot generation events are append-only.
Audit metadata contains only actor correlation, report type, period, QA filter,
public Export ID, row count, checksum, outcome, reason, and request ID.

## Deferred

Admin UI, Storefront UI, V2 browser domains, TLS, production object storage,
production deployment, and payment provider integration are deferred. Payment
provider implementation remains postponed until the later human-approved
payment review sequence.
