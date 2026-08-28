# MIG-096 Credit Card Return / Save Card Purchase Consistency

## Task

- Issue: #407
- Lane / Risk / Activation: Strict Change / R4 / immediate
- Base: `1451b5ec47b7226b784278c28bbbd5832650918f`
- Branch: `fix/MIG-096-card-return-save-consistency`
- Worktree: `/var/www/oripa-worktrees/MIG-096`

## Stage 0 Return Correlation

- Outbound new-card Next Action `return_url`: canonical Platform normal Return Handler.
- Outbound new-card Next Action `failure_url`: canonical Platform failure Return Handler.
- Outbound saved/save=true execute `return_url`: canonical Platform normal Return Handler.
- Outbound saved/save=true execute `return_url_on_failure`: canonical Platform failure Return Handler.
- `redirect_url`: Provider-issued 3DS navigation URL; it is not a merchant return destination.
- Platform does not generate fincode `/v1/payments/cards/success` or `/failure` as merchant return destinations.
- Active Storefront uses `@fincode/js` utility `executePayment()` without a request shape capable of carrying the Platform-provided merchant return URLs. The observed fincode success/failure endpoints are Provider fallback/default destinations.
- Platform Return Handler remains a non-authoritative Browser correlation boundary: success -> 303 `/points/purchase/thanks?pid=...`; failure -> 303 `/points/purchase/{point_product_id}?pid=...`; invalid correlation -> 303 `/points`.

## Stage 0 Save Card Correlation

- Human attempt timestamps: 2026-08-28 15:10:12 JST and 15:14:09 JST.
- Endpoint: `POST /api/v2/me/payment-card-registration-intents`.
- HTTP / Problem: 415 / `UNSUPPORTED_MEDIA_TYPE` / retryable false.
- Application error: `App\Domain\Identity\Exceptions\V2AuthenticationException` raised by the existing Browser Security middleware.
- Request ID: not retained in the available access/application logs.
- Registration Intent: not created; owner/expiry/state/consumption validation not reached.
- registerCard / provider_card_id: not reached / none.
- startPayment(save=true): not reached.
- Payment / Provider Card execute / 3DS redirect: no row / no request / none.
- Transaction: no domain transaction started; no rollback was required.
- Exact failure point: Storefront Client bodyless JSON mutation was rejected before the controller.

## Minimal Fix

- Both Storefront Client `createCardRegistrationIntent()` facades send `body: {}` through the existing transport.
- Existing auth, CSRF, idempotency, ownership, expiry, card cap, concurrency, exactly-once, and Provider boundaries remain unchanged.
- No purchase call to standalone `completePaymentCardRegistration()` is added.
- Platform Card Return code is unchanged; exact Storefront follow-up is required to execute the Card with both Platform-provided merchant return URLs.

## Delivery Boundary

- Migration created / applied: 0 / 0.
- API source change / Build / Activation: 0 / 0 / 0.
- Payment/Coin mutation from task: 0.
- Provider mutation/communication from diagnosis: 0.
- Production mutation: 0.
- Webhook W3: independently unresolved; no replay, event injection, configuration change, or speculative fix.

## Focused Verification

- Storefront Client: build PASS; 31 tests PASS; typecheck/lint/generated check PASS.
- Storefront Testkit: build PASS; 40 tests PASS; typecheck/lint/generated/exports/network checks PASS.
- Site Schema prerequisite build: PASS; no source or artifact version change.
- Artifact governance: 13 tests PASS; pending alpha.30 source validation PASS.
- Policy: 167 unit tests PASS; local Policy Gate PASS for 1,588 tracked files.
- Card Backend: 26 tests / 295 assertions PASS on isolated PostgreSQL with Provider fakes; existing PHPUnit warnings 26.
- Card concurrency/idempotency: 1 test / 22 assertions PASS on isolated PostgreSQL with Provider fakes; existing PHPUnit warning 1.
- Initial non-behavioral failures: Client dependencies/dist absent; Testkit Site Schema prerequisite not built. Frozen install and dependency build resolved both. A stale alpha.29 version-header assertion was then updated to the alpha.30 package version and the same suite passed.
