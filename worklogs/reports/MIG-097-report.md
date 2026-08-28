# MIG-097 Card 3DS Failure Canonical Terminalization

## Governance and Stage 0

- Task ID `MIG-097`, Issue `#410`, Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base is clean local/origin/GitHub `main@95f3b9c7e20cc39c705e8c8c5107a9ff28b2f425`; branch `fix/MIG-097-card-3ds-failure-terminalization` and dedicated worktree `/var/www/oripa-worktrees/MIG-097` use the exact root-owned Task Policy paths.
- OPS-021 contained no unique uncommitted Source change. Its evidence was preserved under the root-only evidence boundary, Issue `#397` was closed, PR `#409` was squash-merged as `95f3b9c7e20cc39c705e8c8c5107a9ff28b2f425`, and its branch/worktree/policy were cleaned before MIG-097 started.
- Stage 0 active API was `oripa-v2-api:preview-MIG-096-3f6f918e2d85`, image ID `sha256:2aca4d66eb3c583b84b06bcd112f67eef710225371ff918b93e6ffbc153b51c7`, OCI revision `3f6f918e2d8537f84354a8d75e6933b5a663459a`. API/Admin/PostgreSQL/Redis were healthy with restart zero; migration ledger was 66 rows through `000066`.
- Active Storefront was source `bddff7106a8e710859a94cc07ada9a93b18aa136`, Build ID `vEpuPZEWLUWtXU7dZ2lE6`, exact Client/Testkit pin `2.0.0-alpha.30`. Latest immutable Artifact was alpha.30 with `candidate=null`.
- Root free was `27,880,689,664` bytes, above the 20 GiB gate. Platform Integration Lock is held for the governed source lifecycle; other shared locks were free at Task start.

## Provider Contract and Root Cause

- fincode's official error contract defines `EC0091310A3` as 3D Secure 2.0 authentication failure requiring retry from the purchase screen: <https://docs.fincode.jp/develop_support/error>.
- The official Card status contract defines `AUTHENTICATED` as an unfinished 3DS/Card state: <https://docs.fincode.jp/payment/status>. The official 3DS callback contract sends success/failure Browser navigation by POST, but that Browser Return is not Payment authority: <https://docs.fincode.jp/payment/fraud_protection/3d_secure_2>.
- The official retrieval schema returns `Payment.error_code` with Card retrieval state. Runtime evidence contained exact canonical tuple `pay_type=Card`, `status=AUTHENTICATED`, `error_code=EC0091310A3` after Provider re-query.
- Exact root cause: `V2FincodeWebhookService` classified only the coarse Provider `status`, mapping every `AUTHENTICATED` response to Platform `requires_action`. It ignored the documented canonical `error_code`, so a confirmed terminal 3DS failure could not traverse the already-valid `requires_action -> failed` Payment transition.

## Canonical Classification

- New `V2FincodeCanonicalStatusClassifier` gives the exact approved tuple `Card + AUTHENTICATED + EC0091310A3` priority over the coarse status and maps it to Platform `failed`.
- `Card + AUTHENTICATED` without `error_code` remains `requires_action`. Unknown or malformed Card/AUTHENTICATED error evidence raises a retryable 503 and performs no Payment, attempt, event, Grant, Ledger, or Mail mutation.
- Other Card statuses and PayPay/Konbini/Virtual Account retain the existing coarse mapping. A `CAPTURED` response is not downgraded by a historical error field.
- Webhook processing and direct reconciliation share the same canonical retrieval, validation, classifier, event recording, and status-application path. Browser normal/failure Return code is unchanged and cannot terminalize a Payment.
- The terminal code is retained only in the existing safe `fincode_payment_attempts.last_error_code` boundary. No schema, OpenAPI, Client, Testkit, or Artifact change is required.

## Ordering and Exactly Once

- Duplicate failure Webhooks reuse the canonical event identity and do not duplicate status history.
- Failure followed by delayed success cannot promote a terminal failed Payment and creates no Grant, Ledger, or Mail.
- Success followed by a late failure cannot destroy the succeeded Payment, CAPTURED Provider status, completed attempt, exactly-once Grant, or exactly-once purchase Mail.
- Challenge event payload alone is non-authoritative: an errorless exact re-query leaves the Payment `requires_action`; only a later exact re-query containing the approved terminal tuple changes it to `failed`.

## Focused Verification

- Isolated PostgreSQL 17 applied the existing 66 V2 migrations; Shared Preview migration application was zero.
- New terminal/no-challenge, challenge-first, direct reconciliation, duplicate/delayed/reverse-order, malformed/unclassified/unavailable tests: 5 tests / 69 assertions PASS.
- Full Fincode backend regression, including Card success, Browser Return non-mutation, PayPay, Konbini, and Virtual Account: 31 tests / 364 assertions PASS.
- Fincode concurrency/idempotency: 1 test / 22 assertions PASS.
- The first local Policy Unit run correctly failed because the prior guard required coarse `CAPTURED` mapping inside `V2FincodeWebhookService`. Scope was expanded only to move that invariant to the new classifier and add negative tests that reject removal of the terminal code or classifier call; no gate or assertion was weakened.
- Final local Policy Unit 181 tests, Policy Gate 1,594 tracked files, Quality Unit 4 tests, Quality Gate, Security Unit 10 tests, PHP syntax, JSON parse, and `git diff --check`: PASS. The standalone Security Gate requires canonical Composer/pnpm audit inputs and is left to the Required GitHub check rather than using synthetic inputs.
- Provider calls were Laravel HTTP fakes only. New real Payment, Card registration, Provider mutation, Webhook replay, artificial event, manual status mutation, Coin Grant, Mail, Shared Preview business mutation, Production mutation, and Secret value readback were zero.
- Required five checks, exact-head fresh Strict self-review, squash merge, canonical merged-main API-only Build, Shared Preview API-only Activation, and runtime readback remain pending Final Head.

## Delivery Boundary

- Migration created/applied: `0/0`; Public OpenAPI, Storefront Client, Storefront Testkit, and immutable Artifact bytes are unchanged and no Artifact is issued.
- Source-phase Build and Runtime Activation are zero. Merge-first delivery will perform one canonical API-only Build and one Shared Preview API-only Activation without Admin or Storefront Build/Activation.
- Existing failure Payments are read only during closeout; MIG-097 does not replay their Webhooks, invoke Provider mutation, or manually change their status.
- Storefront follow-up remains separate: clear the stale pending/resume state after the canonical failure Return so the product purchase page remains the final screen and does not client-navigate to Thanks. Save Card remains a separate Storefront task.
