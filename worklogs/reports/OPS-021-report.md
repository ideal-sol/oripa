# OPS-021 fincode Sandbox Shared Preview Enablement

## Current Status

- Issue `#397`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Sandbox credentials, Webhook registration, Payment Enable, API readiness, Bootstrap readiness, and Return readiness now pass. Platform Payment Browser Acceptance readiness is GO.
- Provider Browser E2E has not started because the active Storefront remains alpha.24; the latest Payment UI alpha.28 Runtime is not active. OPS-021 remains open at the Storefront Activation checkpoint.

## API Env-only Reflection

- Human confirmed the Preview `FINCODE_STOREFRONT_ORIGIN` update. The existing immutable API image `oripa-v2-api:preview-MIG-092-42a70d8efde5`, image ID `sha256:3188f9046e18ae33e4cff09cce4bebd3b700e037f707ee61173382496e121cbc`, source revision `42a70d8efde5b8818b1bcec59ecf2af82f862b6e` was reused.
- Under the Preview Deployment and OS locks, only API was recreated with `--force-recreate --no-build --no-deps`; the guarded helper restored only the existing API egress attachment. Build, Migration, Admin Activation, Storefront Activation, PostgreSQL/Redis recreation, and network recreation were zero.
- Admin, PostgreSQL, and Redis container identities, start times, mounts, volumes, and private-only membership remained unchanged. API, Admin, PostgreSQL, and Redis are healthy with restart count zero. API health returned Application, database, Redis, and storage `ok`; migration ledger remains 66 through `000066`.
- Activation-window Nginx HTTP 500, 502, and 504 counts were zero. Root free remained above the 20 GiB gate.

## fincode Boundary

- Runtime Platform origin remains `https://test.luxe-pack.biz`; Runtime Storefront origin is now `https://luxe-pack.biz`; both are distinct from Admin.
- Runtime URL-builder fixtures produced Preview Platform normal/failure Return Handler URLs, normal Storefront `https://luxe-pack.biz/points/purchase/thanks?pid={Payment.id}`, and failure Storefront `https://luxe-pack.biz/points/purchase/{PointProduct.id}?pid={Payment.id}`. Admin redirects were zero.
- External malformed, missing, and unknown `pid` POST fixtures each returned HTTP 303 to the safe `https://luxe-pack.biz/points` fallback. Payment and Payment Point Grant counts remained `0 -> 0`.
- `FINCODE_PAYMENT_ENABLED` remains effectively false through the unchanged unset/default boundary. fincode Public Key, Secret Key, and Webhook Signature classifications remained absent before and after reflection; secret value readback was zero.
- Provider communication, Production mutation, Payment/Coin business mutation, credential setup, Webhook registration, and Payment Enable were zero.

## Remaining Checkpoint

- MIG-093 completed the canonical Compose wiring blocker. This historical checkpoint is superseded by the Payment Enable readiness section below.

## Credential Reflection Resume — Fail Closed

- Human confirmed Sandbox env setup and Sandbox Dashboard Webhook registration without disclosing values. Canonical root-only env metadata passed: Sandbox API base, non-empty Public Key, non-empty Secret Key, non-empty length-valid Webhook Signature, explicit Payment disabled, Preview Platform origin, and luxe Storefront origin.
- One API-only recreate reused the exact MIG-092 image without Build, Migration, Admin Activation, Storefront Activation, DB/Redis recreation, or network recreation. Health, restart zero, private/API-only network boundary, external unsigned Webhook 401, Return Handler safe 303, Payment/Grant `0/0`, and HTTP 500/502/504 zero passed.
- The active API still classified API base, Public Key, Secret Key, Webhook Signature, and Payment Enable as absent. Source readback confirmed `docker-compose.v2.yml` does not pass these five inputs, while `apps/api/config/v2_fincode.php` consumes them. The apparent Sandbox base is only the safe code default; credential and signed Webhook readiness are not present.
- Payment Enable was not attempted. No ad hoc secret-bearing Compose override, verification weakening, Provider communication, Payment/Coin mutation, or Production mutation was performed. Preview Deployment and OS locks were released.
- OPS-021 is blocked pending a separate Strict MIG that adds canonical Compose wiring and focused policy/config guards. After that MIG is merged and activated, resume this same OPS-021 from credential reflection; do not repeat Build, Migration, Admin Activation, or Storefront Activation unnecessarily.

## Payment Enable / Platform Readiness

- OPS-021 resumed on existing Issue #397, branch, worktree, and Task Policy after MIG-093 closeout. Local/origin/GitHub main are `44120f2cf7ba71abe64c854a05facf60f3adf79b`; root free is `22,334,709,760` bytes, above the 20 GiB gate.
- Preflight confirmed exact MIG-092 API image `sha256:3188f9046e18ae33e4cff09cce4bebd3b700e037f707ee61173382496e121cbc`, revision `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`, healthy/restart-zero API/Admin/PostgreSQL/Redis, and ledger 66 through `000066`.
- Canonical Preview env metadata classified Sandbox API base and Public/Secret/Webhook inputs present/non-empty with Payment false. The Human-approved non-secret toggle alone changed to `FINCODE_PAYMENT_ENABLED=true`; Public Key, Secret Key, and Webhook Signature were not changed or displayed. Secret value readback is zero.
- The existing image was reused for one API-only `--force-recreate --no-build --no-deps` activation. Build, Migration, Admin Activation, Storefront Activation, PostgreSQL/Redis recreation, and network recreation are zero. API/Admin/PostgreSQL/Redis remain healthy with restart zero; non-API identities, volumes, private network, and API-only egress are preserved.
- Runtime and Laravel config metadata confirm Payment true, Sandbox base, Public/Secret/Webhook present non-empty, Preview Platform origin, and luxe Storefront origin. HTTP 500/502/504 is zero and ledger remains unchanged.
- A safe authenticated in-process controller boundary returned HTTP 200, `provider=fincode`, `is_live_mode=false`, Public Key present without value output, `private, no-store`, and `Vary: Cookie`; the external anonymous endpoint remained 401. Payment, Provider Session, Registration Intent, Card, Point/Coin, and Payment Grant table counts were unchanged.
- The first read-only boundary attempt referenced a nonexistent User `status` column and failed before authentication/controller execution or transaction start. It performed no mutation; the corrected canonical `state` query produced the accepted result above.
- Human Sandbox Dashboard Webhook registration is the canonical checkpoint. Unsigned `POST https://test.luxe-pack.biz/webhooks/v2/fincode` remains 401 `application/problem+json`; verification was not weakened and signed Provider event verification is deferred to Browser E2E.
- Platform Return origin remains `https://test.luxe-pack.biz`. Canonical normal/failure final templates remain luxe thanks/product routes; malformed and unknown `pid` safely return 303 to `https://luxe-pack.biz/points`. Browser Return remains non-authoritative and Admin redirects are zero.
- One test-safe Sandbox GET to a nonexistent Payment confirmed connectivity and accepted authentication with HTTP 400. It created no Provider or Platform Payment, Coin, Grant, Session, Registration Intent, or Card state; Production endpoint requests are zero.
- Storefront remains active release `58a6bc6b6119f7daaa2d415c3b9e4c3db4f98b18`, `@oripa/storefront-client` `2.0.0-alpha.24`; latest Platform Artifact is alpha.28. Therefore `Platform Payment Browser Acceptance readiness = GO` and `Provider Browser E2E = HOLD`, reason latest Storefront Runtime pending.

## Final Closeout

- The later Storefront release `bddff7106a8e710859a94cc07ada9a93b18aa136` activated the exact `@oripa/storefront-client` and Testkit `2.0.0-alpha.30` pins. The active bundle contains the MIG-096 empty JSON mutation and both canonical Card merchant return URLs.
- Human Browser Acceptance then confirmed Credit Card success and PayPay Provider success with Coin Grant. Card failure cards reached the canonical Platform failure Return Handler; Platform returned HTTP 303 to the product purchase page as designed.
- The Acceptance also exposed a separate Platform defect: exact Provider re-query retained `AUTHENTICATED` together with documented 3DS failure `EC0091310A3`, while the coarse status mapper left the Payment in `requires_action`. That defect is handed off to a new Strict Platform implementation Task and is not expanded into OPS-021.
- Save Card follow-up and the Storefront failure-return navigation correction remain separate Site responsibilities. OPS-021 changes no Storefront or Platform source and performs no additional Build, Activation, Migration, Provider mutation, Payment mutation, Coin Grant, or Mail delivery during closeout.
- Final live readback: local/origin/GitHub main `1c3ea1d4e715a277c8417c97f86924533cb509eb`; API image `oripa-v2-api:preview-MIG-096-3f6f918e2d85`, image ID `sha256:2aca4d66eb3c583b84b06bcd112f67eef710225371ff918b93e6ffbc153b51c7`, OCI revision `3f6f918e2d8537f84354a8d75e6933b5a663459a`; API/Admin/PostgreSQL/Redis healthy with restart zero; migration ledger 66 through `000066`; root free remains above 20 GiB.
- OPS-021 therefore closes as the completed Sandbox enablement and Platform readiness Task. Browser-discovered implementation defects remain explicit follow-up Tasks rather than hidden acceptance PASS claims.
