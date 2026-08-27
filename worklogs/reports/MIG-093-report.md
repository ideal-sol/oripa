# MIG-093 fincode Compose Credential Wiring

## Task

- Issue `#398`, Branch `fix/MIG-093-fincode-compose-credential-wiring`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- `MIG-093` was unused in GitHub Issue/PR search, root Task Policies, evidence, Worklog, refs, history, branches, and worktrees before allocation. Base is `b805d09469c79f92564d041236daf4d3dc4c67a1`.
- OPS-021 remains Fail Closed. This Task does not enable Payment or communicate with fincode.

## Implementation

- `docker-compose.v2.yml` passes `FINCODE_API_BASE_URL`, `FINCODE_PUBLIC_API_KEY`, `FINCODE_SECRET_API_KEY`, `FINCODE_WEBHOOK_SIGNATURE`, and `FINCODE_PAYMENT_ENABLED` only to API through Compose interpolation.
- No credential value or secret default is embedded. Existing fincode timeout and MIG-092 Platform/Storefront origin wiring are unchanged. Admin, PostgreSQL, Redis, and Storefront do not receive the five inputs.
- The Policy Gate requires every input exactly once in the API service, rejects omission and non-API placement, and preserves the five matching `v2_fincode.php` env consumers. Fixtures contain variable names and public-safe defaults only, never credential values.

## Source Verification

- Focused Compose/Policy guard: 6 tests PASS.
- Full Policy Unit: 165 tests PASS.
- Local Policy Gate: PASS with 1,581 tracked files.
- Compose config quiet validation, PHP config syntax, Python compile, and `git diff --check`: PASS.
- Build, Migration, Runtime Activation, Payment Enable, Provider communication, Payment/Coin mutation, Production mutation, and Secret value readback are zero at this phase.

## Pending

- Commit, Draft PR, Required five Checks, fresh exact-head Strict self-review, squash merge, API-only existing-image activation, Runtime metadata/health/regression acceptance, OPS-021 restart decision, and cleanup.
