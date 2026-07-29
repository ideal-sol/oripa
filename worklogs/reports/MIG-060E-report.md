# MIG-060E Admin Prize／Presentation Asset Mutation 提出用レポート

## 基本情報

- Task ID: `MIG-060E`
- Risk: `R3`
- Issue: `#129`
- Branch: `feat/MIG-060E-admin-prize-asset-mutation`
- Base: `b4c04c998afef762c93f5d2cb77a8b0e58c5985f`
- 対象: Prize／Presentation Asset Create・Update・Archive、Admin UI
- 対象外: Gacha／Probability Mutation、Storefront、LINE Login、Provider、
  Domain／TLS、Deployment

## MIG-060D Closeout

- Issue `#127` Closed、PR `#128` Squash Merged。
- Final Head: `2e20bdf7f0d7ff560987e0c07c727c3be6d2d79e`
- Squash Commit: `b4c04c998afef762c93f5d2cb77a8b0e58c5985f`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## 実装結果

### 共通Mutation基盤の再利用

- MIG-060Dの`catalog.manage`、Authorization Context、Rate Limit、
  Idempotency、OCC、Audit／Outbox、Deadlock Retryを拡張して再利用。
- Admin UIも共通Mutation Form、Confirmation、Conflict Boundary、
  Dirty State Guard、Idempotency Fingerprint、Canonical再取得を再利用。
- Prize／Asset専用の別Permission、別Idempotency Store、別Rate Limiterは未作成。

### Contract／Domain

- Admin OpenAPIへPrize／Presentation AssetのCreate／Update／Archiveを6 Operation追加。
- Admin Realm、有効Session、MFA Enrollment、`catalog.manage`、CSRF、
  Exact Origin、JSON Content-Type、RFC 9457、Request ID、`private, no-store`を適用。
- Public／Webhook Contract非変更。Storefront ClientへAdmin型Exportなし。
- Existing Read Contractは新しいRevision／Archive属性をoptional追加し後方互換を維持。
- 全Mutationで`Idempotency-Key`必須。同じRequestはCanonical Replay、
  異なるRequestは409。`revision` OCCによるStale Updateも409。
- Owner／AdminはMutation可能、OperatorはRead-only。入力Validationより先に
  Permissionを検査し、未許可ActorへMutation規則を露出しない。

### Prize

- Code、Rank、任意Presentation Asset、表示名、説明、表示価格、交換Point、
  Visibilityを既存型付きColumnへ保存。
- Codeは作成後immutable。Rank／AssetはActiveなPublic IDだけを受理。
- Unknown Field、HTML／Script、制御文字、長さ超過、負数を拒否し、文字列をNFC化。
- 公開中／公開予定Gacha参照を破壊する表示変更、非表示化、Archiveを拒否。

### Presentation Asset

- 既存SchemaどおりStorage識別子、Relative Public Path、SHA-256、
  Image／Video、MIME、Byte Size、Alt、Public状態を扱うMetadata登録境界を実装。
- Object Identityは作成後immutable。更新可能なのはAltとPublic状態だけ。
- Storage IdentifierはAdmin Responseにも公開しない。外部URL、Upload、
  Storage Provider拡張は対象外。
- Prize／Rank／Gachaの公開中／公開予定参照を破壊する変更とArchiveを拒否。

### Migration／DB Guard

- Forward-safe Migration:
  `2026_08_06_000017_add_v2_catalog_prize_asset_mutation_foundation.php`
- Prize／Assetへ`revision`、`archived_at`、Constraint／Indexを追加。
- DB Triggerで物理Delete、Archive後Update、Revision飛越し、Prize Code変更、
  Asset Object Identity変更、Published Reference破壊を拒否。
- 既存Migration編集、物理Delete Endpoint、`tenant_id`追加なし。

### Audit／Outbox／Rate Limit

- Create／Update／Archive、Replay、Conflict、Published Reference拒否、
  Permission拒否、Rate LimitをAppend-only Auditへ接続。
- 成功Mutationと`catalog.change` Outboxを同一Transactionで確定。
- 内部ID、Cookie、Session ID、Secret、PIIはAudit／Outboxへ非保存。
- MIG-060DのAdmin相関HMAC Key、合計30回／10分、Limiter Fail Closedを再利用。

### Admin UI

- Prize／Presentation Asset一覧・詳細へ新規作成、編集、Archiveを追加。
- PrizeはRank／Public Assetを既存Read APIから選択。
- Assetは作成時にObject Metadata、更新時にAlt／Public状態だけを編集。
- Client Validation、Dirty State、二重送信防止、409／412 Conflict、
  401／403／429、Canonical再取得、Mobile、Keyboard、Focusを実装。
- OperatorにはMutation UIを表示せず、Backend 403を最終判断とする。

## Test結果

- Backend Catalog対象: 17 Test PASS
- Admin OpenAPI Operation: Public 42／Admin 93／Webhook 0
- Admin Contract生成差分: 0
- Admin Typecheck／Lint／Production Build: PASS
- Admin Unit／Component: 35 Test PASS
- Chromium Browser E2E: 10 Test PASS
- Policy Unit: 88 Test PASS
- Quality Unit: 5 Test PASS
- Security Unit: 4 Test PASS
- Release Unit: 10 Test PASS
- DB Guard Unit: 25 Test PASS
- `policy-gate`／`quality-gate`／`security-gate`: PASS
- Storefront Client: 生成差分0、Typecheck／Lint／Build、14 Test PASS
- Site Schema: 生成差分0、Typecheck／Lint／Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck／Lint／Build、22 Test PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- Legacy Lint: 既存8 Error／1 Warningと一致
- Secret／PII Candidate: 0
- 新規Critical／High: 0

## V2 DB回帰

- Persistent `migrate:fresh`: 2回 PASS
- Ephemeral `migrate:fresh`: 2回 PASS
- 最新Migration Rollback／Reapply: PASS
- 全V2 Suite／Draw／QA／Reporting／Content負荷回帰: PASS
- API／Admin／PostgreSQL／Redis Health: PASS
- Backup／Restore、Schema／Migration Row Checksum一致: PASS
- Task Resource Cleanup: PASS
- Migration数: 17
- Migration Set SHA-256:
  `bd92fa2fa15e9359a23fdf0192233929c81000c552790ff7485bae825c2749f2`
- Schema SHA-256:
  `dc606787bccee66f169c2478667488b6473df4956fc1121cc3685716e0f66313`
- Migration Row SHA-256:
  `dc58041dba95823a451219537e5b504c6b5ab1aa6b04c830964b90d9ab675cc1`
- Backup SHA-256:
  `dd4914fef26abf047c749c55eff916820c849d94abab1463a30ed37e25d85858`
- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060E/persistent-final/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060E/ephemeral-final/`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 結果 |
| --- | ---: | --- | --- |
| Persistent V2 Guard | 初回約93秒、Final約91秒 | 初回は旧Read Testが新Triggerを迂回する直接Updateと旧Mutation不存在Assertionで停止。Self-review後も全回帰 | Testを正本Schemaへ修正。FinalでMigration 2回、Rollback／Reapply、全Suite PASS |
| Ephemeral V2 Smoke | 約5分 | 全Suite、Load、Backup／Restore、Source／Restore比較 | Checksum一致、Resource Cleanup PASS |
| Browser E2E | 約1分18秒 | Auth ShellからPrize／Asset Mutationまで10 Scenario | 10 Test PASS |
| First-party Package | 再実行約22秒 | 並列初回はTestkitが依存Package Build前に型解決できず停止 | 依存Build後に再実行し22 Test／全Boundary PASS |
| Policy Gate | 2回の途中拒否後にPASS | 未Commit Migrationと新Admin Componentの明示Allowlist漏れをFail Closed | 既存限定列挙へ1 Pathだけ追加。Wildcard化せずPolicy Unit 88件／Gate PASS |

## 非変更

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Gacha／Probability Mutation、Storefront機能、LINE Login、Provider、
  Domain／TLS、Staging／Production Deploymentは未実行。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Local Fresh Self-review、Final Head、GitHub Check、Squash Commit、
  Issue Close、Branch／Worktree CleanupはPR完了時に確定する。
