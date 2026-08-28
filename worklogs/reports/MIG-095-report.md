# MIG-095 fincode Unpaid View Consistency / Webhook Correlation

## Governance

- Task ID `MIG-095`, Issue `#405`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base is clean local/origin/GitHub `main@7d72997dd329bc90dcb9a1fb1a60dcd32ac025c7`.
- Branch `fix/MIG-095-fincode-unpaid-view-consistency` and dedicated worktree `/var/www/oripa-worktrees/MIG-095` use the exact root-owned Task Policy paths.
- Platform Integration Lock is held. Artifact Release, Migration Allocation, and Preview Deployment locks remain free during source work.

## Stage 0

- `MIG-095` was unused across GitHub Issue/PR title search, Task Policies, evidence, Worklog, refs, branches, and worktrees before Issue creation.
- Root free was `29,200,404,480` bytes, above the 20 GiB Build gate. No cleanup or prune was performed.
- Shared Preview API ran OPS-022 image revision `be454446a4bc892867f947e7cae7061a3dcbfcf7`; API, Admin, PostgreSQL, and Redis were healthy with restart count zero.
- Shared Preview migration ledger was 66 rows, batch 32, through `2026_09_22_000066_add_v2_verification_failed_user_state`.
- Latest immutable Storefront Contract Artifact remains MIG-094 package-only `2.0.0-alpha.29`. Public OpenAPI, Storefront Client, Storefront Testkit, and Artifact bytes are unchanged by MIG-095.
- The Human Scope Override accepts the earlier Shared Preview test-environment secret readback. Subsequent secret-value readback is zero; no value is recorded in Repository, Issue, PR, Worklog, Evidence, or report.

## Canonical Unpaid Contract

- Old `view=unpaid` selected only `processing + AWAITING_CUSTOMER_PAYMENT` for future-expiry Konbini and Virtual Account Payments.
- New canonical policy selects exactly `requires_action + UNPROCESSED` or `processing + AWAITING_CUSTOMER_PAYMENT` for the authenticated owner, provider `fincode`, supported unpaid method, future expiry, and an existing decryptable saved fincode HTTPS redirect.
- `history(view=unpaid)` and `resumeUnpaidPayment()` share one method/provider/state-pair/expiry/saved-redirect policy. Card, PayPay, terminal, mismatched state pair, other-user, missing redirect, undecryptable redirect, and invalid redirect authority fail closed.
- Unpaid pagination continues to use `id DESC`, opaque cursor, limit, `has_more`, and `next_cursor`. Invalid saved redirects are skipped while scanning so an eligible row is neither duplicated nor omitted across cursor pages.
- Konbini active-unpaid limit remains one per User. The existing initial `requires_action + UNPROCESSED` Konbini is returned by unpaid history, while a second start remains HTTP 409 `KONBINI_UNPAID_LIMIT_REACHED`, non-retryable, before Provider communication.

## Webhook 404 Correlation

- Existing Nginx and API access evidence confirms two API-reaching `POST /webhooks/v2/fincode` requests at the acceptance timestamp, both HTTP 404.
- One diagnostic Virtual Account Payment was created nine seconds earlier and retained a present provider reference and saved redirect. Provider Event rows were zero before and after the observed requests.
- The retained evidence does not contain request body, event name, pay type, or provider reference. It therefore cannot uniquely prove whether either request targeted the diagnostic Payment or a stale/unrelated reference.
- Classification is `W3 unresolved`. Webhook code change, replay, Provider communication, Dashboard/config mutation, DB write, manual status change, and Coin Grant are zero. Payment success/Coin Grant Provider E2E remains HOLD pending follow-up correlation evidence.

## Verification

- PHP syntax for service and focused test: PASS.
- V2 isolated migration fresh through `000066`: PASS on Task PostgreSQL only; Shared Preview application is zero.
- Fincode focused suite: 26 tests / 295 assertions PASS.
- Payment plus fincode concurrency suite: 48 tests / 402 assertions PASS.
- Host Composer dependency preparation stopped before application tests because host PHP 8.3 does not satisfy the repository PHP 8.4 requirement; the same install completed in the cached PHP 8.5 Composer container.
- The first isolated broad Payment run stopped on an invalid synthetic Task-only `APP_KEY`; after correcting only the isolated harness and recreating the V2 Task database, the exact broad suite passed as recorded above.
- Local Policy Gate: PASS with 1,586 tracked files before final evidence paths; rerun is required on Final Head.
- `git diff --check`: PASS before final evidence paths; rerun is required on Final Head.
- Required five GitHub checks and fresh fixed-head Strict self-review: pending Final Head.

## Contract, Artifact, and Runtime Boundary

- Public OpenAPI, response schema, endpoint, Storefront Client, Storefront Testkit, and immutable Artifact: unchanged; new Artifact is not issued.
- Migration created: zero. Shared Preview migration applied: zero.
- Source-phase Build and Runtime Activation: zero. Admin and Storefront Build/Activation: zero.
- Shared Preview business-data, Payment, Coin, Provider, fincode configuration, and Production mutation: zero.
- Merge-first boundary: the exact source PR must squash merge before the canonical merged-main `preview-image-build.yml` API-only workflow is dispatched. Post-merge Build, artifact readback, API-only Activation, Runtime checks, and OPS-021 restart decision are recorded in root-only evidence and Issue #405.

## Handoff State

- Unpaid view Contract fix: pending merge and Activation.
- Existing Virtual Account visibility: code/test GO; Runtime or Human verification pending.
- Existing Konbini visibility: code/test GO; Runtime or Human verification pending.
- Konbini unpaid limit: unchanged / PASS.
- Card Platform Bootstrap: PASS and unchanged.
- Card UI: Storefront / Browser SDK follow-up.
- Webhook 404: W3 unresolved.
- OPS-021 Provider Browser E2E restart: HOLD until post-Activation unpaid visibility readiness; Provider success/Coin Grant remains HOLD while W3 is unresolved.
