# V2 Database Baseline

## Status

MIG-040 establishes a non-Production PostgreSQL／Redis boundary. It does not
create Identity, Admin, Audit, Outbox, Point, Payment, or other business tables.

## Isolation

- V1 Production continues in Compose Project `oripa`.
- Persistent V2 development uses Compose Project `oripa-v2-dev`.
- CI uses unique `mig040-v2-*` projects.
- PostgreSQL 17 and Redis 7 use V2-only Network and Volume resources.
- PostgreSQL and Redis publish no Host Port.
- V2 uses a distinct database name, user, password, Redis password, and
  Application key.
- Redis is Cache／Queue infrastructure only and is never the authority for
  Point balances or financial records.
- Shared Runtime, shared Database, shared Redis, and `tenant_id` multi-tenancy
  are prohibited.

## Migration Boundary

The V1 migration root is `apps/api/database/migrations`. Its 40-file content
checksum is fixed by `.ci/baselines/v1-migrations.json`.

The V2 migration root is `apps/api/database/migrations-v2`. Every V2 command
must use the guarded runner and pass that path explicitly. The runner rejects:

- Production;
- a V1 or non-V2 database name;
- the V1 Compose Project or Volume namespace;
- the V1 migration path or an omitted path;
- a PostgreSQL／Redis Host outside the V2 Compose Network;
- an Env File that is a Symlink or readable by Group／Other.

Laravel's standard `migrations` repository table is the only table created by
MIG-040. No speculative schema-version or business table is added.

## Local Development

The non-secret path is `/etc/oripa-v2/dev.env`. The file is `root:root` mode
`600`; its values must never be printed, committed, or copied into an Issue,
PR, Worklog, or command argument.

```bash
python3 scripts/db/v2_database.py init-env \
  --repository . \
  --env-file /etc/oripa-v2/dev.env \
  --project oripa-v2-dev \
  --migration-path apps/api/database/migrations-v2

python3 scripts/db/v2_database.py persistent \
  --repository . \
  --env-file /etc/oripa-v2/dev.env \
  --project oripa-v2-dev \
  --migration-path apps/api/database/migrations-v2 \
  --evidence-dir /var/www/oripa-v1-evidence/MIG-040-v2-db-local
```

The persistent command starts only V2 PostgreSQL／Redis as long-lived services.
The API migration container is one-shot and removed after execution.

## Ephemeral Verification

`smoke` generates two short-lived, non-Production configurations. It applies
the empty V2 migration root twice, records status and schema inventory, creates
an empty-database backup, restores it into a second PostgreSQL resource, and
compares schema and migration checksums.

Cleanup never uses a global prune or an unscoped Volume deletion. The runner
stops only its named Task Projects and removes only Volumes carrying the same
Compose Project label.

## Production Boundary

This baseline does not create or authorize a Production database, credential,
migration, deployment, or commercial GO. V1 Production Database／Redis／Storage
must not be read, written, migrated, rolled back, seeded, or reset by this
runner.
