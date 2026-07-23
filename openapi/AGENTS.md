# OpenAPI Contract Rules

## Scope

These instructions apply to contracts under `openapi/`. They refine the Root
`AGENTS.md` and the finalized API v2 and Storefront Client contract.

## Contract Authority

- Use OpenAPI 3.1.1.
- Keep Public, Admin, and Webhook contracts separated.
- Follow the contract-first sequence: OpenAPI, contract tests, client generation,
  Laravel implementation, then E2E verification.
- Preserve documented error codes, validation semantics, authentication, and
  idempotency contracts.
- Do not make a breaking contract change without explicit human approval and the
  required compatibility/version action.

## Disclosure Rules

- Never expose secrets, credentials, PII, provider internals, probability
  internals, or infrastructure fields in a contract.
- Do not publish Admin types or endpoints through the Storefront surface.
- Keep examples synthetic and public-safe.
- Do not manually edit generated clients; regenerate them from the reviewed
  source contract.

## Verification

- Validate OpenAPI 3.1.1 syntax and semantics.
- Run contract tests, generated-diff checks, client typecheck/tests, and the
  applicable Laravel and E2E checks.
- Review the breaking-change and information-disclosure impact before commit.
- Follow the Root autonomous GitHub lifecycle. A contract PR may be squash
  merged only when fixed-head self-review and all applicable contract,
  compatibility, generated-diff, security, and CI gates pass.
