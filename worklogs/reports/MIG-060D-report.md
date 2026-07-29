# MIG-060D Admin Category／Tag／Rank Mutation Foundation 提出用レポート

## 基本情報

- Task ID: `MIG-060D`
- Risk: `R3`
- Issue: `#127`
- Branch: `feat/MIG-060D-admin-catalog-master-mutation`
- Base: `3fe45e39ed05564a70d03419da8a20db62a81373`
- 対象: Category／Tag／Rank Create・Update・Archive、共通Mutation基盤、Admin UI
- 対象外: Prize／Presentation Asset Mutation、Gacha／Probability、
  Storefront、LINE Login、Provider、Domain／TLS、Deployment

## MIG-060C Closeout

- Issue `#125` Closed、PR `#126` Squash Merged。
- Final Head: `2e3572aac804384122d8b7deb6aa60126330a360`
- Squash Commit: `3fe45e39ed05564a70d03419da8a20db62a81373`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## Characterization／Permission

- V1 Category／Tag／RankはCreate／Updateを持つが、物理Delete／Archive APIはない。
- V2 Public CatalogはMaster表示値を動的参照するため、公開中または公開予定Gachaが
  参照するName、Slug、Description、Sort Order、Visibility、Archive変更を拒否。
- 中央Permission Matrixへ`catalog.manage`を追加。
- Owner／Adminは許可、Operatorは不許可で`catalog.read`だけを維持。
- UI／ControllerにRole比較を追加せず、Backend有効Permissionを正本化。

## 実装結果

### Contract／Domain

- Admin OpenAPIへCategory／Tag／RankのCreate／Update／Archiveを9 Operation追加。
- Admin Realm、有効Session、MFA Enrollment、`catalog.manage`、CSRF、
  Exact Origin、JSON Content-Type、RFC 9457、Request ID、`private, no-store`を適用。
- Public／Webhook Contract非変更、Storefront ClientへのAdmin型Exportなし。
- `Idempotency-Key`必須。同一Admin＋同一Key＋同一RequestはCanonical Replay、
  異なるRequest／Operationは409。
- `revision`によるOCC、Stale Update 409、Code immutable、Archive後Update拒否。
- Unknown Field、長さ、不正Code／Slug、HTML／制御文字、負Sort Orderを拒否。
- Unicode NFC、型付きColumn、UUIDv7 Public ID、内部ID非公開。
- Service境界でもPermissionを検査し、直接呼出による迂回を拒否。
- Deadlock／Serialization Failure Retryは同一Keyで最大3回。

### Migration／DB保護

- Forward-safe Migration:
  `2026_08_05_000016_add_v2_catalog_master_mutation_foundation.php`
- Category／Tag／Rankへ`revision`、`archived_at`、Constraint／Indexを追加。
- DB Triggerで物理Delete、Code変更、Archive後Update、Revision飛越しを拒否。
- 公開中／公開予定Gacha参照の表示破壊変更をDB境界でも拒否。
- 既存Migration編集、`tenant_id`、物理Delete Endpointなし。

### Audit／Outbox／Rate Limit

- Create／Update／Archive、Replay、Conflict、公開参照拒否、Permission拒否、
  Rate LimitをAppend-only Auditへ接続。
- 成功Mutationと`catalog.change` Outboxを同一Transactionで確定。
- 内部ID、Cookie、Session ID、不要なPIIはAudit／Outboxへ非保存。
- Admin相関HMAC KeyでCreate／Update／Archive合計30回／10分。
- Limiter障害はFail Closed、429＋`Retry-After`、Read APIは非対象。

### Admin UI

- Category／Tag／Rank一覧・詳細へ新規作成、編集、Archiveを追加。
- OperatorにはMutation操作を表示せずRead-only。
- 共通Mutation Form、Confirmation Dialog、Conflict Boundary、
  Dirty State Guardを実装。
- Client Validation、未保存変更警告、二重送信防止、409／412再取得、
  成功後Canonical再取得を実装。
- Network結果不明時は同じ入力へ同じIdempotency-Keyを再利用。
- Existing Shell／PermissionProvider／ProtectedAdminRouteを再利用。
- Mobile、Keyboard、Focus、Dialogを確認。架空Data、Storage Tokenなし。

## Test結果

- Backend Catalog対象: 24 Test／364 Assertion PASS
- 新規Mutation: 9 Test／110 Assertion PASS
- Admin OpenAPI Operation: Public 42／Admin 87／Webhook 0
- Admin Contract生成差分: 0
- Admin Typecheck／Lint／Production Build: PASS
- Admin Unit／Component: 32 Test PASS
- Chromium Browser E2E: 9 Test PASS
- Policy Unit: 88 Test PASS
- Quality Unit: 5 Test PASS
- Security Unit: 4 Test PASS
- Release Unit: 10 Test PASS
- DB Guard Unit: PASS
- `quality-gate`／`security-gate`: PASS
- Storefront Client: 生成差分0、Typecheck／Lint／Build、14 Test PASS
- Site Schema: 生成差分0、Typecheck／Lint／Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck／Lint／Build、22 Test PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- Secret／PII Candidate: 0
- 新規Critical／High: 0
- Commit固定後に`policy-gate`本体を再実行する。

## V2 DB回帰

- Persistent `migrate:fresh`: 2回 PASS
- Ephemeral `migrate:fresh`: 2回 PASS
- 最新Migration Rollback／Reapply: PASS
- 全V2 Suite／Draw／QA／Reporting／Content負荷回帰: PASS
- API／Admin／PostgreSQL／Redis Health: PASS
- Backup／Restore、Schema／Migration Row Checksum一致: PASS
- Task Resource Cleanup: PASS
- Migration数: 16
- Migration Set SHA-256:
  `5e1cc64eaa3b8ea05d6500f1bc2d4866283ed8dd21785bd9f7d96ed5ffe49ffa`
- Schema SHA-256:
  `e868893e67fa303f6f656a067df32f33c5c921d9318b19a566674086ff0b2043`
- Migration Row SHA-256:
  `7c10bd058debe42bde7b379d1a91aab80fbc08d6670552328f5244e602aa9311`
- Backup SHA-256:
  `8a7d5438763983acf66150e2fb70de78c139e0d51ff6d6c4b67440f63e535b1d`
- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060D/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060D/smoke/`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 結果／次回短縮 |
| --- | ---: | --- | --- |
| Persistent V2 Guard | 各約2分 | 初回はCache-Control Directive順序だけで203 Test中1件停止 | 意味を保つ実値へTestを修正し、Migration 2回と全Suiteを再実行してPASS |
| Browser E2E | 初回52秒、成功Run 50.6秒 | 新規Locatorが既存Sort Selectと曖昧 | Dialog内Exact Accessible Nameへ限定、9 Test PASS |
| Ephemeral V2 Smoke | 308秒 | 全Suite、Load、Backup／Restore、Source／Restore比較を実行 | 1成功Run、Checksum一致、Resource Cleanup PASS |
| Catalog固有Security再検証 | 約34秒 | Rate Limit／Limiter障害／Outbox Rollback Testを追加しTask API Imageを再構築 | Mutation 9 Test／110 Assertion、Catalog対象24 Test／364 Assertion PASS |
| Frozen Install／Package／Admin／Gate | 約4分 | First-party Packageを依存順に全検証 | 生成差分0。未CommitFile列挙PolicyだけCommit固定後に再実行 |

## 非変更／未実行

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Prize／Asset Mutation、Gacha／Probability、他業務画面、Storefront、
  LINE Login、Staging／Production Deploymentは未実行。

## Gate／完了処理

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、CleanupはPR完了時に確定。
- 次Task候補:
  `MIG-060E Admin Prize／Presentation Asset Mutation`
- MIG-060Eは本Task内で開始しない。
