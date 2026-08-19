# OPS-010 Mailgun Direct Mail Runtime Activation

## Task

- Issue: `#311`
- Risk: `R4`
- Base SHA: `e622ee61fdeba61ebd24ef07823b82ae3c30392f`
- Branch: `chore/OPS-010-mailgun-direct-mail-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-010`
- Task Policy SHA-256: `1eebaaa6b210ad7dac5056376d2c467ced30a8a7d82cbf3fb89e78c1c634fdd7`

## Scope

- Add a non-internal `v2_api_egress` bridge attached only to the API service.
- Keep `v2_private` internal and keep Admin, PostgreSQL, and Redis exclusively on it.
- Build a new immutable API image whose Application tree includes merged MIG-064, then recreate only the shared non-Production Preview API service.
- Do not send an email or execute registration, resend, or verification-complete flows.

## Preflight

- `git fetch --prune` completed before task start; clean local `main`, `origin/main`, task base, and MIG-064 squash SHA all equal `e622ee61fdeba61ebd24ef07823b82ae3c30392f`.
- All shared locks and lanes were idle. OPS-010 acquired only the Preview Deployment Lock.
- Existing API image is `oripa-v2-api:preview-OPS-009-d8374dc91824`, OCI revision `d8374dc918248385f9cceb92d96122800e3c70bd`, and Docker health is healthy.
- Existing runtime resolves `V2EmailVerificationNotifier` to `V2OutboxEmailVerificationNotifier`.
- Existing runtime has `MAIL_MAILER=mailgun`; Mailgun domain, secret, and From address are configured without displaying their values.
- Existing API-to-PostgreSQL and API-to-Redis checks pass, and internal `/api/health` returns 200.
- Existing API is attached only to internal `mig061a-v2-preview_v2_private`; Mailgun DNS and HTTPS fail because that network has no external route.

## Implementation

- Compose adds `v2_api_egress` as a project-scoped bridge and attaches it only to API.
- Database and Policy guards require exactly the private and API-egress networks, preserve `internal: true`, reject egress attachment by Admin/PostgreSQL/Redis, and reject a non-bridge or internal egress network.
- Focused DB and Policy unit suites pass: 180 tests.
- Canonical Compose resolution and `git diff --check` pass without displaying environment values.

## Runtime Activation

- Pending immutable image build and API-only recreate.

## Closeout

- Required Checks, fixed-head self-review, squash merge, Issue close, lock release, and branch/worktree cleanup are pending.
