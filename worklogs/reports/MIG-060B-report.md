# MIG-060B Admin Dashboard／Navigation／Permission Foundation 提出用レポート

## 基本情報

- Task ID: `MIG-060B`
- Risk: `R3`
- Issue: `#123`
- Branch: `feat/MIG-060B-admin-navigation-permissions`
- Base: `cfe1b511cb0ecf5ef170a7e00165a2c4f2709211`
- 対象: Effective Permission Contract、Dashboard、Navigation Registry、
  Permission Provider／Gate、Module Root Route、共通Component
- 対象外: 各業務管理画面、Storefront UI、LINE Login、通知実送信、
  Payment Provider、Domain／Nginx／TLS、Staging／Production Deployment

## MIG-060A Closeout

- Issue `#121` Closed、PR `#122` Squash Merged。
- Final Head: `4ee324bc8c2b4615f499443158b79065cb6308e5`
- Squash Commit: `cfe1b511cb0ecf5ef170a7e00165a2c4f2709211`
- Required 5 Check、CodeQL 2件、Dependency Reviewを含む8 Check成功。
- Fresh Self-review一致、SEV-0／SEV-1 0件。
- Remote／Local Branch、Worktree Cleanup済み。
- Local `main = origin/main`、Working Tree clean、V1非変更。

## 実装結果

### Effective Permission Contract

- Admin OpenAPIへ`GET /admin/api/v2/auth/permissions`を追加。
- 有効なAdmin Realm SessionとMFA Enrollment完了を必須化。
- ResponseはRole、有効Permission Code、Request IDだけ。
- `Cache-Control: private, no-store`と`X-Request-Id`を付与。
- Public／Webhook ContractとStorefront ClientへAdmin型を非公開。
- 中央`V2PermissionAuthorizer`だけをPermission正本とし、未知Role、
  未登録／重複PermissionをFail Closed。
- `catalog.read`を中央Matrixへ追加し、Owner／Admin／Operatorへ割当。
- Permission専用Controllerを分離し、無関係なAuth Provider設定解決を回避。

### Navigation／Route Foundation

- 型付きRegistryへDashboard、Catalog、QA Draw、Prize／Shipping、
  Reporting／Export、Content、Contactを集約。
- RegistryはStable Route ID、Label、Path、Permission、Icon、Section、
  Sort Order、実装状態、Fresh MFA境界を保持。
- Permissionがない項目をNavigation／Dashboardから非表示。
- Permission取得失敗時はDashboard以外をFail Closed。
- 直接URLにも同じPermission Route Guardを適用。
- `/catalog`、`/qa`、`/shipping`、`/reports`、`/content`、`/contacts`を追加。
- 各RouteはTitle、Breadcrumb、Active Navigation、Loading、Error、403、
  準備中表示を持ち、架空業務Dataを表示しない。
- Active判定はPath segment境界で行い、類似Prefixを誤選択しない。

### Dashboard／共通Component

- DashboardへCurrent Admin Public ID、Role、MFA Enrollment状態、
  Server取得Permission、利用可能Moduleを表示。
- 売上、User、Gacha等の架空集計値なし。
- Browser時刻によるSession／Fresh MFA判定なし。
- `PermissionProvider`
- `PermissionGate`
- `ProtectedAdminRoute`
- `AdminNavigation`
- `Breadcrumb`
- `ModulePlaceholder`
- `DashboardModuleCard`
- `AdminPageHeader`
- Mobile Drawer、Escape Close、Focus移動／復帰、Keyboard操作を実装。

### Security

- Backendを唯一のAuthorization正本として維持。
- Component内でRole名による独自権限判定なし。
- 401はSession失効、403は汎用Forbidden、429は安全な`Retry-After`表示。
- Permission取得不能時は空PermissionとしてFail Closed。
- Same-origin Admin Cookie、Admin CSRF、Exact Origin、User Realm分離を維持。
- Authorization Header、Local Storage／Session Storage Tokenを不使用。
- CSP、Unknown Host拒否、Stack Trace非表示、`private, no-store`を維持。

## Test結果

- Admin OpenAPI Unit／Bundle: PASS
- OpenAPI Operation: Public 42／Admin 68／Webhook 0
- Admin Contract生成差分: 0
- Admin Typecheck／Lint／Production Build: PASS
- Admin Unit／Component: 21 Test PASS
- Chromium Browser E2E: 6 Test PASS
- Backend Permission Contract: 4 Test／46 Assertion PASS
- Policy Unit: 86 Test PASS
- Quality Unit: 5 Test PASS
- Security Unit: 4 Test PASS
- Release Unit: 10 Test PASS
- `policy-gate`: PASS
- `quality-gate`: PASS
- `security-gate`: PASS
- Storefront Client: 生成差分0、Typecheck／Lint／Build、14 Test PASS
- Site Schema: 生成差分0、Typecheck／Lint／Build、10 Test PASS
- Storefront Testkit: 生成差分0、Typecheck／Lint／Build、22 Test、
  Export／Network Boundary PASS
- Root／Legacy Frozen Install: PASS
- Root Audit: 0 Finding
- Legacy Audit: 既存11 Finding
- Composer Audit: 既存期限付き10 Finding、Baseline拡張なし
- Secret／PII Candidate: 0
- 新規Critical／High: 0

## V2 DB回帰

- Persistent `migrate:fresh`: 2回 PASS
- Ephemeral `migrate:fresh`: 2回 PASS
- 最新Migration Rollback／Reapply: PASS
- 全V2 Suite／Draw／QA／Reporting／Content負荷回帰: PASS
- API／Admin Health、PostgreSQL／Redis Health: PASS
- Backup／Restore、Schema／Migration Row checksum一致: PASS
- Task Resource Cleanup: PASS
- Migration数: 15
- Migration Set SHA-256:
  `53cbd05cae2fa794d39a3fd5c71ad87cefcb398e69eafc066a29ec9356e4f27a`
- Schema SHA-256:
  `7ae754f3fcbf1cff5cdf48961f0e03293e0e4e432124e92b0bbb399dcec60090`
- Migration Row SHA-256:
  `3e9d7878e58a77810819042186ef4ac43acb4926d74a7e619296657e382fd4ea`
- Backup SHA-256:
  `8a61ca630206f5d35c6ea52a5b3220e342b8a135b9fa8a376c42f06ff25350e4`
- Persistent Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060B/persistent/persistent-result.json`
- Ephemeral Evidence:
  `/var/lib/oripa-v2-evidence/MIG-060B/smoke/`

## 時間を要した作業

| 作業 | 所要 | 原因／再試行 | 解決／次回短縮 |
| --- | ---: | --- | --- |
| Persistent V2回帰 | 約2分／成功Run | 初回無出力SIGTERM。HTTP 500、Cookie Harness、WebAuthn設定解決、Header順序、Auth Guard Cacheを対象Testで順次検出 | Controller分離とTest境界修正。今後は対象HTTP Route SmokeをFull Guardより前に実行 |
| Ephemeral V2 Smoke | 約4分30秒 | 全Suite、Load、Backup／Restoreを一括実行 | 1成功Runで完了。Admin-only Taskの同一Head DB Evidence再利用可否をGovernance改善候補とする |
| Package検証 | 再実行あり | 並行実行時のProcess SIGTERMとSite Schema Build前のTestkit参照 | 依存順にSerial実行。今後もSite Schema、Client、Testkit順を固定 |
| Browser視覚確認 | E2E内 | fallback Chromiumに日本語Fontなし | DOM／Accessible Name／Keyboard／LayoutはPASS。Stagingで実配信Fontを再確認 |

## 非変更／未実行

- V1 Migration 40件Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- V1 Runtime、本番DB／Redis／Storage、Nginx、`v1/early-release`、
  Archive Branch、Annotated Tagは非変更。
- Catalog／QA／Shipping／Reporting／Content／Contactの業務画面は未実装。
- Storefront UI、通知Transport、Staging E2E、Production Deploymentは未実行。
- LINE Login `MIG-058B`は保留。

## Gate／完了処理

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Final Head、GitHub Check、Fresh Self-review、Squash Commit、CleanupはPR完了時に確定。
- 次Task候補:
  `MIG-060C Admin Catalog／Gacha／Probability Management`
- MIG-060Cは本Task内で開始しない。
