# LINEUX-20261120 — integrated LINE management source handoff

Status: Draft source change, R3 / Strict Change / Application Runtime Activation
`deferred`. This document records the Human's integration specification v1.0;
it grants no deployment, provider call, Ready, merge or approval-plan authority.
Issue: none. Branch: `feat/LINEUX-20261120`. Dedicated worktree preserves the
occupied checkout and all unrelated worktrees. Base:
`3442dfb875630ed2009896657092032db12a6de4`.

## Behavior

- The LINE group immediately follows announcements: settings, chat, templates,
  deliveries and uploads. Existing URLs and permission boundaries are retained;
  `/line-uploads` uses `line.chat.read`, registration uses Owner's existing
  `line.bot.manage`. Individual chat activates the same group.
- The upload workspace reuses the existing signed intents, validation, manifests
  and CDN. It supports image, video with preview, imagemap and carousel assets,
  preview, kind filtering within the displayed page and pagination. Lists and
  finalization bind the active BOT and authentication generation. Existing
  sending/editing screens only select verified assets.
- The delivery list uses the shared Admin table, Japanese category/group/status
  labels and planned versus resolved recipient counts. Draft/unresolved counts
  display a dash. Recipient count never means successful delivery. Creator and
  per-status aggregate columns are removed only from the list; detailed history
  and underlying contracts remain.
- LINE-scoped form widths, label placement, standard buttons, wrapping and
  responsive layout are shared with settings, templates, chat and uploads.
- Verified LINE ID-token `name` updates `users.display_name` after existing
  account/session/identity checks. Missing, invalid or overlong names preserve
  the stored value. `full_name` is unchanged. NFC and control-character
  normalization protect display and message expansion.
- Linked chat names and `%username%` use display name, then full name, then
  `お客様`. Membership depends on identity linkage, never the name's presence.
  The existing frozen payload and retry key remain immutable after name changes.
- A trusted manual command can complement exactly one known linked member's
  missing name: `v2:line:backfill-profile-name MEMBER_UUID --human-approved
  --same-provider-confirmed --actor=OPERATOR_UUID`. It requires explicit
  operational authority and same-provider confirmation, validates the encrypted
  subject binding, bounds HTTP response/time, checks returned user ID and
  rechecks BOT generation/member binding under locks. It neither enumerates D7
  followers nor runs automatically. Its audit contains no profile-name value.
  This task does not execute that command against an operational environment.

## Finalize failure

The isolated PHP8.4.27 / locked AWS SDK test uses the real SDK with a fake HTTP
handler which closes the request stream. The previous unconditional `fclose`
reproduces a PHP TypeError; guarding it with `is_resource` permits both original
and preview publication. A rejected PUT still propagates its exception. Image
validation and HTTP failure semantics are unchanged. No real S3 call or failed
intent replay is performed. This reproduces the suspected mechanism; without
the historical exception it does not prove that mechanism caused the OLD Test
incident.

## Monthly quota and migration 117

The Human confirmed exclusive paid sending through this application. Existing
Human-proven accounts and periods remain the authority. Current/next JST periods
are generated under the existing singleton only for the active connected BOT
with the matching authentication revision and a usable prior baseline. New
periods start external opening usage at zero, conservatively inherit/lower the
limit, and carry unresolved exposure. Concurrent initialization creates one
period and one system audit. Past periods are never recalculated or rebased.

Provider quota/consumption reads run outside database transactions. The actual
send boundary checks a fresh provider limit before dispatch. Approximate usage
can stop admission but cannot add capacity or replace exact local accounting.
Lower limits apply to current/future reservations. Provider failure, unknown
initial provenance, generation change, unaccounted attempts and halted accounts
fail closed. A month change never clears a halt or an uncertain result. Existing
23-hour retry, cross-month reservation, payload and same-key guarantees remain.
Disabled/already handled/ineligible automated events do not need quota HTTP.
No new worker/timer is introduced and D4 enablement remains unchanged.

The Admin removes ordinary monthly baseline entry and shows local upper-bound
usage, reservations, uncertainty including uncarried prior exposure, remaining
capacity, origin, last provider observation and halt state. Existing privileged
baseline/reconciliation entry points remain for initial proof and incidents;
monthly automation does not grant incident recovery authority.

Migration `2026_11_20_000117_add_v2_line_quota_system_actor.php` is forward-only:

- Add nullable `line_quota_accounts.last_checked_at`.
- Add `line_quota_periods.baseline_origin` (existing rows default to `human`)
  and nullable `authentication_revision`.
- Make `verified_by_admin_id` nullable only with an explicit actor constraint:
  human requires an Admin; system requires no Admin, positive generation and
  zero external opening usage. Never attribute automatic approval to an Admin.
- Preserve existing append-only/delete/truncate/limit-reduction guards and every
  historical row. Existing migration files are untouched.

117 was allocated after remote/worktree/coordination inspection under the shared
Migration Allocation Lock. Its logical reservation remains with this Draft.
Only disposable synthetic PostgreSQL received migrations001–117. Operational
application requires the separate approved forward-only migration route before
new code is activated. Schema down is prohibited; recovery retains117/history
and uses separately approved source/image recovery or a forward correction.

## Contract and scope

Admin quota adds optional `baseline_origin` and `last_checked_at` response
fields; the Admin bundle and generated TypeScript are regenerated. No route,
Public API, Storefront, provider message payload or security scheme is changed.
No package/lockfile, CI workflow, image recipe, infrastructure, point, payment,
draw or inventory behavior changes. CI/database control edits only register the
new exact paths and forward-only migration; no check is relaxed.

## Verification and review

Focused evidence and exact final Head/Tree/inventory/check states are recorded
on the single Draft PR. Reproduction uses synthetic identities/data and fake
LINE/S3 HTTP only, the existing locked dependencies, PHP8.4.27 and PostgreSQL17.
The disposable media fixture additionally installs the already specified
FFmpeg7:5.1.9 test encoder without updating any package or changing a product
image, dependency manifest or lockfile. No Artifact Build is performed.

Commands (from the appropriate application/repository directory):

```text
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineLoginVerticalSliceTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineProfileBackfillTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineChatReceiverTest.php tests/V2/LineAutomatedTest.php tests/V2/LineDeliveryManagementTest.php
vendor/bin/phpunit -c phpunit.xml tests/Unit/V2LineMediaStorageTest.php tests/Unit/V2LineMediaInspectorTest.php tests/Unit/V2LineProfileNameTest.php
pnpm exec vitest run test/admin-navigation-hierarchy.test.tsx test/line-uploads.test.tsx test/line-delivery-workspace.test.tsx test/line-chat-csp.test.ts --maxWorkers=1 --no-file-parallelism --testTimeout=30000
pnpm exec playwright test --config playwright.line-ux.config.ts --grep 'LINE screens share|chat navigates'
pnpm exec tsc --noEmit
node scripts/generate-admin-contract.mjs --check
python3 scripts/ci/openapi_contract_gate.py --repository . --check-bundles
python3 scripts/ci/policy_gate.py --repository .
python3 -m unittest discover -s tests/ci/policy -p 'test_*.py'
python3 -m unittest discover -s tests/db -p test_v2_database.py
```

Browser fixtures use fake Admin APIs and a local development server. That
harness bypasses development CSP for webpack; the separate CSP regression tests
assert the actual proxy policy. Neither is deployed Human browser acceptance.
Initial fixture errors, synthetic DB lock exhaustion and overloaded test
timeouts are retained in the task's evidence and distinguished from final PASS.
No assertion, authorization rule or failure condition is removed to pass tests.

Known inherited limitations: D5's documented FFmpeg SEV-2 was accepted for source
only, with exact-image SBOM/reachability review still required before runtime.
The unchanged Next.js version emits a dependency advisory warning. This task
does not waive either dependency issue or modify dependencies. New-change
findings and final source review are reported separately on the PR.

## OLD Test handoff

**DRAFT_PREVIEW_INELIGIBLE. Image Mode: `normal` (API + Admin).** Migration117,
identity kernel modifications and exact CI/database inventory registration are
outside the current Draft Preview fast path. The current committed plan list
does not authorize this task; no approval plan is created or modified.

Use the existing merged-source route described in
[OLD Test Draft Preview](../operations/deployment/old-test-draft-preview.md):
obtain separate Human review/approval for the normal source/migration route,
complete Required Checks5/5 and fresh Strict review for the final head, then
follow separately approved source merge, main artifact/authority and Canonical
Executor procedures. Runtime migrations, image preparation, activation and
worker effects require their own exact authority. Do not dispatch Draft Preview
for this source or reuse another task's plan. This task ends at Draft PR.

Human browser acceptance remains pending for menu placement, all four media
formats (including JPEG/PNG and video preview), asset selection from templates,
chat and deliveries, Japanese table/count semantics, all device widths, linked
name/fallback and `%username%`, monthly quota/reservations/halt display, actual
chat receive/reply, authentication, permissions and runtime CSP. Any actual
provider send requires separate authorization. Recheck active BOT generation,
storage/CDN configuration, previous November ledger continuity and exact-image
security before activation. No production or OLD Test runtime is changed.

## PR717 focused follow-up: chat search and quota observations

The additional Human instruction retains this Change/branch/Draft PR and
Strict/deferred status. The reviewed starting head is
`fb2802c7186ea8325b52b4c3f674d5c526b7b57f`. Search now uses the nonempty LINE
display name, otherwise the registered full name (including NULL/empty/space-only
LINE names). The correlated identity predicate, active/restricted member states,
revoked/superseded/closure exclusions, status/unread filters and pagination are
unchanged. Literal LIKE metacharacters remain escaped; the pattern remains a
bound SQL parameter. Profile acquisition/storage and display behavior are unchanged.

Quota/Sender production source is deliberately unchanged. The initial campaign
observation precedes whole-campaign admission and unknown-exposure creation;
the post-prepare observation protects the actual send after a separate commit.
A lower cap, provider failure or BOT generation change between those points
must still stop HTTP and retain the committed exposure/key. Reusing the earlier
observation would miss that change. An observation before exposure also detects
usage which a newly added unknown charge might otherwise obscure. The post-429
observation classifies a later provider response and cannot reuse a pre-send
result. Later batches/retries require fresh observations; no cross-request cache
or expiry policy is introduced. Monthly admission already avoids HTTP when its
period exists; missing-month generation performs its own bounded observation.

All measurement uses disposable PostgreSQL17 schemas and fake HTTP with stray
requests forbidden. Count only GETs to `/v2/bot/message/quota` and its
`/consumption` endpoint; OAuth and message POSTs are separate. Campaign resolve
performs two additional GETs beyond the send-only count. Individual chat and
automated dispatch with an existing monthly period use admission0 / send2.
Exact measured campaign/retry/month totals, initial fixture failures, final
focused commands/results and exact-head CI are recorded on PR717.

Large diagnostics completed100 Push with204 GETs (resolve2 + send202) and500
Push with1004 GETs (resolve2 + send1002). **1000 Push in one campaign did not
complete in this synthetic environment:** the unchanged five-second Phase A
validation budget rejected the first batch. A separate isolated diagnostic
measured4 GETs (resolve2 + pre-prepare2),0 message POSTs,0 attempted batches and0
accepted batches. This is a throughput HOLD, not a successful1000-recipient
delivery or an inferred2004-GET result. The diagnostic failure remains recorded.
No send deadline or reservation check is relaxed. Its per-batch revalidation
cost requires separate bounded performance investigation and OLD Test capacity
verification; quota HTTP caching does not resolve that database work.

Keep the long Push diagnostic fixture/results with task evidence; normal
regression uses actual first/subsequent Push and1000-recipient Multicast plus
all safety cases. This separates a measured throughput limitation from the
unchanged fail-closed contract, without counting the failed diagnostic as PASS.

Focused regression command (API directory; disposable synthetic DB, fake HTTP):

```text
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryTest.php tests/V2/LineChatReceiverTest.php tests/V2/LineAutomatedTest.php --filter 'test_name_search|test_unlinked_reply|test_suspended_and_revoked|test_read_watermark|test_quota_get_measurements|test_fresh_post_prepare|test_quota_month|test_quota_unknown|test_quota_parallel|test_quota_lowered|test_monthly_|test_commit_before_send|test_http_deadlines|test_expired_retry|test_switch_and_unfollow|test_result_commit|test_automated_handoff|test_second_notification|test_concurrent_success'
```

That command passed26 tests /297 assertions on application/test head
`92a435a3f347bcdb353e6d58eeec3a8a577ac67b`. The subsequent record-only commit
does not change those application/test bytes; reuse that evidence, not old-head
Required CI. Final exact-head checks and source review remain on PR717.

| Measured path | GETs (unchanged before/after) | Result |
|---|---:|---|
| First campaign batch / subsequent batch | 4 / 2 | PASS |
| 100 Push including resolve | 204 | PASS |
| 500 Push including resolve | 1004 | PASS |
| 1000 Push, first batch before guard stops it | 4 including resolve | HOLD,0 message POSTs |
| 1000 Multicast,2 batches including resolve | 8 | PASS |
| Chat / automated / manual resend | admission0 + send2 | PASS |
| Standalone429 attempt including classification | 4 | PASS |
| Same-key retry | 2 | PASS |
| Missing-month admission / repeated admission | 2 / 0 | PASS |
| Month-end campaign / next-month retry | resolve2 + send4 / retry2 | PASS |

The original head's Integration Database CI failed in the historical
LineBotMigrationUpgradeTest fixture (quota table absent when117 runs) and
LineDeliveryManagementTest's FFmpeg media-generation fixture; its summarized
log also lists Paidy failures. These remain unresolved, outside the explicitly
bounded search/quota-GET follow-up. Neither diagnostic HOLD nor old CI failure
is waived by the26 focused passes. No migration/media/payment/CI implementation
change is inferred. The initial follow-up push also carried the old40-path PR
body in its event; update the body to41 paths before pushing this final record
commit, without a manual workflow rerun or weakening the inventory gate.

Migration117 and its logical reservation, API/contracts/generated artifacts,
dependencies, security controls and workflow/CI files remain unchanged by this
follow-up. `DRAFT_PREVIEW_INELIGIBLE` / Image Mode normal remains. Human OLD Test
acceptance additionally checks name search with/without LINE names and literal
search symbols. Before Ready/Merge, Human browser acceptance remains mandatory;
this handoff does not authorize merge-first validation or a Preview exception.
