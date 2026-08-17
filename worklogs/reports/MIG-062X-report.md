# MIG-062X 残在庫Weighted Draw／Probability Legacy整理

## Task

- Task ID: MIG-062X
- Issue: #289
- Base SHA: `ac3df5985018ab134065fbae56d1ad8b10042fb0`
- Branch: `feat/MIG-062X-remaining-inventory-weighted-draw`
- Risk: R4
- Migration Allocation: 取得なし
- Integration Lock: 取得済み。Closeoutで解放する。
- Preview Lock／Artifact Lock: 取得なし
- Final Head／PR／Squash Commit: Closeout時に確定

## Summary

- Production Drawの景品選択を、同一TransactionでRow LockしたOperational Inventoryの`available_quantity`だけをWeightとする残在庫Weighted Selectionへ変更した。
- 新規DrawはPrize-onlyとし、Probability Entry／Stage範囲、Minimum Guarantee、Direct Point Backを景品選択から外した。Legacy Schema／履歴／表示用metadataと既存Replayは維持する。
- Gacha、Draw State、Inventoryの既存Lock順序、既存Transaction Runner、対象SQLSTATE限定・最大3回Retry、Aggregate Inventory update、Result／User Prizeのbatch／chunk永続化を維持した。

## Specification sources

- 人間確定B2仕様、root／nested `AGENTS.md`、Governance Rev2、Release Gates Rev1、Data／Point／Payment baselineを適用した。
- 最新`origin/main`、Public OpenAPI、Storefront Client Contract、Storefront Testkit、Artifact Manifest、Merge済みMIG-062S／MIG-062W実装を正本とした。
- OPS／Cutover／Payment Review環境は調査・変更していない。

## Scope

### Allowed paths

- `apps/api/app/Domain/Draw/Services/V2DrawService.php`
- `apps/api/tests/V2/**`
- `scripts/ci/policy_gate.py`
- `tests/ci/policy/test_policy_gate.py`
- `worklogs/new_ver_main.md`
- `worklogs/reports/MIG-062X-report.md`

### Changed files

- `apps/api/app/Domain/Draw/Services/V2DrawService.php`
- `apps/api/tests/V2/AdminUserPrizeReadTest.php`
- `apps/api/tests/V2/CurrentUserDrawHistoryReadContractTest.php`
- `apps/api/tests/V2/DrawVerticalSliceTest.php`
- `apps/api/tests/V2/GachaDetailPresentationContractTest.php`
- `apps/api/tests/V2/PrizeShippingConcurrencyTest.php`
- `apps/api/tests/V2/PrizeShippingVerticalSliceTest.php`
- `apps/api/tests/V2/QaDrawVerticalSliceTest.php`
- `apps/api/tests/V2/QaTestUserGuaranteeIntegrationTest.php`
- `apps/api/tests/V2/V1DrawCharacterizationTest.php`
- `apps/api/tests/V2/ZDrawConcurrencyLoadTest.php`
- `apps/api/tests/V2/ZQaTestUserGuaranteeConcurrencyTest.php`
- `scripts/ci/policy_gate.py`
- `tests/ci/policy/test_policy_gate.py`
- `worklogs/new_ver_main.md`
- `worklogs/reports/MIG-062X-report.md`

### Explicitly not changed

- Public OpenAPI、Storefront Client、Storefront Testkit Contract、Artifact Version、Storefront Repository、Admin UI、Migration、Probability／Point Back Schema、Point Exchange、Draw Lifecycle、MIG-062W LINE Friend State／Eligibility Contractは変更していない。
- Nginx、Runtime、Public Asset、OPS-006／OPS-007／Cutover／Payment Review、Production DB／Deployは変更していない。

## Weighted Selection

- `catalog_gachas`、`gacha_draw_states`、対象`prize_inventories`を既存順序でRow Lockし、Lock済みCollectionの`available_quantity`合計から`remaining`／`executed_count`／Point Costを確定する。未ロック事前SUMと永続化後SUMは使用しない。
- 各通常当選で現在の全available合計を動的上限とし、既存`V2CryptographicRandomSource::integer(1, total_weight)`を呼ぶ。Production実装はPHP `random_int`によるCSPRNGで、float、ppm、moduloを使用しない。
- Cumulative integer weightで景品を決定し、選択直後にin-memory availableとtotal weightを1減らす。`available_quantity=0`はSkipし、次の当選は更新後Weightを使用する。
- 選択結果を景品別deltaへ集約し、既存CASE updateでInventoryをまとめて永続化する。1000連でも選択／Inventory read／updateは結果単位のDB queryを行わない。

## Transaction／Idempotency／Partial Remaining

- Point消費、Draw Request、Draw Result、Inventory available減算／awarded増加、`sold_count`、User Prize、履歴、Idempotency成功結果を同一Transactionで確定する。途中Result insert失敗を注入し、全副作用Rollbackを確認した。
- `requested_count=1000`／Lock済み`remaining=900`で`executed_count`、Point消費、Result、User Prize、Inventory delta、awarded、`sold_count`、履歴、Replayを900へ一致させた。Inventory不足を全体失敗へ戻していない。
- 同一Idempotency Keyは保存済み`draw_requests.response_data`をCanonicalに返し、再抽選、再課金、再減算、User Prize再作成を行わない。Synthetic Legacy Point Back成功responseを保存してReplayし、保存値を維持することも確認した。
- 既存Transaction Runnerの最大3回、SQLSTATE `40001`／`40P01`限定Retryを変更していない。独自分散Lockは追加していない。

## QA／Legacy

- Persistent QA保証は1 Requestにつき指定景品1件だけを先にLock済みavailableから消費し、残りを保証消費後のWeightで通常選択する。1／5／10／100／1000連で保証は1件のみである。
- 指定景品available 0は`QA_CONFIGURATION_INVALID`でFail Closedし、Point、Draw、Inventory、`sold_count`、User Prize、Idempotency成功結果を部分確定しない。
- Legacy QA Planの再設計は行わず、既存ordered prize selectionを維持した。ただしB2後の新規QA Drawを含め全新規ResultはPrize-onlyである。
- Probability Version／Stageは非NULL Legacy metadata参照として保存を維持するが、Stage範囲、Entry、Minimum Guarantee、Direct Point Back設定は景品選択へ使用しない。新規`point_back_amount=0`、Response `point_back_total=0`、Point Back Wallet Grant 0である。

## Verification performed

- PHP syntax: 変更PHP 12 files PASS。`git diff --check` PASS。
- Policy Gate: B2の動的bounded CSPRNG、Canonical locked Inventory snapshot、Partial Remaining、Aggregate Inventory mutation、Transaction／Idempotency、Prize-only境界、Legacy Probability／Direct Point Back禁止、Inventory-first QA lock順をMachine-readable Gateへ同期した。Legacy Schema／過去履歴互換の既存Migration／Public Read境界は維持した。Policy Unit 131 tests PASS、Local Policy Gate PASS。
- Quality: Quality Unit 4 tests PASS、Local Quality Gate PASS、Composer manifest validation PASS。
- Draw Unit／Integration: `DrawVerticalSliceTest` 22 tests／210 assertions PASS。1／5／10／100／1000、動的Weight、zero exclusion、Probability非依存、Prize-only、Partial Remaining 900、Idempotency、Legacy Point Back Replay、Rollback、Typed Error、LINE audience、Query特性を含む。
- Persistent QA: `QaTestUserGuaranteeIntegrationTest`＋`QaDrawVerticalSliceTest` 23 tests／252 assertions PASS。1000連1件保証、残りWeighted、在庫0 Fail Closed、Legacy QA、Rollbackを含む。
- Read／Lifecycle regression: Current User Draw History、Admin User Prize、Gacha Detail／Eligibility、Admin Gacha Usage Historyを実行し、Task変更に起因する失敗0。MIG-062W LINE Friend State Read／Eligibility変更を維持した。
- Point Exchange regression: bulk exchange／rollback 2 tests／19 assertions PASS。Point Exchangeで元Inventory／`sold_count`を戻さない既存仕様を維持した。
- Concurrency: Draw対Admin Inventory Adjustment 1 test／14 assertions、daily limit 1／11、残在庫境界Partial 1／16、Persistent QA concurrent draw＋same-key replay 1／24、Prize Exchange対Shipping 1／9、全てPASS。Oversell、負数、二重消費、`sold_count`不整合、Inventory恒等式違反0。
- Load: 同一Gacha 5／10／20並列および別Gacha 20並列で計55 requests、failure 0、unresolved deadlock 0、各request query max 56、`SELECT` max 31、`INSERT` max 19、`UPDATE` max 6でPASS。
- 1000連: p95 640.407ms、query max 55（`SELECT` 30、`INSERT` 19、`UPDATE` 6）。100連はquery max 49（`SELECT` 30、`INSERT` 13、`UPDATE` 6）で、Selection／Inventory queryのN+1はない。
- Local isolated synthetic PostgreSQLへ既存V2 Migration 53件を適用した。Task Migration created 0、Task／Preview／Production Migration applied 0。
- Local harness初回はMigration path誤指定によるSchema未作成、`APP_KEY`／Audit HMAC test env未指定、および旧ppm値をそのままbounded値へClampしたFixture期待差でFAILした。V2 Migration pathとSynthetic test keyを正し、Fixtureを旧比率相当のbounded integerへ修正後、上記Final focused commandを全て再実行してPASSした。ApplicationのSecurity Controlは変更・迂回していない。
- 初回PR Head `eb87ff173dc72a108dbb9987255cb02874072af5`は、Policy Gateが旧固定ppm／Point-first QA lock順を要求してFAILし、Quality GateはTask実行前の`setup-php`取得でGitHub側429／502によりFAILした。B2 Human仕様へPolicy境界とUnitを同期し、失敗履歴のないfresh headで全Checksを再実行する。

## Verification not performed

- Task指示に従いLocal Repository全Suite／全Buildは実行していない。Required GitHub CIがCanonical全体検証を行う。
- Public Contract Shape変更なしのためOpenAPI／Client／Testkit／Artifact Versionは更新していない。
- Preview Lockを要する変更ではなく、共有Preview Deploy／実Draw／Browser E2Eは実施していない。Production Deployは実施していない。
- Required Checks、CodeQL、Dependency Review、Fresh Self-review、Squash Merge、Issue Close、branch／worktree／Lock cleanupはFinal Head固定後に実施する。

## Review findings

- SEV-0: 0
- SEV-1: 0
- SEV-2: 0
- SEV-3: 0
