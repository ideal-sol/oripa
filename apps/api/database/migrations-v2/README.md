# V2 Migration Root

## Purpose

This directory is the only Laravel migration root for the new V2 database.
MIG-040 created the empty migration boundary. MIG-041 adds only the approved
Identity／Admin Realm foundation. Laravel's standard `migrations` repository
table is created inside the isolated V2 database when the guarded runner
executes.

## Rules

- Run this path only through `scripts/db/v2_database.py`.
- Always pass `apps/api/database/migrations-v2` explicitly.
- Never load `apps/api/database/migrations` as part of a V2 migration command.
- Never target Production, the V1 database, or a shared PostgreSQL service.
- Add domain tables only in their approved contract-first task.
- MIG-041 owns Identity accounts, Realm-separated sessions, User remember
  devices, and Admin MFA credential storage.
- Audit, Outbox, Point, Payment, Draw, and other business tables remain
  prohibited until their approved tasks.
- Do not rewrite or remove an applied V2 migration.

## Status

The root contains three MIG-041 Identity migrations. It is a development and CI
baseline, not a Production migration set. Login, Registration, OAuth, MFA
Enrollment, Password Reset, Audit, and Outbox flows are not implemented.
