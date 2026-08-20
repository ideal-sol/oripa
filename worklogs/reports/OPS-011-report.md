# OPS-011 MIG-066 Runtime Activation

## Task

- Issue: `#319`
- PR: `#320`
- Risk: `R4`
- Base SHA: `f79f1301300b3f518a2ab983010e9efb197a781d`
- Branch: `chore/OPS-011-mig-066-runtime-activation`
- Worktree: `/var/www/oripa-worktrees/OPS-011`
- Task Policy SHA-256: `68ee1d9a9abc0c58e66317126d895b21a2f757c5cc16af30ae58092e46488079`

## Scope

- Build a canonical GitHub-hosted `linux/amd64` Preview API image while this OPS PR is open and fixed to an exact head.
- Prove the image Application tree equals merged MIG-066 `main`, then activate only the existing non-Production V2 Preview API service.
- Preserve the OPS-010 internal private network and guarded API-only egress boundary.
- Do not change Application code or execute real recipient email, registration, Resend, or Verification Complete.

## Preflight

- Clean local `main`, `origin/main`, task base, and Remote `main` all equal `f79f1301300b3f518a2ab983010e9efb197a781d`, the MIG-066 squash commit.
- Merged MIG-066 `apps/api` tree is `8e159ce7db4b4545ff14f7751979ef20f42ec575`.
- Active API remains OPS-010 image `oripa-v2-api:preview-OPS-010-238eacbce382`, image ID `sha256:14de371be8eeba12d5608a50cb0fbe81e31413a7392386d6b8f4db7747b45d18`, OCI revision `238eacbce382f4daa4464a70790b63eb6a1ad84a`, and Docker health is healthy.
- `mig061a-v2-preview_v2_private` remains `internal: true` with API, Admin, PostgreSQL, and Redis. The non-internal `192.168.62.0/28` egress network contains only API; Admin, PostgreSQL, and Redis remain private-only.
- Source contains the exact Email Verification allowlist `["/", "/mypage"]`, MIG-065 Browser `303 See Other` implementation, and the `V2MailEmailVerificationNotifier` binding.
- Shared locks were idle. OPS-011 acquired only the Preview Deployment Lock; Migration Allocation, Platform Integration, and Artifact Release Locks were not acquired.

## Planned activation gate

- Create an open Draft PR from this OPS-only head and pass all five required checks.
- Dispatch the canonical Preview Image Build using trusted `main` as workflow control ref and the open PR exact head as source.
- Download and load only the verified artifact through the canonical wrapper, then prove its API tree equals merged MIG-066 `main`.
- Record image evidence on a fixed activation head, pass the required checks and fresh self-review, then recreate only API with `--no-build --no-deps` and guarded private-first egress attachment.
- Perform only read-only health, source/config, database, Redis, DNS/HTTPS, Public API, and error-window checks.

## Current state

- Preview image build, activation, Runtime verification, Required Checks, self-review, merge, Issue closure, and cleanup are not yet complete.
- Migrations created/applied: `0 / 0`.
- Application, API/OpenAPI, database schema, auth semantics, Point, Payment, Draw, inventory, Admin, Contract, Artifact, Storefront, and Production changes: `0`.
