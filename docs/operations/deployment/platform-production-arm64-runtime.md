# Platform Production ARM64 Runtime Authority

## Status and Boundary

Stage 3B adds artifact and runtime authority for the Platform API and Admin on
native `linux/arm64`. It creates no Production deployment, performs no Runtime
Activation, and does not authorize commercial Production GO. Existing Shared
Preview remains `linux/amd64` and unchanged.

AGENCYREADY-20260917 extends the same authority to the Agency Portal without
changing business behavior, migrations, authentication, or existing Preview.

The Strict pull-request CI path builds all three Production targets on a GitHub-hosted
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

- repository `ideal-sol/oripa` and components `api`, `admin`, and `agency`;
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

## Agency Runtime And Configuration Gate

- Existing target: `production` in `apps/agency/Dockerfile`; digest-pinned Node
  `22.22.3`, pnpm `10.12.1`, frozen dependencies, `@oripa/agency` Next.js standalone
  build, non-root `node`, internal port `3000`, `node server.js`.
- No site-specific public build argument is needed. Browser requests use the
  relative same-origin `/agency/api/v2` prefix. The Human-confirmed public origin
  is `https://agent.oripa-z.com`; no Test host port or upstream is prescribed.
- No dedicated Agency readiness endpoint or Docker healthcheck exists. `/login`
  can prove process/routing availability, not API, database or mail readiness.
- [Required non-secret config](agency-production-config.json) is an activation
  hard gate, not authorization to deploy. NEW Server must read back effective
  Laravel config after its existing config-cache procedure. In particular,
  `V2_AGENCY_LOGIN_URL=https://agent.oripa-z.com/login` must be explicit.
- `v2_agency.login_url` reads that environment setting before its old Test
  default. Both Agency mail call sites pass only this configured value; no
  redirect or API response uses the fallback independently. The inactive source
  string is retained for existing Test/dev behavior, not accepted as an effective
  Production URL. Persisted mail templates must also be checked before delivery.
- Both ARM64 build paths execute `agency_production_readiness.py` against the
  freshly built images with no network and a read-only filesystem: actual API
  config and four Agency mail variables are checked with the required public
  environment, and both frontend bundles are scanned for Old Test values.
  This executes no database operation, provider send, or live activation.
- Agency idle/absolute limits remain 6h/12h; its host-only Secure/HttpOnly session
  cookie remains distinct from the deliberately JavaScript-readable CSRF cookie.

When building on GitHub, OLD Server need only retain/download the archives.
Check free space and inodes before download/verification; verify one expanded
archive at a time. Do not build/load ARM64 images on OLD AMD64 or prune rollback
images/volumes to make room. Any later rollback retains Agency schema/data and
rolls Storefront AGENCY-004B back first if the API crosses below AGENCY-004A.

## Explicit Deferrals

This authority does not define PostgreSQL or Redis Production services, their
digests or persistence, Asset persistence, Storefront runtime, a Production
Compose stack, loopback host ports, Nginx, ALB, Target Groups, ACM, DNS, Security
Groups, Production secrets, deployment, migration, or Runtime Activation. Each
remains a separate later Stage with its own Human checkpoint.
