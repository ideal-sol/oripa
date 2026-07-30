# MIG-060I Admin Gacha Publish Preflight 提出用レポート

## 基本情報

- Task ID: `MIG-060I`
- Risk: `R3`
- Issue: `#141`
- Branch: `feat/MIG-060I-gacha-publish-preflight`
- Base: `24e6013ebf87f6b10319ebd888199eb336fa0183`
- 対象: Draft Gacha VersionへのPublished Probability Snapshot選択と、
  Gacha公開前のServer Preflight
- 対象外: Gacha Version Publish／Schedule／Unpublish、Public Catalog切替、
  Draw参照切替、Storefront UI、Deployment

## MIG-060H Closeout

- Issue `#139` Closed、PR `#140` Squash Merged。
- Final Head: `68099084053f477c34d229fb1fa85678d6dee16b`
- Squash Commit: `24e6013ebf87f6b10319ebd888199eb336fa0183`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup、Local main同期、V1非変更を確認。

## Characterization／設計

- MIG-050、MIG-060F～HのSchema、Gacha Draft、Probability Editor、
  不変Published Snapshot、Catalog Mutation基盤を再利用した。
- Gacha Versionの`published_probability_version_id`をSelection Pointerとした。
  候補は同じGacha Versionに属するPublished／非Archived Snapshotに限定する。
- Gacha Masterの`published_version_id`は現行公開Version Pointerであり、
  次DraftのPreflight時に存在してよい。Preflightはこれを変更しない。
- Probability SelectionとPreflightはGacha／Public Catalog／Draw Pointerを
  変更しない。実PublishとActivationはMIG-060Jへ延期した。

## Contract／Permission

- Admin OpenAPIへ候補一覧、現在選択、Selection Mutation、
  Publish Preflightの4 Operationを追加した。
- Admin Operation数120、Public 47、Webhook 1。Public／Webhook Contractは非変更。
- 既存`catalog.publish`を使用し、Owner／Adminを許可、Operatorを拒否する。
- Admin Realm、MFA Enrollment、Fresh MFA 5分、CSRF、Exact Origin、JSON、
  Idempotency-Key、Revision OCC、Critical Mutation Rate Limit、
  RFC 9457、Request ID、`private, no-store`を適用した。

## Selection／Preflight Domain

- Idempotency Record、Gacha Master、Gacha Version、Probability VersionをLockし、
  Draft状態、親Relation、Published状態、Snapshot SHA形式と再計算値を確認する。
- Selection Pointer、Revision、Audit、`catalog.change` Outboxを
  同一Transactionで確定する。
- 同一Key／同一RequestはCanonical Replay、同一Key／異内容、
  Stale Revision、Cross-Version、Draft／Archived Snapshot、Concurrent後着は
  Fail Closedとする。
- Server PreflightはMaster／Version状態、Snapshot、全Stage
  `1,000,000 ppm`、Category／Tag／Prize／Rank／Asset、必須表示Asset、
  価格／販売口数、表示期間を毎回再検証する。
- Responseは`publishable`、選択Probability Public ID、Snapshot SHA、
  Validation Code、Blocking Reason、Revision、Request IDを返す。
- PreflightはAuditだけを記録し、Catalog／Draw状態とOutboxを変更しない。
  実Publish Endpointは追加していない。

## Migration／DB Guard

- Forward-safe Migration:
  `2026_08_11_000024_guard_v2_gacha_probability_selection.php`
- 既存Migrationは編集していない。
- Draft以外のPointer変更、Pointer解除、Cross-Version、Draft／Archived Snapshot、
  不正SHA、Revision bypassをDB Triggerで拒否する。
- Published Snapshotの既存不変GuardとRestrict FKを維持し、Snapshot変更、
  FK付替え、物理Deleteによる参照破壊を拒否する。
- Migration Apply／Rollback／Reapply、Dump／Restore後のTrigger一致を確認した。

## Admin UI

- Gacha Version画面へ候補、Published At、Snapshot Hash、Stage数、
  Validation状態、現在選択、選択変更確認、Fresh MFA、
  Publish Preflight、Blocking Reasonを追加した。
- Dirty State、Conflict再読込、同じIdempotency-KeyでのRetry、
  Canonical再取得、二重送信防止、Mobile、Keyboard、Focusを確認した。
- OperatorはRead-onlyで、Backend 403を最終境界とする。
- 実Publish／Schedule／Unpublish Buttonは存在せず、公開操作未実装を明示する。

## Test／検証

- Selection／Preflight対象: `6 Test／81 Assertion` PASS。
- 専用Concurrency／DB Guard: PASS。
- 現行公開Version Pointerを保持したまま次DraftのPreflightが成功し、
  Public／Draw Pointerが不変であることを確認。
- Admin OpenAPI Lint／Bundle／Breaking Check、生成差分0、
  Typecheck、Lint、Production Build: PASS。
- Admin Unit／Component: `50 Test` PASS。
- Admin Browser E2E: `13 Test` PASS。
- Persistent／Ephemeral Guardで全V2 Suite、Probability／Catalog／Draw／QA、
  Point／Payment／Shipping／Reporting／Content回帰: PASS。
- Persistent／Ephemeral `migrate:fresh`: 各2回 PASS。
- 最新Migration Rollback／Reapply、API／DB／Redis／Storage Health: PASS。
- Migration数: 24
- Migration Set SHA-256:
  `95eecf98d221cd9e468e54ebe6eaef06267d4d5b4d34de34c86d12aef6871143`
- Backup SHA-256:
  `09e14090c7ea31090ac656653b7149756bd3dbec336d2e187bf6638fda073f71`
- Source／Restore Schema SHA-256:
  `1c147d59ea76095fdf977834a6b2c1ebc8086a52cd47fecbf873f17b0e2bce56`
- Source／Restore Migration Row SHA-256:
  `7d40801e7c2f3b763802572adf10c89a0403134dcb930e225617a60985420969`
- Site Schema 10、Storefront Client 14、Storefront Testkit 22 Testを
  依存順にSerial実行し、生成差分／Typecheck／Lint／Buildを含めPASS。
- Policy Unit 89、Quality Unit 5、Security Unit 4、DB Guard Unit 26、
  OpenAPI Unit 4、Release Unit 10: PASS。
- Root／Legacy Frozen Install、Legacy Typecheck／Build: PASS。
- Legacy Lint既存`8 Error／1 Warning` Fingerprint: 一致、増加なし。
- Root Audit 0、Legacy Audit 11、Composer既存Baseline 10、
  新規Critical／High 0、Secret／PII Candidate 0。
- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-060I/`

## 時間を要した作業

| 作業 | 所要 | 原因／効率改善 | 結果 |
| --- | ---: | --- | --- |
| Admin Browser E2E | 約1分 | Selection／Preflight／Fresh MFA／Mobileを既存Scenarioへ統合 | 13 Test PASS |
| Persistent Guard | 約3分 | Fresh 2回、全Suite、Rollback／Reapply | PASS |
| Ephemeral Guard | 約5分 | 全Suite、性能回帰、Backup／Restore、Cleanup | PASS |
| API Image Build | 約30秒 | Final BackendをClassic Builderで固定 | PASS |
| DB Guard補正 | 約2分 | Fixture Importの同一Statement遷移との整合を確認 | Guard／全回帰PASS |
| Fresh Self-review補正 | 約9分 | 現行公開Versionを誤ってBlocking扱いする問題を検出 | 対象／Persistent／Ephemeral再実行PASS |

- 対象Test初回はPHPUnit Suite PathとTask DB名が一致しなかった。
  共有DBを変更せずTask専用PostgreSQLへ切り替えた。
- 初期DB GuardはFixture ImportのDraftからPublishedへの同一Statement遷移を
  拒否した。既存Published不変条件を維持したままOLD状態判定へ補正した。
- Fresh Self-review後のBackend修正に対して対象Test、Persistent、
  Ephemeral Guardを再実行した。Admin／Contract／Migration Pathは不変のため、
  Governanceに従い同一差分系統のAdmin／Package Evidenceを再利用した。
- Root 11GB、`/tmp` 3.2GBの空きを重い検証前にRead-only確認した。
  安全閾値内のためCleanupは不要だった。
- Existing Classic BuilderとFirst-party Package Serial順を維持し、
  Host Toolchain、Gate、Baseline、Assertion、Timeout、Memoryを変更していない。

## 非変更

- Gacha Version Publish／Schedule／Unpublish、Public Catalog切替、
  Draw Probability Pointer切替、Probability Snapshot、Public／Webhook Contract、
  Storefront UI、Payment Provider、Domain／Nginx／TLS、Deployment。
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tag。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local R3検証は成功。
- Final Head、GitHub 8 Check、Fresh Self-review、Squash Commit、Issue Close、
  Branch／Worktree／Task Resource CleanupはPR Closeoutで確定する。
- 次Task候補:
  `MIG-060J Admin Gacha Version Immediate Publish／Public Activation`
- MIG-060Jは開始しない。
