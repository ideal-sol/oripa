# OPS-023 Shared Preview Storefront API Upstream Stabilization

## Task

- Issue `#412`, Draft Pull Request `#413`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base/local/origin/GitHub main at Task start: `8e5277110097a2e3b567392a035a23026aafce18`.
- Task Policy: `/etc/ideal-sol/github-app/task-policies/OPS-023.json`, SHA-256 `068386fd56d4eb4e3719b2b28cb07c78206d5d8a92ca7319c65119215bdde052`, ten exact allowed Repository paths.

## Stage 0

- Host Nginx is the active topology. `/etc/nginx/conf.d/test.luxe-pack.biz.conf` had SHA-256 `68fb67457d310a4210ef926ebe891a1a38eb0d32c23ff67bb522b0641c670a6b` and sent exact `/api/v2`, prefix `/api/v2/`, and exact fincode webhook traffic to `http://192.168.61.10:8000`.
- Active API source is `e006c7a95592e5a03eef0be7e59bfa995185171c`, image `oripa-v2-api:preview-MIG-097-e006c7a95592`, image ID `sha256:e782e21228fb59e01f518213da48352750e7bf36b97a6c135fa75517980fadba`; its loopback publication is only `127.0.0.1:8611:8000`.
- Direct health, auth session, gachas, and point-products returned HTTP 200. The corresponding Storefront same-origin API requests returned HTTP 502, while `/` and `/points` returned HTTP 200 without redirect.
- API, Admin, PostgreSQL, and Redis were healthy with restart count zero; Storefront and Host Nginx were active with restart count zero. `v2_private` remained internal, DB/Redis had no host ports, and root free was `27,402,104,832` bytes.
- Active Storefront source is `bddff7106a8e710859a94cc07ada9a93b18aa136`, Build ID `vEpuPZEWLUWtXU7dZ2lE6`. Immediate API rollback image is `oripa-v2-api:preview-MIG-096-3f6f918e2d85`.

## Root Cause

The base Compose definition already provides the stable loopback API publication and does not assign a static private address. MIG-097 API-only Activation composed an image-only override and recreated API without updating Nginx or running same-origin smoke, so Docker assigned a different container address while Host Nginx retained the historical fixed address. The repository runbook incorrectly required preservation of that fixed IP, and the Nginx canonicalizer mirrored the existing API upstream into the webhook route instead of owning a stable endpoint.

## Canonical Source Change

- `scripts/ops/preview_fincode_nginx.py` now owns `http://127.0.0.1:8611` for exact API, API prefix, and exact fincode webhook routes. It changes only `proxy_pass`, requires the current Host/X-Forwarded contract and path semantics, and verifies RFC1918 container upstreams fail closed.
- The helper writes a mode `0600` byte-identical backup before atomic replacement. Its guarded activation restores on config-test failure and invokes reload only after `/usr/sbin/nginx -t` passes.
- Preview callback and image-build runbooks preserve the loopback-only publication, remove fixed-container-IP dependency, prohibit static `ipv4_address`, and require direct plus Storefront same-origin smoke after every API-only Activation.
- Focused Policy Gate guards reject a non-canonical Preview upstream and removal of same-origin acceptance. Existing gate architecture and unrelated assertions are unchanged.
- Changed Repository paths are exactly the ten Task Policy paths. Storefront, Admin, API domain, Payment, SITE-047, OpenAPI, Client, Testkit, Compose, Docker network, migration, and artifact source are unchanged.

## Source Verification

- Preview Nginx canonicalizer: 11 tests PASS.
- Preview image pipeline and runbook: 25 tests PASS.
- Policy Unit: 171 tests PASS.
- Local Policy Gate: PASS for 1,596 tracked files.
- Python compile and `git diff --check`: PASS.
- The initial no-argument Policy Gate invocation returned its usage error before validation; the required `--repository .` invocation then passed. This was not a test or product failure.
- Required five checks and fresh exact-head Strict self-review remain pending until the final evidence head is pushed.

## Runtime Boundary

Runtime mutation is prohibited until squash merge and exact main synchronization. The merged script must back up the unchanged active vhost to `/var/lib/oripa-v2-evidence/OPS-023/test-vhost.before.conf`, test the config, reload once, and retain rollback evidence. Final Runtime acceptance belongs to Issue `#412` and `/var/lib/oripa-v2-evidence/OPS-023/` because it necessarily occurs after this single Task PR merges.

API, Storefront, and Admin Build count, container recreate, Nginx config apply/reload, Migration created/applied, Artifact publication, DB mutation, Provider/Payment/Coin/Mail mutation, Production mutation, and secret value readback are all zero before merge.

## Admin API Remaining Blocker

Read-only Stage 0 confirmed the Admin API proxy uses a separate vhost and separate runtime config, so the permitted test vhost change cannot naturally repair its HTTP 502. OPS-023 does not mutate that owner/path. A separate Strict OPS Task is required to stabilize the Admin vhost upstream.

## Source Editing Safety

The first canonicalizer patch was accidentally applied to the main worktree. It was detected before commit, push, Runtime activity, or any second file; the exact diff was moved to the dedicated OPS-023 worktree with `apply_patch`, and the main file bytes and executable mode were restored to HEAD immediately. Main remained on the same SHA and clean thereafter.
