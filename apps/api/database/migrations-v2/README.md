# V2 Migration Root

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

This append-only root contains the current V2 migration source through
`2026_09_29_000073_create_v2_agency_foundation.php`. Runtime applied/pending state
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
`V2_AGENCY_LOGIN_URL` configures the future Portal link and does not activate it.
