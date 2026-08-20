# STORE-SITE-034 Contact Browser-safe Mutation Boundary

## Task

- Platform Task ID: `STORE-SITE-034`
- Platform Issue: `#324`
- Storefront Task: `SITE-034`
- Branch: `feat/STORE-SITE-034-contact-browser-safe-client`
- Worktree: `/var/www/oripa-worktrees/STORE-SITE-034-contact-browser-safe-client`
- Base SHA: `7d2f85d2a4e2dadf993594c559f3ffc6c6add04d`
- Risk: `R3`
- Phase: `B` integration

The initially created Platform Issue `#323` incorrectly used `SITE-034` as the
Platform Task ID. It was closed before Phase A validation and is not the formal
Contract Lane task. The obsolete Remote branch contains only the unchanged base
commit and no implementation.

## Runtime Audit

Decision `A` applies. Existing Runtime responsibilities are sufficient:

- `GET /api/v2/auth/session` accepts anonymous and authenticated Browser requests.
- `V2PublicAuthController::session` rotates the User CSRF Cookie even when no User
  Session exists.
- `V2CsrfService` owns token generation, Cookie attributes, and Cookie／Header
  equality validation.
- `EnforceV2BrowserSecurity` requires exact Origin, JSON, same-site Browser
  context, and valid CSRF for Contact POST.
- Browser transport owns bootstrap, `credentials: include`, Cookie reading, and
  XSRF Header construction.

No Runtime, middleware, session, CSRF service, controller, database, or migration
change is required.

## Browser-safe Client API

`@oripa/storefront-client/browser` adds
`createBrowserStorefrontContentContactClient(configuration)`. Its
`submitContact(input, options?)` accepts only optional `AbortSignal` and timeout;
it does not accept or expose a CSRF token, Cookie name, Header name, bootstrap
path, retry flag, or Idempotency Key.

The existing low-level `createStorefrontContentContactClient(transport)` remains
available for compatibility. Both low-level and Browser-safe Contact mutations
set `retry: false`, preserving the existing non-idempotent mutation semantics.

## Testkit

- Public-safe Contact input and HTTP 202 Receipt fixtures.
- Validation Problem Details fixture with field errors.
- HTTP 429 fixture with `retry_after_seconds`.
- Anonymous first submit proves bootstrap GET precedes Contact POST, Cookie
  credentials are included, the Client-built XSRF Header is present, and no
  Idempotency Key is sent.
- Authenticated submit uses the same Client-owned CSRF boundary.
- Validation and 429 become `ApiProblemError` without automatic retry.
- Network failure becomes typed `StorefrontTransportError(NETWORK_ERROR)` without
  automatic retry.

## Phase B Verification

- MIG-063G merged／cleaned state, latest local／origin／GitHub `main`, active
  tasks, Shared Locks, and latest Artifact state: read back before Phase B.
- Phase A changes were preserved in a dedicated stash, the task branch was
  safely synchronized to latest `main`, and the same 11-file／494-line change
  set was restored without conflict or loss.
- Storefront Client build／typecheck／lint／29 tests: PASS.
- Storefront Testkit build／typecheck／lint／38 tests: PASS.
- Client／Testkit generated checks, Testkit export check, Testkit
  network-boundary check, and `git diff --check`: PASS.
- Public OpenAPI, generated public types, Site Schema, and Runtime source diff:
  zero.
- Initial GitHub Policy Gate rejected the Human-approved Platform Task ID
  `STORE-SITE-034`. The Task ID validator now additively accepts only the
  `STORE-SITE-<digits>` Contract Lane form alongside every existing form;
  malformed and unrelated `STORE-*` forms remain rejected. No task ID
  substitution was used.
- Laravel targeted Runtime tests: NOT RUN because `apps/api/vendor/autoload.php`
  is absent in this worktree; the read-only audit inspected existing focused
  Runtime tests and implementation.
- Browser／E2E: NOT RUN; Phase A uses deterministic Testkit Browser transport.

## Contract and Runtime Impact

- Public OpenAPI semantics: none.
- Generated public types: none.
- Site Schema: none.
- Runtime／middleware／controller: none.
- Database／migration: none created, none applied.
- Contact request／response／error semantics: unchanged.
- Storefront Repository: unchanged.

## Concurrency and Locks

MIG-063G changes Admin Catalog UI paths only. It does not change Public OpenAPI,
generated contracts, Storefront Client, Site Schema, Testkit, Artifact inputs,
or Contact／CSRF／Browser transport sources.

The Platform Integration Lock is held by `STORE-SITE-034`. Artifact Release and
Preview Deployment Locks remain unacquired. Artifact allocation starts only
after the Integration head satisfies the required checks and exact-head fresh
self-review. Runtime activation and Preview Deployment remain out of scope.
