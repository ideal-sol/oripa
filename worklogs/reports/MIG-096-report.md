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

## Stage 0 PayPay Correlation

- Target Payment: `01a0473c-ea1a-72f5-bd43-24fa2c9400bb`, created 2026-08-28 16:19:29 JST, method `paypay`.
- Stored Provider payment reference: `o9f422e197feab4c885d4543aa9`; current status / provider status: `requires_action` / `UNPROCESSED`.
- Provider success evidence: Human observed PayPay App success. Canonical Provider retrieval using the stored reference returned HTTP 400 / `EE006025002` (`transaction does not exist`), so the Platform cannot independently read back that reference as a PayPay transaction.
- Browser Return: Platform normal Return Handler received the target `pid` at 16:19:47 JST and returned the canonical 303 Storefront thanks destination. Browser return performed no Payment or Coin mutation.
- Polling: authenticated `getPayment(pid)` returned the unchanged non-terminal state through the observed 30-second polling boundary.
- Webhook received / HTTP: `POST /webhooks/v2/fincode` reached the API three times at 16:19:35, 16:19:35, and 16:19:40 JST; each response was HTTP 404. The route exists, and this controller returns 404 only for `FINCODE_PAYMENT_NOT_FOUND` after signature, JSON, supported event, pay type, and reference-shape validation.
- Retained webhook evidence does not include the raw body, event name, pay type, or incoming Provider reference. No raw Provider payload was displayed or persisted by this diagnosis.
- Provider Event / correlation / webhook re-query: 0 rows / failed / 0 calls. The service stops before Provider re-query when `payments.provider_payment_id` does not match the webhook order reference.
- Canonical status transition / transaction: no `succeeded` transition and no webhook domain transaction began; therefore no commit or rollback occurred.
- Payment Point Grant / purchase mail: 0 / 0.
- Exact failure point: redirect-session order correlation was broken before webhook Provider re-query.
- Root cause: `POST /v1/sessions` sent the merchant order reference as `transaction.id`. The official fincode OpenAPI requires the optional preassigned transaction reference at `transaction.order_id`; without it fincode generates a different Payment ID. Platform then stored and queried the unregistered merchant value, while the signed webhook carried the Provider-created transaction reference, causing `FINCODE_PAYMENT_NOT_FOUND`.
- Minimal fix: send `transaction.order_id` and never send the unsupported `transaction.id`. The same redirect-session builder is shared by PayPay, Konbini, and Virtual Account, so all three retain the existing canonical return and correlation contract.
- Diagnostic Provider communication: 3 read-only GET attempts, all safely reduced to HTTP/category evidence. Provider mutation, replay, artificial event delivery, Payment mutation, and Coin mutation were 0.

## Delivery Boundary

- Migration created / applied: 0 / 0.
- API source change: 1 minimal adapter mapping. Build / Activation remain pending merge-first delivery.
- Payment/Coin mutation from task: 0.
- Provider mutation from diagnosis: 0.
- Production mutation: 0.
- Webhook W3: exact `transaction.id` versus `transaction.order_id` cause is resolved without replay, event injection, signature weakening, configuration change, or speculative mapping.

## Storefront Artifact

- Version / Source: `2.0.0-alpha.30` / `4a7703859473f0c3f5e317cfca454cb8dce401ae`.
- Canonical workflow / Artifact: `33154172300` / `9678987823`; release mode `package-only`.
- GitHub outer / Manifest / SHA256SUMS: `f9c7e8f65b0121fc5d99d0b79292b8c1c43eb2046145c8112516b823ee955b6a` / `25667419d9db73a946f48ca1351f2c8b0e9fc1f371508efe2c44b9403852fe5a` / `5849402d1d7770751683c93d0dfd619edbf33eb7bd262094d6b3ce87948aa363`.
- Client / Testkit / Public OpenAPI: `f44e2da2d427621296f2bb27958ef7b20e217b5b07fbcf6cc342978e2ef9dae6` / `f349b6e07421507ccbdca9a6e0cbc07d79379b444fbe2119b1a92709319e8809` / `41ebdddbd7c4edeedd36ad3810b2afa564495aa2d1c3e48a187f44c85deb85da`.
- Ledger: alpha.30 is `latest_immutable`; `candidate=null`; Storefront exact-pin is GO.
- An initial workflow dispatch inherited `image_mode=normal`. It was cancelled during the image-build step before any Preview image or Storefront Artifact upload, then replaced by the successful explicit `api-only` run. No cancelled-run artifact is accepted.

## Focused Verification

- Storefront Client: build PASS; 31 tests PASS; typecheck/lint/generated check PASS.
- Storefront Testkit: build PASS; 40 tests PASS; typecheck/lint/generated/exports/network checks PASS.
- Site Schema prerequisite build: PASS; no source or artifact version change.
- Artifact governance: 13 tests PASS; pending alpha.30 source validation PASS.
- Settled Artifact/policy governance: 180 tests PASS; local Policy Gate PASS for 1,589 tracked files.
- Policy: 167 unit tests PASS; local Policy Gate PASS for 1,588 tracked files.
- Card Backend: 26 tests / 295 assertions PASS on isolated PostgreSQL with Provider fakes; existing PHPUnit warnings 26.
- Card concurrency/idempotency: 1 test / 22 assertions PASS on isolated PostgreSQL with Provider fakes; existing PHPUnit warning 1.
- Fixed PayPay/Card/redirect-session head: 27 tests / 317 assertions PASS on a fresh isolated PostgreSQL with Provider fakes; existing PHPUnit warnings 27.
- Initial non-behavioral failures: Client dependencies/dist absent; Testkit Site Schema prerequisite not built. Frozen install and dependency build resolved both. A stale alpha.29 version-header assertion was then updated to the alpha.30 package version and the same suite passed.
