# MIG-098 3DS-Verified Save Card Registration Contract

## Governance And Resume

- Task `MIG-098`, Issue `#416`, branch `feat/MIG-098-3ds-verified-save-card-registration`, Lane `Strict Change`, Risk `R4`, Activation `deferred`.
- The frozen checkpoint was `231a9a0b7bcb3ba450bf72b0669adca3c550ad2f`. After GOV-020, the branch was rebased from old base `59e776922b30a6a4f3dce4c9135f81a12649b728` onto protected main `cfe24c416b9d95131ef0ec271bd129875a91c9f1`, producing checkpoint `91766b6bdb518050295857f0f69646f26c4e635f`.
- Semantic overlap was limited to `scripts/ci/policy_gate.py` and `tests/ci/policy/test_policy_gate.py`. GOV-020 exact merged-main publication, contract-only API Build 0, PR-head rejection, immutable upload/readback, preview-image workflow, and separate Release Ledger reconciliation invariants remain intact while MIG-098 adds Migration 000067 and Payment contract inventory guards.
- Platform Integration Lock and Migration 000067 Allocation Lock are held by MIG-098. Issue `#416`, the branch, dedicated worktree, and root-owned Task Policy remain active.
- The rebased Task Policy uses Base `cfe24c416b9d95131ef0ec271bd129875a91c9f1`. Human-approved Testkit export scope expansion added only `packages/storefront-testkit/scripts/check-exports.mjs`, bringing the exact allowed path count to 46 and SHA-256 to `5e91031d43e9d0a313fb159673d7d11611d607a038babb9bfb318867d0628547`.
- GitHub protected main stayed at the GOV-020 squash during the scope update. The protected-main GOV-020 report still contains its pre-final text; Human directed MIG-098 not to edit or reopen that documentation.

## Stage 0 Readback

- Active API image was `oripa-v2-api:preview-MIG-097-e006c7a95592`, OCI revision `e006c7a95592e5a03eef0be7e59bfa995185171c`; API, Admin, PostgreSQL, and Redis were healthy with restart count zero.
- Shared Preview migration ledger ended at existing `000066`; Migration 000067 was absent and has not been applied. Root free space was 26 GiB, above the 20 GiB gate.
- Latest immutable Storefront Artifact was `2.0.0-alpha.30` with no candidate before MIG-098 source preparation.
- Shared Preview SELECT-only readback found active saved Card 0, deleted Card 0, per-User active maximum 0, Registration Intent states `expired=3` and `reserved=1`; the reserved row was already past expiry and consumed no effective capacity. Existing Card verification-proof columns were 0 because Migration 000067 is unapplied.
- Existing Card rows are never inferred or backfilled as Registration 3DS2 verified. With active saved Card count 0, deferred activation has no current saved-Card availability impact requiring a separate Human data decision.

## Official Provider Contract

- Authority is fincode official documentation, API reference, and official OpenAPI linked from `docs/operations/payment-model/FINCODE.md`; unofficial articles are not used.
- Canonical creation is `POST /v1/customers/{customer_id}/payment_methods` with `pay_type=Card`, `tds_type=2`, `tds2_type=2`, fixed Platform normal/failure Return URLs, and a durable Provider Payment Method registration identifier. `tds2_type=3` is prohibited.
- Browser Return is non-authoritative. The formal `customers.payment_methods.updated` Webhook is only a signed reconciliation trigger and Card-reference correlation boundary.
- Success authority is exact Payment Method GET plus exact Card GET. The Platform verifies Provider Customer, Payment Method, Card, User, Intent, `ACTIVATED`, and `AUTHENTICATED` before saving a Card.
- TEST/SANDBOX does not yet include `customers.payment_methods.updated`; this blocks Runtime Activation but not Source, merge, or contract-only Artifact publication.

## Canonical Registration Architecture

- `startPaymentCardRegistration` accepts a short-lived Browser fincode token, persists no token/PAN/CVC, locks the User, enforces effective capacity, and creates one durable Platform Intent per Idempotency-Key.
- Durable authority records flow type, Provider idempotency key, Payment Method/access/Card/transaction references, Provider and 3DS2 states, encrypted allowlisted redirect, attempt/reconciliation timestamps, expiry, terminal timestamps, and exactly-once linkage to the saved Card.
- `pending` and `requires_action` are non-terminal and non-authoritative. `completed` is possible only after exact re-query and exactly-once Card creation. `failed`, `canceled`, and `expired` create Card 0, Payment 0, Coin 0, and Mail 0 and release capacity.
- Provider unavailable or malformed/unknown state is never promoted to success; it remains retryable pending until canonical evidence or expiry. Unsupported Registration 3DS2 fails terminally.
- Duplicate Browser Return, duplicate signed Webhook, duplicate direct reconcile, and concurrent reconcile create at most one Card and one completion Audit record.
- The normal and failure Return handlers ignore Browser payload, accept only the Platform-generated opaque Registration ID, perform canonical re-query, and redirect with HTTP 303 to the configured Storefront origin root. No arbitrary callback URL or open redirect is accepted.

## Legacy And Payment Boundaries

- Legacy standalone completion always returns `CARD_REGISTRATION_3DS_REQUIRED`; Browser `provider_card_id` is not authority.
- `startPayment(source=new, save=true)` also returns `CARD_REGISTRATION_3DS_REQUIRED` before Card, Payment, Provider, Coin, or Mail mutation.
- `save=false` new-card Payment remains available and requires Payment 3DS2. A completed Registration returns a Platform-owned `saved_card_id`; using that verified saved Card creates a separate Payment that again fixes `tds_type=2` and `tds2_type=2`.
- Registration 3DS2 never substitutes for Payment 3DS2. PayPay, Konbini, and Virtual Account behavior is unchanged.

## Capacity And Migration

- Existing `limits.remaining` remains `max(0, 3 - undeleted stored Card rows)`.
- Additive `registration_remaining` is `max(0, 3 - verified usable Card rows - non-expired live Registration attempts)`. `next_capacity_at` is the earliest live Registration expiry only when pending Registration contributes to zero capacity; otherwise it is null.
- Additive Migration `apps/api/database/migrations-v2/2026_09_23_000067_add_fincode_card_registration_3ds_authority.php` is created. It changes no existing Migration and performs no authority backfill.
- Fresh apply, rollback/reapply, constraint preservation, and existing unverified Card preservation run only on the isolated PostgreSQL test database. Shared Preview and Production apply count is 0.

## Public Contracts And Packages

- Public OpenAPI alpha.28 has 71 operations and adds start/get/reconcile/cancel Card Registration, normal/failure Registration Return, Registration state/action schemas, verified Card presentation, effective capacity, and typed Problems. Legacy operations remain deprecated and fail closed.
- Webhook OpenAPI alpha.28 documents `customers.payment_methods.updated` as trigger-only and exact Provider re-query as authority. Admin OpenAPI changes only its version metadata; Admin behavior, Build, and Activation remain unchanged.
- Storefront Client alpha.31 candidate exposes the typed Registration facade and Problem/status guards without Provider-internal authority. Storefront Testkit alpha.31 candidate adds success, failure, cancel, expiry, capacity, unavailable, ownership, and legacy-rejection fixtures.
- The Testkit exact export allowlist admits only `PUBLIC_PAYMENT_CARD_CAPACITY_FIXTURES`, `PUBLIC_PAYMENT_CARD_REGISTRATION_FIXTURES`, and `PUBLIC_PAYMENT_CARD_REGISTRATION_PROBLEM_FIXTURES`; wildcard, Admin, Webhook, and Provider-internal exports remain rejected.

## Focused Verification

- Isolated PostgreSQL 17 plus Laravel HTTP Provider fakes: Fincode Backend and concurrency 38 tests / 502 assertions PASS, including fresh Migration apply, rollback/reapply, exactly-once, failure, cancel, expiry, unsupported 3DS, Provider unavailable, ownership mismatch, Browser non-authority, legacy fail-closed, save=false, saved Card Payment 3DS2, Payment Grant/Mail exactly-once, and PayPay/Konbini/Virtual Account regressions. PHPUnit reported 38 existing warnings and exit 0.
- Storefront Client generated check, typecheck, lint, build, and 32 tests PASS.
- Storefront Testkit generated check, typecheck, lint, build, 41 tests, exact export allowlist, and network boundary PASS.
- OpenAPI canonical bundle check PASS: Admin 222, Public 71, Webhook 2.
- Artifact source validation PASS for pending alpha.31; Artifact unit 13 tests and exact-main publication unit 11 tests PASS.
- Policy unit 181 tests and local Policy Gate 1,607 tracked files PASS. `git diff --check` PASS.
- Required five GitHub checks and fresh exact-head Strict self-review remain pending Final Head. No Browser/E2E or real Provider communication is claimed.

## Artifact And Deferred Activation

- Source ledger candidate is contract-additive alpha.31 over immutable alpha.30, with no predicted Source Commit or publication digest. The next unused version must be live-confirmed immediately before merge and publication.
- Publication is allowed only after squash merge from the exact protected-main MIG-098 squash through `.github/workflows/storefront-contract-artifact-publish.yml`. Expected counts are Artifact publication 1 and API/Admin/Storefront Build, API push/Activation, and Migration apply all 0.
- Publication readback must verify Artifact ID/run/name, outer ZIP digest, Manifest Source SHA, Client/Testkit/Public OpenAPI digests, and `SHA256SUMS`.
- Publication digest reconciliation is a separate metadata Task/PR and remains HOLD. SITE-048 remains HOLD until reconciliation.
- Runtime Activation remains HOLD until Human adds `customers.payment_methods.updated` to the existing TEST/SANDBOX endpoint. A later read-only checkpoint verifies event presence, exact endpoint origin/path, and signature configuration presence without reading the Secret value.

## Mutation And Handoff Boundary

- Migration created/applied: 1/0. API Build/Activation: 0/0. Admin and Storefront Build/Activation: 0/0.
- Provider Card Registration, real Payment, Webhook replay, Card/Payment/Coin/Mail business mutation, Production mutation, and Secret value readback are all 0.
- SITE-048 must exact-pin the reconciled Artifact, show the Human-approved save-card confirmation popup, retain entered Card input on Back, use Registration `next_action` and opaque ID correlation, start Payment only after `completed + saved_card_id`, require Payment 3DS2 again, use `registration_remaining`, and remove non-3DS `registerCard()` from the Canonical save flow.
- Remaining Source gates are final documentation/evidence synchronization, Required five checks, fresh Strict self-review with SEV-0/SEV-1 zero, squash merge, exact-main contract-only Artifact publication/readback, and branch/worktree/lock/policy closeout. Runtime Activation, Release Ledger reconciliation, SITE-048, and Human Sandbox E2E remain separate.
