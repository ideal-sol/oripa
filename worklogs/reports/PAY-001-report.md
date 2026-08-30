# PAY-001 Save Card Payment Method Registration Final Fix

## Governance

- Human Change: `Save Card Payment Method Registration Final Fix`; delivery identifier `PAY-001`; Branch `fix/PAY-001-save-card-payment-method-registration-final-fix`; Lane `plat-main / Strict Change`; Risk `R4 / Payment / Provider / Card Registration`; Activation `immediate`.
- Exact base is protected `main@273aebfd0066b6dc5e5f217d0f05153d14f29fa2`. The Human explicitly waived a GitHub Issue, dedicated worktree, Task Policy, exact allowed-path declaration, and Source Lock for this Change. Direct push to `main` remains prohibited.
- The installed GitHub App write/build wrappers and existing CI metadata parser still require a root-only Task Policy plus exact changed-path metadata. Delivery will use a temporary compatibility policy only to satisfy those existing non-weakened controls; no Issue, dedicated worktree, Source Lock, branch-protection change, permission change, or gate weakening is introduced. Its public-safe policy SHA-256 is `c04e30d2c8b64421e5421c1474a7819a77743c2258c0a8f7eb40dc5495f67b56`; the policy will be removed at closeout.

## Light Stage 0

- Local, origin-tracking, live Remote, and GitHub protected `main` were clean and equal at the exact base. No unrecognized worktree change or conflicting Platform PR/branch existed; fifteen open Dependabot PRs were unrelated.
- Active API was healthy on `oripa-v2-api:preview-OPS-025-762a766a0e24`, image ID beginning `sha256:c59524ae2253`, OCI revision `762a766a0e24fb28b9dc9006c3ae901cf81910a4`, restart count zero. Immediate rollback image `oripa-v2-api:preview-MIG-097-e006c7a95592` remained available.
- Shared Preview migration ledger was `67 / batch 33 / 2026_09_23_000067_add_fincode_card_registration_3ds_authority`. Artifact latest immutable was `2.0.0-alpha.31`, candidate null.
- Active Storefront source was `259953797661347794f5e08e7b426758f6b0d0bf`, Build ID `VfNi7Zdr1ePZiXFZ465Jh`, active with restart count zero. Direct API health, test/live same-origin session, and test/live Storefront `/` were HTTP 200. Root free space exceeded 20 GiB.

## Full Delivery Preflight

- The ordered path was confirmed once before implementation: Source -> focused and concurrency tests -> Draft PR -> five Required Checks -> fresh exact-head Strict self-review -> exact-head API-only Build -> protected-main drift gate -> squash merge -> PR-head tree/merge tree equality and zero content diff -> API-only Activation -> safe Runtime Acceptance.
- Reviewed Tree Authority from OPS-025 remains applicable. Build input must be the exact final reviewed PR Head and its OCI revision. Any head change or protected-main drift invalidates checks, review, and build. Any tree mismatch after squash merge prohibits Activation.
- No migration, Artifact, OpenAPI, Client/Testkit, Storefront, Admin, Nginx, credential, Provider-setting, or Production change is required. The only known delivery compatibility blocker is the wrapper/CI Task Policy metadata requirement described above; it does not require a security or branch-protection change.

## Official Provider Contract Review

- Authority was limited to fincode primary material: [Payment Method](https://docs.fincode.jp/payment/payment_method), [3D Secure 2.0](https://docs.fincode.jp/payment/fraud_protection/3d_secure_2), [API reference](https://docs.fincode.jp/api), and the API-reference OpenAPI snapshot `https://docs.fincode.jp/assets/api/fincode-openapi.yml?date=20250108`.
- The reviewed OpenAPI was `3.0.2`, fincode API version `1.4.0`, Sandbox server `https://api.test.fincode.jp`, SHA-256 `f15508c053bde7a87a2ebc940861ae928b5e285f17e1e2dd5b2fd4f81f2b8be1`.

| Boundary | Official current contract | Previous Platform request | Decision |
| --- | --- | --- | --- |
| HTTP / endpoint | `POST /v1/customers/{customer_id}/payment_methods` | Exact method and endpoint; path customer is URL-encoded Provider customer ID | Match |
| Authentication / encoding | Secret Bearer or Basic; `application/json` | Secret Bearer, JSON `Accept` and `Content-Type` | Match |
| Idempotency | Optional `idempotent_key` header, UUID v4 | Persisted UUID v4 per Registration | Match |
| `pay_type` | Required; `Card` | `Card` | Match |
| `default_flag` | Required `0|1`; each customer/payment type with any Payment Method must have exactly one default | Always `0`, including the first Card Payment Method | **Confirmed mismatch** |
| `card.token` | Required when `pay_type=Card`; max 512 | Single-use Browser token nested under `card`; not persisted | Match |
| `card.tds_type` | Optional `0|2` | Security-fixed `2` | Match |
| `card.tds2_type` | Optional `2|3` | Security-fixed `2`; `3` remains prohibited | Match |
| callbacks | Optional success/failure URL, max 256; Provider redirects by POST | Canonical HTTPS Platform normal/failure URLs with only `rid` query | Match |
| merchant fields | `client_field_1` optional, max 100 | Registration UUID in `client_field_1` | Match |
| other Card/fraud/customer properties | Optional unless explicitly conditional | Omitted, not sent as null or empty | Match; no inferred fields added |
| Provider errors | HTTP status plus structured `errors[].error_code`; code length exactly 11 | HTTP status and Provider error body were discarded | Diagnostic mismatch fixed safely |

- The exact historical Provider rejection reason cannot be reconstructed because the previous implementation discarded it. The static request defect is nevertheless confirmed: the first Card Payment Method was explicitly submitted as non-default while the official contract requires one default whenever a Payment Method exists. The official error catalog includes `EC013136002` for absence of a default Card, but this code is not attributed retroactively to the Human Case A.

### Previous Request

```json
{
  "pay_type": "Card",
  "default_flag": "0",
  "return_url": "https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/normal?rid=<registration-id>",
  "return_url_on_failure": "https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/failure?rid=<registration-id>",
  "client_field_1": "<registration-id>",
  "card": {"token": "<single-use-token>", "tds_type": "2", "tds2_type": "2"}
}
```

### Corrected Request

```json
{
  "pay_type": "Card",
  "default_flag": "1 when active Platform saved-card count is zero; otherwise 0",
  "return_url": "https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/normal?rid=<registration-id>",
  "return_url_on_failure": "https://api.luxe-pack.biz/api/v2/payment-card-registration-returns/fincode/failure?rid=<registration-id>",
  "client_field_1": "<registration-id>",
  "card": {"token": "<single-use-token>", "tds_type": "2", "tds2_type": "2"}
}
```

## Implementation

- The first active Platform saved Card now submits `default_flag=1`; subsequent registrations submit `0`. The decision runs inside the existing user-serialized Registration preparation transaction. Existing Card limit, idempotency, token handling, callbacks, and 3DS2 parameters are unchanged.
- Non-success Provider responses retain only the HTTP status and first contract-shaped 11-character uppercase alphanumeric `errors[].error_code`. The existing Registration `last_error_code` stores compact internal evidence such as `FINCODE_PROVIDER_REJECTED|HTTP_400|EC013136002`; no migration is required.
- User-facing errors remain typed `CARD_REGISTRATION_FAILED` or `CARD_REGISTRATION_UNAVAILABLE`. Raw response, `error_message`, request body, token, Authorization, credential, PAN/CVC, and customer-sensitive values are never stored or logged. Malformed and empty bodies preserve only safe status evidence and fail closed.
- Browser Return remains non-authoritative; signed Webhook remains trigger-only; Payment Method exact GET plus Card exact GET remain success authority. No Card is created before verified completion. Save=false Card Payment, PayPay, Konbini, Virtual Account, Payment 3DS2, exactly-once grant, and concurrency behavior are unchanged.

## Source Validation

- PHP syntax: four changed PHP files PASS.
- Focused `FincodePaymentBackendTest`: `40 passed / 497 assertions / 0 warnings`. This covers the exact create request, first/subsequent default regression, Provider success, structured/malformed/empty rejection, no raw evidence persistence, Browser Return non-authority, exact GET authority, zero Card/Payment/Coin/Mail mutation on failure, save=false, PayPay, Konbini, Virtual Account, and exactly-once regressions.
- `ZFincodePaymentConcurrencyTest`: `1 passed / 41 assertions / 0 warnings`.
- The first task-branch push attempt stopped locally before network update because the wrapper's high-confidence Secret Guard rejected an exact fake Authorization literal in the request assertion. The test now compares the header to the configured test value without embedding a Bearer credential-shaped literal; the targeted contract test passed `1 / 30`, and no guard was weakened or bypassed.
- Tests used HTTP fakes only. Real Provider communication, Card Registration, Card, Payment, Coin/Point Grant, and Mail mutation were zero.
- The retained isolated test database was advanced from migration `000062` through existing protected-main `000067` only to execute tests. Migration files created by this Change: `0`; Shared Preview/Production migrations applied by this Change: `0`.

## Delivery Status

- Source implementation and initial focused validation are complete. PR, Required Checks, fresh self-review, API-only Build, squash merge/tree authority, API Activation, Runtime Acceptance, cleanup, and Human final E2E handoff remain pending.
