# STORE-SITE-034 Contact Browser-safe Mutation Boundary

## Task

- Platform Task ID: `STORE-SITE-034`
- Platform Issue: `#324`
- Storefront Task: `SITE-034`
- Branch: `feat/STORE-SITE-034-contact-browser-safe-client`
- Worktree: `/var/www/oripa-worktrees/STORE-SITE-034-contact-browser-safe-client`
- Base SHA: `09f6292306873821733b340ee432dea307219143`
- Risk: `R3`
- Phase: `B` artifact release
- Artifact Version: `2.0.0-alpha.24`

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
- After the first exact-head checks and self-review passed, SEC-012 moved `main`.
  The task branch was synchronised again through the governed two-parent base
  sync. The only conflict was the append-only Platform Worklog; both task
  sections were retained. The prior checks and self-review are superseded.
- GOV-016 merged to `main@09f6292306873821733b340ee432dea307219143`
  and established the canonical package-only alpha.24 release model. The clean
  task head was synchronized again by a conflict-free two-parent merge; its net
  change remains the same 14 approved STORE-SITE-034 paths.
- Laravel targeted Runtime tests: NOT RUN because `apps/api/vendor/autoload.php`
  is absent in this worktree; the read-only audit inspected existing focused
  Runtime tests and implementation.
- Browser／E2E: NOT RUN; Phase A uses deterministic Testkit Browser transport.
- GOV-016-based package source head
  `209252d9fcbad42090677f5a7bece52c5a5d3597` passed fresh Required 5 Checks.
  Exact-head Self-review `#issuecomment-5356175413` reported SEV-0／1／2／3 all
  zero. One preceding Quality run encountered a transient unrelated Admin
  lifecycle test failure; the unchanged exact head passed its focused 3 tests
  locally and the complete fresh Quality rerun.

## Contract and Runtime Impact

- Public OpenAPI semantics: none.
- Generated public types: none.
- Site Manifest Schema semantics／generated types／package identity: none.
- Runtime／middleware／controller: none.
- Database／migration: none created, none applied.
- Contact request／response／error semantics: unchanged.
- Storefront Repository: unchanged.

## Concurrency and Locks

MIG-063G changes Admin Catalog UI paths only. It does not change Public OpenAPI,
generated contracts, Storefront Client, Site Schema, Testkit, Artifact inputs,
or Contact／CSRF／Browser transport sources.

Platform Integration Lock remains held by `STORE-SITE-034` through merge and
cleanup. Artifact Release Lock was acquired second only after Required 5 and
Fresh Self-review passed, then released after immutable Artifact readback and
consumer validation. Preview Deployment Lock was never acquired. Runtime
activation and Preview Deployment remain out of scope.

## Artifact Release Model

- GOV-016 fixes bundle version `2.0.0-alpha.24` as the exact next immutable
  package-only release after alpha.23.
- Published packages are Storefront Client alpha.24 and Storefront Testkit
  alpha.24. Site Schema alpha.23 is referenced by immutable digest／source tree
  and is neither repacked nor included.
- Platform／Application and Public／Admin／Webhook OpenAPI remain alpha.23. The
  Client minimum Public API Contract remains alpha.23 and Testkit resolves
  Client alpha.24 plus Site Schema alpha.23.
- `storefront_contract_artifact.py validate-source` passes after latest-main
  synchronization.

## Artifact Evidence

- Canonical Workflow Run: `32371450527`; Artifact ID: `9407435514`; source:
  `209252d9fcbad42090677f5a7bece52c5a5d3597`.
- Bundle: `2.0.0-alpha.24`; Manifest SHA-256:
  `f71edc9e1c9e9215381d01b00ca066ff8bd2678e8cad92d28fce5981145aad94`.
- GitHub／downloaded outer SHA-256:
  `70b285f22cfdd1bf3cafbdc5f0e11a0fd0ada22a1926947d6f8468ab6639464b`.
- Storefront Client alpha.24 SHA-256:
  `fbe156fbbc9f27a07e4017cc9bea3a9cdcd71aa2943e03fb48236bb48bbda259`.
- Storefront Testkit alpha.24 SHA-256:
  `3dc1c3488342846580a2a75372f5d9fff8a510b29d1fad2db468e7276b9efc78`.
- Referenced Site Schema alpha.23 SHA-256:
  `b4ca0ddb0ec8a6f4bda6dfec40fb5f3f5098a837160310be64de97cab36740c2`;
  it is absent from the alpha.24 payload and was not repacked.
- Referenced Public OpenAPI alpha.23 SHA-256:
  `5c735fe26514d5bfb47b3515ead108bf473fd5e1f81e0936b7e1986290904043`.
- `SHA256SUMS`, actual payload digests, Manifest identities, exact five-file
  inventory, GitHub outer digest, zip／package archive safety, Platform／
  Application alpha.23 references, and local canonical verifier: PASS.
- Frozen offline consumer install resolves Client／Testkit alpha.24 and both
  Testkit transitive dependencies to Client alpha.24／Site Schema alpha.23. It
  imports `createBrowserStorefrontContentContactClient`, Contact fixtures, and
  the Site Schema API successfully.
- The canonical workflow also generated Preview image build artifacts as part
  of its fixed workflow, but no image was downloaded, loaded, deployed, or
  activated by this Task.
