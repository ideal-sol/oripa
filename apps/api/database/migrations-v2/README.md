# V2 Migration Root

## RANKPOINTS-20261016 (M2)

Migration `2026_10_16_000090_add_v2_member_rank_monthly_judgment.php` is allocated
to `RANKMONTH-20261016` under the Migration Allocation Lock. It extends immutable
rank changes with one-step monthly demotion and a unique month origin, enforces
forward judgment cursors, and adds durable monthly jobs with lease and progress.
Previous migration bytes remain unchanged. Forward-only; operational application
and Worker activation require separate Human GO. See the
[M3 handoff](../../../../docs/implementation/MEMBER_RANK_M3_HANDOFF_2026-10-16.md).

Migration `2026_10_16_000089_create_v2_member_rank_accounting.php` is reserved
under the exclusive Migration Allocation Lock after verifying all open PRs,
worktrees and prior allocations. Six additive tables separate rank state,
monthly totals, append-only payment entries, changes, achievements and current
entitlements. Deferred total reconciliation and verified payment-origin guards
protect accounting. Generation-specific achievements preserve future recovery
and regrant history. Existing migrations, activation gates and Public contracts
are unchanged. M3 monthly judgment and M7/M8 adjustment/recovery remain future.
Apply only to task-owned disposable PostgreSQL for tests. Operational application
and runtime activation are deferred. `down()` rejects; rollback retains schema
and history and uses a separately reviewed source change.

## MEMBERRANK-20261016 (M1)

Migration `2026_10_16_000088_create_v2_member_rank_settings.php` is allocated
under the exclusive Migration Allocation Lock. It adds only a disabled singleton,
immutable setting versions and seven constrained rank values per version.
Deferred completeness/order constraints and irreversible activation history guards
protect stored settings. M1's API refuses initial activation. Settings and
cancellations append a version effective at next month 00:00 Asia/Tokyo;
reads select it without a worker. Existing migrations are unchanged.
Test application is restricted to disposable isolated databases. Operational
application, runtime activation and feature enablement require separate Human GO.
Rollback retains the additive schema and uses the previous application; down()
rejects. The allocation remains reserved through Change closeout.

## SIGNUP-SMS-GATE-20261012

Migration `2026_10_12_000083_add_v2_signup_sms_gate.php` adds nullable User
`full_name`, `birth_date` (DATE), and `first_sms_verified_at` (TIMESTAMPTZ).
The Migration Allocation Lock was acquired before allocation of 000083;
000081 and 000082 are unchanged. There is no backfill. A database trigger
rejects changes or clearing once the first SMS timestamp is non-null.
Existing phone ownership or consumed OTP history prevents a new qualification.
Rollback is application-only with the additive schema retained; `down()`
requires a forward correction. Only disposable task databases are authorized
for test application. OLD Test, NEW and Production application and Runtime
activation remain deferred pending separate Human approval.

## Purpose

This directory is the only Laravel migration root for the new V2 database.
MIG-040 created the empty migration boundary. MIG-041 and MIG-041A add the
approved Identity／Admin Realm and Authentication foundations. MIG-042 adds the
Audit／Transactional Outbox foundation. Laravel's standard `migrations`
repository table is created inside the isolated V2 database when the guarded
runner executes.

## Rules

- Run this path only through `scripts/db/v2_database.py`.
- Always pass `apps/api/database/migrations-v2` explicitly.
- Never load `apps/api/database/migrations` as part of a V2 migration command.
- Never target Production, the V1 database, or a shared PostgreSQL service.
- Add domain tables only in their approved contract-first task.
- MIG-041 owns Identity accounts, Realm-separated sessions, User remember
devices, and Admin MFA credential storage.
- MIG-041A owns Email Verification and Initial Admin Invitation persistence.
- MIG-042 owns append-only Audit, Daily Digest, and Transactional Outbox
  persistence.
- MIG-043 owns Wallet, Point Operation／Lot／Ledger, Adjustment request,
  Ledger-cutoff Snapshot, Reconciliation, and Idempotency persistence.
- `point_lot_reservations` is intentionally deferred to MIG-044 because its
  required `payment_adjustment_id` Foreign Key depends on the approved Payment
  Model migration. MIG-044 must add that table with Foreign Keys to
  `point_lots` and `payment_adjustments`, amount／status constraints, and
  Transactional reserved-balance reconciliation.
- Payment, Draw, and other business tables remain prohibited until their
  approved tasks.
- Do not rewrite or remove an applied V2 migration.

## Status

Migration `2026_10_13_000084_create_v2_banner_asset_operations.php` adds the durable
`content_banner_asset_operations` ledger in Source. It is independent of the
30-day import Job/Row history and forbids ledger DELETE and identity mutation.
It is forward-only: `down()` rejects with
`Banner asset operations require a forward correction migration.`
Application is authorized only in the dedicated synthetic F2-B test database.
Source presence does not prove application; OLD, NEW and Production application
and Runtime activation require separate Human GO. Existing 000082/000083 are
unchanged. The new 200-row acceptance limit is application-only; the legacy
1–1,000 Job CHECK remains compatible with retained history. Object deletion and
automatic ledger retention expiry are HOLD.

Migration `2026_10_11_000082_create_v2_banner_imports.php` is added in Source for
`content_banner_import_jobs` and `content_banner_import_rows`, including short-lived
encrypted image URLs. It is forward-only: `down()` rejects with
`Banner import history requires a forward correction migration.` The DB guard
verifies rejection without changing schema or the migration ledger. Application
is authorized only during dedicated F2-A isolated test DB verification; Source
presence is not proof of application. It is NOT applied to OLD, NEW or Production.
Operational migration and Runtime activation require separate Human GO; Banner
CSV Import remains disabled by default. Existing migration history is unchanged.

This append-only root contains the current V2 migration source through
`2026_10_06_000081_add_v2_prize_banner_external_ids.php`. Migration 000081 adds nullable,
case-sensitive external IDs to existing Banners and Prizes without data backfill.
It preserves the Login mutation guard except for a published Prize's one-time
null-to-valid external ID update with its revision/timestamp; all other Prize
columns must remain byte-equivalent. Existing migration 000078 is unchanged. Its
rollback is application-only with the additive columns retained; schema changes
require a forward correction. Runtime applied/pending state
must always be read from the guarded environment migration ledger; source
presence alone is not evidence that a migration was applied. Production
application remains a separate Human-authorized Release Gate action.

AGENCY-001 adds Agency credentials, immutable Advertising Codes, and an empty
User Attribution foundation. No registration hook or existing-User backfill is
included. Rollback of `000073` is allowed only before Agency business history
exists; after use, retain the schema and roll application images back or use a
forward correction migration.

Agency notifications extend the fixed Mail Template catalog. Password-bearing
mail is attempted once after commit using request-local values; delivery rows
store status and identifiers only. A failed or interrupted notification requires
an explicit Admin login-information reissue with a newly entered password.
`V2_AGENCY_MAILER` defaults to the existing `MAIL_MAILER` configuration. QA
verification explicitly selects the non-external `array` transport or a fake.
Delivery configuration may select an existing SMTP or Mailgun mailer; log,
failover, and queued persistence are not supported for Agency credentials.
`V2_AGENCY_LOGIN_URL` configures the Portal link and does not activate it.

AGENCY-002 adds dedicated `agency_sessions`, the Agency audit realm, and the
fixed `agency_email_changed` template. Sessions use the canonical V2 persisted
time policy with idle 6 hours / absolute 12 hours. Migration `000074` can roll
back only before Agency session, audit, or email-change delivery history exists.
After use, retain schema and roll back the API/Portal images or apply a forward
correction. Migration application and domain routing activation are independent.
## Member rank Admin operations (000091)

Migration `2026_10_17_000091_add_v2_member_rank_admin_operations.php` extends
the payment-only rank ledger with signed, actor-attributed manual entries while
preserving payment origin validation, uniqueness, immutable history and deferred
monthly-total equality. It adds immutable manual-change and bonus-recovery
records, generation-specific recovery origins and a frozen closure timestamp.
Promotion/demotion shapes remain constrained; manual changes and promotion
reversals have separate source references. Alpha.45 and old migrations remain
unchanged. Rollback retains schema/history and requires a reviewed forward fix.
This source does not authorize operational migration, worker or feature activation.

# Rank video sets (000086)

Migration `2026_10_15_000086_add_v2_rank_video_sets.php` backfills every historical
video revision as one immutable 100% item. New sets contain 1–10 distinct rank
effect materials with positive integer weights totaling 10000. The legacy asset
column remains the highest-weight item (ties use display order); single-video
inserts still create one 100% item. Deferred database checks validate complete
sets. Draw results retain their old revision and snapshots, with a nullable item
reference for newly selected videos. Published login Gachas permit only new video
revisions and their active pointer updates; composition remains immutable.

Runtime activation is deferred. Apply schema before the new application. Retain
the additive schema on application rollback; older applications cannot implement
weighted selection or the revised login behavior, so restoring them requires
pausing affected draws until a forward fix. Migration down is deliberately refused.

## Member rank Gacha conditions (000093)

Migration `2026_10_18_000093_add_v2_member_rank_gacha_conditions.php` adds
independent rank conditions to Gacha versions and immutable Draw rank snapshots.
Existing versions default to no condition; existing Draw snapshots remain null.
Signup conditions are forbidden and published conditions cannot change.
The migration is forward-only. Retain schema and snapshots on rollback.
Application to operational databases and initial feature activation are deferred.

## Member rank purchase bonuses (000092)

Migration `2026_10_17_000092_create_v2_member_rank_purchase_bonuses.php` adds an
immutable payment-to-purchase-bonus snapshot. Its effective setting, pre-increment
rank, rate, paid amount, JST grant month and positive free-grant Operation origin
are retained for M8 cancellation. Deferred constraints bind the Operation, lot
and ledger to the same payment/user/amount/time. Zero bonuses have no Operation.
Existing payment grant totals and migrations remain unchanged. No backfill;
forward-only rollback. Test application is isolated; operational application is
deferred and requires separate authority.

## Member rank payment adjustments (000094)

Migration `2026_10_20_000094_add_v2_member_rank_payment_adjustments.php` retains
positive payment entries and adds a separately keyed negative adjustment origin.
Requested cancellation remains nonzero even when the applied decrease is zero.
Deferred checks bind immutable adjustment accounting to the final succeeded
refund/chargeback, original user/payment/grant/JST month, signed point entry,
prior month total and paid/free impact. A partial unique index permits at most
one succeeded refund or chargeback per original payment. Canceled payments
cannot subsequently be backfilled into positive rank points.

Payment-origin free points are one combined normal/limited-bonus lot. M4 purchase
bonus points remain a separate operation with exactly one lot and grant ledger
under unchanged migration 000092. New reversals append operations and negative
ledger entries; grant origins are never rewritten. Purchase-bonus shortfall is a
subset of free shortfall, allocated after the payment-origin free requirement.

Schema and writer activation must be coordinated: an older writer cannot satisfy
the new success constraint. Do not roll back to a writer that omits cancellation
accounting. Retain immutable evidence and use a reviewed forward fix; down is
refused. No operational application, feature activation or contract publication
is authorized by this source change.
