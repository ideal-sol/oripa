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
build the deployable candidate from the explicit `source_sha` recorded in
`manifests/platform-production-approved-source.json`. The workflow and approval
metadata come from exact current protected `main`; the Runtime Source remains
the requested approved commit, which must exist and be an ancestor of that main.
Changing the approved target requires a protected PR and explicit Human authority.
The gate requires the merged internal source PR identity, reviewed/merged tree
equality, all five Required Checks on the reviewed source, and all five current
workflow-authority checks. It also runs current policy validation and scans the
exact Runtime Source using the current secret/path security checks. It
then packages, verifies, loads, and uploads a seven-day artifact named
`oripa-platform-production-candidate-<APPROVED_SOURCE_SHA>-linux-arm64`. This is a
merge-first Build; it does not deploy. CI emulation is not used and the
Production host must not use QEMU.

The source checkout is separate from the workflow-authority checkout. The
manifest, OCI revision, image labels and readiness evidence retain the exact
requested source SHA. `production-source-authority.json` records that source,
its tree, reviewed head, workflow SHA and check evidence. A main advance never
substitutes a new Runtime Source; a stale dispatch, missing approval, arbitrary
ancestor, malformed/missing commit, tree mismatch or failed check is rejected.
Contact preparation does not dispatch this workflow; see
[the preparation review](contact-production-preparation.md).

## Approved Source Update — PRODAUTH-20260924

Human acceptance approves Runtime Source
`be1a8f3f822d23f3251d32e616fb0b2fe422714e`, including PR #499 and PR #500.
The canonical dispatch identity is `change_id=PRIZEIMAGE-20260924`,
`pr_number=500`, and that exact `source_sha`. The authority-update PR is not
the source PR. Starting protected main
`80776f36305fade6ae43eea6fe96db942890eda6` includes PR #501 release metadata;
neither it nor a later metadata merge becomes an approved Runtime Source.

This update changes only the approved-source manifest, regression tests, this
runbook, and the Worklog. Application Runtime code, migrations, ENV, and secrets
remain identical to the approved target. Exact source, ancestor, merged source
PR, reviewed-tree, current checks, native ARM64, and OCI revision constraints
remain enforced by the unchanged canonical workflow and authority helper.
The previous source and unapproved main commits remain rejected.

Immutable contract `2.0.0-alpha.39` is already published from the same Runtime
Source. The canonical release ledger records Artifact `10800178238` from Run
`35981484873`; fresh canonical readback verifies its manifest and all package
and Public OpenAPI digests. No contract is republished. Storefront handoff uses
the exact ledger values and the verified Artifact, without a repository change
in this task.

Required CI candidate builds are permitted. Post-merge readiness invokes the
canonical authorization and source scans without an additional build or any
Production connection. This update authorizes no activation, server operation,
service restart, traffic change, or database mutation. A future rollback of
source approval requires a new reviewed PR and explicit Human source authority.

## Approved Source Update — PRODAUTH-20260925

PR #503 implementation, OLD Test Technical verification, and Human Browser
acceptance approve Runtime Target `e3121034c5dc7b9184673b1be64bb5076e9d3a90`.
Its dispatch identity is `change_id=CATALOG-20260924`, `pr_number=503`.
The authority-only merge must not replace that Runtime Target with its own SHA.
Deployable Production Build, activation, and NEW server operations are not
authorized. Human permits only the mandatory CI verification-image builds.

Relative to the reported current Production source
`be1a8f3f822d23f3251d32e616fb0b2fe422714e`, the only application changes are
PR #503's Admin Catalog Read selection and Admin revision-conflict message.
Other changes are tests, docs, contract release metadata, and source approval.
Contact Reply Mail, Identity Mail, and SMS workers require no update for this
delta: worker code, dependencies, configuration, schemas, and Outbox contracts
are unchanged; the changed service belongs only to the Admin HTTP read path.

The existing immutable Contract bundle remains `2.0.0-alpha.39`, sourced from
`be1a8f3f822d23f3251d32e616fb0b2fe422714e`: Public API `2.0.0-alpha.35`,
Admin/Webhook `2.0.0-alpha.34`, Client/Testkit `2.0.0-alpha.39`, and Site Schema
`2.0.0-alpha.23`. Contract/package sources are byte-identical at the new Target;
the existing ledger and verified immutable Artifact remain its provenance.

For assessment on **2026-09-25**, both dependency-security and frontend ESLint
baselines require refresh. Their `AGENCYREADY-20260917` management authority
expires on `2026-09-24`; earlier CI PASS does not extend that expiry. This
metadata update does not refresh either baseline or establish Production build
readiness. Current path policy classifies source approval as Strict Change;
there is no separate Authority-only CI lane or permission to bypass its checks.

## Approved Source Update — PRODAUTH-20260925-A40

The latest Human acceptance fixes Runtime Target
`e4361ece51fc1249a5cfb2c64cf56d3aa4bb0c29`, including PR #506 representative
presentation/full-results implementation, PR #507 alpha.40 release metadata,
and PR #508 Public presentation asset URL correction. Dispatch identity is
`change_id=ASSETURL-20260925`, `pr_number=508`, and that exact `source_sha`.
The later authority merge is Workflow Authority only, never Runtime Source.

`e3121034c5dc7b9184673b1be64bb5076e9d3a90` is a Git comparison base supplied
by the Human, not an independently verified live Production revision. It is
an ancestor of the Target. The only application changes are three API service
paths under Catalog/Draw, all from #506/#508. PR #507 has no application change.
The other intervening commits are #504 source approval and #505 reviewed
Security/ESLint baselines. No unapproved application delta was found. The only
lockfile change is the exact workspace Client alpha.39-to-alpha.40 reference;
no external dependency, workflow, migration, ENV or runtime config changed.

Contract Artifact Source remains
`dadf79f3b0b2409a57e41b10a83c7b6570ea3507`, an ancestor of the Runtime Target.
OpenAPI, package sources and lockfile are byte-identical between those commits.
Canonical exact-ID readback of Artifact `10845475225`, Run `36089413205`,
matches the immutable alpha.40 release ledger, including outer, Manifest,
Client, Testkit, Public OpenAPI and SHA256SUMS digests. The release ledger is
the digest authority; do not replace its Source with the Runtime Target.

The current Security and ESLint authority is `BASELINE-20260925`, merged in
PR #505 at `032aa4acd4af3f87428145afa044252e534b5e07`, expiring `2026-10-02`.
It supersedes the expired readiness assessment in the preceding historical
update. No baseline refresh or manual expiry extension is needed on September 25.

Future activation scope for this delta is API only. Admin, Contact Reply,
Identity, SMS, Agency and Scheduler updates are not required. This authority
sync changes no application code and authorizes no deployable Production
Artifact Build, activation, new-server operation, DB mutation or ENV change.
Readiness is the canonical source authorization, current policy and source
scan evaluated read-only; it is not a successful Production Artifact Build.
Rollback of approval requires a reviewed metadata PR and Human source authority.

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

## Required Non-secret Domain Authority

Production readiness resolves `V2_PUBLIC_ORIGIN`, `V2_ADMIN_ORIGIN`,
`V2_AGENCY_ORIGIN`, and `V2_AGENCY_LOGIN_URL` from its process environment.
[The config](agency-production-config.json) declares these environment keys,
not expected domain values. Missing, empty, malformed or non-HTTPS values fail
closed; the three origins allow no path, query or fragment. The Agency login
URL must have the same origin as the Agency origin, including its effective port.

The canonical `platform-production-arm64-artifact.yml` dispatch requires
`public_origin`, `admin_origin`, `agency_origin`, and `agency_login_url` inputs
without defaults. Operators supply the intended non-secret values explicitly.
Changing domains requires new validation inputs, not a Platform source edit.
The Public API retains its relative same-origin `/api/v2/` path.

Each artifact includes `production-readiness.json` with the exact source SHA,
image references and all four effective domain values after offline checks
succeed. The immutable GitHub artifact digest covers this evidence alongside
the image archives and their existing manifest/checksums. PR ARM64 verification
uses explicitly supplied `.invalid` fixture origins; those artifacts are CI
verification only. Final Production candidates use the operator dispatch inputs.
Neither path changes deployed Runtime ENV or authorizes activation.

## Agency Runtime And Configuration Gate

- Existing target: `production` in `apps/agency/Dockerfile`; digest-pinned Node
  `22.22.3`, pnpm `10.12.1`, frozen dependencies, `@oripa/agency` Next.js standalone
  build, non-root `node`, internal port `3000`, `node server.js`.
- No site-specific public build argument is needed. Browser requests use the
  relative same-origin `/agency/api/v2` prefix. `V2_AGENCY_ORIGIN` supplies the
  intended public origin; no Test host port or upstream is prescribed.
- No dedicated Agency readiness endpoint or Docker healthcheck exists. `/login`
  can prove process/routing availability, not API, database or mail readiness.
- [Required non-secret config](agency-production-config.json) is an activation
  hard gate, not authorization to deploy. NEW Server must read back effective
  Laravel config after its existing config-cache procedure. In particular,
  `V2_AGENCY_LOGIN_URL` must be explicit and match the Agency origin.
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
