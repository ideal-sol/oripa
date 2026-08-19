# OPS-010 Mailgun Direct Mail Runtime Activation

## Task

- Issue: `#311`
- PR: `#312`
- Risk: `R4`
- Base SHA: `e622ee61fdeba61ebd24ef07823b82ae3c30392f`
- Branch: `chore/OPS-010-mailgun-direct-mail-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-010`
- Task Policy SHA-256: `9f13eb909b6fd9b35d9d5737ad96ba4baaec35d0da8e4c38a5e07697f5747f45`

## Scope

- Add a non-internal API-only egress bridge while keeping `v2_private` internal.
- Build a fresh immutable API image whose Application tree includes merged MIG-064.
- Recreate only the shared non-Production Preview API service.
- Do not send email or execute registration, resend, or verification-complete flows.

## Preflight

- `git fetch --prune` completed before task start. Clean local `main`, `origin/main`, task base, and the MIG-064 squash SHA all equaled `e622ee61fdeba61ebd24ef07823b82ae3c30392f`.
- All shared locks and lanes were idle. OPS-010 acquired only the Preview Deployment Lock.
- Previous API image was `oripa-v2-api:preview-OPS-009-d8374dc91824`, OCI revision `d8374dc918248385f9cceb92d96122800e3c70bd`, image ID `sha256:ef8b309fbe008ef3c8804ed00cb5d1413ea1761e469d525a069f8bf9bf19afb4`.
- Previous runtime resolved `V2EmailVerificationNotifier` to `V2OutboxEmailVerificationNotifier`.
- `MAIL_MAILER=mailgun`; Mailgun domain, secret, and From address were already configured without displaying their values.
- API health, PostgreSQL, Redis, and external Public Session returned PASS/200 before activation.
- API belonged only to internal `mig061a-v2-preview_v2_private`; Mailgun DNS and HTTPS failed as expected without egress.

## Egress Implementation

- `v2_private` remains `internal: true` with API, Admin, PostgreSQL, and Redis.
- `v2_api_egress` is a non-internal bridge using the minimal, overrideable `192.168.62.0/28` default subnet.
- Docker Engine 25 cannot create a container with the internal fixed-IP network and external default-gateway network simultaneously. It installs the external default route first and rejects the private address.
- The canonical procedure therefore creates API on `v2_private`, waits for health, then runs `scripts/ops/v2_api_egress.py attach`. The helper validates container identity, running state, internal/private isolation, bridge/subnet non-overlap, exact service memberships, and API-only egress attachment.
- Admin, PostgreSQL, and Redis remain exclusively on `v2_private`; only `mig061a-v2-preview-api-1` is attached to `v2_api_egress`.
- The DB guard rejects any create-phase egress attachment. The Policy gate and guarded helper reject private isolation changes, internal/non-bridge/overlapping egress, and any non-API egress membership.

## Image

- Application Head `238eacbce382f4daa4464a70790b63eb6a1ad84a` passed the Required 5 Checks.
- GitHub-hosted Preview Image Build Run `32270006079` produced Artifact `9371916558` for `linux/amd64`.
- Artifact digest is `sha256:11b743eb630a6c6eb828805adf007613ce059aecf1c02655a455bba0a3b2fe87`; manifest SHA-256 is `67dadcb0882d975aae0b0e5b1708e0ceeda8d9d4d225c979324cd9868397062d`.
- Loaded API image is `oripa-v2-api:preview-OPS-010-238eacbce382`, image ID `sha256:14de371be8eeba12d5608a50cb0fbe81e31413a7392386d6b8f4db7747b45d18`, OCI revision `238eacbce382f4daa4464a70790b63eb6a1ad84a`.
- API tree `adf7458f5b31cecdb1cf5b3a7388daec2889f2ee` exactly equals fixed latest-main/MIG-064 API tree.
- The pipeline also built its standard Admin image, but Admin was not deployed or recreated.

## Activation

- The first attempt failed closed because Docker automatically selected overlapping egress subnet `192.168.32.0/20`. API was immediately rolled back to the prior image/private-only topology and returned healthy/200.
- The second simultaneous-attach attempt used a non-overlapping `/28` but reproduced the Engine 25 default-route ordering limitation. API was again immediately rolled back and returned healthy.
- An isolated short-lived probe proved private-first creation followed by live egress attachment preserves private DNS and enables Mailgun DNS/HTTPS. Probe container and temporary probe network were removed.
- Final activation recreated only API with `--force-recreate --no-build --no-deps`, using canonical `/var/www/oripa/docker-compose.v2.yml`, the retained immutable image override, existing environment sources, fixed private IP, loopback behavior, restart policy, and persistent Asset volume.
- After API became healthy, the guarded helper attached only API to `v2_api_egress`. Admin, PostgreSQL, and Redis container IDs and start times remained unchanged.

## Runtime Verification

- Resolved notifier: `App\Domain\Identity\Services\V2MailEmailVerificationNotifier`.
- `MAIL_MAILER=mailgun`.
- Mailgun domain, secret, endpoint, scheme, and From address: `CONFIGURED`; no values were displayed.
- API Docker health and internal `/api/health`: PASS/200.
- PostgreSQL and Redis connections: PASS.
- `api.mailgun.net` DNS resolution: PASS.
- TCP 443: PASS. HTTPS/TLS: PASS with HTTP 200 and certificate verification result 0.
- Public API Session through `https://test.luxe-pack.biz/api/v2/auth/session`: 200.
- Activation-window API error/HTTP 500/502/504 count: 0. Nginx HTTP 500/502/504 count: 0.
- Real email, registration, resend, verification complete, Payment, Draw, Refund, and Chargeback: not executed.

## Impact

- Migrations created/applied: `0 / 0`.
- Database schema changes: `0`.
- Public/Admin contract changes: `0`.
- Repository artifact changes/publications: `0`.
- Storefront, Payment, Point, Draw, Auth semantics, Mail domain design, and Production: unchanged.

## Failure Handling and Rollback

- The first image workflow failed on a transient GitHub HTTP 504 while Composer downloaded `psr/http-message`; the unchanged exact head was retried successfully without weakening checks.
- The first closeout head failed Integration because Docker Compose removes an unused top-level network from normalized `config`; the DB guard now validates the normalized private-only create phase while Policy and the runtime helper retain the full egress boundary checks. The CI-equivalent smoke passed after this correction.
- Previous API image remains retained. Rollback is API-only recreate with that image and `python3 scripts/ops/v2_api_egress.py detach --project mig061a-v2-preview`; no DB or Redis data operation is required.
- Preview Deployment Lock was released after final runtime verification. Migration Allocation, Platform Integration, and Artifact Release Locks were never acquired.

## Validation

- Focused DB, Policy, and Egress suites: 188 tests PASS.
- Policy Unit 144, Quality Unit 4, Security Unit 10, DB Unit 39, and Ops Unit 39: PASS.
- CI-equivalent isolated V2 migration, full Identity/Draw/Reporting suites, backup/restore comparison, health checks, and task resource cleanup: PASS.
- Local Policy, Quality, and Security Gates: PASS.
- Composer validation and Composer/workspace pnpm/legacy pnpm audits: PASS with zero findings.
- Canonical Compose resolution, deployment JSON parsing through Quality Gate, and `git diff --check`: PASS.

## Closeout

- Final Required Checks, fresh fixed-head self-review, squash merge, Issue close, branch/worktree cleanup, and main synchronization are pending.
