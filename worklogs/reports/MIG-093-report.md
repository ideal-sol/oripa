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
- Build, Migration, Payment Enable change, Provider communication, Payment/Coin mutation, Production mutation, and Secret value readback are zero.

## Runtime Acceptance

- Source Head `2fa6b8b26a474e52d1a962f07a5541f526a1eb62` passed the five Required Checks and fresh fixed-head Strict self-review. Existing exact MIG-092 API image `sha256:3188f9046e18ae33e4cff09cce4bebd3b700e037f707ee61173382496e121cbc`, revision `42a70d8efde5b8818b1bcec59ecf2af82f862b6e`, was reused for one API-only recreate. Build and Migration are zero.
- Runtime and Laravel config metadata classify `FINCODE_API_BASE_URL` as present/non-empty Sandbox, Public/Secret/Webhook inputs as present/non-empty, and `FINCODE_PAYMENT_ENABLED` as present/false. Platform origin remains `https://test.luxe-pack.biz`; Storefront origin remains `https://luxe-pack.biz`. Secret value readback is zero.
- API, Admin, PostgreSQL, and Redis are healthy with restart 0. Admin/PostgreSQL/Redis container identities and start times are unchanged; private networking and API-only egress are preserved. Root free is `22,304,661,504` bytes.
- Unsigned external Webhook remains 401. Malformed normal and unknown failure Return fixtures remain non-mutating 303 redirects to `https://luxe-pack.biz/points`; Preview and luxe same-origin Public API smokes are 200. HTTP 500/502/504 are zero.
- Payment and Payment Point Grant counts remain `0|0` before and after. Provider endpoint log matches, Provider communication, Production mutation, and Payment/Coin mutation are zero. `OPS-021 restart = GO` after merge and cleanup.

## Pending

- Runtime evidence-only successor Head, final Required five Checks, fresh fixed-head Strict self-review, squash merge, Issue/branch/worktree cleanup, and main synchronization.
