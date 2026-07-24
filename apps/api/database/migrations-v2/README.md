# V2 Migration Root

## Purpose

This directory is the only Laravel migration root for the new V2 database.
MIG-040 intentionally adds no business tables. Laravel's standard `migrations`
repository table is created inside the isolated V2 database when the guarded
runner executes.

## Rules

- Run this path only through `scripts/db/v2_database.py`.
- Always pass `apps/api/database/migrations-v2` explicitly.
- Never load `apps/api/database/migrations` as part of a V2 migration command.
- Never target Production, the V1 database, or a shared PostgreSQL service.
- Do not add Identity, Admin, Audit, Outbox, Point, Payment, or other domain
  tables before their approved contract-first task.
- Do not rewrite or remove an applied V2 migration.

## Status

The root is empty apart from this instruction file. It is a development and CI
baseline, not a Production migration set.
