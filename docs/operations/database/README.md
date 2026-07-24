# V2 Database Baseline

## Status

MIG-040 establishes a non-Production PostgreSQL／Redis boundary. MIG-041 and
MIG-041A add the V2 Identity／Admin Authentication foundation. MIG-042 adds
Audit／Transactional Outbox persistence. Point, Payment, Draw, and other
business tables are not part of this baseline.

## Isolation

- V1 Production continues in Compose Project `oripa`.
- Persistent V2 development uses Compose Project `oripa-v2-dev`.
- CI uses unique Task-scoped `migNNN-v2-*` projects.
- PostgreSQL 17 and Redis 7 use V2-only Network and Volume resources.
- PostgreSQL and Redis publish no Host Port.
- V2 uses a distinct database name, user, password, Redis password, Application
  key, and Audit HMAC key.
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

MIG-041 creates `users`, `admins`, Realm-separated Session tables, User remember
devices, and Admin WebAuthn／TOTP／Recovery Code storage. The two Realm tables,
Providers, Guards, Cookie names, and Session policies are separate.

MIG-041Aは`user_email_verifications`と`admin_invitations`を追加する。両Tableは
CSPRNG TokenのSHA-256 Hashだけを保存し、User Verificationは60分、Admin
Invitationは30分、いずれも1回限りである。`admin_sessions`へ
`requires_mfa_enrollment`を追加し、Recovery Code使用後のSessionを通常Admin
業務AccessからFail Closedにする。

MIG-042は`audit_logs`、`audit_daily_digests`、`outbox_messages`を追加する。
AuditはPostgreSQL TriggerとApplication Modelの両境界でUpdate／Delete／Truncateを
拒否し、外部`V2_AUDIT_HMAC_KEY`によるRecord Hash ChainとDaily Digestを持つ。
OutboxはDomain変更と同じTransaction内でenqueueし、Deduplication Keyと
`FOR UPDATE SKIP LOCKED`によるClaim、Lease、Retry、Delivered／Failed遷移を持つ。
外部通信はDB Transaction外の後続Worker責任であり、本Taskでは実装しない。

Verified User Email is protected by a partial Unique Index while Pending Email
may repeat. Admin Email is Unique inside the Admin Realm. Account State, fixed
Admin Role, Argon2id Hash format, Session Hash format, remember-device lifetime,
and MFA storage format are protected by PostgreSQL Constraints.

No `tenant_id`, speculative schema-version table, Point, Payment, or Draw table
is added.

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
The API migration／Identity Test container is one-shot and removed after
execution.

## Ephemeral Verification

`smoke` generates two short-lived, non-Production configurations. It applies
the V2 migration root twice, runs `tests/V2`, records status and schema
inventory, creates a V2 Identity database backup, restores it into a second
PostgreSQL resource, and compares schema and migration checksums.

Cleanup never uses a global prune or an unscoped Volume deletion. The runner
stops only its named Task Projects and removes only Volumes carrying the same
Compose Project label.

## Production Boundary

This baseline does not create or authorize a Production database, credential,
migration, deployment, or commercial GO. V1 Production Database／Redis／Storage
must not be read, written, migrated, rolled back, seeded, or reset by this
runner.
