# MIG-060L Admin Gacha Sales Pause／Resume Operations 提出用レポート

## 基本情報

- Task ID: `MIG-060L`
- Risk: `R3`
- Issue: `#147`
- Branch: `feat/MIG-060L-gacha-sales-pause`
- Base: `78bc149b64ddb1e0fa6324c15cd84611ccf6b036`
- 対象: 公開中Gachaの新規販売／Draw Pause、Server Preflight付きResume
- 対象外: Unpublish、Public Deactivation、Draw Algorithm、Storefront UI、
  Production Scheduler、Payment Provider、Domain／Nginx／TLS、Deployment

## MIG-060K Closeout

- Issue `#145` Closed、PR `#146` Squash Merged。
- Final Head: `752936897b60377d37fe6d7db218866d9d03bee9`
- Squash Commit: `78bc149b64ddb1e0fa6324c15cd84611ccf6b036`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Branch／Worktree／Task Resource Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- 手動PauseはGacha Master単位の運用状態とし、公開Version切替で解除しない。
- PauseはUnpublishではなく、Published Version、Probability Snapshot、
  Public／Draw Pointer、Draw Historyを保持する。
- 新規DrawはGacha／Active Draw State Lock後、Point／Inventory／Result更新前に
  Pause正本を検査する。
- 成功済みDraw ReplayはPause判定より先にCanonical Resultを返す。
- Public OpenAPIを変更せず、既存`remaining_count=0`でList／Detailを販売不可へ
  一致させる。Resume後は同じ公開Versionで販売可能に戻す。

## Contract／Security

- Admin API: Sales状態取得、Pause／Resume Preflight、Pause、Resume。
- Admin 131 Operation、Public 47、Webhook 1。Public／Webhook Schemaは非変更。
- Permission: `catalog.publish`。Owner／Admin許可、Operator拒否。
- Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Revision OCC、Critical Rate Limitを強制。
- Reason Code: `operations_review`、`inventory_review`、`incident_response`。
- Canonical Replay、Key Conflict、Stale Revision、Limiter Fail Closedを実装。

## Pause／Resume／Draw

- PauseはIdempotency Record、Gacha、公開Version、Draw Stateを固定順でLockし、
  状態、DB時刻、Revision、Audit、Outboxを単一Transactionで確定する。
- ResumeはGacha、Pointer、Snapshot SHA、価格、口数、Inventory、期間、売切れ、
  Processing ScheduleをServer側で再検証する。
- Pause後の新規DrawはPoint／Inventory／Historyを変更せず拒否する。
- Pause中も完了済みDraw Replayは二重処理なしで返す。
- Pause／DrawのProcess競合は「Draw全成功後Pause」または
  「Pause成功後Draw全拒否」へ収束し、部分消費を残さない。
- Immediate／Scheduled Publish、Worker、予約取消との競合でもPauseを保持する。

## Migration／DB Guard

- Migration:
  `2026_08_14_000027_add_v2_gacha_sales_pause.php`
- 既存Migrationは非変更。Migration数27。
- 型付きSales状態、時刻、Actor Public ID、Reason、Last Requestを追加。
- Canonical CHECK、Restrict FK、Transition／History TriggerでRevision bypass、
  Cross-Gacha、無効Pointer、Archived Resume、History削除、Direct SQLの
  Pause無視Activationを拒否。
- Task DB: `oripa_v2_mig060l`、用途`v2-task-ephemeral`。
- Persistent／Ephemeral `migrate:fresh`各2回、Rollback／Reapply、
  Dump／Restore: PASS。
- Migration Set SHA-256:
  `775ae702a20979a8ca65fb88b82ae62f5566d17940aa66345315be53117c2096`
- Backup SHA-256:
  `303e80b85d56ad66459ca7a22d2bef82773107462208b5b6150260149427438c`
- Source／Restore Schema SHA-256:
  `3fa6937cc2fb3be56480a1542610d6602d34721e076d8fb1c6a227696a684d4a`
- Migration Row SHA-256:
  `de9e36937c4acb945b802f4cec1003312da047fdf430c750966a08489229fdfa`

## Admin UI

- Sales状態、Pause Reason、Pause／Resume Preflight、Fresh MFA、Confirmation、
  Resume Blocker、公開Version／Probability、Schedule、Conflict／429、
  Canonical再取得を追加。
- OperatorはRead-only。Unpublish／公開解除Buttonは存在しない。
- Dirty State、二重送信防止、Mobile、Keyboard、Focusを確認。
- TailAdmin無料版を視覚基準とし、Dependency／CSP／認証境界は非変更。

## Test／性能

- Backend対象: `18 Test／303 Assertion` PASS。
- Process Concurrency: `3 Test／33 Assertion` PASS。
- Admin OpenAPI Lint／Bundle／Breaking、生成差分0、Typecheck、Lint、Build: PASS。
- Admin Unit／Component: `54 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- Persistent／Ephemeral Guard、全V2 Suite、Immediate／Scheduled Publish、
  Catalog／Probability／Draw／QA、Point／Payment／Shipping／Reporting／Content:
  PASS。
- 100回Draw p95: `195.193 ms`、Query最大56、Response 667 byte。
- 1000回Draw p95: `696.244 ms`、Query最大58、Response 18,546 byte。
- Notice First Page p95 `61.243 ms`、Contact First Page p95 `6.548 ms`、
  Concurrent Contact p95 `674.536 ms`、Peak Memory `46,661,632 byte`。
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Test:
  依存順SerialでPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 29、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root／Legacy Frozen Install、Legacy Typecheck／Build:
  既存Lint 8 Error／1 Warning FingerprintでPASS。
- V1 Backend隔離回帰: `334 Test／1,820 Assertion`、
  既存Payment 2 Failure Fingerprint一致、Baseline Gate PASS。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060L/persistent-final/`
  `/var/lib/oripa-v2-evidence/MIG-060L/ephemeral-final/`
  `/var/lib/oripa-v2-evidence/MIG-060L/v1-backend-tests.xml`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約1.1分 | 対象指定が全Suiteへ転送されたためEvidenceを維持 | 13 Test PASS |
| Admin Unit／Component | 約35秒 | 同一Sourceの全Suiteを実行し重複再実行を省略 | 54 Test PASS |
| Persistent Guard | 約3分 | Fresh 2回、全Suite、Rollback／Reapply | PASS |
| Ephemeral Guard | 約6分 | 全Suite、Load、Backup／Restore、Cleanup | PASS |
| V1 Backend Baseline | 約2.7分 | 隔離V1 DBで既存Payment Fingerprint確認 | Baseline PASS |
| API Image Build | 約45秒 | Existing Classic BuilderでSource固定 | PASS |
| DB／Trigger再試行 | 約7分 | 旧Trigger残存をTask DB Freshで解消 | 対象Test PASS |
| Process Concurrency補正 | 約4分 | Point Backを含む実結果でWalletをReconcile | 3 Test PASS |
| Ephemeral Prefix補正 | 約1分 | Allowlist外をResource作成前にFail Closed | 安全なPrefixでPASS |
| Admin Timezone補正 | 約2分 | Fresh reviewでBrowser依存表示を検出 | Admin全検証PASS |

- 対象PHPUnit初回は既定`oripa_test`参照をTest開始前に検出し、
  Task Marker付き外部Configへ切り替えた。
- ComposerはRuntime Imageに存在しないため、既存Host Composer 2.9.8で
  Lock Auditを行った。Host Toolchainは更新していない。
- GitHub初回Policy CheckはPR本文の必須見出し不足で失敗した。
  Git差分／Task Policyから本文を再生成し、空Commitなしで修正した。
- Fresh Self-reviewでPause／Schedule日時のBrowser timezone依存を検出した。
  `Asia/Tokyo`へ固定し、Contract生成差分0、Typecheck、Lint、Build、
  Unit／Component 54件、Browser E2E 13件を再実行した。
  Backend／Migration／Contractは同一である。
- 開始時Root 6.1GB、`/tmp` 3.1GB、重い検証前Root 5.7GB、
  Ephemeral中Root 3.5GBをRead-only確認した。
- 容量不足による失敗はなく、Docker Cache、Named Volume、稼働Resource、
  V1 Resourceを削除していない。
- First-party Packageは依存順にSerial実行した。
- Gate、Baseline、Assertion、Security、Timeout、Memoryを緩和していない。

## 非変更

- Unpublish／Public Deactivation、公開Version削除、Probability Snapshot、
  Draw Algorithm、Public OpenAPI、Storefront UI、Admin全体TailAdmin移行、
  Payment Provider、Domain／Nginx／TLS、Staging／Production Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証: PASS
- Final Head／Squash Commit／GitHub 8 Check／Issue・PR状態／Cleanup:
  PR Closeout時に確定
- 次Task候補:
  `MIG-060M Admin Gacha Unpublish／Public Deactivation`
- MIG-060Mは開始しない。
