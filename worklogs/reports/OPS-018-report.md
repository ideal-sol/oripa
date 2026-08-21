# OPS-018 Shared Mailgun Credential Rotation

## Task

- Risk: `R4`
- Issue: `#348`
- Pull Request: `#349`
- Base SHA: `0819b1c0933af0ef45552a87a55a4f89cdac234d`
- Branch: `chore/OPS-018-mailgun-shared-credential-rotation`
- Worktree: `/var/www/oripa-worktrees/OPS-018`

## Stage 0 and Human Authority

Human Operator established that Shared Preview and V1 Production use the same Mailgun domain and intentionally share `MAILGUN_SECRET`. Human Operator created the new credential, installed it in `/var/www/oripa/.env` without disclosure, authorized the credential-only Production reflection, retained the old credential through all pre-revocation gates, and revoked it at `2026-08-21 08:15 UTC`.

No credential value, hash, or digest was printed, committed, written to Issue/PR evidence, or requested through this conversation.

## Credential Result

- `MAILGUN_SECRET`: `ROTATED`.
- Credential type: Mailgun API credential with observed domain-read capability.
- Exact provider-side subtype: not inferred from the secret or exposed in evidence.
- Preview and Production canonical sources are root-owned mode `0600` and contain the same new credential without value output.
- The old credential is rejected by Mailgun authentication after revocation.
- Remaining exposed credentials: `0`.

## Shared Preview

- Recreated only the API using the existing OPS-017 immutable image and canonical Compose inputs.
- Reattached the guarded API-only egress network.
- API internal health and Public Session: `200`.
- Admin, PostgreSQL, and Redis remained healthy and were not recreated.
- API, Admin, PostgreSQL, and Redis restart counts: `0`.
- New credential test-mode authentication and canonical sender self-recipient operational send passed both before and after old credential revocation.

## Production Consumer Coordination

- The running V1 Production backend was an active Mailgun consumer whose canonical `/var/www/oripa/backend/.env` still held the old credential.
- Changed only its `MAILGUN_SECRET` line atomically, then recreated only the backend with no build and no dependencies.
- Production backend health returned `200`; authentication and operational send passed before and after old credential revocation.
- Frontend, PostgreSQL, and Redis container identity and start time remained unchanged; PostgreSQL and Redis remained healthy with restart count `0`.
- Stopped queue and scheduler snapshots contained a different historical credential. They were recreated without starting and now hold the new credential, preventing a direct future start from reviving an old value.

## Verification and Impact

- Old credential rejection after revocation: PASS.
- New credential Preview actual send after revocation: PASS.
- New credential Production backend actual send after revocation: PASS.
- Activation-window HTTP `500 / 502 / 504`: `0 / 0 / 0`.
- Restart loop: none.
- Business mutation: `0`.
- Migrations created/applied: `0 / 0`.
- Database, Storefront, Nginx, DNS, network topology, Payment, Point, Coin, Draw, and unrelated Production changes: `0`.
- Protected old/new task secret copies were removed after post-revocation verification.
- OPS-017 non-Mailgun rollback candidate was not read or changed.

## Activation Readiness

After OPS-018 merge and closeout evidence, `000056` through `000059` plus the corresponding API/Admin Runtime Activation may start. This statement does not apply migrations or activate that Runtime.

## Validation

- Runtime verification: PASS.
- Mailgun authentication and actual send: PASS.
- Policy Unit: 152 tests PASS.
- Quality Unit: 4 tests PASS.
- Security Unit: 10 tests PASS.
- Local Policy, Quality, and Security Gates: PASS; dependency findings and secret candidates are 0.
- Composer, workspace pnpm, and legacy pnpm audits: PASS with 0 findings.
- JSON parse and `git diff --check`: PASS.
- Required 5 Checks and fresh fixed-head self-review: PASS at reviewed head `60fed67bc627f0c493db45da4bbe5833bb1ac04c`, with no SEV-0/SEV-1 findings.
- The first merge attempt was fail-closed because an earlier superseded workflow left PR mergeability `unstable`; a documentation-only successor head must repeat all Required 5 Checks and fresh fixed-head self-review before merge.
- Full Backend/Frontend/Browser/E2E suites: NOT RUN because this Task changes only operational evidence and Mailgun Runtime credential state; executed Runtime sends and health checks are the behavioral verification.

## Rollback

Before old credential revocation, protected old/new candidates and atomic env replacement procedures were verified. After successful revocation and post-revocation send verification, those task secret copies were removed. The revoked credential must not be restored; recovery now reapplies the canonical new credential and recreates only the affected consumer.
