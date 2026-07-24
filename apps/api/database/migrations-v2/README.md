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

The root contains six migrations through MIG-043. It is a development and CI
baseline, not a Production migration set. OAuth, Password Reset, Payment, Draw,
real external notification transport, Point Reservation／Payment Adjustment,
Audit search／export, and WORM storage integration are not implemented.
