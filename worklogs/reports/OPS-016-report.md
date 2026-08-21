# OPS-016 Shared Preview Runtime Credential Rotation

## Task

- Issue: `#344`
- PR: `#345`
- Risk: `R4`
- Base: `2a017ff0bcdf70ca63a512fe44f15fb620fbac22`
- Branch: `chore/OPS-016-preview-runtime-credential-rotation`
- Worktree: `/var/www/oripa-worktrees/OPS-016`

## Preflight

- `git fetch --prune` completed. Clean local `main`, `origin/main`, GitHub App readback, and authenticated Remote readback exactly matched the Base.
- All task lanes were idle, all Shared Locks were `none`, the Preview Deployment OS lock was free, and no competing deployment held the authoritative lock.
- API, Admin, PostgreSQL, and Redis were healthy with restart count `0` before the Rotation Gate.
- The OPS-014 transcript exposure was reviewed by key name only. Runtime aliases were `APP_KEY`, `DB_PASSWORD`, `POSTGRES_PASSWORD`, `REDIS_PASSWORD`, `V2_AUDIT_HMAC_KEY`, `V2_PII_CORRELATION_KEY`, and `MAILGUN_SECRET`.
- Canonical rotation keys are `V2_APP_KEY`, `V2_DB_PASSWORD`, `V2_REDIS_PASSWORD`, `V2_AUDIT_HMAC_KEY`, `V2_PII_CORRELATION_KEY`, and `MAILGUN_SECRET`.

## Canonical Sources

- Core Preview Runtime source: root-owned mode `600` `/var/lib/oripa-v2-evidence/MIG-061A/v2-preview.env`.
- Mail provider source: root-owned mode `600` ignored file `/var/www/oripa/.env`.
- The core source is scoped to Compose project `mig061a-v2-preview`; however, an authoritative Preview-only provider-account boundary for `MAILGUN_SECRET` was not found.

## Rotation Gate

- `V2_APP_KEY` protects Laravel-encrypted data. No authoritative current/previous-key or complete re-encryption procedure exists for this Runtime.
- `V2_AUDIT_HMAC_KEY` verifies the existing audit hash chain and daily digests. The current source exposes only the active `v1` key and no authoritative versioned rotation procedure.
- `V2_PII_CORRELATION_KEY` backs persisted PII correlation values used by shipping, identity, and contact behavior. No authoritative dual-key or reindex procedure exists.
- `MAILGUN_SECRET` requires an external provider rotation operation. No approved callable provider method exists, and Preview-only sharing is not confirmed.
- The explicit Rotation Gate therefore failed closed before any change. Partial DB/Redis-only rotation was not attempted.

## Rotation Result

- Credential actually changed: **FAIL / NOT CHANGED**.
- Rotation result: **FAIL_CLOSED**.
- New secret generation: `0`; temporary secret files: `0`; dependent service recreate/restart: `0`.
- Remaining exposed canonical credentials: `6`.
- No old or new credential value, prefix, hash, or digest is present in the repository report or authoritative evidence.
- During preflight, a broad search of pre-existing root-only evidence unintentionally re-rendered previously exposed values into the OPS-016 Codex transcript. Values were not copied into Task files, but OPS-016 cannot claim zero transcript display; this strengthens the remaining rotation blocker.

## Env Shape

- OPS-015's unexpected fields are `V2_ADMIN_ALLOWED_HOSTS`, `V2_ADMIN_ORIGIN`, `V2_PUBLIC_ORIGIN`, `V2_WEBAUTHN_ORIGIN`, and `V2_WEBAUTHN_RP_ID`.
- Canonical Compose or Application source actively references all five fields. They are not obsolete credential fields and were not removed.
- `scripts/db/v2_database.py` is the authoritative development/CI database initializer with a narrower generated env schema; it was not weakened or changed to accept the persistent Runtime source.
- Runtime env, guard, Governance, policy, Nginx, DNS, and network topology changes: `0`.

## Runtime Verification

- Internal API health: HTTP `200`; its controller checks Application, PostgreSQL, Redis, and storage.
- Public read-only session endpoint: HTTP `200`.
- Admin internal and external health: PASS / HTTP `200`.
- PostgreSQL and Redis container health: PASS.
- API, Admin, PostgreSQL, and Redis restart counts: all `0`; restart loop: none.
- Activation-window Nginx HTTP `500` / `502` / `504`: `0`.
- `v2_private` remains internal with API, Admin, PostgreSQL, and Redis; `v2_api_egress` remains API-only.
- An external `/api/health` request returned `404` because that route is not published through the Public origin; the canonical Public session read returned `200`.

## Scope Preservation

- Migrations `000056` through `000059`, database schema, and business data changes: `0`.
- Application source, Artifact, Production, Storefront, Payment, Coin, Draw, Nginx, DNS, and network changes: `0`.
- Registration, email send, Payment, Draw, and any business mutation: `0`.
- Preview Deployment Lock was not acquired because the task failed closed before the mutable window; the OS lock remained free and required no release.

## Authoritative Evidence

- Root-only: `/var/lib/oripa-v2-evidence/OPS-016/rotation-evidence.json`.
- Repository: `deployments/OPS-016-preview-runtime-rotation.json` and this report.
- Evidence status is explicitly blocked, not rotation complete.

## Remaining Blocker

- A separately approved procedure must define complete safe rotation for Laravel encryption, audit-chain verification, and PII correlation, plus an approved Preview-only Mailgun provider rotation method.
- Migration Activation for `000056` / `000057` is **NOT STARTABLE** while the six canonical credentials remain exposed.

## Closeout Validation

- PASS: Policy Unit 152 tests, Quality Unit 4 tests, and Security Unit 10 tests.
- PASS: local `policy-gate`, `quality-gate`, and `security-gate`.
- PASS: fresh Composer, workspace pnpm, and legacy pnpm audits with zero findings.
- PASS: deployment JSON parse, exact allowed-path review, `git diff --check`, binary/submodule review, and repository secret scan with zero candidates.
- Backend, Frontend, Build, Browser/E2E, Migration application, and Runtime activation tests were not run because Application, Migration, and Runtime mutation are out of scope or blocked.
- Required five GitHub checks and fresh fixed-head self-review remain mandatory before squash merge.
