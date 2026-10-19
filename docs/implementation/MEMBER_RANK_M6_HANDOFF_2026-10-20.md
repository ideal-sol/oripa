# Member Rank M6 — Public read contract / Draft handoff

## A. Source authority

- Task `MEMBERRANK-M6-20261020`; Issue none; R3; Strict Change; Application Runtime Activation deferred. Latest Human permits implementation, isolated verification and one Draft PR only. Ready, merge, auto-merge, runtime, operational migrations, feature activation and publication remain prohibited.
- Base protected main `1478b9ec8eec3a4c7f110485fcf7d0ecd433f5e4`, tree `cdba8f97c8f2838ecbb552eff86ec7ab0f6cf8ff`. Dedicated branch `feat/MEMBERRANK-M6-20261020`; primary and other worktrees are not modified.
- Before source freeze, main advanced to `0be79d94906a33edefc64c0b5b5728e2ab58ed18`, tree `a72af3ba2d64169aacbe8b441f027e664c2378cb`, through OLDDEPLOY-203: deploy script/test and separate Worklog append only. No M6 domain/candidate/policy collision. Move only M6's own Worklog insertion away from the EOF append; retain original fork without merging main. The PR event target Base SHA and original Source merge-base are reported separately. Human synchronization approval was requested, not inferred.
- v1.5 specification SHA256 `688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`; phased plan REV2 `b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`; execution prompts REV2 `83270c7a37a7bdc4279232fba2ce44295f49b7a776ddfa33fb2c881f966cdb62`.
- GitHub confirms M1 PR575, M2 PR577, M3 PR579, M7 PR580, M4 PR581 and M5 PR584 merged and included in the base. Formal handoff `MEMBERRANK-M5-TO-M6-SOURCE-AUTHORITY-20261020` supplies context, not authority to override v1.5.
- Final Head/Tree, PR URL, final-head checks, dated machine-readable Draft HOLD review and execution metrics are recorded on this Change's PR after source freeze. This committed document does not predict unexecuted final-head results or serve as later MERGE evidence.

## B. Source changes

- Add `GET /api/v2/me/member-rank`: authenticated User/SMS realm, private no-store and Vary Cookie, canonical request/version headers; the authenticated ID alone selects data. Reuse M3 read-only `project()`, never `materialize()`. Public allowlist excludes internal IDs, Admin reasons, audits and PII.
- Add anonymous `GET /api/v2/member-ranks`: OFF returns an empty list; ON returns seven fixed ranks in order from the setting effective at the current JST month start. Explicit no-store avoids both scheduled-month and activation cache staleness; cacheability is optional, not required.
- Personal progress uses this month's points and maintenance settings, not M3's previous-month judgment inputs. VIP next rank and beginner maintenance are null. Protection ends immediately before the first unskipped judgment; integer basis points format exact two-decimal purchase-bonus percentages and free-point type.
- New files: `apps/api/app/Domain/MemberRank/Services/V2MemberRankPublicReadService.php`, `apps/api/app/Http/Controllers/V2/V2MemberRankController.php`, `apps/api/tests/V2/MemberRankPublicApiTest.php`, `packages/storefront-client/src/member-ranks.ts`, `tests/ci/openapi/test_member_rank_public_contract.py`, and this handoff.
- Modified API route file; Public OpenAPI and bundle; Client generated types/index/test; Testkit generated contract/fixtures/index/test/export validation/package compatibility metadata; candidate manifest; exact Task-ID and operation inventory in policy validator plus its regression; appended Worklog. The PR enumerates every exact path.
- Reuse M4 point-product bonus display/settlement and M5 Gacha eligible filtering/cursor/Draw recheck unchanged. No second bonus calculator, accounting operation, Draw rule or SDK-side business authority.
- Migration creation/reservation zero; latest existing migration000093 unchanged. No Migration Allocation Lock needed. No Artifact Release Lock or publication. No overlapping M6 semantic hunk found in PR572/573/576/578/593; isolated Worklog additions preserve their entries. Superseded PR573 does not authorize immutable-ledger changes.
- Policy changes only accept the exact Human Task ID and the two added canonical operations. Existing Strict gates, exact paths, permissions, assertions, timeouts and fail-closed checks remain intact.

## C. Contract

- Existing unpublished candidate only: Bundle/Client/Testkit `2.0.0-alpha.46`, Public `2.0.0-alpha.42`, Admin `2.0.0-alpha.41`, Webhook `2.0.0-alpha.40`. Public operations81 →83.
- Public OpenAPI SHA256 `63aa72b538397ac8cedafd6268e0e87205cc0a27dd1da374af878cb81e5d786a`. Canonical generator produces Client/Testkit artifacts; package versions/minimum Public contract remain unchanged. Only candidate checksum/count metadata changes.
- Published alpha.45, all25 immutable records and Artifact12031381227 remain unchanged. Existing published archive checksum verified; its actual Client runtime passes four compatibility cases: optional catalog fields, unknown ineligible reason, optional purchase bonus and two unrecognized Problem codes preserving `ApiProblemError` rather than rejecting/deserializing incorrectly. The two catalog properties are exercised in one case.
- Client exposes thin `getMyMemberRank()`/`listMemberRanks()` facade operations using existing browser and request-scoped server transports. Testkit adds OFF/beginner/silver/VIP/protection/month-boundary/fractional-bonus/public-list fixtures and inherited auth Problem checks. No Admin/provider/secret data is exposed.
- No package publish, artifact dispatch, Release, Tag or customer Site repository change; no early installation of unpublished alpha.46 into a Site.

## D. Verification and evidence rules

- Task-owned PostgreSQL17/PHP8.4.23 containers use network-none, no published ports, synthetic Unix-socket database and cleared environment. API source is read-only, rootfs read-only, capabilities dropped, no operational credentials or Docker socket mounted. Isolation preflight passes; existing93 migrations applied only to the synthetic DB.
- API coverage includes authentication/SMS/Admin realm, actual User session cookie and revocation, IDOR query rejection, privacy/cache headers, OFF non-writing behavior, all seven ranks, actual-payment reach lines, free-bonus exclusion, JST month/year/leap boundaries, manual skip counts0–12, multi-month lazy projection, scheduled settings and current-month maintenance, preregistration500pt/replay/created_at invariance. Domain snapshots prove GET does not mutate rank, setting, ledger or wallet tables; normal authentication-session activity is separate.
- Development Client checks:40 tests PASS plus typecheck/lint/build/generated check. Testkit:52 tests PASS plus typecheck/lint/build/exports/network/generated checks. OpenAPI unit54 PASS; release unit95 PASS. Exact final-head repetition and backend regression counts are reported on the PR, not inferred from these development runs.
- Initial new API run:31 tests/339 assertions with one preregistration fixture error (`cutoff_at` was after the simulated activation instant); fix the fixture's explicit UTC boundary, not production behavior. Focused retry1 PASS. Initial contract test import failed because `jsonschema` was not an established dependency; use stdlib structural schema assertions without adding dependencies. Initial policy check correctly rejected stale81-operation inventory; update exact inventory to83. Retain failed-attempt evidence separately from passing reruns.
- Expanded development API run:38 tests/426 assertions PASS,0 failure/error/skip. Policy unit's stale fake-count mutation initially failed1/310; update its83-to82 corruption input so it again tests fail-closed rejection, rather than weakening the assertion. Final-head rerun remains separately reported.
- Full required final-head `policy-gate`, `quality-gate`, `security-gate`, `integration-gate`, `ci-gate`, all applicable checks and fresh exact-head review are mandatory. Pending/failed tests are not PASS. No browser/visual acceptance claim: this Change contains no Site/Admin UI. Deployed/provider/Production acceptance is outside scope.

## E. OPEN findings and boundaries

- **M5 OFF specification discrepancy retained:** v1.5 §6.3 says rank-conditioned Gachas cannot be drawn before activation; inherited `V2MemberRankGachaCondition::matches()` returns true while OFF and its test permits Draw. M5's historical record cites an earlier Human OFF override; the current M6 instruction explicitly requires reporting the discrepancy. M6 does not silently adopt that as v1.5 conformance or change Draw semantics. Human must reconcile authority and authorize a separate M5 Change if correction is required. The M5 eligible-only list still returns empty while OFF/anonymous.
- Existing M2/M3/M4/M5 PERF OPEN remains, including M5 synthetic1000-Draw p50/p95=7832.492/8955.46ms versus2000ms in its later recorded run, and host-specific initial data-readiness observations. These are inherited observations, not fresh M6 measurements or performance PASS. Optimization remains a separate authorized phase after Source phases, OLD Test reflection and Human browser acceptance.
- No unrecognized changes are overwritten, no parallel branch/worktree is cleaned, and no main synchronization occurs without any required Human approval. A later moving main requires fresh ownership/hunk analysis and final-head checks.

## F. Next phase and activation

- M6 Source remains Draft/unmerged even after all checks pass. Branch/worktree retained for Human review; no merge actor/squash SHA or merged-branch cleanup exists.
- Runtime unchanged; operational migration0; feature OFF unchanged; Worker0; Contract unpublished; Site unchanged. Isolated fixture ON is test data only, not Runtime Activation.
- Storefront UI handoff waits for approved Client publication. Follow-up dependency M9 → M8 is unchanged. Next Human decision is M6 Source review/Merge GO, separately from publication, runtime and feature GO.
- Draft review explicitly prohibits Ready/merge/runtime and cannot be reused for a later MERGE review. Any later approved merge requires fresh head-bound evidence and all canonical gates. Rollback now means discarding/revising unmerged source; no operational rollback is needed.
