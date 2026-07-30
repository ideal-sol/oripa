# MIG-060J Admin Gacha Immediate Publish 提出用レポート

## 基本情報

- Task ID: `MIG-060J`
- Risk: `R3`
- Issue: `#143`
- Branch: `feat/MIG-060J-gacha-immediate-publish`
- Base: `725617a2c9f96cc372a414e5b1142bad343d865b`
- 対象: Preflight済みDraft Gacha Versionの即時Publishと、
  Public Catalog／Draw参照の原子的Activation
- 対象外: Schedule、Unpublish、自動公開Worker、Public OpenAPI変更、
  Draw Algorithm変更、Storefront UI、Deployment

## MIG-060I Closeout

- Issue `#141` Closed、PR `#142` Squash Merged。
- Final Head: `2ef2b47e284a5f69023c8ed53638a4b6464bc274`
- Squash Commit: `725617a2c9f96cc372a414e5b1142bad343d865b`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- Gacha Masterの`published_version_id`をPublic Pointer、
  新規`active_draw_state_id`をDraw Pointerとした。
- Gacha VersionとPublished Probability Snapshotは既存Relationを正本とする。
- 旧Version、旧Draw State、旧Snapshot、旧Draw Historyは変更・削除せず、
  不変履歴として保持する。独自の旧Version状態は追加していない。
- Immediate PublishはDraftだけを対象とし、Server Preflight、
  Snapshot SHA、Active Relation、価格、口数、期間を毎回再検証する。
- Commit前は旧Versionだけ、Commit後はPublic CatalogとDraw Resolverが同じ
  新Version／Probability Snapshotを参照する。

## Contract／Permission／Transaction

- Admin OpenAPIへ現在公開状態取得とImmediate Publishを追加。
  Admin 122 Operation、Public 47、Webhook 1。
- Public／Webhook ContractとStorefront Clientは非変更。
- `catalog.publish`、Admin Realm、MFA Enrollment、Fresh MFA 5分、
  CSRF、Exact Origin、JSON、Idempotency-Key、Critical Rate Limitを適用。
- RequestはGacha Version RevisionとGacha Master Revisionを必須とする。
- Idempotency Record、Gacha Master、現行Version、対象Draft、
  Probability Snapshotを固定順でLockする。
- Publish、Published At、Draw State、Inventory、Public／Draw Pointer、
  Revision、Audit、Outboxを単一Transactionで確定する。
- Canonical Replay、Key Conflict、Stale Revision、Concurrent後着を
  Fail Closedとする。

## Migration／DB Guard

- Forward-safe Migration:
  `2026_08_12_000025_add_v2_gacha_immediate_publish_activation.php`
- 既存Migrationは編集していない。
- Version別Draw Stateを履歴保持し、`active_draw_state_id`を追加した。
- Deferred Constraint TriggerとHistory Guardで、部分Activation、
  Cross-Gacha、Draft／Archived、Published Atなし、Probability不一致、
  Revision bypass、FK付替え、旧State物理Deleteを拒否する。
- Persistent／Ephemeral `migrate:fresh`各2回、Apply、Rollback／Reapply、
  Dump／Restoreを確認した。

## Public Catalog／Draw／Admin UI

- Public List／Detailは公開VersionとActive Draw Stateの組を参照する。
- Draw ResolverはGacha Rowの後にActive Draw StateをLockする。
- 新規Drawだけが新Versionを使用し、旧Draw履歴Relationは不変。
- 1000回Draw、CSPRNG、Point、Inventory、Idempotencyの意味は変更していない。
- Admin UIへPublish Now、Server Preflight、Snapshot Hash、価格／口数／期間、
  現行Version切替、Fresh MFA、Confirmation、二重送信防止、Conflict／429、
  Canonical再取得、Published Atを追加した。
- OperatorはRead-only。Schedule／Unpublish Buttonは存在しない。

## Test／検証

- Backend対象: `33 Test／363 Assertion` PASS。
- Draw 100回p95: `165.047 ms`
- Draw 1000回p95: `679.606 ms`
- Draw 1000回Query数: 最大58
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、
  Typecheck、Lint、Production Build: PASS。
- Admin Unit／Component: `51 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- OCC補正後のPersistent／Ephemeral Full Guard、全V2 Suite、
  Immediate Publish／Atomicity／Concurrency、Catalog／Probability／Draw／QA、
  Point／Payment／Shipping／Reporting／Content／Load回帰: PASS。
- Migration数: 25
- Migration Set SHA-256:
  `79af6bbdce2f63a305101557655263e0407d680fd9435c459cad7c14eee9213b`
- Backup SHA-256:
  `ccd38cc1eeff7b5629528bbf925462f66274a4d6fb96ca3dab571e1465d25f36`
- Source／Restore Schema SHA-256:
  `f1501b0d8b9869784cc135239d7948af9a57c41096a8db2c466c292f5b868612`
- Source／Restore Migration Row SHA-256:
  `828aaa9afcf54d6dd6e464f6e4f4585ac0c74d9fa69bdf0a3cabcc9253d5d461`
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Test:
  依存順SerialでPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root／Legacy Frozen Install、Legacy Typecheck／Build: PASS。
- V1 Backend既存Payment 2 FailureはFingerprint一致、Baseline Gate PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060J/persistent-final2/`
  `/var/lib/oripa-v2-evidence/MIG-060J/ephemeral-final2/`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約1.1分 | Publish／Fresh MFA／Mobileを既存Scenarioへ統合 | 13 Test PASS |
| Persistent Guard | 約3分 | Fresh 2回、全Suite、Rollback／Reapply | PASS |
| Ephemeral Guard | 約5分 | 全Suite、Load、Backup／Restore、Cleanup | PASS |
| API Image Build | 約45秒 | PHP 8.4 Classic BuilderでLocked InstallとFinal Sourceを固定 | PASS |
| Fixture／Revision補正 | 約8分 | 初期ActivationでRevisionが2回進む既存回帰を検出 | 修正後Full Guard PASS |
| Fresh Self-review OCC補正 | 約12分 | 異なるDraftのConcurrent Publish後着をFail Closed化 | 全対象／Full Guard再実行PASS |
| V1 Backend baseline | 約2.7分 | 既存Payment 2 Failureの完全Fingerprint確認 | Baseline PASS |

- V1 baseline先行Migrationは接続名指定がTask APIの既定V2 DBを参照し、
  最初の重複DDLでTransaction内拒否された。状態変更はなかった。
- Legacy検証後にTask用`oripa_test`を削除したため対象Test初回が接続失敗した。
  Task専用DBを再作成し、Code変更なしで再実行した。
- OpenAPIは固定Tool、BackendはClassic Builderを使用し、Host Toolchainを
  更新していない。
- Root 7.5GB以上、`/tmp` 3.1GB以上を重い検証前に確認し、
  安全閾値内のためDocker CacheやNamed Volumeを削除していない。
- First-party Packageは依存順にSerial実行し、Gate、Baseline、Assertion、
  Timeout、Memoryを緩和していない。

## 非変更

- Schedule／Unpublish、自動公開Worker、Probability Snapshot、Draw Algorithm、
  Public／Webhook Schema、Storefront UI、Payment Provider、Domain／Nginx／TLS、
  Staging／Production Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証: PASS
- Final Head／Squash Commit／GitHub 8 Check／Issue・PR状態／Cleanup:
  PR Closeout時に確定
- 次Task候補:
  `MIG-060K Admin Gacha Publish Schedule／Unpublish Operations`
- MIG-060Kは開始しない。
