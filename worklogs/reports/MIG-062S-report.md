# MIG-062S Operational Inventory／在庫調整

## Task

- Task ID: MIG-062S
- Issue: #268
- Base: `main@2daef365fa1b5a845857b93e64651114700dc22e`
- Branch: `feat/MIG-062S-operational-gacha-inventory`
- Risk: R4
- Task Policy SHA-256: initial `fbf7dfde9ebc275333b669341887d3ddde8627d09e0164fadb7b2031f6c26c90`; current `1f0791271f20892f65ec3163bf7329f66dc7b03869498bb36049734b96a29dea`
- Final Head／PR／Squash Commit: Closeout時に確定

## Summary

- Gacha Prize在庫をOperational Inventoryへ移行し、Admin調整、Draw、Catalog／Detailを同じCanonical Sourceへ切り替える。
- Inventory調整は過去Draw、User Prize、Draw State、`sold_count`を変更せず、Transaction、Row Lock、OCC、Idempotency、append-only Auditで実行する。

## Specification sources

- 最新`origin/main`、root／nested `AGENTS.md`、Governance／Release Gatesを適用した。
- Merge済みMigration／Catalog／Draw Domain、およびMIG-062Q／062J／062L／062Mを実装面の正本とした。

## Scope

### Allowed paths

- V2 Laravel Gacha／Catalog／QA、Admin API／Admin UI、PostgreSQL Migration、DB schema inventory guard、OpenAPI／generated artifact、targeted tests、Task deployment／worklog／policy fixture。

### Changed files

- Operational Inventory schema／mutation／audit、Draw integration、Public aggregate count、Admin inventory form／contract、targeted tests、Task recordsを変更した。

### Explicitly not changed

- Storefront Repository、V1、Nginx、Point／Payment、Persistent QA制約、Probability／Minimum Guarantee／Direct Point Back、残在庫Weighted Selection、Production Drawアルゴリズムは変更していない。

## Characterization

- Previewの既存DataをRead-only調査し、Gacha 6件、Prize 8件、Version Prize 12件、Prize Inventory 7件を確認した。
- 既存在庫は総数55、当選済み15、現残数40。成功Draw Result 16件のうちPrize 15件、User Prize 15件、Draw State `sold_count`合計16、Completed Draw `executed_count`合計16だった。
- Version Snapshot総数は1,100,044、Version Prize Snapshot総数は92。Inventoryが存在するPublished 7景品はSnapshotと一致し、未作成5景品は全てDraftだった。
- Inventory超過、Prize Draw Result／User Prizeとの当選数差、Draw StateとCompleted Draw／Draw Resultの`sold_count`差、Published Inventory欠落／別State参照は0件だった。
- Version SnapshotとOperational対象のRelation合計が異なる7件は履歴Snapshotと現在Relationの意図した分離であり、Inventory Backfillの意味推測を必要としないと判定した。

## Operational Inventory

- `prize_inventories`を景品単位のCanonical Sourceとし、`total_quantity = awarded_count + available_quantity + withdrawn_quantity`をDB CHECKで強制する。
- `awarded_count`は成功したPrize Drawだけが増加し、Admin payloadには含めない。Adminは`total_quantity`と`available_quantity`を指定し、`withdrawn_quantity`を差分から導出する。
- Draft Prize作成時からDraw State未接続のInventoryを作成し、初回Publishでは同じInventoryを同じVersionのDraw Stateへ接続する。Inventory調整でVersion Publish、Draw State作成、Draw State更新、`sold_count`更新、Draw／User Prize履歴更新を行わない。
- DrawはGacha、Draw State、Inventoryを同一TransactionでRow Lockし、Availabilityを`available_quantity`合計から読み、Prize成功時だけ`available_quantity`減少と`awarded_count`増加を同時反映する。Probability、Minimum Guarantee、Direct Point Back、Partial Remainingの抽選仕様は維持する。

## Admin Mutation／Audit

- MIG-062QのPrize編集Mutationをdraft／scheduled／published／sales_pausedで利用し、総在庫数、現在個数、理由、Inventory Revision OCCを追加した。PublishedのRank／交換Point／原価／有効状態は従来どおりImmutableである。
- Mutationは既存Idempotency claim、Transaction、Gacha／Version／Prize／Relation／Draw State／Inventory Row Lock、Inventory `lock_version`比較を使用する。DrawとAdminの競合は一方だけを確定し、他方をTyped conflictまたは非販売として拒否する。
- `prize_inventory_adjustments`へactor、request、idempotency key、reason、before／after四数量、before／after lock versionを記録する。DB TriggerでUPDATE／DELETE／TRUNCATEを拒否し、通常Auditへ`catalog.inventory.adjusted`を記録する。

## Count／Public

- `sold_count`は`gacha_draw_states.sold_count`、`remaining_count`は接続Inventoryの`available_quantity`合計、`total_count`は`total_quantity`合計を正本とする。
- `sold_out`は`remaining_count == 0`で判定し、Pause中も実Inventory残数を返す。`total_count - sold_count = remaining_count`は前提にしない。
- Admin再入荷後は過去の`draw_state.status = sold_out`を書き換えず、Operational Availabilityが正なら同じDraw StateでDraw／Pause／Resumeを継続できる。
- Public Catalog／DetailのSchema Shapeは変更しない。景品単位の`total_quantity`、`available_quantity`、`awarded_count`、`withdrawn_quantity`はPublic Responseへ出さない。Admin OpenAPI／Generated Clientだけをadditiveに更新する。

## Migration

- Migration `2026_09_08_000053_operational_gacha_inventory.php`は通常Dataの不整合をPreflightでFail Closedし、既存Inventoryを`available = total - awarded`、`withdrawn = 0`へBackfillする。履歴のないDraft Relation欠落分だけを`awarded = 0`で作成する。
- Task DBでfresh、rollback、reapplyをPASSした。成功Draw履歴を持つInventoryを旧Columnへrollback後に再適用し、`total=90`、`awarded=1`、`available=89`、`withdrawn=0`を自動Testで確認した。
- Adjustment Logが存在する場合、またはOperational調整後の`draw_state.sold_count > draw_state.total_count`をLegacyへ戻せない場合はrollbackをFail Closedする。

## Verification performed

- Backend targeted: Admin Inventory、Publish／Pause／Resume、Draw、Partial Remaining、Catalog／Detail、MIG-062Q Lifecycle、MIG-062L Snapshot、MIG-062M QA Guarantee、QA Draw、Migration BackfillがPASSした。PHP 8.4の既知Fixture path warning以外の失敗はない。
- Concurrency: 同一InventoryへDrawとAdmin Adjustmentを同時実行し、確定は一方だけ、`available=0`、`awarded + withdrawn = total`、`sold_count = awarded`、Adjustment件数=`withdrawn`を確認した。Oversell／Lost Updateは0件だった。
- Admin targeted: Rank／PrizeおよびLifecycle UI 7 tests、Generated Contract check、TypecheckがPASSした。OpenAPI Admin 212／Public 49／Webhook 1 operationのlint／bundle check、Policy Unit、`git diff --check`がPASSした。
- Required Integration Gateが新規Adjustment TableのV2 schema inventory未登録をFail Closedで検出した。Task Policyへ`scripts/db/v2_database.py`と`tests/db/test_v2_database.py`だけをAtomic追加し、`prize_inventory_adjustments`をCanonical inventoryへ登録、DB Guard Unit 34 testsとPolicy Gateを再実行してPASSした。
- 初回Preview Image Buildはexact PR HeadのImage生成自体はPASSしたが、Workflow control refをTask BranchでdispatchしたためArtifact read wrapperがtrusted `main` control ref条件でFail Closedした。未検証Artifactは使用せず、control refを`main`へ固定して再dispatchする。
- 全Suite、Production Host Build、残在庫Weighted Selection、Production Draw再設計、Persistent QA制約、Storefront Repository、V1、Nginx、Point、Paymentは対象外である。
- Preview Image、Required Checks、CodeQL／Dependency Review、Fresh Self-review、Squash Merge、CleanupはCloseout時に確定する。
- G4／G5はNOT COMPLETEを維持する。

## Verification not performed

- Repository全SuiteとProduction Host BuildはTask指示に従い実行していない。
- Preview、exact-head Required Checks、Fresh Self-review、Merge／CleanupはCloseout工程で実施する。
