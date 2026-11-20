# LINEUX-20261120 — integrated LINE management source handoff

Status: Draft source change, R3 / Strict Change / Application Runtime Activation
`deferred`. This document records the Human's integration specification v1.0
and the subsequent LINE UX/CSP/automatic initial quota v1.1 decision;
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
  preview, API kind filtering before 30-item pagination and newest-first stable
  ordering (`created_at DESC, id DESC`). Lists and
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

Historical v1.0 decision below: the first Human-baseline prerequisite is
superseded by the v1.1 automatic-initialization section at the end. Existing
human/system records and monthly continuation safeguards remain applicable.

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

## PR717 CI and Push performance repair (supersedes the earlier HOLD)

This follow-up starts from `03bbe8c0190740fab7347d7a37a16a10557f5b39`
on the same Draft PR/branch; base remains `3442dfb875630ed2009896657092032db12a6de4`.
Risk R3, Strict Change, activation deferred. Final exact HEAD/Tree, inventory,
test results and Required Checks are recorded on PR717. Old-head checks never
substitute for new-head checks; no manual rerun, dispatch or local build.

### Integration Database diagnosis

- Migration fixture regression:117 ran in the initial pre-108 loop, before112
  created `line_quota_accounts`. Exclude117 from that loop, then apply it after
  108–116 with an existing human baseline. Verify unchanged baseline data,
  human/system actor constraints, quota/terminal-history guards and forward-only
  behavior. Migration001–117 bytes are unchanged. Focused result:1 test54 assertions.
- Media fixture regression:Integration Database uses the Compose Preview target,
  which deliberately ships ffprobe but no ffmpeg. Reproduction stderr is
  `sh: 1: exec: /usr/bin/ffmpeg: not found`, exit127. This is distinct from the
  integration-suite host, where FFmpeg is installed. Debian5.1.9's libx264 encoder
  successfully generated both synthetic black MP4s with one thread; the same
  version's package was extracted only into test evidence, with no package upgrade.
  `SyntheticLineVideo` supplies real H.264/yuv420p bytes with provenance/SHA256.
  Real ffprobe inspection, corrupt-file/preview-ratio rejection and four-format
  finalization remain. Focused finalization1/30 and inspector7/49 PASS in the
  encoder-free Preview image; no validation relaxation or test skip.
- Paidy:the old CI summary mixes two actual errors with subsequent numbered
  Risky entries. PaidyGrace alone passes16/67 both on base and the starting source.
  Running the preceding FinancialAdmission cleanup reproduces
  `Test code or tested code removed error handlers other than its own` and the
  equivalent exception-handler warning; capture/grant assertions still pass.
  Base reproduces7 tests45 assertions,6 Risky and exit1 with `--fail-on-risky`.
  The cleanup boots a Laravel application outside the test lifecycle and leaves
  handlers behind. Its existing disposable-schema reset now releases bootstrap
  handler state in `finally`. Ordered regression17/76 passes with `--fail-on-risky`.
  No Paidy deadline, admission, capture/refund, financial ledger or runtime change.

### Measured performance and safety

Synthetic PostgreSQL17, PHP8.4.27, existing locked vendor and Fake LINE HTTP.
An external instrumented copy records method wall time and `DB::listen` SQL
counts/times without bindings. Production source has no instrumentation.
Baseline is the starting source; after is this repair's source. Measurements
include instrumentation overhead and normal host variance, not a runtime SLA.
Baseline sends only the first Batch per measured campaign; the after run fully
completes1/100/500/1000. Previous100/500 full-run GET evidence remains separately
valid for unchanged provider call paths.

| Push recipients | Recipient resolution ms before / after | Batch insertion ms before / after | Initial quota cover ms before / after | First Phase A ms before / after | First Phase B ms before / after |
|---|---:|---:|---:|---:|---:|
| 1 | 16.282 / 27.293 | 17.713 / 4.313 | 12.221 / 13.420 | 26.021 / 35.426 | 23.378 / 24.851 |
| 100 | 25.110 / 12.456 | 2428.325 / 157.935 | 1930.307 / 14.746 | 1251.873 / 21.926 | 25.837 / 21.699 |
| 500 | 72.782 / 96.921 | 10452.929 / 1025.737 | 8244.912 / 39.372 | 4345.780 / 50.337 | 40.889 / 35.668 |
| 1000 | 137.496 / 209.246 | 15713.316 / 2301.780 | 12373.002 / 141.179 | 5337.436 / 54.143 | not reached / 26.921 |

Before, insertion time includes repeated per-Batch quota cover; after, the single
campaign cover is separate. For1000, comparable registration+reservation is
15,713.316ms before and2,442.959ms after; full resolve is16,013.068→2,844.447ms.

| Push recipients | Resolve SQL before / after | First-send SQL before / after | Resolve quota SQL ms before / after | First-send quota SQL ms before / after |
|---|---:|---:|---:|---:|
| 1 | 64 / 62 | 97 / 96 | 20.300 / 21.310 | 21.890 / 21.210 |
| 100 | 1846 / 260 | 788 / 94 | 1447.440 / 20.160 | 1110.110 / 20.090 |
| 500 | 9064 / 1078 | 3588 / 94 | 5962.360 / 59.790 | 3695.230 / 31.990 |
| 1000 | 18085 / 2102 | 7041 / 95 | 8646.610 / 133.830 | 4355.060 / 29.430 |

At1000, first-send quota SQL count7012→36 and maximum identical SQL repetition
2000→8. Row-lock statement aggregate execution/acquisition time at1000 is
588.610→8.080ms in resolve and2.940→5.230ms in first send. These are measured
SQL durations including acquisition, **not isolated lock-wait durations**; they
must not be represented as zero contention or a production lock-wait SLA.
The two-connection regression separately enforces100ms lock_timeout and55P03;
shared broadcast/chat/automated capacity and actual writers remain serialized.

| Metric,1000 Push | Before | After |
|---|---:|---:|
| Registration + quota reservation | 15,713.316ms | 2,442.959ms |
| First Phase A | 5,337.436ms | 54.143ms |
| Resolve + first-send SQL | 25,126 | 2,197 |
| Quota GETs through first send | 4 at failure | 6 |
| Full campaign Quota GETs | not completed | 2,004 |
| Message POSTs | 0 | 1,000 |
| Full campaign completion | HOLD | PASS |

The first failure was the PHP `hrtime` five-second validation guard after quota
prepare, **not** PostgreSQL statement_timeout, lock_timeout or transaction_timeout.
All remain unchanged. No provider POST, attempted Batch or accepted Batch existed
at the baseline stop. After:1000 recipients,1000 distinct Batch/retry identities,
1000 accepted quota units and completed campaign; replay of the accepted first
Batch produces no extra HTTP.100/500 remain204/1004 GETs. First/subsequent send
remain4/2 GETs (plus resolve2);1000 Multicast remains8 GETs total.

`cover()` now groups immutable Batch IDs by BOT and month under the existing
singleton lock, validates every generation/account, carries unknown exposure,
counts missing recipients in bounded groups and checks all deltas before bulk
inserts. Already reserved campaigns still check lowered limits. No observation
survives the call. `resolve()` registers all campaign Batches/recipients and
reserves once within the same original transaction; an injected insertion
exception verifies complete rollback. Standalone chat/automated/manual admission
still reserves immediately. No new worker, cache, timer, dependency or migration.

Sender/recipient safety boundaries are unchanged:Phase A exposure commit,
fresh pre-Phase-B provider observation, phase B recipient/generation/limit check,
unknown retention,429 re-observation, immutable payload/key,23-hour retry,
month crossing, send pacing and historical audit. CI includes a bounded1000-row
regression that sends first/middle/last and checks query counts and the full
ledger; the longer full-send measurement stays outside routine CI.

Additional commands (API directory; disposable synthetic PostgreSQL only):

```text
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineBotMigrationUpgradeTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryManagementTest.php --filter test_four_media_formats_finalize_real_bytes_and_reject_changed_generation
vendor/bin/phpunit -c phpunit.v2.xml tests/Unit/V2LineMediaInspectorTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/PaidyFinancialAdmissionTest.php tests/V2/PaidyGraceTest.php tests/V2/PaidyPaymentTest.php --fail-on-risky
```

Final Required5/5 and test totals are recorded against the exact new PR HEAD,
separately from historical evidence. No new SEV-0/1/2 is accepted. The historical
synthetic1000-Push throughput HOLD is resolved; inherited D5/D6 limitations and
exact-image/runtime acceptance remain. No incremental API contract, migration,
dependency, authentication/security-boundary, workflow or infrastructure change.
`DRAFT_PREVIEW_INELIGIBLE` / normal remains; no approval-plan change. OLD Test
must use the separately authorized normal route, apply unchanged117 before
activation, and check real pacing/provider latency and shared monthly capacity.
Synthetic throughput PASS does not authorize runtime or real LINE sends.

## v1.1: media selection, enforced CSP and automatic initial quota

Human's additional `line-ux.md` instruction supersedes the first-baseline manual
prerequisite. Starting HEAD is `f8023cb4da3c506ef2d6d43a4f5ef5f5dd6e0b09`;
base, Change, branch and Draft PR717 remain unchanged. Final HEAD/Tree, complete
incremental/cumulative inventory and exact-head CI are recorded on that PR.

### Media and CSP

The optional `kind` query filters image/video/imagemap/carousel before paging.
Omitting it still lists all kinds. Current connected BOT and authentication
generation constrain both the list and asset validation. Owner-only upload and
existing Owner/Admin/Operator read/edit permissions remain unchanged. The UI
resets page/candidates on type changes, reloads page1 after upload, distinguishes
errors from empty state, and checks the current BOT/generation before accepting
a candidate. Send-time asset validation remains authoritative if the BOT changes
after selection. Optional list BOT/generation fields support this UI check.

Real Chromium uses the production-mode Admin server with `bypassCSP: false`.
Nine tests cover direct chat, settings/templates/deliveries navigation at1440
and390px, real PNG/H.264 MP4/WAV playback, and uncertain reply round-trip with
the same request key and document time origin. All nine pass without a CSP
violation. A synthetic403 attachment response is rejected in the UI; the API
regression independently verifies unauthenticated401 and existing unsend guards.
The transition defect does not reproduce with the current navigation. No proxy,
CSP, navigation or chat-state source change was needed, and no additional reload
or CSP relaxation was introduced. API authentication is mocked only in browser
fixtures; browser results do not claim runtime login verification.

### Initial quota and approximate usage

The [official quota](https://developers.line.biz/ja/reference/messaging-api/#get-quota)
and [consumption](https://developers.line.biz/ja/reference/messaging-api/#get-consumption)
responses supply a numeric monthly limit and approximate usage, including
Official Account Manager sends. `none` does not establish an application-owned
numeric allowance. A new account therefore requires a valid nonnegative numeric
limit and usage. Invalid/missing values or provider failure never manufacture
capacity. Usage at or above the limit is recorded honestly with zero remaining.

First quota viewing/admission obtains HTTP outside a transaction, then takes the
existing singleton and rechecks active/connected BOT, generation and JST month.
With no existing account or ambiguous send history it atomically records the
account, `system_initial` opening and system audit. No fictitious Admin approval
is stored. A subsequent call preserves the opening. Initialization and quota
reservation both commit before any message POST. Campaign/chat/automatic/manual
resend share the existing admission path; nested callers preflight before their
transaction. No timer, worker, cache or new dependency is introduced.

Opening300 plus100 accepted units means used400; a provider cumulative400 is
never added again. A lagging350 cannot reduce charges or create more capacity.
If provider usage exceeds opening plus exposed internal units, its cause cannot
be distinguished reliably from delayed initial approximation or external sends.
The separate `provider_usage_exceeds_estimated_ledger` reason retains a
conservative halt requiring incident reconciliation. This is not claimed to be
an exact external-send diagnosis. Legacy attempts/charges without a usable
account similarly stop as `initial_history_requires_reconciliation`. Normal
uninitialized accounts need no manual baseline; ambiguous incidents are not
silently rebased. Existing halted accounts, same-BOT generation changes and
unknown exposure never auto-clear. New BOTs have separate ledgers.

Normal following months keep origin `system` and opening0, inheriting the
existing conservative limit and unknown carry. Phase A/B, fresh pre-send
provider checks,429 checks,23-hour retries, frozen keys/payloads, recipient
revalidation, pacing and whole-campaign reservation remain unchanged. Admin
distinguishes pending, initialized, managed, limit reached, provider error and
halted, and explicitly labels estimated usage/remaining capacity.

### Migration118 and activation order

The shared allocation lock covered inspection of live Remote heads, local refs,
worktrees, policies and coordination reservations.118 was free and is now
reserved for `2026_11_20_000118_add_v2_line_quota_initial_origin.php`.
Migration001–117 bytes are unchanged.118 adds `system_initial` with no Admin,
positive generation matching both BOT and account, and nonnegative opening and
limit. Only this new origin may truthfully record opening above its actual cap.
Human/system meanings and all append-only/history/limit-decrease guards remain.
The upgrade fixture applies118 after116/117 and checks unchanged historical
rows, valid/invalid actors/generations, immutable history and forbidden down.

Only disposable synthetic PostgreSQL received001–118. Any separately authorized
normal activation must apply117 then118 before deploying this new code. Do not
run new code against117-only schema or down-migrate118. Recovery retains schema
and history and uses reviewed compatible source or a forward correction.
`DRAFT_PREVIEW_INELIGIBLE`, Image Mode normal, remains unchanged. No approval
plan was created or edited; this handoff authorizes no runtime operation.

### Verification and OLD Test handoff

Focused commands (API commands use disposable synthetic PostgreSQL/Fake HTTP):

```text
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineBotMigrationUpgradeTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineChatReceiverTest.php tests/V2/LineAutomatedTest.php
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryManagementTest.php --filter 'test_quota|test_four_media_formats|test_preview|test_missing'
pnpm exec vitest run test/line-uploads.test.tsx test/line-delivery-workspace.test.tsx test/line-chat-csp.test.ts test/admin-navigation-hierarchy.test.tsx
pnpm exec playwright test --config playwright.line-csp.config.ts
pnpm exec tsc --noEmit
python3 scripts/ci/openapi_contract_gate.py --repository . --check-bundles
python3 -m unittest discover -s tests/ci/policy
```

The CSP server required a local `next build` test compile: one initial stale
generated-type failure and one successful compile after bundle/client ordering
was corrected. No distributable Artifact/image build or manual workflow dispatch
was requested. Failed fixture/diagnostic runs are retained separately; final
results and full-send measurements are recorded below/on the exact-head PR.
Existing Paidy repair evidence is reused only for unchanged financial fixtures;
there is no new financial source or fixture change in v1.1.

OLD Test Human checks remain: upload enough mixed assets to span pages, verify
new uploads/type switches/non-Owner selection, navigate all chat entry paths and
play attachments on mobile, preserve an unknown reply across navigation, inspect
automatic first quota and monthly states, verify actual image security/pacing
and provider behavior under separate authority. Synthetic API/browser PASS does
not replace these runtime acceptance checks. Inherited D5 FFmpeg SEV2, D6
attachment/unsend SEV3 and the existing Next advisory remain separately tracked.

### v1.1 final local results

- Migration upgrade1/68 PASS; management quota/real-media4/65 PASS.
- Chat/automated full42/431 PASS. Final unauthenticated attachment1/17 PASS.
- Delivery full43 executed:42 PASS, one FAIL in the old external-send fixture
  because a newly selected BOT now needs its own provider observation. The
  missing zero-cap fake is added without weakening its no-inherited-capacity
  assertion. Final focused10/135 PASS includes that case, all six new initial
  scenarios, existing human baseline,1000 rows and explicit resend. The old
  full-run failure remains recorded; it is not relabeled a successful full run.
- Browser CSP9/9, UI36/36 plus latest upload7/7, TypeScript/scoped ESLint,
  OpenAPI bundles/base compatibility, policy277, DB helper70 and secret scan0
  findings PASS. Development UI harness keeps its original6 cases; actual CSP
  cases run separately against the production-mode server, with no bypass.
- External full-send profile1/33 PASS. It executes actual domain methods with
  synthetic DB/Fake LINE HTTP and test-only zero pacing, instrumenting method
  time and SQL without bindings; no production instrumentation or timing limit
  change. All campaigns begin without a Human baseline, limit2000/opening300.

| Push recipients | Resolve ms | Batch insertion ms | Initial cover ms | First Phase A ms | First Phase B ms | Resolve / first-send SQL | Quota GET | POST | Completion |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 1 | 181.135 | 6.863 | 17.021 | 37.979 | 48.209 | 72 / 98 | 6 | 1 | PASS |
| 100 | 343.780 | 215.170 | 20.224 | 51.581 | 79.703 | 270 / 96 | 204 | 100 | PASS |
| 500 | 3838.588 | 3192.557 | 214.382 | 64.170 | 30.172 | 1088 / 96 | 1004 | 500 | PASS |
| 1000 | 5983.019 | 4878.257 | 187.584 | 259.977 | 149.843 | 2112 / 97 | 2004 | 1000 | PASS |

For1000, registration plus reservation is5065.841ms and Phase A259.977ms,
well below the unchanged five-second Phase A guard. Resolve itself is not that
Phase A deadline. Full1000 has1000 Batches/recipients,1000 accepted quota units,
completed campaign, and accepted-Batch replay adds no HTTP.

Historical f802 profile used a preexisting Human baseline: registration plus
reservation2442.959ms, Phase A54.143ms, resolve/first-send SQL2102/95,
2004GET/1000POST. Current automatic initialization adds10 resolve SQL and2
first-send SQL, not per-recipient quota work. Current wall times include
concurrent synthetic regression load and cannot establish a controlled
before/after latency ratio. The measured safety bound and full-send outcome
pass; no claim of a faster runtime or production SLA is made.

GET counts remain100/500/1000 Push204/1004/2004, first send4 and later send2
plus resolve2. Full regression also measures1000 Multicast8,429/retry4/2,
same-key and month behavior. No provider cache or omitted safety observation.

Final focused command:

```text
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineDeliveryTest.php --filter 'test_initial_|test_thousand_push_|test_management_snapshot_without_fresh_mfa_and_explicit_manual_resend|test_quota_automatic_opening_balance|test_quota_external_send_halts'
vendor/bin/phpunit -c phpunit.v2.xml tests/V2/LineChatReceiverTest.php --filter test_private_attachment_is_encrypted_and_authenticated_and_unsend_hides_it
```

The external full-size diagnostic uses the existing LineDeliveryTest fixture
through a temporary instrumented copy and stays outside normal CI. The committed
bounded1000 test covers complete reservations and first/middle/last dispatch.
New-head Required CI is observed separately; no old-head success is substituted.
