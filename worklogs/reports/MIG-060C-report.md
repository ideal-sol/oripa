# MIG-060C Admin Catalog Read／Selection Foundation 提出用レポート

## 基本情報

- Task ID: `MIG-060C`
- Risk: `R3`
- Issue: `#125`
- Branch: `feat/MIG-060C-admin-catalog-read`
- Base: `34e074e4ea5e72c736a4a1139a3ba57c9ea31cc1`
- 対象: Category、Tag、Rank、Prize、Presentation AssetのAdmin参照Contract、
  検索／選択UI、共通Catalog Component
- 対象外: Catalog Mutation、Gacha／Probability管理、他業務画面、
  Storefront UI、LINE Login、Provider、Domain／TLS、Deployment

## MIG-060B Closeout

- Issue `#123` Closed、PR `#124` Squash Merged。
- Final Head: `f7feb8cb2af69f6ba0acbefa16f292e413ff8b28`
- Squash Commit: `34e074e4ea5e72c736a4a1139a3ba57c9ea31cc1`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## 実装結果

### Admin Catalog Contract

- Admin OpenAPIへCategory、Tag、Rank、Prize、Presentation Assetの
  一覧／詳細を合計10 Operation追加。
- 全OperationでAdmin Realm、有効Session、MFA Enrollment、
  中央Permission Matrixの`catalog.read`を必須化。
- `Cache-Control: private, no-store`、Request ID、RFC 9457を適用。
- Opaque CursorをResource／Sort／Directionへ暗号化Bindingし、
  Public ID tie-breakによるStable Paginationを実装。
- Search、Visibility、Rank、Media Type、SortをAllowlist化。
- Public IDだけを返し、内部DB ID、原価、個別ppm、Storage Identifier、
  非公開Asset Pathを非露出。
- Service境界でもAuthorization Contextを検証し、Controller迂回を拒否。
- Mutation Endpoint、DB Migration、Public／Webhook Contract変更なし。
- Storefront ClientへのAdmin型Exportなし。

### Admin Catalog UI

- `/catalog`をCatalog Overviewへ置換。
- Category、Tag、Rank、Prize、Presentation Assetの一覧／詳細Routeを追加。
- Cursor Pagination、Search、Filter、Sort、Loading、Empty、Error、
  401／403／429、Breadcrumb、Active Navigationを実装。
- 既存Admin Shell、PermissionProvider、ProtectedAdminRouteを再利用。
- Data Table、Catalog Navigation、Search／Filter、Detail、Status Badge、
  Public Asset Preview、API Error Boundaryを共通化。
- 架空Data、Client Role比較、別Permission基盤なし。
- Asset PreviewはPublicかつ同一Origin Relative Pathだけを許可。
- Video autoplayなし、不正URL／非公開Asset／読込失敗はFallback。
- CSP／許可Domainの弱体化なし。
- Mobile、Keyboard、FocusをChromium E2Eで確認。

## Test結果

- Backend Catalog Contract: 5 Test／146 Assertion PASS
- Admin OpenAPI Operation: Public 42／Admin 78／Webhook 0
- Admin Contract生成差分: 0
- Admin Typecheck／Lint／Production Build: PASS
- Admin Unit／Component: 27 Test PASS
- Chromium Browser E2E: 7 Test PASS
- Policy Unit: 88 Test PASS
- Quality Unit: 5 Test PASS
- Security Unit: 4 Test PASS
- Release Unit: 10 Test PASS
- `policy-gate`: PASS
- `quality-gate`: PASS
- `security-gate`: PASS
- PHP Syntax: 558 File PASS
- Storefront Client: 生成差分0、Typecheck／Lint／Build、14 Test PASS
- Site Schema: 生成差分0、Typecheck／Lint／Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck／Lint／Build、22 Test、
  Export／Network Boundary PASS
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
- API／Admin Health、PostgreSQL／Redis Health: PASS
- Backup／Restore、Schema／Migration Row Checksum一致: PASS
- Task Resource Cleanup: PASS
- Migration数: 15
- Migration Set SHA-256:
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`
- Schema SHA-256:
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`
- Migration Row SHA-256:
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
- Backup SHA-256:
  `f0ee5e6ae4efee3ce6f5b2c1c1985297fa0a09506dfaa093dfd1d632164a1e1b`
- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060C/persistent/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060C/smoke/`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 解決／次回短縮 |
| --- | ---: | --- | --- |
| Task API Image／対象Test | 約2分 | Compose 2.40.1と旧Buildx 0.12.1でBuildKit `--allow`非対応、Composer層再評価 | Classic Builderを使用。Gate／Dependency変更なし。Host Buildx更新を環境改善候補とする |
| Browser E2E | 成功Run約44秒 | 初回にServer ComponentからLucide ComponentをClientへ渡す境界違反を検出 | SerializableなResource IDだけを渡す構造へ修正し、Unit／E2Eを再実行 |
| Persistent V2回帰 | 約2分 | Migration 2回、全V2 Suite、Healthを実行 | 1成功Runで完了 |
| Ephemeral V2 Smoke | 約6分 | 全Suite、Load、Backup／Restore、Source／Restore比較を実行 | 1成功Runで完了しTask Resourceを自動Cleanup |

## 非変更／未実行

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Catalog Mutation、Gacha／Probability業務画面、他業務Admin画面、
  Storefront UI、LINE Login、Staging／Production Deploymentは未実行。

## Gate／完了処理

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、CleanupはPR完了時に確定。
- 次Task候補:
  `MIG-060D Admin Catalog Mutation Foundation`
- MIG-060Dは本Task内で開始しない。
