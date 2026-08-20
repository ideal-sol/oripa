# OPS-012 SEC-012 Runtime Activation

## Task

- Issue: `#328`
- Risk: `R4`
- Base SHA: `ee5299633a7325fd91198537ac3bd429233293fb`
- Branch: `chore/OPS-012-sec-012-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-012`
- Task Policy SHA-256: `d93274b7425dd3caefdbb92632320d8cc6647406589b8f13fff96bb2e7b1745c`

## Scope

- Build a canonical GitHub-hosted `linux/amd64` Preview API image while this OPS PR remains open and fixed to an exact head.
- Prove the image Application tree equals SEC-012 merged `main`, then activate only the existing non-Production V2 Preview API service.
- Preserve the OPS-010/OPS-011 internal private network and guarded API-only egress boundary.
- Do not change Application code or execute any real-user Session logout.

## Preflight

- Clean local `main`, `origin/main`, task base, Remote `main`, and SEC-012 squash commit all equal `ee5299633a7325fd91198537ac3bd429233293fb`.
- SEC-012 merged `apps/api` tree is `5636f9d8353402897b57a7f0731d0b91ccb251fe`.
- Active API remains OPS-011 image `oripa-v2-api:preview-OPS-011-0d8697294449`, image ID `sha256:d075d82d5649a010f0c056d39067830de2e8a734b89d3adc2a6212165c22a28a`, OCI revision `0d86972944491bdd3e9716787381e439848d606f`, and Docker health is healthy.
- `mig061a-v2-preview_v2_private` remains `internal: true` with API, Admin, PostgreSQL, and Redis. The non-internal `192.168.62.0/28` egress network contains only API; Admin, PostgreSQL, and Redis remain private-only.
- API health, PostgreSQL, and external Public Session returned PASS/200. Direct unauthenticated Redis probe correctly required authentication; the authenticated Application probe remains required before activation.
- OPS-012 acquired only the Preview Deployment Lock. Migration Allocation, Platform Integration, and Artifact Release Locks were not acquired.

## Planned Activation Gate

- Create an open Draft PR from the OPS-only evidence head and pass all five required checks.
- Dispatch the canonical Preview Image Build using trusted `main` as workflow control ref and the open PR exact head as source.
- Download and load only the verified artifact through the canonical wrapper, then prove its API tree equals SEC-012 merged `main`.
- Record image evidence on a fixed activation head, pass required checks and fresh self-review, then recreate only API with `--no-build --no-deps` and guarded private-first egress attachment.
- Perform only read-only health, source/config, database, Redis, DNS/HTTPS, Public API, and error-window checks.

## Current State

- Issue, branch, dedicated worktree, Task Policy, and initial evidence exist.
- Preview image build, activation gate, Runtime activation, verification, closeout checks, merge, Issue closure, and cleanup are pending.
- Migrations created/applied: `0 / 0`.
- Application, API/OpenAPI, database schema, auth semantics, Point, Payment, Draw, inventory, Admin, Contract, Artifact, Storefront, MIG-063F/MIG-063G Runtime, Task ⑤, and Production changes: `0`.

## Rollback

- Retain the OPS-011 API image. Rollback is API-only recreate with that image followed by the same guarded private-first/API-only egress attach; no database or Redis operation is required.
