# MIG-094 Payment Resume JSON Request Contract

## Governance

- Task ID: MIG-094
- Issue: https://github.com/ideal-sol/oripa/issues/400
- PR: https://github.com/ideal-sol/oripa/pull/401
- Lane: Strict Change
- Risk: R4
- Activation: deferred
- Base SHA: `44120f2cf7ba71abe64c854a05facf60f3adf79b`
- Artifact Source SHA: `5cde1e0a91151b584de8a63d19efd7b4a15e8ab1`

## Root Cause And Fix

- The Client sent unpaid resume as a bodyless POST, so the canonical transport omitted JSON Content-Type and Browser Security returned 415.
- Initial Konbini and Virtual Account Payments retain the saved redirect in `requires_action + UNPROCESSED`, while resume previously accepted only `processing + AWAITING_CUSTOMER_PAYMENT`, causing a subsequent 409.
- Both Client facades now pass `body: {}` through the existing transport.
- Resume accepts only owned, unexpired Konbini or Virtual Account Payments in either exact state/provider-state pair, with an existing ciphertext that decrypts to a fincode HTTPS URL.
- Expired, other-user, missing redirect, undecryptable redirect, invalid authority, Credit Card, PayPay, and all other combinations fail closed with the existing public code.
- Existing Payment to existing encrypted redirect to decrypt to existing redirect remains the only resume path.

## Verification

- PHP 8.4.23 / isolated PostgreSQL 17: 2 focused tests, 39 assertions PASS.
- Storefront Client: generate, build, 31 tests, typecheck, lint PASS.
- Storefront Testkit: generate, build, 40 tests, typecheck, lint, exports, network PASS.
- Release: 13 focused tests and source validation PASS.
- Policy: 165 unit tests and local policy gate PASS.
- OpenAPI: bundled check and 9 tests PASS; Public alpha.27 remains 65 operations.
- Required five Source Head checks and fresh fixed-head Strict self-review PASS.

## Artifact

- Bundle: `2.0.0-alpha.29`
- Workflow Run: `33084320143`
- Artifact: `9651612069`
- GitHub outer SHA-256: `1e11a5f793ad009320b0a52cc83a14c9f1dd48ecaebc5fcb4e94c43973f7b97b`
- Manifest SHA-256: `9e5059d1d098d435d16399d8ce7d60172befb1c2ffe979037bf93ae1c447423b`
- Client SHA-256: `28e5756000847df3a1a27cf77be3da97beb4aef447486978ee74ecd979b425e1`
- Testkit SHA-256: `1e976d1cd83c00e79c632636018c57461bc89940640d0de949568cc1769b0b56`
- Public OpenAPI SHA-256: `41ebdddbd7c4edeedd36ad3810b2afa564495aa2d1c3e48a187f44c85deb85da`
- SHA256SUMS SHA-256: `23a1afd8f69eacff43e5b0146259172e93754a542f88fa4294f52deec9c3a944`
- Compatibility: package-only, Public OpenAPI unchanged, exact-pin GO.

## Mutation Boundary

- Middleware weakening: 0
- Provider request behavior change: 0
- Provider communication: 0
- Provider Session creation from resume: 0
- Payment or Coin mutation outside isolated tests: 0
- Migration created / Shared Preview / Production applied: 0 / 0 / 0
- Database mutation outside isolated tests: 0
- API Runtime Activation: 0
- Production mutation: 0
