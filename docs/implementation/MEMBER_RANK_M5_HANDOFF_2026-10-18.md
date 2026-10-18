# Member Rank M5 — Draft HOLD / M6 handoff

## Authority and disposition

- Change `MEMBERRANK-M5-20261018`; Issue none; R3 / Strict Change / Application Runtime Activation deferred.
- Formal M4 source: `36d37ac57d84c127dfd3ed54f37803bbd671b802`, merged PR581. Dedicated task branch/worktree; primary and other task worktrees are preserved.
- Human specification v1.5 SHA256 `688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`, especially sections11/16; phased plan REV2 SHA256 `b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`.
- Latest M5 execution instruction overrides the older feature-OFF wording: configured conditions are treated as unspecified while OFF. No operational activation is authorized.
- Draft only. Ready, merge, auto-merge, operational migration/runtime/worker activation, OLD Test/Production, feature ON and contract publication require separate authority. CI recovery Issue582/PR583 remains independent and is not incorporated.

## Implemented contract

- Version-level `member_rank_mode` (`none`, `at_least`, `only`, `at_most`) and nullable `member_rank_level`1–7. Validation and PostgreSQL reject incomplete/mismatched pairs; signup_once remains none/null. Copy and version clones preserve conditions. Published conditions cannot change, including after unpublishing.
- Audience and rank are independent AND conditions. Existing sale-state handling remains; eligibility priority is anonymous, audience/registration, rank, daily usage. Rejected rank Draw returns403 `GACHA_MEMBER_RANK_NOT_ELIGIBLE`.
- Draw takes the User Lock before capturing the instant; M3 Resolver materializes lazy monthly state inside the transaction. Requests persist the effective immutable level; Public result/replay/history expose its fixed code/name/level. OFF and QA retain null snapshots. No probability or point-accounting authority moves to clients.
- Enabled login_daily counts completed non-QA requests across all login_daily Gachas for one JST day. OFF retains per-Gacha limits; signup_once is excluded; standard daily limits remain per Gacha.
- Public summary/detail/presentation and Login summary/detail expose conditions. Login listing now includes optional member-specific eligibility and uses private/no-store with Vary Cookie. Optional `member_rank_eligible_only` lists conditioned, currently eligible standard Gachas with existing cursor semantics; anonymous/OFF yields no rank-benefit list.
- Admin creation/composition/edit/copy/list/detail add conditions and published locks. Existing audience is labelled 対象ユーザー; the already-correct 景品ランク設定 menu is preserved.
- A tagged composition-save failure discovered by the new tests is corrected: the two tag junction tables have no timestamp columns. No schema columns or existing assertions were removed.

## Migration and candidate

- New forward-only `2026_10_18_000093_add_v2_member_rank_gacha_conditions.php`; allocation under Migration Allocation flock after all-ref/reservation scan. Existing000001–000092 bytes remain unchanged.
- Supplemental version trigger composes with the existing typed-version/audience guards. Draw snapshot guards reject rewrites and deletion of recorded ranks; immutable history requires forward fixes, not down migration.
- Only task-owned network-none synthetic PostgreSQL is used. Operational/Staging/Production applications:0. Fresh2, rollback refusal and exact schema/ledger dump-restore evidence are required separately from functional tests.
- Continue the existing unpublished bundle/client/testkit alpha.46 / Public alpha.42 candidate. Public bundle checksum: `244463e32a42ec7130e64eed02265917f44596d824f4c4466ab7bc7a609d7a93`. Released alpha.45 and25 immutable entries remain byte-equivalent. No artifact publication; M6 extends this candidate.

## Validation and open gates

- Pre-freeze core M5 tests:23 tests/122 assertions PASS; initial tagged-save errors and missing synthetic SMS fixture were reproduced and corrected, not attributed to CI. Additional HTTP/DB/concurrency cases require their separate completed run.
- Pre-freeze Admin typecheck and condition-component2 tests PASS. OpenAPI condition tests2, exact Task ID/path tests2 and policy source validation PASS. Client39 tests PASS; Testkit initially failed for an unbuilt site-schema workspace dependency and is being rerun after its build.
- Legacy93-test Draw/Login/presentation run, expanded M5 cases, real-backend PC/mobile browser, full relevant rank/preregistration/first24h regression, final-head source/security/quality and CI are not yet accepted. The PR and task evidence must report each result and failure explicitly; no overall PASS is claimed here.
- Main initially has failed integration/ci gates; inspect actual final-head checks rather than assuming M5 failures share that cause. No gate, timeout, assertion, skip or workflow is weakened.
- Retain prior M2/M3/M4 OPEN/PERF findings. Speed optimization follows all M source phases, OLD Test bulk reflection and Human browser acceptance in an independent PR. Functional/security/accounting defects cannot be deferred as performance.
- PR and exact-head Draft HOLD review record Head/Tree/paths, results, remaining gates, elapsed/CI/build metrics and task-only resource cleanup. Branch/worktree and logical000093/alpha.46 reservation remain for review; no squash SHA or merge actor exists for M5.
