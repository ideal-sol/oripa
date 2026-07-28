# V2 QA Draw Operations

## Purpose

V2 QA Draw is an Owner-only verification boundary. It selects configured prizes
while preserving the normal Draw transaction: Point consumption, inventory,
sold and won counts, Draw Result, User Prize, Audit, and Outbox are real domain
records. QA Draw is not a Mock, free Draw, or probability shortcut.

## Authorization

Only an MFA-verified `owner` in the V2 Admin Realm may read or change QA Mode,
QA Plans, or QA Executions. `admin` and `operator` roles are denied for read and
write operations. Public and Storefront contracts do not expose QA management
types, reasons, actor information, internal IDs, or storage identifiers.

## Draw Behavior

An absent, disabled, not-yet-started, or expired QA Mode uses the normal
Probability Draw. An active QA Mode requires one valid active Plan for the User
and Gacha. Missing, invalid, exhausted, or inconsistent configuration fails
closed before Point consumption; it never falls back to normal probability.

Plan Items are consumed by `sort_order`, internal ID, then remaining quantity.
Each Draw still resolves its Probability Stage and generates CSPRNG input, but
QA prize selection uses the locked Plan Item and cannot produce Point Back.
Counts `1`, `5`, `10`, `100`, and `1000` retain one transaction and existing
set-based persistence. Completed idempotent replay does not consume the Plan or
domain balances again.

## Data Lifecycle

Mode, Plan, Item, and Execution rows are retained. Disable operations are
logical; QA Executions are immutable. QA identity is traced through Draw
Request, Draw Result, and User Prize relations without adding a duplicate QA
flag to User Prize. This relation is the reporting and export boundary for
MIG-054.

No V1 QA Mode, Plan, or Execution is imported. V2 Production starts with zero
QA rows. Fixtures are limited to task-specific non-Production databases, and no
Production Seeder is provided.

## Audit And Privacy

Mode and Plan administration, completion, QA Draw success or failure, replay,
conflict, and Execution access emit redacted Audit events. Full email,
password, Cookie, Session ID, Token, CSPRNG value, secret, PII, and internal
asset storage information are excluded from Audit metadata and public output.

## Operational Limits

QA Draw does not provide Admin UI, Storefront UI, Reporting, CSV export,
Production Deployment, external notification delivery, or Payment Provider
integration. Operators must not create QA fixtures in Production to validate
this Alpha boundary.
