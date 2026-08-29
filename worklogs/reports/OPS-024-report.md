# OPS-024 Production Storefront Same-Origin API Upstream Stabilization

## Task

- Issue `#414`, Draft Pull Request `#415`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base/local/origin/GitHub main at Task start: `efc64e24fbbb01cab10f1f2953c6b7ee49d90484`.
- Task Policy: `/etc/ideal-sol/github-app/task-policies/OPS-024.json`, SHA-256 `1c309dc63b4f6a7981ee140768928bbcb667e31e3cd804f0ee9d300938e90585`, ten exact allowed Repository paths.

## Stage 0

- Host systemd Nginx is active with restart count zero. `/etc/nginx/conf.d/luxe-pack.biz.conf` has SHA-256 `82beef1a07bf16084a5cee87c0d7ef6d507b258d30d9fd5dd421b5c1719078df` and sends exact `/api/v2` and prefix `/api/v2/` traffic to `http://192.168.61.10:8000`.
- The OPS-023 test vhost remains canonical at SHA-256 `f726e156b214f15ce5e4857eb33e172105726babe9dafd3dfa82465d783899e6`, with its API and exact fincode webhook routes on stable loopback.
- Active API source is `e006c7a95592e5a03eef0be7e59bfa995185171c`, image `oripa-v2-api:preview-MIG-097-e006c7a95592`, image ID `sha256:e782e21228fb59e01f518213da48352750e7bf36b97a6c135fa75517980fadba`, published only as `127.0.0.1:8611:8000`.
- Active Storefront source is `0a8bcdf09e1d0549dac7d1736b9cb868eb04f0e9`, Build ID `hjgAN8D6Bf2kOE71jmmh6`, with exact `@oripa/storefront-client 2.0.0-alpha.30` pin.
- Direct health, auth session, gachas, and point-products returned HTTP 200. All corresponding test-domain requests returned HTTP 200; all corresponding live-domain requests returned HTTP 502 without redirect.
- API, Admin, PostgreSQL, and Redis were healthy with restart count zero; Storefront and Nginx were active with restart count zero. `v2_private` remained internal, API bind remained loopback-only, Admin/PostgreSQL/Redis host-port additions were zero, and root free was `27,283,791,872` bytes.
- No open human Issue/PR, task branch/worktree, or held task lock conflicted. OPS-024 was unused before Issue creation. Fifteen open Dependabot PRs were unrelated.

## Root Cause

The live Storefront vhost retained an obsolete Docker-assigned API address. Host Nginx failed at TCP connection before Laravel dispatch, so API application logs received no live-domain request. Cloudflare and forced-origin requests reproduced the same failure, while direct loopback and the OPS-023 test vhost succeeded. SITE-047 did not change API transport and the failure predates that activation. OPS-023 managed only `test.luxe-pack.biz`, leaving the live vhost outside its canonical source and policy guard.

## Canonical Source Change

- `scripts/ops/preview_fincode_nginx.py` keeps the existing preview default while adding an explicit `luxe-pack.biz` server profile. The test profile owns exact API, API prefix, and exact fincode webhook routes; the live profile owns only exact API and API prefix routes. Both use `http://127.0.0.1:8611`.
- The helper changes only managed `proxy_pass` lines and requires the existing Host, X-Real-IP, X-Forwarded-For, X-Forwarded-Proto, and path contract. It leaves body size, request method/body, Cookie, CSRF, Origin/Referer, Sec-Fetch-Site, TLS, Storefront root proxy, Admin isolation, and non-target server blocks unchanged.
- RFC1918 container upstream verification fails closed. Backup remains byte-identical mode `0600`, replacement is atomic, invalid input does not overwrite active config, and config-test failure restores without reload.
- The API-only Activation runbook now requires direct plus test and live same-origin smoke. The new live-vhost runbook fixes merged-main authority, exact input/backup paths, guarded activation, acceptance, security, and rollback boundaries.
- Focused Policy Gate changes require the two exact managed server profiles, stable upstream, live runbook, and test/live acceptance; focused negative tests reject fixed-IP and missing-live-smoke regressions. Existing gates are not weakened.

## Source Verification

- Canonicalizer: 12 tests PASS.
- Preview image pipeline/runbooks: 26 tests PASS.
- Policy Unit: 174 tests PASS.
- Active test vhost read-only verify: canonical PASS.
- Active live vhost read-only verify: expected fail-closed `container_specific_upstream_rejected` before Runtime mutation.
- `git diff --check`: PASS.
- Local Policy Gate PASS for 1,599 tracked files. Its first invocation failed only because the new allowed runbook was necessarily untracked before the first commit; the same gate passed from committed source. Required five checks and fresh exact-head Strict self-review remain pending.

## Runtime Boundary

Runtime mutation is prohibited until squash merge and exact main synchronization. The merged script must confirm the unchanged Stage 0 vhost digest, create `/var/lib/oripa-v2-evidence/OPS-024/luxe-pack-vhost.before.conf`, apply atomically, pass `nginx -t`, reload once, and retain rollback evidence. No task-branch source may modify Runtime.

API, Storefront, and Admin Build count, container recreate, service restart, Nginx config apply/reload, Migration created/applied, Artifact publication, DB mutation, Provider/Payment/Coin/Mail mutation, Production credential/secret mutation, TLS/Cloudflare/support/Admin change, and secret value readback are all zero before merge.

## Browser Handoff

Browser Acceptance remains HOLD until merged-main Runtime acceptance proves live API JSON, Storefront pages, fresh logs, headers/Cookie/CSRF contract, service health, binds, and restart counts. Codex will not execute a new Payment.
