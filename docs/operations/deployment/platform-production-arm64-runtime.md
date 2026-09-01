# Platform Production ARM64 Runtime Authority

## Status and Boundary

Stage 3B adds artifact and runtime authority for the Platform API and Admin on
native `linux/arm64`. It creates no Production deployment, performs no Runtime
Activation, and does not authorize commercial Production GO. Existing Shared
Preview remains `linux/amd64` and unchanged.

The Strict pull-request CI path builds both Production targets on a GitHub-hosted
native `ubuntu-24.04-arm` runner and uploads a one-day verification artifact.
That parallel PR artifact is test evidence, not Runtime Activation Authority.

After merge, `.github/workflows/platform-production-arm64-artifact.yml` may
build the deployable candidate from exact current protected `main`. It requires
the merged internal PR identity and all five Required Checks on the merge SHA,
then packages, verifies, loads, and uploads a seven-day artifact named
`oripa-platform-production-candidate-<MERGE_SHA>-linux-arm64`. This is a
merge-first Build; it does not deploy. CI emulation is not used and the
Production host must not use QEMU.

## Immutable Artifact Identity

The architecture-aware manifest binds:

- repository `ideal-sol/oripa` and component `api` or `admin`;
- exact pull-request source SHA and `architecture=arm64`;
- immutable OCI config digest in `image_id` and the exact image reference;
- OCI source revision equal to the source SHA;
- archive SHA-256, archive size, canonical manifest SHA-256, and `SHA256SUMS`.

Package, verify, and load require the expected artifact kind and architecture.
Source, OCI revision, image digest, checksum, or native host mismatch fails
closed. Mutable `latest` is never deployment authority. Rollback in a later
deployment uses the previously recorded exact artifact, image ID, and source
revision; no image is rebuilt during rollback.

## API Runtime Contract

- Target: `production` in `infra/docker/backend/Dockerfile`.
- Runtime: digest-pinned PHP 8.4 Apache image, HTTP port `8000`, non-root
  `www-data` user, Composer `--no-dev --classmap-authoritative` install.
- Configuration: Laravel application mode/origins, database and Redis endpoints,
  session/cache/queue, mail/provider flags, audit keys, and storage disk are
  runtime inputs. None are build arguments or OCI labels.
- Secrets: inject only at a later approved deployment boundary. No real
  Production `.env` belongs in source, image layers, manifests, or logs.
- Persistence: application image layers are immutable. Asset persistence and
  the final writable storage layout remain undefined for a later Stage.
- Health: `/api/health` is the deep readiness endpoint and remains dependent on
  database, Redis, and configured storage. A 503 prevents readiness.
- Restart: the later supervisor must use a bounded restart policy such as
  `unless-stopped`; this Stage does not define or activate it.

## Admin Runtime Contract

- Target: `production` in `apps/admin/Dockerfile`.
- Runtime: digest-pinned Node `22.22.3`, pnpm `10.12.1` frozen build, standalone
  Next.js server, non-root `node` user, HTTP port `3000`.
- Configuration: `ADMIN_ALLOWED_HOSTS`, the planned public origin, and other
  explicitly public runtime inputs may be injected later. No Production secret
  is a build input or client-visible value.
- Health: `/api/health` proves only that the Admin process can serve requests.
  Its `readiness_scope=process` does not claim API, database, provider, routing,
  legal, or commercial readiness.
- Restart: the later supervisor must use a bounded restart policy such as
  `unless-stopped`; this Stage does not define or activate it.

## Planned Non-secret Origins

Human-planned values are `https://oripa-z.com` for the public origin,
`https://admin.oripa-z.com` for Admin, and same-origin `/api/v2/` for the Public
API. They are documentation only in Stage 3B and are not injected or routed.

## Explicit Deferrals

This authority does not define PostgreSQL or Redis Production services, their
digests or persistence, Asset persistence, Storefront runtime, a Production
Compose stack, loopback host ports, Nginx, ALB, Target Groups, ACM, DNS, Security
Groups, Production secrets, deployment, migration, or Runtime Activation. Each
remains a separate later Stage with its own Human checkpoint.
