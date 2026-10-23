# SHIPPINGMIN-20261023 — Source / Site handoff

Human specification: shipping minimum points v1.0, 2026-10-23. Risk R3;
Strict Change; Application Runtime Activation deferred. Base:
`799eab891e0b428764de60847c1ced877613348c`.

## Authority and allocation

This Change is authorized through a Draft PR only. Ready, merge, auto-merge,
operational migration, package/tag publication, Site pin changes and Runtime
activation are prohibited without separate Human authority. Existing OLD Test
and NEW Production DB/ENV/containers/workers/providers are outside scope.

Dedicated branch `feat/SHIPPINGMIN-20261023`; worktree
`/var/www/oripa-task-worktrees/SHIPPINGMIN-20261023`. Existing dirty checkout is
preserved. Migration Allocation Lock `/run/lock/oripa-v2-migration-allocation.lock`
was acquired before checking 77 refs, registered worktrees, task policy
reservations and open PRs. Latest existing allocation was 000097; 000098 was
unused and reserved for
`2026_10_23_000098_add_v2_shipping_minimum_points.php`. Retain this logical
reservation at Draft handoff. Do not reuse it.

## API and behavior

- GET `/api/v2/me/shipping-policy`: existing User Session and SMS verification;
  private/no-store; returns `minimum_points`, `effective_required_points`,
  `revision`, `zero_total_allowed: false`.
- POST `/api/v2/me/shipping-requests`: existing body and successful response
  remain compatible. After successful-key replay and existing eligibility/hold
  checks, sum locked `user_prizes.exchange_point_snapshot`. Require total > 0
  and total >= minimum. Individual `allowed_actions.shipping` remains candidate
  eligibility and does not apply the batch threshold.
- HTTP 409 `SHIPPING_MINIMUM_POINTS_NOT_MET`: decimal strings `minimum_points`,
  `effective_required_points`, `selected_total_points`, `shortfall_points`, plus
  numeric safe-integer `setting_revision`. Zero total is refused even at a zero
  configured minimum; effective required points is then `"1"`.
- GET/PUT `/admin/api/v2/settings/shipping-minimum-points`: read permission
  `shipping.settings.read` for Owner/Admin/Operator; write permission
  `shipping.settings.manage` for Owner/Admin. PUT accepts a canonical decimal
  string `minimum_points` (0..9007199254740991), integer `expected_revision`,
  Idempotency-Key and existing Admin CSRF/session requirements. Existing ordinary
  settings authorization does not add a Fresh MFA requirement.
- Invalid setting input/key: 422. Stale revision: 409
  `SHIPPING_SETTING_REVISION_CONFLICT`. Missing/corrupt configuration: 503
  `SHIPPING_POLICY_UNAVAILABLE`, never an implicit zero. No-op save preserves
  revision/updater/time and emits no settings audit event. A change records
  `shipping.settings.updated`; replay returns the original response.

All gacha types, ranks, acquired dates and shipping-only prizes share one rule.
Different gachas and zero-point items may be combined when the total satisfies
that rule. No Wallet, point ledger, exchange behavior, inventory or rank-point
calculation is changed. Creation-time zero-point prevention is outside scope.

## Transactions and recovery

Financial Admission and V2PointTransactionRunner remain unchanged. Lock order:
admission → idempotency → User → Wallet → ordered User Prizes → shared settings
row. Admin update takes idempotency → exclusive settings row and does not lock
User/Wallet/prizes. A waiting request therefore uses the committed setting
revision, while a request holding the shared lock retains that revision through
commit. Failure rolls back idempotency and leaves shipment/history/mail/outbox
and financial balances unchanged.

Each new shipment persists selected total, applied minimum and revision.
Existing rows retain NULL snapshots; no historical values are invented. All
three values must be NULL together or valid together; snapshot updates are
rejected. New singleton settings initialize to 0pt/revision1. Existing migrations
are immutable. Recovery is forward-only: retain settings and historical
snapshots, deploy a reviewed compatible fix. Do not run migrate:rollback or
reconstruct historical applied settings. Restoring the pre-feature application
may bypass the new rule and therefore requires a separate operational decision.

PHP validates decimal bounds before integer casts and uses integer addition.
100 × 9007199254740991 = 900719925474099100 fits signed 64-bit PHP/PostgreSQL.
New point values cross the API as strings; revision is bounded to a JS-safe
integer. There is no float calculation.

## Site Codex integration (separate Site Change)

The Platform does not modify `ideal-sol/luxe-pack-storefront`. Consume the new
`getShippingPolicy()` method from the pending Client Source. The package version
remains alpha.46 **pending**, not a published artifact. Existing alpha.45,
immutable history and approved Runtime/Source manifests are preserved. Candidate
bundle hash and operation inventory (84) follow this additive source change;
no package publication or Site package pin update is authorized here.

1. Fetch policy in the authenticated SMS-verified shipping flow. Display current
   required points, selected sum and shortfall; preserve address and SMS flows.
2. Convert each existing safe integer `exchange_points` with `BigInt` before
   addition; parse policy strings directly with `BigInt`. Never sum with Number
   or convert the sum back to Number. Format via BigInt.toLocaleString.
3. Disable confirmation when sum < effective required points, including an
   all-zero selection. Do not individually disable low-value or zero-value
   candidates. Server validation remains authoritative.
4. Use `isShippingMinimumPointsProblemError` for validated typed details. Display
   the safe server shortfall, refetch policy and recalculate after 409; retain
   selected prizes/address. Handle other/unknown errors with the existing safe
   fallback. No automatic retry loop for business rejection.
5. Keep the same Idempotency-Key and payload across uncertain network failures.
   A successful request remains replayable after policy changes. A new selection
   is a new payload and needs a new key. Keep existing CSRF handling.
6. Testkit exports `SHIPPING_POLICY_FIXTURES` and
   `SHIPPING_MINIMUM_PROBLEM_FIXTURE`, including zero minimum and maximum values.
   Test zero-only, mixed zero/positive, exact threshold, insufficiency, policy
   changes, lost response/replay and mobile display.

Formal pinning requires separately published and verified artifacts in a Site
Change; this Source handoff cannot authorize it.

## Verification and OLD Test dependency

Exact final HEAD/Tree, actual check outcomes, Fresh Self-review and outstanding
items are recorded in the Draft PR. Local evidence is task-owned under
`/var/lib/oripa-v2-evidence/SHIPPINGMIN-20261023`. Unexecuted tests must remain
NOT RUN; infrastructure failures must not be reported as PASS.

After a separate Human Source Merge GO, the OLD Test batch integration owner
must receive the actual merge SHA, fresh Source/CI review, approved artifacts,
forward migration/backup evidence and a compatible separately published Site
client. Migration098, application rollout, Site pin rollout and feature
activation are separate gates. This Change performs none of those operational
steps and provides no fabricated merge SHA.


## Draft handoff verification hold (2026-10-23)

Draft PR: https://github.com/ideal-sol/oripa/pull/608 . Keep Draft and the
000098 reservation. Source implementation is committed; acceptance is HOLD.
Client generation/typecheck/lint/build and 41 tests passed. Testkit
 typecheck/lint/build and 54 tests passed after updating the exact operation and
export inventories. DB runner offline regression passed 89 tests; this is not
live PostgreSQL acceptance.

The isolated DB smoke attempt did not reach migration or tests: the composed
API/Admin image build remained incomplete under shared-host resource pressure
and was stopped after approximately 25 minutes. Backend vertical slice,
concurrency, rank activation/Financial Admission regression, live constraints,
fresh migration, schema/ledger restore and forward-only recovery are NOT RUN.
No operational database was used as a substitute.

Admin Unit attempts failed before test execution (worker startup timeout).
The browser attempt failed while the page still showed session loading; the
development server also logged a React development/CSP eval warning. This is
FAIL, not UI acceptance. Do not relax the CSP, assertions, timeouts or CI gate.
Repeat using the approved production verification build in a sufficient
isolated environment, including Owner/Admin/Operator, network retry/OCC,
confirmation, mobile overflow, keyboard and unauthorized/error states.
The final PR records Admin typecheck/lint/build completion separately.

Latest Human steering assigns login-required browser acceptance to Human and
explicitly removes it from Codex execution. No more login-browser runs are
required from this task. PR UI Verification is NOT_APPLICABLE to Codex under
that assignment; this does not claim browser PASS. Human checks the settings
screen and role/save behavior separately. Keep the attempted failure above as
evidence. CI gate code, CSP and assertions are unchanged. Remaining Backend/DB,
Admin Unit/build and final-head Required Checks still determine Source HOLD.
Fresh review cannot replace those gates. Retain Draft until separate Human GO.
