# OPS-025 MIG-098 3DS Save Card Shared Preview Activation

## Task Governance

- Task `OPS-025`, Issue `#422`, Draft Pull Request `#423`, platform lane `plat-main / operations`, Change Lane `Strict Change`, Risk `R4`, Activation `immediate`.
- Base is exact protected main `52efea768b6cfba086f1e85523f2e9f561246af4`.
- Root-owned Task Policy is `/etc/ideal-sol/github-app/task-policies/OPS-025.json`, mode `0600`, SHA-256 `eaae357e24cc2885c70d37e6fb7a3edba8f4d0143d046e9b4dc92cce15a7cb23`.
- Exact allowed Repository paths are `deployments/OPS-025-mig-098-shared-preview-activation.json`, this report, and `worklogs/new_ver_main.md`. Application Source is unchanged.

## Stage 0

- Before Issue creation, GitHub Issue/PR history, Repository files and history, worklogs/reports, deployment records, local/remote branches, worktrees, Task Policies, and root-only evidence contained no `OPS-025`. The Task ID was not substituted or inferred.
- Local main, origin tracking main, live Remote main, and protected GitHub main were clean and equal at `52efea768b6cfba086f1e85523f2e9f561246af4`.
- MIG-098 Issue `#416` and PR `#419`, and GOV-021 Issue `#420` and PR `#421`, are closed/merged. Their task branches, worktrees, and Task Policies are cleaned. MIG-098 squash `ad078ecd1eebd68cd2443b347d387433177fd686` is an ancestor of protected main.
- GitHub had no open Issue or human-authored PR. Fifteen unrelated Dependabot PRs were open. No Platform task worktree or active shared OS lock conflicted.
- Active API was `oripa-v2-api:preview-MIG-097-e006c7a95592`, image ID `sha256:e782e21228fb59e01f518213da48352750e7bf36b97a6c135fa75517980fadba`, OCI revision `e006c7a95592e5a03eef0be7e59bfa995185171c`. It is the immediate rollback image for the planned replacement.
- API, Admin, PostgreSQL, and Redis were healthy with restart count zero. Storefront source `0a8bcdf09e1d0549dac7d1736b9cb868eb04f0e9` / Build ID `hjgAN8D6Bf2kOE71jmmh6` and Host Nginx were active with restart count zero.
- Nginx test/live profiles verified canonical and retain stable API upstream `127.0.0.1:8611`. Direct, test-domain, and live-domain health/public API smoke returned HTTP 200; both Storefront domains returned HTTP 200 for `/` and `/points` with no redirect.
- Shared Preview migration ledger was 66 rows, max batch 32, through `2026_09_22_000066_add_v2_verification_failed_user_state`. Migration `000067` was absent. Protected-main source contains exactly 67 PHP migrations and only `2026_09_23_000067_add_fincode_card_registration_3ds_authority.php` at or after allocation 000067; unexpected pending count is zero.
- Aggregate baseline contained Card rows `0` (`active=0`, `deleted=0`), Registration Intents `4` (`expired=3`, `reserved=1`), Payments `21`, Payment Point Grants `6`, and Point Ledger Entries `31`. No business row or PII content was read or recorded.
- Release Ledger was `latest_immutable=2.0.0-alpha.31`, `candidate=null`. Root free space was `27,155,279,872` bytes, above 20 GiB.

## Human Webhook Confirmation

- The Human confirmation for TEST/SANDBOX `customers.payment_methods.updated` at canonical URL `https://test.luxe-pack.biz/webhooks/v2/fincode` is accepted without Provider settings readback.
- Protected-main Repository and the active API route table both contain canonical `POST /webhooks/v2/fincode`.
- Provider Settings GET/change, Webhook replay/send, and artificial Provider event generation are all zero and remain prohibited.

## Human Reviewed Tree Authority

- The Human adopted `Engineering Safety Strict / Git Lite` for OPS-025 and explicitly declined GOV-022 and the six-path CI/Git scope expansion. Required Checks, fresh Strict self-review, protected main, PR review/merge, migration safety, Provider authority, Payment/Coin exactly-once, and Runtime Acceptance remain unchanged.
- OPS-025 no longer requires the API OCI revision to equal the squash merge commit SHA. The exact Final reviewed PR Head remains the build input and OCI revision.
- Activation authority requires the Final PR Head tree object and squash merge tree object to be identical, plus a zero content diff. A commit-SHA difference is accepted only through that exact byte-equivalence proof.
- Root-only closeout evidence and Issue `#422` must record Final PR Head SHA/tree, squash merge SHA/tree, equality result, zero content diff, Build workflow run, image digest, and the mapping `OCI PR Head -> common reviewed/merged tree -> Merge SHA`.
- Any tree mismatch, content diff, image/OCI mismatch, or protected-main drift blocks Migration `000067`, blocks Activation, and leaves the old API Runtime unchanged.

## Full Delivery Preflight

- A read-only end-to-end preflight completed at `2026-08-29T16:39:23Z` before this authority evidence update. Source change, Build, Migration apply, Runtime Activation, and Provider/business mutation during the preflight were all zero.
- Live protected main and the PR base remained `52efea768b6cfba086f1e85523f2e9f561246af4`; live Draft PR `#423` remained open, cleanly mergeable, and at Head `fc24322064401867e51ed89005bca2f892d94977`. Both Platform worktrees were clean and the merge-base was exact protected main.
- The preflight Head had all five Required Checks successful. This evidence update intentionally creates a new Final Head, so those runs are historical only and cannot satisfy the new Head.
- Canonical `preview-image-build.yml` can dispatch from protected `main`, validate the open internal PR and exact checked Head, run `image_mode=api-only`, build API once, skip Admin, and expose the workflow run, GitHub artifact digest, Docker image ID, archive digest, and OCI revision through the verified artifact manifest/read wrapper.
- Git and the squash wrapper expose the exact pre-merge base, returned merge SHA, both tree objects, and a direct content diff. The wrapper also revalidates the exact PR Head, scope, five checks, fresh self-review, mergeability, and squash method immediately before merge.
- The verified API image contains the merged-main migration root and supports the canonical Compose one-off migration runner without replacing the active API. Shared Preview is healthy at ledger `66`, batch `32`, latest `000066`; only `000067` is pending and current aggregate counts remain unchanged.
- API-only Compose Activation accepts the explicit verified image/OCI identity and does not require OCI revision equality with protected-main commit SHA. It replaces only API, preserves loopback publication `127.0.0.1:8611`, and does not mutate Nginx, Admin, Storefront, PostgreSQL, or Redis.
- Test and live Nginx profiles verify canonical on `127.0.0.1:8611`; Nginx config is valid and its fresh access/error log window is measurable. API/Admin/PostgreSQL/Redis, Storefront, and Nginx are healthy or active with restart zero.
- Platform Integration and Migration `000067` locks remain held by OPS-025; Preview Deployment Lock is free and acquirable before Activation. All three lock release paths and Task Policy/branch/worktree/Issue cleanup remain available after complete acceptance.
- No additional hard blocker exists across Final Head -> Required Checks -> API-only Build -> pre-merge drift gate -> squash merge -> tree equality -> Migration -> Activation -> Runtime Acceptance -> lock cleanup.

## Lock Handoff

- Platform Integration Lock was acquired by OPS-025 at `2026-08-29T15:46:52Z` after Issue, remote branch, and dedicated worktree establishment.
- Protected-main GOV-021 evidence fixed MIG-098 as the retained Migration 000067 holder. Competing holder count was zero; no transfer helper exists. MIG-098 was released and OPS-025 acquired the canonical 000067 OS lock immediately at `2026-08-29T15:47:15Z`.
- Migration 000067 remains held through complete Runtime Acceptance. Preview Deployment Lock remains free until its pre-activation gate. Artifact Lock is not required.

## Reviewed-tree Delivery Boundary

- This PR records Stage 0, Full Delivery Preflight, Reviewed Tree Authority, ordered activation plan, acceptance gates, rollback boundary, and public-safe evidence only.
- The new Final Head must pass focused local validation, all five Required Checks, and fresh exact-head Strict self-review. From that fixed Head through Build and merge, Repository Source is immutable.
- Delivery order is fixed: API-only Build from the exact Final reviewed PR Head, pre-merge protected-main drift gate, squash merge, tree equality and zero content diff, Migration `000067` apply exactly once, built-image API-only Activation exactly once, then read-only Runtime Acceptance.
- If protected main drifts after Build, the PR must update, all checks and self-review must repeat, and a new API image must be built. The prior image cannot become an Activation candidate.
- Runtime mutation remains prohibited until squash merge, exact main synchronization, and tree equality PASS. The pre-merge image is not Runtime authority by itself; it becomes eligible only through the Human-approved Reviewed Tree mapping.
- Canonical operational closeout evidence is Issue `#422` plus root-only `/var/lib/oripa-v2-evidence/OPS-025/`. No Application Source, workflow, wrapper, migration, OpenAPI, Client/Testkit, Artifact, Storefront, Admin, or Nginx source changes are in scope.

## Planned Acceptance And Failure Handling

- API-only Build must use the exact Final reviewed PR Head, produce one API image and zero Admin/Storefront/Artifact-release builds, and retain the old API rollback image. OCI/manifest mismatch or Build failure blocks merge and preserves the existing API.
- The pre-merge drift gate rechecks protected main against the Final Head base. Drift blocks merge and requires updated checks, self-review, and Build.
- Tree equality and zero content diff are mandatory after squash merge. Mismatch blocks Migration and Activation and prohibits use of the built image.
- Migration preflight rechecks DB health, exact pending set, lock holder, aggregate baseline, current backup/rollback policy, and root disk. Apply failure blocks Activation and retains the lock without manual repair.
- API-only Activation must preserve Admin, Storefront, PostgreSQL, Redis, Nginx config/reload, stable loopback upstream, private network, and rollback image. Activation failure remains Fail Closed under Governance.
- Runtime Acceptance covers API health, 000067 ledger/schema and zero false verification backfill, direct/test/live public regression, Storefront pages/dynamic data, active MIG-098 routes and authority source, Artifact ledger, service health/restarts, fresh 500/502/504 window, and before/after aggregate preservation.
- Provider Card Registration, Registration POST, Payment, Webhook replay/send, Provider event creation, manual Card/Payment/Coin mutation, Mail, Production, and Secret value readback remain zero.

## Source-phase Status

- Initial source commit `451693db737608d63fca16e3a63213f1f6762591` was pushed through the policy-aware GitHub App wrapper and Draft PR `#423` was opened against `main`.
- Policy Unit `181` tests, Local Policy Gate over `1,610` tracked files, deployment JSON parse, exact three-path scope, high-confidence secret scan with zero candidates, and `git diff --check` passed.
- Pre-resume Head `fc24322064401867e51ed89005bca2f892d94977` also passed all five Required Checks, but this Human-authority evidence update supersedes that Head. The resulting new Head must rerun focused validation, Required Checks, and fresh Strict self-review.
- The Reviewed Tree candidate evidence bytes pass Policy Unit `193` tests, Local Policy Gate over `1,610` tracked files, deployment JSON parse, exact three-path scope, high-confidence secret scan with zero candidates, and `git diff --check`. Required Checks and self-review remain pending until the exact committed Head exists.
- Migration created/applied: `0 / 0` for OPS-025. Migration `000067` was created and source-verified by MIG-098 only.
- API Build/Activation: `0 / 0`. Admin and Storefront Build/Activation: `0 / 0`. Artifact publication: `0`.
- Database business mutation, Provider, Card Registration, Payment, Coin/Point Ledger, Mail, Production, Nginx, and Secret mutation/readback: `0`.
- New Final-head validation/checks/self-review, API-only Build, pre-merge drift gate, squash merge, tree equality, Migration apply, API Activation, Runtime Acceptance, GO/HOLD decisions, lock release, and cleanup remain pending.
