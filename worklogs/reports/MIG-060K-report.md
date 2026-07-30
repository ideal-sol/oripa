# MIG-060K Admin Scheduled Publish Foundation／Worker 提出用レポート

## 基本情報

- Task ID: `MIG-060K`
- Risk: `R3`
- Issue: `#145`
- Branch: `feat/MIG-060K-gacha-scheduled-publish`
- Base: `18a170ddf58174956f21eef693cbcaac0a5473e9`
- 対象: Draft Gacha Versionの将来時刻Publish予約、取消、Worker Activation
- 対象外: Unpublish、販売停止／再開、Production Scheduler、Public Contract、
  Draw Algorithm、Storefront UI、Deployment

## MIG-060J Closeout

- Issue `#143` Closed、PR `#144` Squash Merged。
- Final Head: `d63a5aa8e59cdcf54c912408a7a7d329c04fac5d`
- Squash Commit: `18a170ddf58174956f21eef693cbcaac0a5473e9`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- 予約時刻はUTC保存、期限到来はDB Server `CURRENT_TIMESTAMP`を正本とする。
  Admin表示は既存`Asia/Tokyo`規則に従う。
- 有効予約は同一Gacha／Version各1件。予約中Draftの破壊的変更を拒否し、
  取消後はDraft編集を再許可する。
- 現行VersionがあってもMIG-060JのActivation Domainを再利用し、
  Public CatalogとDraw Pointerを単一Transactionで切り替える。
- 旧Version、Probability Snapshot、Draw State、Draw Historyは不変履歴として保持。
- 独自の予約優先順位、承認制度、Fallback、Unpublish状態は追加していない。

## Contract／Permission／Schedule

- Admin OpenAPIへSchedule取得、Preflight、予約作成、予約取消を追加。
  Admin 126 Operation、Public 47、Webhook 1。
- Public／Webhook SchemaとStorefront Clientは非変更。
- `catalog.publish`、Admin Realm、MFA Enrollment、Fresh MFA 5分、
  CSRF、Exact Origin、JSON、Idempotency-Key、Revision OCC、
  Critical Rate Limitを適用。
- 状態は`scheduled`、`processing`、`completed`、`cancelled`、`failed`。
- Idempotency Record、Gacha、Draft Version、Published Probabilityを固定順でLockし、
  Preflight、予約、Revision、Audit、Outboxを同一Transactionで確定。
- Canonical Replay、Key Conflict、Stale Revision、重複予約、過去時刻、
  Published／Archived、Probability未選択をFail Closedとする。

## Worker／Atomicity

- Command: `v2:catalog:work-scheduled-publishes`
- Batch上限5、`FOR UPDATE SKIP LOCKED`、Lease 120秒。
- DB Server時刻で期限到来予約だけをClaimする。
- Publish直前にPreflightとSnapshot SHAを再検証し、MIG-060J Activationを再利用。
- Transient Failureは最大3回、60秒基準の指数Backoff。
- Claim、Activation、Schedule完了、Audit、Outboxは同一Transaction。
- 同時Worker、再起動、Redelivery、Immediate Publish競合でも部分Activationを残さない。
- Read Contractは取消／完了後も現在のGacha／Version Revisionを返す。

## Migration／DB Guard

- Forward-safe Migration:
  `2026_08_13_000026_create_v2_gacha_publish_schedules.php`
- 既存Migrationは非変更。
- Partial Unique Index、Restrict FK、Canonical CHECK、Triggerで重複予約、
  Cross-Gacha／Version、状態飛越し、Revision bypass、FK付替え、
  物理Deleteを拒否。
- DB Target Safety Guardで用途、Host、Port、DB、Schema、Environment、
  Task ID Marker、Migration集合をDDL前に機械照合。
- Task DB: `oripa_v2_mig060k`
- Persistent／Ephemeral `migrate:fresh`各2回、Rollback／Reapply、
  Dump／Restore: PASS。

## Admin UI

- 予約日時、Timezone、Schedule Preflight、現在予約、対象Version／Probability、
  現行Version切替、Fresh MFA、Confirmation、取消、Worker状態、
  Completed／Failed、Canonical再取得を追加。
- Dirty State、二重送信防止、Conflict／429、Mobile、Keyboard、Focusを確認。
- OperatorはRead-only。Unpublish／販売停止Buttonは存在しない。
- TailAdmin無料版を視覚基準とし、Dependency／CSP／認証境界は非変更。

## Test／検証

- Backend対象: `13 Test／223 Assertion` PASS。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、
  Typecheck、Lint、Production Build: PASS。
- Admin Unit／Component: `52 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- Final Persistent／Ephemeral Guard、全V2 Suite、Schedule／Worker、
  Immediate Publish／Atomicity、Catalog／Probability／Draw／QA、
  Point／Payment／Shipping／Reporting／Content／Load回帰: PASS。
- Migration数: 26
- Migration Set SHA-256:
  `0a7f4dd2ee33ae7f028c039286da4b5479fe8821b3cb13cb6589a0d639287fd3`
- Backup SHA-256:
  `c82c6eb361ec39e832e2c83f3411d6ef5a277dd2fe74e441d431f1a12d5cdeaa`
- Source／Restore Schema SHA-256:
  `24cc06f4fccca233ba16471cb320309784a56f9169eddf4a9936c596d3bd3f6a`
- Source／Restore Migration Row SHA-256:
  `8e3e563704ef5303ff74aa29bfe86a7789a12238dccdae1ef6dee77c23a9d8b5`
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Test:
  依存順SerialでPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root／Legacy Frozen Install、Legacy Typecheck／Build: PASS。
- V1 Backend既存Payment 2 Failure／332 WarningはFingerprint一致、
  Baseline Gate PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060K/persistent/`
  `/var/lib/oripa-v2-evidence/MIG-060K/ephemeral/`
  `/var/lib/oripa-v2-evidence/MIG-060K/v1-backend-tests.xml`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約1.1分 | Schedule／Fresh MFA／Mobileを既存Scenarioへ統合 | 13 Test PASS |
| Persistent Guard | 約3分 | Fresh 2回、全Suite、Rollback／Reapply | PASS |
| Ephemeral Guard | 約6分 | 全Suite、Load、Backup／Restore、Cleanup | PASS |
| V1 Backend Baseline | 約2.7分 | 既存Payment Failure Fingerprint確認 | Baseline PASS |
| Final API Image Build | 約45秒 | Classic BuilderでLocked Sourceを固定 | PASS |
| DB Safety Guard調整 | 約6分 | Marker／Portなし隔離V1 TargetのDDL前照合 | Fail Closed後PASS |
| Trigger／Timezone補正 | 約12分 | Table別Column分岐とISO Offset保持を対象Smokeで検出 | 対象／Guard PASS |
| Ephemeral Fixture補正 | 約6分 | 複数`now()`の秒境界を同一時刻へ固定 | 閾値非変更でPASS |
| Fresh Self-review補正 | 約8分 | 取消／完了後Read Revisionの旧値返却を検出 | Full Guard再実行PASS |

- 開始時と重い検証前にRoot 6.0GB以上、`/tmp` 3.1GB以上を確認した。
  安全閾値内のためDocker Cache、Named Volume、稼働Resourceを削除していない。
- First-party Packageは依存順にSerial実行した。
- APIは既存Classic Builderを使用し、Host Compose／Buildx／PHP／Nodeを更新していない。
- Gate、Baseline、Assertion、Security境界、Timeout、Memoryを緩和していない。

## 非変更

- Unpublish、販売停止／再開、Production Cron／systemd、外部Scheduler、
  Public／Webhook Schema、Draw Algorithm、Storefront UI、Payment Provider、
  Domain／Nginx／TLS、Staging／Production Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証: PASS
- Final Head／Squash Commit／GitHub 8 Check／Issue・PR状態／Cleanup:
  PR Closeout時に確定
- 次Task候補:
  `MIG-060L Admin Gacha Unpublish／Sales Pause Operations`
- MIG-060Lは開始しない。
