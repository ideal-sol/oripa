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

- Canonical Preview image build, API-only activation, exact Preview Nginx activation, external reachability, final Runtime health, Required Checks, fixed-head Strict self-review, squash merge, and cleanup remain pending.
- Admin, Storefront, PostgreSQL, Redis, Docker network, Production routing/environment, fincode credentials, Payment enable, Webhook Dashboard, Provider communication, Payment, and Coin have not been changed.
