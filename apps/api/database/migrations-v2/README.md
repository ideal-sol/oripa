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
- Point, Payment, Draw, and other business tables remain prohibited until
  their approved tasks.
- Do not rewrite or remove an applied V2 migration.

## Status

The root contains five migrations through MIG-042. It is a development and CI
baseline, not a Production migration set. OAuth, Password Reset, Point,
Payment, Draw, real external notification transport, Audit search／export, and
WORM storage integration are not implemented.
