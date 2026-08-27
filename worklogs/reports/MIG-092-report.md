# MIG-092 fincode Preview External Callback / Origin Fix

## Task

- Issue `#395`, Branch `fix/MIG-092-fincode-preview-callback-origin-fix`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- `MIG-092` was unused in GitHub, refs, Task Policies, worktrees, and the Worklog before allocation. Base is `b1ff701d2b356860542fecd6749933f5817bd31a`.
- Stage 0 reproduced both OPS-021 blockers without Provider traffic or business mutation: external `POST /webhooks/v2/fincode` returned the Nginx 404 surface, and fincode return origins inherited Admin-scoped generic Application values.

## Implementation

- `FINCODE_PLATFORM_ORIGIN` and `FINCODE_STOREFRONT_ORIGIN` are explicit API Runtime inputs. fincode no longer derives Provider or Storefront return URLs from `APP_URL` or `FRONTEND_URL`; those generic values remain unchanged for their existing Admin/Auth/Mail responsibilities.
- Both origins are validated as authority-only HTTPS URLs outside local/testing, with no userinfo, path, query, or fragment. An origin matching `V2_ADMIN_ORIGIN` fails closed. Provider normal/failure paths, Storefront thanks/failure paths, `pid`, and Point Product ID contracts remain unchanged.
- The Preview-only Nginx canonicalizer exposes only exact `POST /webhooks/v2/fincode`, discovers the existing `/api/v2/` upstream, rejects broad webhook surfaces and non-Preview vhosts, preserves a root-only rollback copy, and updates the vhost atomically. Webhook signature verification and all MIG-079 Payment authority are unchanged.

## Verification

- Isolated PostgreSQL/Redis with the existing 66 migrations: `FincodePaymentBackendTest` 23 tests / 241 assertions PASS with zero warnings.
- Preview Nginx canonicalizer 4 tests PASS; Policy Unit 162 tests PASS; local Policy Gate PASS; Compose config, PHP syntax, and `git diff --check` PASS.
- Live pre-activation readback correctly fails with `fincode_webhook_location_missing`. This is evidence of the current blocker, not an acceptance PASS.

## Activation Status

- Application Head `42a70d8efde5b8818b1bcec59ecf2af82f862b6e` passed Required Checks: Policy `98434565899`, Quality `98434622536`, Security `98434621939`, Integration `98435563928`, and CI `98435588668`.
- The first Preview workflow was dispatched against the Task branch. Run `33047814811` succeeded, but its Artifact `9636426427` failed the canonical wrapper's `main`-ref authority and was not downloaded, loaded, or activated. No validation was bypassed. The exact same approved head was dispatched correctly against `main`; canonical Run `33048184569`, Artifact `9636581132`, outer digest `sha256:3e06b6a7f3bda5eb129c6dbcb6401b04a695b0fe5bbc5821d49461fa6067eec5`, and manifest digest `65769d60ca1257fd982418c80ff93d80eb2e4dbeceff5a5ac33f77b8bb31d38e` passed outer/inner verification and load.
- API was recreated once with `oripa-v2-api:preview-MIG-092-42a70d8efde5`, image `sha256:3188f9046e18ae33e4cff09cce4bebd3b700e037f707ee61173382496e121cbc`, then reattached to the unchanged API-only egress network. Admin was not recreated and remains on the OPS-020 image. PostgreSQL/Redis container identity, volumes, and private network are unchanged.
- Preview Nginx received one atomic exact-path update from the Repository-managed canonicalizer, `nginx -t` passed, and one reload completed. The Production vhost digest remains exactly `82beef1a07bf16084a5cee87c0d7ef6d507b258d30d9fd5dd421b5c1719078df`.

## Runtime Acceptance

- API container health returned app, database, Redis, and storage `ok`; Admin health passed; API/Admin/PostgreSQL/Redis restart counts are zero. Migration ledger remains 66 through `000066`.
- `POST https://test.luxe-pack.biz/webhooks/v2/fincode` now returns the existing API 401 Problem Details response, not the Nginx 404. The exact route is POST-only; unrelated `/webhooks/v2/line` remains 404.
- Both Preview Platform Return Handler paths reach the API. Missing and unknown `pid` values return HTTP 303 to the canonical Preview Storefront `/points` fallback. Focused tests preserve normal `/points/purchase/thanks?pid=...` and failure `/points/purchase/{PointProduct.id}?pid=...` generation.
- API Runtime metadata confirms both explicit origins are `https://test.luxe-pack.biz`, are distinct from the Admin origin, and the Admin comparison input is present. fincode Secret/Public keys, Webhook signature, and Payment enable remain absent; Secret value readback is zero.
- Payment and Payment Point Grant counts remain zero. Activation-window Nginx 500/502/504 count is zero. Root free remains `22,261,661,696` bytes (20.73 GiB), above the 20 GiB gate.
- Webhook external routing `GO`, Platform Return Handler origin `GO`, Storefront final origin `GO`, and OPS-021 restart from a new Stage 0 `GO`.

## Remaining Closeout

- Final evidence-only head checks, fresh fixed-head Strict self-review, readying, squash merge, branch/worktree cleanup, and main synchronization remain pending.
- Admin source/activation, Storefront, PostgreSQL/Redis mutation, migration, Production routing/environment, fincode credentials, Payment enable, Webhook Dashboard, Provider communication, Payment, and Coin changes are zero.
