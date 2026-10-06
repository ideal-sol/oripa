# Preview Image Build Pipeline

## Boundary

Platform API and Admin PR Preview images are built only by
`.github/workflows/preview-image-build.yml` on GitHub-hosted
`ubuntu-24.04` x64. The separate OLD Test merged-main path below uses
the same native runner and artifact verifier. The Preview host must never run `docker build`.

This workflow owns Preview image build only. It never builds, uploads, or
publishes the Storefront Contract Artifact; that post-merge responsibility is
isolated in `.github/workflows/storefront-contract-artifact-publish.yml`.

The workflow accepts an approved Task ID, an internal PR number, its exact
reviewed head SHA, and `image_mode`. The PR may be open or already merged; a
closed unmerged PR fails closed. This permits a Task to merge the canonical
workflow capability before dispatching it from `main` while retaining the exact
checked and reviewed source head. The default `normal` mode builds API and Admin.
Explicit `api-only` mode builds API and skips the Admin build entirely. Any
other mode fails before checkout or build. It also rejects an external PR, a
non-`main` base, a branch or title without the Task ID, and a changed head.

Only the workflow merged on `main` is canonical for Preview Activation. A run
from a task branch is rejected by the artifact read wrapper even when its source
head passed checks.

## Artifact

The one-day GitHub Artifact is named
`oripa-preview-images-<TASK_ID>-<FULL_HEAD_SHA>`. Normal mode contains:

- `oripa-v2-api-linux-amd64.docker.tar.zst`
- `oripa-v2-admin-linux-amd64.docker.tar.zst`
- `manifest.json`
- `SHA256SUMS`

API-only mode contains the same metadata and checksum files plus only the API
archive. An Admin-only artifact, reversed inventory, unknown file, or missing
API archive fails closed.

The manifest fixes the Task, PR, source commit, `linux/amd64` platform, image
references, image IDs, archive checksums, and OCI labels. The OCI revision is
the requested PR head SHA. New manifests record `architecture=amd64` explicitly.
The helper continues to accept the exact legacy v1 Preview schema only when its
platform and every image remain unambiguously `linux/amd64`; legacy metadata is
never reinterpreted as ARM64.

## Reviewed Tree Activation Authority

Preview images may use either a merge-first Build from exact protected `main`
or the canonical Reviewed Tree sequence:

`Final PR Head -> Required Checks PASS -> Fresh self-review PASS -> Build -> Squash Merge -> Head tree == Merge tree -> content diff 0`

For Reviewed Tree Authority, the exact final PR head is the Build input and OCI
revision. The image is not eligible for load, Migration, or Activation before
squash merge. After merge, record and compare the Final Head Tree SHA and Merge
Tree SHA, then prove a direct content diff is zero.

Required evidence is Final Head SHA/Tree SHA, Merge SHA/Tree SHA, tree equality,
content diff zero, GitHub Artifact digest, loaded image digest or image ID, and
OCI revision. Any head/base drift, stale review, failed check, digest or OCI
mismatch, tree mismatch, or non-zero content diff blocks load, Migration, and
Activation. Keep the prior Runtime unchanged.

## Host Import

Use the installed GitHub App read wrapper to list and download the exact
artifact. Preview image import is a privileged Runtime operation, so this path
uses a Task Policy even though Task Policies are not universal Change ceremony.
The wrapper validates that policy, the open or merged internal PR, successful
workflow identity, GitHub's outer artifact digest, safe ZIP members, and all
inner checksums and image metadata.

```bash
oripa-github-app-api preview-artifacts <TASK_ID> <PR_NUMBER> <HEAD_SHA>
oripa-github-app-api download-preview-artifact \
  <TASK_ID> <PR_NUMBER> <HEAD_SHA> <ARTIFACT_ID> \
  /var/lib/oripa-v2-evidence/<TASK_ID>/preview-image-artifacts/<HEAD_SHA>
python3 /var/www/oripa/scripts/ops/preview_image_artifact.py load \
  --directory /var/lib/oripa-v2-evidence/<TASK_ID>/preview-image-artifacts/<HEAD_SHA>/payload \
  --task-id <TASK_ID> --pr-number <PR_NUMBER> --source-sha <HEAD_SHA>
```

`load` first re-verifies the artifact, then rejects any machine or Docker host
that is not AMD64. It only invokes `docker image load`; it neither builds nor
removes an image.

## Preview Update

After load, create a repository-external Compose override containing the exact
loaded image references. Preserve the existing project, container, network,
environment, loopback-only published port, and restart policy. Do not add or
preserve a static `ipv4_address`: Host Nginx reaches the API through the stable
`127.0.0.1:8611` published endpoint, not a Docker-assigned container address.

```bash
docker compose -p mig061a-v2-preview \
  -f /var/www/oripa/docker-compose.v2.yml \
  -f <REPOSITORY_OUTSIDE_VERIFIED_IMAGE_OVERRIDE> \
  up -d --no-build --no-deps api admin
```

For an API-only artifact, the override contains only the API image and the
activation command targets only `api` with the same `--no-build --no-deps`
boundary. It must not recreate Admin. The existing API publication must remain
`127.0.0.1:8611:8000`; do not add a new host port or a `0.0.0.0` bind.

Record current and rollback image IDs before replacement. Do not remove either
image.

## API-only Activation Acceptance

Every API-only Activation must pass direct loopback plus both test and live
Storefront same-origin smoke before acceptance. These checks use the existing
TLS origins and do not require Browser, Provider, Payment, Coin, or Mail
activity.

- Direct `http://127.0.0.1:8611/api/health` returns HTTP 200.
- Direct `http://127.0.0.1:8611/api/v2/auth/session` returns HTTP 200.
- Direct `http://127.0.0.1:8611/api/v2/gachas?limit=1` returns HTTP 200.
- Direct `http://127.0.0.1:8611/api/v2/point-products` returns HTTP 200.
- Same-origin `https://test.luxe-pack.biz/api/v2/auth/session` returns HTTP 200.
- Same-origin `https://test.luxe-pack.biz/api/v2/gachas?limit=1` returns HTTP
  200.
- Same-origin `https://test.luxe-pack.biz/api/v2/point-products` returns HTTP
  200.
- Storefront `https://test.luxe-pack.biz/` and
  `https://test.luxe-pack.biz/points` return HTTP 200 without an unexpected
  redirect.
- Same-origin `https://luxe-pack.biz/api/v2/auth/session` returns a canonical
  non-5xx response.
- Same-origin `https://luxe-pack.biz/api/v2/gachas?limit=1` returns HTTP 200.
- Same-origin `https://luxe-pack.biz/api/v2/point-products` returns HTTP 200.
- Storefront `https://luxe-pack.biz/` and `https://luxe-pack.biz/points` return
  HTTP 200 without an unexpected redirect.
- A fresh post-activation Nginx log window contains zero HTTP 500, 502, and 504
  responses.

Also verify that API, Storefront, Admin, PostgreSQL, and Redis remain healthy or
active with restart count zero; `v2_private` remains `internal: true`; API
publishing remains loopback-only; and Admin, PostgreSQL, and Redis gain no host
port. API-only Activation is incomplete if any same-origin smoke is omitted.

## Target Architecture

OPS-005 confirms that the existing Preview host and Docker daemon are `x86_64`.
The canonical artifact target is therefore `linux/amd64`. The artifact helper
keeps `linux/amd64` as the default target; both Preview CI build selection and
the Preview host load guard consume that boundary. The helper also validates the
separate `linux/arm64` Production-candidate artifact kind, but Preview rejects
that kind and architecture. Cross-architecture loading remains fail closed.

## OLD Test Exact Merged-Main Payload

`.github/workflows/old-test-main-artifact.yml` is a separate AMD64
build control plane. It does not replace or relax the PR Preview head guard,
and does not change Production ARM64 behavior. It neither deploys nor migrates.

The reviewed `manifests/platform-old-test-approved-source.json` binds an
explicit Human-approved artifact Task, merged source PR, exact payload SHA,
`old-test` target, `linux/amd64`, and either `api-only` or `normal` (API/Admin).
Agency and Admin-only artifact inventories remain prohibited. The approved mode
must match both provenance and the verified ordered image inventory; a caller
cannot omit Admin from an approved normal artifact or add an unapproved image.
Future payload approvals require
a separately reviewed change; a caller cannot request an arbitrary main ancestor.

Build Control Authority is the current protected `main` workflow SHA. Application
Payload Authority is the manifest-approved 40-character SHA, which may be an older
ancestor. The shared CI/retrieval validator requires the source PR's squash SHA,
internal main base, reviewed/merged tree equality, trusted successful five Required
Checks on its reviewed head and control main, and protected-main ancestry. Unknown,
PR-only, unapproved, malformed, Production, and ARM64 inputs fail closed.
The workflow checks out control and payload separately, verifies exact checkout,
and builds only the payload directory. OCI revision is the payload SHA, never the
control SHA. The validator is `authorize_old_test_source` in the source-controlled
GitHub App artifact wrapper, also called by `old_test_source_authority.py` in CI.

Ancestor comparison uses the structured `api_compare` operation: only the fixed
Repository and two complete hexadecimal commit SHAs are accepted. The wrapper
internally constructs the SHA-to-SHA compare endpoint with a fixed query. Generic
raw-path reads still reject dot-dot, including repeatedly encoded traversal, and
cannot opt into comparison with a skip flag or caller-supplied URL. Transport-level
tests exercise both guards without substituting the higher-level `api_get` helper.

After the infrastructure PR and control-main checks pass, provision only the
reviewed `infrastructure/github-app/oripa-github-app-api` to the existing installed
wrapper path. Preserve its owner/mode and an external byte-exact rollback copy.
Verify source/runtime byte identity. Existing broker, authentication, and Secret
handling are unchanged. No global schema or unrelated wrapper operation changes.

```bash
oripa-github-app-api dispatch-old-test-artifact <TASK_ID> <SOURCE_PR> <PAYLOAD_SHA>
oripa-github-app-api old-test-artifacts <TASK_ID> <SOURCE_PR> <PAYLOAD_SHA>
oripa-github-app-api download-old-test-artifact \
  <TASK_ID> <SOURCE_PR> <PAYLOAD_SHA> <ARTIFACT_ID> \
  /var/lib/oripa-v2-evidence/<TASK_ID>/preview-image-artifacts/<PAYLOAD_SHA>
```

Dispatch uses the existing authenticated GitHub App transport, with fixed workflow
and `ref=main`, after root-owned Strict Task Policy and reviewed source approval
validation. It does not reuse a PR check operation. Retrieval repeats source
validation and requires the successful main-only workflow run at current control
SHA. Main drift requires revalidation, not a permissive fallback.

The GitHub artifact is `oripa-old-test-images-<TASK_ID>-<PAYLOAD_SHA>` and the image
is `oripa-v2-api:old-test-<TASK_ID>-<PAYLOAD_SHA_PREFIX12>`. Its manifest uses
`artifact_kind=old-test`, never a Production or PR Preview tag. `source-authority.json`
records source/reviewed tree, control SHA, Task, source PR, image mode, run ID and attempt.
The GitHub outer digest authenticates the exact provenance and archive file set;
inner checksums and Docker image ID/OCI labels are verified independently.

The retrieval wrapper extracts the existing standalone image verifier from the
validated control Git object, not the host's possibly older checkout or artifact
payload, and retains it as `verified-control-artifact-helper.py` beside `payload/`.
Use that exact verifier's `load` command with `--artifact-kind old-test`,
`--architecture amd64`, and the same Task/PR/payload identity. Import does not build.

Activation requires separate explicit Human OLD Test approval and an immediate
Task Activation phase after artifact verification. An API/Admin artifact does
not require activating both images. For SEC-20261006, retain exact payload
`4b7d00e8e31223136cd0b70134916d091dfea6cb` independently of the newer control main;
activate only the existing Admin with an external image-only Compose override
and `--no-build --no-deps admin`. Preserve its configuration, network and routes,
retain its prior image for rollback, and leave API, Agency, Worker, Scheduler,
Storefront and migrations unchanged. `activation_authorized` remains false;
the explicit Human task supplies the bounded OLD Test activation permission,
not Production permission. Stop after technical rich-text and layout acceptance;
focused Browser acceptance remains Human-only.

For separately approved API activation, only the existing 8611 `api`
and 8621 `test-api` services are eligible. Record current image IDs, ordered Compose
chain, ports, mounts, config-source paths and networks without exposing Secrets.
Append an external image-only override; preserve the chain and TEST credentials.
Start on the existing private network only, wait for healthy, then attach the
existing API egress network. Never change host routes, create networks, or start
with both networks attached. Keep the previous image and reverse the image override
with the same private-start/health/egress sequence if Technical verification fails.

OLD-only acceptance uses 8611/8621 loopback health plus the existing Test HTTPS
API/session endpoints and non-destructive webhook route inspection. Unlike the
broader historical Preview procedure above, this task does not touch Production.
Require the exact same payload revision/image ID on both APIs, zero restart loop,
no 502/unexpected 5xx, unchanged Storefront/Nginx/config, and preserved migration
ledger. Do not execute real Card DELETE, Payment, 3DS or Provider requests.
Global payment/card registration reconciliation and scheduler remain OFF.
Stop at Technical PASS; Browser Card Delete acceptance remains Human-only.
