# オリパ・パッケージ V2
# V1 → V2 移行計画 最終確定版

- 文書ID: `V2-V1-MIGRATION-PLAN-001`
- 状態: **FINAL / Architecture Baseline 1.0**
- 確定日: 2026-07-22
- 適用対象:
  - `myong-ideal/oripa` のV1資産
  - オリパ・プラットフォームV2
  - `oripa-site-template`
  - `oripa-site-luxe-pack`
  - 将来作成する各顧客サイト
- 保存推奨先: `docs/architecture/V1_TO_V2_MIGRATION_PLAN_FINAL_2026-07-22.md`

## 優先関係

1. 人間による最新の明示決定
2. 本書
3. `V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md`
4. `V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md`
5. `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md`
6. `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`
7. V2全体設計
8. V1の確定仕様・実装記録

本書は、V1の業務仕様を無断で変更するものではない。

V2で意図的に変更する項目は、本書および上位の確定済みV2設計に基づく。

---

# 1. 移行方式の最終決定

V1からV2への移行は、稼働中サービスを無停止で切り替えるLive Migrationではなく、次の方式とする。

```text
V1を凍結・保全
    ↓
V2を別構成・新DBで並行構築
    ↓
承認されたMaster DataだけをImport
    ↓
V2 Stagingで全機能確認
    ↓
V2を最初の商用Productionとして公開
    ↓
V1をArchive化
```

この方式を次の名称で扱う。

```text
Build-and-Replace Migration
```

## 1.1 採用理由

現時点では次の前提がある。

- 本番決済Providerは未接続
- 実運用は未開始
- V1の決済は開発・検証用mock
- V1にはテストUser、テストPoint、QAデータが含まれ得る
- V2ではPayment、Point、Auth、Session、AuditのSchemaが大きく変わる
- User Storefrontと管理画面を別Next.js Appへ再構築する
- 顧客SiteごとにServer、DB、Redis、Storageを完全分離する

そのため、次の複雑な方式は採用しない。

- V1 / V2 DBへの二重書込み
- Change Data Capture
- Shared Database
- 全Tableへの`tenant_id`
- 稼働中User Sessionの移送
- mock PaymentのV2移送
- V1 Point残高の自動引継ぎ
- V1とV2の長期的な同時Production運用

---

# 2. 現在の基準点

## 2.1 共有資料時点

2026-07-21作成の現状資料では、次の状態だった。

```text
local HEAD:
5914e8b feat: add admin sales management UI

origin/main:
0af553b Add LINE friend reward settings
```

さらに、返金・チャージバック、QA抽選、売上CSV、残高閲覧API等の大規模な未コミット差分が記録されていた。

## 2.2 公開GitHub確認時点

2026-07-22の公開GitHubでは、`main`の先頭は次だった。

```text
bfca8efa0b85c00a88fb0fd439a123b722577b68
feat: add refund QA draw and reporting tools
```

このCommitには、97 Files、約15,043 Additionsがあり、次を含む。

- 返金・チャージバック
- QA Test User / QA Draw
- 売上CSV
- Point残高Snapshot閲覧
- Backend Test
- V1設計書・Test Plan
- `admin-dashboard.tsx`の追加UI

公開Repositoryは確認時点で次の状態だった。

```text
Branch:
mainのみ

Tag:
なし

Release:
なし
```

`frontend/src/app/admin-dashboard.tsx`は引き続き約9,843行、約395KBである。

## 2.3 正式なV1基準Commit

上記`bfca8ef...`はV1基準候補であり、まだ自動的に最終基準としない。

移行開始時に、Platform Codexが次を確認する。

```bash
git status --short
git branch --show-current
git rev-parse HEAD
git fetch origin
git rev-parse origin/main
git log --oneline --decorate -n 30
git diff --check
```

### Localに未保存差分がない場合

```text
HEAD = origin/main = bfca8ef...
```

または、それ以降の人間承認済みCommitを正式なV1基準とする。

### Localに未保存差分がある場合

禁止:

```text
git reset --hard
git checkout .
git clean -fd
```

実施:

1. 差分一覧を保存
2. 機能単位でCommit
3. RemoteへPush
4. Test結果を保存
5. 人間が最終基準Commitを承認

本書内の`<V1_BASELINE_SHA>`は、この確認後のFull SHAへ置き換える。

---

# 3. 移行の不変条件

1. V1の未保存差分を失わない。
2. V1 Migrationを編集・削除しない。
3. V1の抽選、Point、在庫、配送、返金、QAの確定ルールを再現可能な状態で保存する。
4. V2で抽選・Point・在庫処理をNext.jsへ移さない。
5. V1の巨大FrontendをV2へそのままCopyしない。
6. V2管理画面は新しい独立Appとして再実装する。
7. V2 Storefrontは別Repositoryとして再実装する。
8. V1 mock Payment、Test User、Test PointをV2 Productionへ移さない。
9. V2 Productionは新しい空DBから開始する。
10. V2で各SiteのServer、DB、Redis、Storage、Secretを完全分離する。
11. V2のDB変更は確定済み基準設計に従う。
12. UserとAdminの認証Realmを分離する。
13. Public / Admin / Webhook APIを分離する。
14. Stable Release後のMigrationを編集しない。
15. Production Server上でNext.js BuildやDocker Image Buildを行わない。
16. V2 Release前にBackup / Restoreを確認する。
17. V2へ最初のLive Transactionが入った後、V1 DBへ戻すRollbackを行わない。
18. 移行完了判定は「実装済み」だけでなく、Test、Migration、Git、Staging、Releaseを別々に確認する。

---

# 4. 移行対象の分類

V1資産は次の5分類へ分ける。

```text
REUSE
→ Domain RuleとServiceを基本的に再利用

ADAPT
→ 業務Ruleは維持し、V2 Interface / Schemaへ適応

REBUILD
→ 仕様を参考に新規実装

IMPORT
→ 承認済みMaster Dataだけ移送

ARCHIVE
→ V2へ移さず、参照用に保存
```

---

# 5. V1機能別移行方針

| Domain | V1資産 | V2方針 | 分類 |
|---|---|---|---|
| 抽選 | CSPRNG、ppm、Stage、最低保証、連番、Transaction | Characterization Testを追加し、Domain Ruleを維持 | REUSE / ADAPT |
| Probability | Published Version immutable | V2 Schema・Public API制限へ適応 | REUSE / ADAPT |
| Point | Wallet、Lot、Ledger、free優先消費 | Wallet / Lot / Operation / Ledgerへ再構成 | ADAPT |
| Point Snapshot | 実行時残高保存 | Ledger cutoff方式へ置換 | REBUILD |
| Payment | mock Payment、Webhook基盤 | V2 Payment / Provider Event / Adjustmentへ再実装 | REBUILD / ADAPT |
| Refund / CB | Eligibility、取消順、景品hold、返送依頼 | 業務Ruleを維持し、Adjustment 1:NとReservationへ適応 | ADAPT |
| Shipping | 景品単位State / Tracking | V2 API・権限・公開IDへ適応 | REUSE / ADAPT |
| QA Draw | Owner限定、最大24時間、指定順排出、通常Data更新 | Ruleを維持し、Audit / QA識別 / Fresh MFAを追加 | ADAPT |
| Sales / CSV | 売上・Point消費・返金CB帳票 | V2 Payment / Adjustment / Ledger Queryで再構築 | REBUILD |
| Auth | Email認証、SMS基盤、Google基盤、LINE Link | User / Admin Realm、MFA、Sessionへ再構築 | ADAPT / REBUILD |
| Content | Banner、Notice、Static Page、Contact | V2 APIへ移植、必要なMasterをImport | ADAPT / IMPORT |
| Admin UI | 1つの巨大TSX中心 | 新App・Route / Feature単位で再実装 | REBUILD |
| User UI | V1 Next.js内の画面 | `oripa-site-luxe-pack`へ新規実装 | REBUILD |
| Storage | Server内Storage / MinIO基盤 | S3互換Object Storageへ移行 | ADAPT / IMPORT |
| Mail / Discord | Mailgun、Discord通知 | Adapter＋Outboxへ適応 | ADAPT |
| Docs / Tests | 仕様書、Design、Feature / Unit Test | V2仕様の根拠・Regression資産として保存 | REUSE / ARCHIVE |

---

# 6. V2へ移さないData

次はV2 Productionへ移行しない。

```text
users
admins / admin_users
sessions
remember tokens
password reset tokens
email verification tokens
SMS challenges

wallets
point_lots
point_ledgers
point_balance_snapshots
point adjustments

payments
payment reversals
mock provider events
refund / CB test data

draw requests
draw results
user prizes
shipping requests
shipping items

QA test user modes
QA draw plans
QA draw executions

contacts / test inquiries
mail logs
queue jobs
failed jobs
cache
audit logs
application logs
```

理由:

- 本番運用前
- mock / Test / QA Dataが混在し得る
- V2 SchemaとSecurity基準が異なる
- Production開始時点の残高を0から明確にできる
- Provider EventとPayment Grantの監査線を最初から整合させられる

## 6.1 V2 Production開始状態

```text
User:             0
Payment:          0
Point Balance:    0
Draw History:     0
User Prize:       0
Shipping:         0
QA Execution:     0
```

OwnerはV2のCLI Bootstrapで新規作成する。

---

# 7. Import候補Data

人間が明示承認した場合だけ、次をV1からImportできる。

```text
Gacha Category
Gacha Tag
Rank
Rank Asset
Prize Master
Gacha Master
Gacha-Prize Relation
Probability Version
Probability Stage
Probability Entry
Presentation / Animation Asset
Top Banner
Announcement
Static Page
公開用Content
一般運用設定
```

## 7.1 そのままImportしないもの

### Point Purchase Plan

V2では次が確定している。

```text
1 paid point = 1 JPY
購入Bonus = free Point
Published Plan = immutable
```

V1 Planは自動Importせず、V2 Ruleへの適合を確認して再作成する。

### Secret / Provider Config

次はImportしない。

```text
APP_KEY
Payment Secret
Webhook Secret
Mailgun Key
SMS Secret
OAuth Secret
LINE Secret
Storage Secret
Discord Webhook
```

V2 Environmentごとに新しいSecretを設定する。

### Authentication Config

User / Admin Cookie、Session、MFA、RoleはV2基準で新規設定する。

---

# 8. Master Data Import方式

ImportはSQL Dumpの直接投入ではなく、V2 ApplicationのImporterを使用する。

## 8.1 Export形式

V1から次を出力する。

```text
manifest.json
categories.json
tags.json
ranks.json
rank_assets.json
prizes.json
gachas.json
gacha_prizes.json
probability_versions.json
probability_stages.json
probability_entries.json
banners.json
announcements.json
static_pages.json
assets.csv
```

各Fileに次を含める。

- Source schema version
- Source baseline SHA
- Export日時
- Record count
- SHA-256
- Export tool version

SecretやPIIを含めない。

## 8.2 Import監査

V2へ次を追加する。

```text
migration_import_runs
migration_import_id_maps
migration_import_errors
```

### `migration_import_runs`

- public ID
- source baseline SHA
- import type
- manifest checksum
- status
- started / completed at
- imported / skipped / failed count
- executed by
- tool version

### `migration_import_id_maps`

- import run ID
- resource type
- legacy ID
- V2 internal ID
- V2 public ID
- source checksum

### `migration_import_errors`

- import run ID
- resource type
- legacy ID
- error code
- redacted detail

## 8.3 Import順序

```text
1. Category / Tag
2. Rank
3. Rank Asset / Presentation Asset
4. Prize Master
5. Gacha Master
6. Gacha-Prize Relation
7. Probability Version
8. Probability Stage
9. Probability Entry
10. Banner / Announcement / Static Page
11. General Settings
12. Object Storage Asset Link
```

## 8.4 Import Validation

- Record count
- Checksum
- Foreign Key
- Duplicate code / slug
- Published Probability immutable
- 各Stageの合計が1,000,000ppm
- `no_prize`なし
- Result typeは`prize`または`point_back`
- Inventoryが負数でない
- 公開日時の整合
- Asset checksum
- V2 Public APIで景品別個別ppmが出ない
- Site TimezoneがAsia/Tokyo
- Purchase Planは別Validation

ImportはIdempotentにする。

同じManifestの再実行で重複Recordを作らない。

---

# 9. Asset移行

## 9.1 分類

### Site Repositoryへ置く

- Logo
- Favicon
- 固定Brand Image
- Site固有Font設定
- 固定Layout用Asset

### Object Storageへ置く

- Gacha Image
- Prize Image
- Banner
- Rank演出画像
- 演出動画
- Upload Content
- Export File

## 9.2 手順

1. V1 StorageのFile Inventoryを出す
2. File Path、Size、MIME、SHA-256を記録
3. 未参照Fileを分ける
4. Malware / MIME検査
5. V2 Object Keyを生成
6. Site専用BucketへCopy
7. Checksum照合
8. V2 Recordへ`disk` / `object_key`を保存
9. Signed / Public URLを実行時生成
10. V1絶対URLを保存しない

---

# 10. Repository・Branch移行

## 10.1 V1保全

正式な`<V1_BASELINE_SHA>`確定後、次を作成する。

```text
Branch:
archive/v1-current

Annotated Tag:
v1-before-productization-2026-07-22
```

Tag Messageに次を含める。

- Full SHA
- Test結果
- Schema dump checksum
- V1 feature inventory checksum
- 作成者
- 作成日時

`archive/v1-current`はProtectedかつRead-onlyにする。

## 10.2 追加Backup

Repository外の安全な場所へ次を保存する。

- Git Bundle
- V1 Schema-only Dump
- Migration file checksum
- `.env.example`
- Package Lockfile
- V1 Test結果
- V1 Screen一覧
- V1 API Route一覧
- Asset Inventory
- Master Data Export
- Production Secretを除く構成一覧

## 10.3 V2 Branch

```text
v2/bootstrap
```

を`<V1_BASELINE_SHA>`から作成する。

最初のV2 Bootstrap PRが承認・Mergeされた後は、`main`をV2 Integration Branchとする。

V1の新機能を`main`へ追加しない。

V1に緊急修正が必要な場合:

```text
hotfix/v1-<issue>
```

をArchive Branchから作成し、V2にも必要な修正だけを別PRで適用する。

## 10.4 現在のRepository名

`myong-ideal/oripa`をPlatform Repositoryとして継続利用する。

当面、Repository名は変更しない。

---

# 11. 最終Repository構成への移行

最終構成:

```text
oripa/
├── apps/
│   ├── api/
│   └── admin/
├── packages/
│   ├── storefront-client/
│   ├── site-schema/
│   ├── storefront-testkit/
│   └── admin-client/
├── openapi/
├── infrastructure/
├── deployments/
├── docs/
└── legacy/
    └── v1-frontend/
```

## 11.1 移動ルール

File移動と機能変更を同じPRに含めない。

### Mechanical Move PR

```text
backend/
→ apps/api/
```

条件:

- 業務Logic変更なし
- Namespace変更を最小化
- Composer / Docker / CI Pathだけ更新
- Backend Test結果が移動前後で一致
- `git diff --find-renames`でRenameを確認

### Legacy Frontend Move PR

```text
frontend/
→ legacy/v1-frontend/
```

条件:

- V1参照用としてのみ保持
- 新機能追加禁止
- V1比較用の起動方法を文書化
- V2 Production Imageへ含めない

### New Admin PR

```text
apps/admin/
```

は空の新Appから開始する。

`admin-dashboard.tsx`を分割Copyして開始しない。

---

# 12. 別Repository作成

## 12.1 `oripa-site-template`

Platform Codexが作成・管理する。

含むもの:

- Next.js基本構成
- Site Manifest
- Storefront Client
- Testkit Adapter
- API Error Boundary
- Auth Session Boundary
- Security Header
- 空のUI Primitive領域
- 空のDesign Token領域
- CI
- AGENTS.md

含めないもの:

- Luxe Pack固有Design
- 顧客固有画像
- Laravel
- Admin API
- 抽選・Point・Payment Logic

## 12.2 `oripa-site-luxe-pack`

Templateから新規作成する。

Luxe Pack専用Codexが担当する。

V1 Frontendから移してよいもの:

- 承認済みBrand Asset
- 確定文言
- Page要件
- Legal Page内容
- Screenの業務要件

そのまま移してはいけないもの:

- V1 API直接`fetch`
- Auth Cookie処理
- 抽選Logic
- Point Logic
- `admin-dashboard.tsx`
- 共通管理画面
- V1 Global State
- V1の巨大Component
- 未使用CSS

---

# 13. AGENTS.mdと仕様優先順位の移行

現在のRoot `AGENTS.md`はV1仕様を最優先に読む構成である。

V1 Tag作成前に変更してはならない。

V1保全後、Root `AGENTS.md`をV2用へ更新する。

## 13.1 V2 Reading Order

```text
1. Latest explicit human decision
2. V2_ARCHITECTURE_FINAL
3. V2_API_CLIENT_CONTRACT
4. V2_DATA_POINT_PAYMENT_BASELINE
5. V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_REV1
6. V2_PACKAGE_VERSION_COMPATIBILITY_POLICY
7. V1_TO_V2_MIGRATION_PLAN
8. Feature-specific V2 ADR
9. V1 approved specifications as behavioral reference
```

## 13.2 Legacy Rule

V1の仕様優先順位は次へ保存する。

```text
legacy/v1/AGENTS.md
```

V2 CodexがV1の古いTask Boardを現在仕様として扱わないようにする。

---

# 14. Characterization Test

V1 Serviceを変更・移植する前に、現在の業務動作をTestで固定する。

## 14.1 最優先

- Draw Transaction
- `draw_sequence_number`
- ppm / Stage / Minimum Guarantee
- Idempotency
- Point free-first消費
- 同一期限・付与順
- paid FIFO
- Point失効
- Wallet / Lot / Ledger整合
- Shipping item単位State
- Refund Eligibility
- Point Reversal
- Chargeback取消順
- Shortfall
- Prize Hold / Return Request
- QA指定順排出
- QA失敗時Rollback
- Email Verified占有競合
- Phone Verified一意性

## 14.2 比較対象

V1とV2でWire Responseの完全一致は要求しない。

次を比較する。

- DB上の最終業務状態
- Point増減
- Lot残高
- Inventory
- sold / won count
- Draw Result
- User Prize
- Refund / CB Impact
- QA consumed count
- Errorの意味
- Transaction Rollback

API ResponseはV2 OpenAPI Contractへ従う。

---

# 15. API移行方式

## 15.1 Contract First

各Domainは次の順で進める。

```text
1. Use Case確定
2. OpenAPI
3. Error Code
4. Contract Test
5. Storefront / Admin Client生成
6. Laravel V2 Controller
7. Application Service
8. Domain Service接続
9. E2E
```

## 15.2 V1 Routeの扱い

V1 RouteをV2 Schemaの互換性要件にしない。

V1 Environment:

```text
<V1_BASELINE_SHA>
＋
V1 DB
＋
V1 Frontend
```

V2 Environment:

```text
V2 main
＋
V2 DB
＋
V2 Admin
＋
V2 Storefront
```

V2 Source Tree内にV1 Route Fileを一時保持してもよいが、V2 Production RuntimeではV1 APIを公開しない。

## 15.3 API移植順

```text
1. Site Runtime / Health
2. Public Content
3. Catalog / Gacha Read
4. User Registration / Login / Verification
5. Current User / Wallet Read
6. Draw
7. User Prize / Exchange
8. Shipping
9. Payment
10. Refund / Chargeback
11. QA
12. Sales / Reporting / Export
13. Admin API
14. Webhook
```

Payment Provider固有EndpointはProvider ADR確定後に実装する。

---

# 16. DB移行方式

## 16.1 V2 Baseline Migration

V1 Migrationを流用・改変せず、V2用Baseline Migration群を作る。

例:

```text
0001_identity
0002_authentication_security
0003_catalog_probability
0004_wallet_point
0005_payment_adjustment
0006_draw_qa
0007_prize_shipping
0008_content
0009_reporting_export
0010_audit_outbox
0011_indexes_constraints
```

実際のFile単位は、Migration Dependencyを考慮してPlatform Codexが確定する。

## 16.2 必須Test

- `migrate:fresh`
- Seed
- Master Import
- Constraint
- Concurrent Transaction
- Backup
- Restore
- Reconciliation
- Start Snapshot
- Migration checksum

## 16.3 DB比較

V1とV2のTable名・Column名の一致を目標にしない。

業務不変条件と監査可能性を目標にする。

---

# 17. Domain移植の詳細順

## 17.1 Catalog / Probability

最初にRead-only Vertical Sliceを作る。

完了条件:

- Category / Tag / Rank / Prize / Gacha表示
- Published Versionのみ公開
- Stage合計1,000,000ppm
- 景品別個別ppmをPublic APIへ出さない
- Asset表示
- Master Import Test

## 17.2 Identity

次にUser / Admin Realmを実装する。

完了条件:

- User / Admin別Table・Guard・Cookie
- Email Verification競合
- Password 8～128文字
- Argon2id
- Admin MFA
- Owner / Admin / Operator
- Session Timeout
- CSRF / Origin
- Audit

## 17.3 Wallet / Point

Paymentより先にPoint基盤を作る。

完了条件:

- Wallet
- Lot
- Point Operation
- Immutable Ledger
- free-first
- paid FIFO
- Expiry
- Reservation
- Reconciliation
- Ledger cutoff Snapshot

## 17.4 Draw

PointとCatalogを統合する最初の重要なVertical Slice。

完了条件:

```text
AdminでGacha設定
→ Public APIで表示
→ User Login
→ Test用free Point付与
→ Draw
→ Point消費
→ Inventory減少
→ Draw Result
→ User Prize
→ Idempotency Replay
```

同一Transaction、Row Lock、同時実行Testを必須とする。

## 17.5 Prize / Shipping

- User Prize一覧
- Point Exchange
- Shipping Address
- Shipping Request
- 景品単位Status
- Tracking
- PII Access Audit

## 17.6 Payment / Refund / CB

- Provider Adapter
- Provider Event
- Payment Grant 1:1
- Point Reservation
- Refund Saga
- Adjustment 1:N
- Chargeback
- Shortfall
- Financial Hold
- Prize Action
- Outbox
- Provider Replay

mock ProviderはTest / Localだけにする。

## 17.7 QA

- Owner限定
- Fresh MFA
- 最大24時間
- 指定順
- 通常Data更新
- QA識別
- Audit
- Reporting Filter
- E2E
- 同時実行
- 終了時刻境界

## 17.8 Reporting / Export

V1 QueryをそのままCopyせず、V2の次を基準に作る。

- payments
- payment_adjustments
- point_operations
- point_ledger_entries
- QA marker
- export_jobs

少量はStreaming、大量はQueue＋Object Storageとする。

---

# 18. 管理画面移行

## 18.1 新規実装方針

`apps/admin`はRoute / Feature単位で作る。

```text
apps/admin/src/
├── app/
├── features/
├── ui/
└── lib/
```

禁止:

- V1 `admin-dashboard.tsx`の全Copy
- catch-all Route中心
- 全画面Stateを一つのComponentへ集約
- `refreshAll()`
- 非表示画面のMount
- Admin API型を手書きで複製

## 18.2 画面移行順

```text
1. Admin Login / MFA
2. Shell / Navigation / Permission
3. Dashboard
4. Category / Tag / Rank
5. Prize
6. Gacha / Probability / Presentation
7. User / Point
8. Shipping
9. Sales
10. Refund / Chargeback
11. QA
12. Content
13. Settings / Security / Audit
14. Export / Snapshot
```

## 18.3 画面ごとの完了条件

- Route
- Permission
- Loading
- Error
- Empty State
- Form Validation
- Mutation後の局所Query更新
- Keyboard操作
- Mobile最低確認
- E2E
- Audit
- V1業務要件との比較

---

# 19. Luxe Pack Storefront移行

## 19.1 Platform Codexの責任

- API v2
- Storefront Client
- Site Schema
- Testkit
- Auth / CSRF
- Error Code
- Mock Fixture
- Security Header

## 19.2 Luxe Pack専用Codexの責任

- Page Design
- UI Primitive
- Design Token
- Header / Footer
- Gacha Card
- Modal
- Animation
- Responsive
- Brand Asset
- Site固有Content
- Site E2E
- Visual Regression

## 19.3 並行作業のHandoff

### Handoff A

Platform Codexが次を公開する。

```text
OpenAPI 2.0.0-alpha.1
Storefront Client 2.0.0-alpha.1
Site Schema 2.0.0-alpha.1
Test Fixture
```

Site CodexはCatalog / Content画面を開始できる。

### Handoff B

次を公開する。

```text
Auth API
Session Fixture
Wallet Fixture
```

Site CodexはLogin / Register / My Pageを開始できる。

### Handoff C

次を公開する。

```text
Draw API
Idempotency
Draw Fixture
```

Site CodexはDraw Flowを開始できる。

### Handoff D

次を公開する。

```text
Payment Provider ADR
Payment API
Shipping API
```

Site CodexはPurchase / Shippingを完成できる。

Site Codexは未確定APIを推測して作らない。

---

# 20. PhaseとGate

## Phase 0: V1 Freeze・Evidence

### 作業

- Local差分確認
- 必要なCommit / Push
- V1 Test
- Feature Inventory
- API Route Inventory
- DB Schema-only Dump
- Migration checksum
- Asset Inventory
- V1 Screen Inventory
- Git Bundle
- Archive Branch
- Annotated Tag

### Gate G0

- Working Tree clean
- Baseline Full SHA確定
- RemoteへPush済み
- Archive Branchあり
- Annotated Tagあり
- V1 Test結果保存
- Secret混入なし
- Schema / Asset Inventoryあり

G0完了前にV2構造変更を開始しない。

---

## Phase 1: V2 Governance・Repository骨格

### 作業

- 確定済みV2文書をRepositoryへ保存
- Architecture Index
- Root AGENTS.md更新
- Branch Protection
- CODEOWNERS
- Secret Scan
- CI Skeleton
- `apps/`、`packages/`、`openapi/`、`infrastructure/`
- Release / Compatibility Manifest Schema

### Gate G1

- V2文書が正本としてCommit済み
- V1仕様がLegacyへ隔離
- CIが空SkeletonでPASS
- Platform CodexとSite CodexのPath境界あり
- Version 2.0.0-alpha.1方針あり

---

## Phase 2: Mechanical Structure

### 作業

- `backend` → `apps/api`
- `frontend` → `legacy/v1-frontend`
- V1 reference compose
- V2 compose skeleton
- `apps/admin` skeleton
- pnpm workspace
- Package skeleton

### Gate G2

- Mechanical Move前後でBackend Test一致
- V1 referenceが起動可能
- V2 Skeletonが起動可能
- Business Logic変更なし
- Production ImageへLegacy Frontendが含まれない

---

## Phase 3: Contract・Client・DB Baseline

### 作業

- OpenAPI 3.1.1
- Public / Admin / Webhook Contract
- Storefront Client
- Site Schema
- Testkit
- V2 Baseline Migration
- User / Admin Realm
- Point / Payment基礎Table
- Audit / Outbox
- Health / Site Runtime

### Gate G3

- `migrate:fresh`
- OpenAPI lint
- Generated Client clean
- Realm separation
- Contract Test
- Constraint Test
- Backup / Restore初回確認
- `2.0.0-alpha.1` Artifact作成

---

## Phase 4: Core Vertical Slice

### 作業

- Catalog / Probability
- User Auth
- Wallet / Point
- Draw
- User Prize
- 最小Admin画面
- 最小Luxe Pack画面

### Gate G4

次のFlowがStagingで通る。

```text
Owner Login＋MFA
→ Gacha設定
→ User登録＋認証
→ Test free Point付与
→ Gacha表示
→ Draw
→ Point / Inventory / Result整合
→ User Prize表示
```

さらに:

- Idempotency
- Concurrent Draw
- Rollback
- Audit
- API / Client Compatibility
- Site E2E

---

## Phase 5: Full Functional Migration

### 作業

- Shipping
- Content
- Google OIDC
- SMS Verification
- LINE Linking
- QA
- Sales
- Export
- Snapshot
- Refund / CB
- Settings
- Security UI

### Gate G5

V1機能Inventoryの各項目が次のいずれかになっている。

```text
V2 Implemented
Intentionally Changed
Deferred by approved ADR
Not Applicable
```

未分類項目を残さない。

---

## Phase 6: Payment Provider・Commercial Hardening

### 作業

- Provider選定
- Provider ADR
- Production Adapter
- Webhook
- Refund
- Chargeback
- 3D Secure等
- S3
- Mail / SMS
- Monitoring
- Load Test
- Security Test
- Legal / Accounting確認

### Gate G6

- mock Payment Production拒否
- Provider Webhook Replay Test
- Payment二重Grantなし
- Refund Saga
- CB
- Backup / Restore
- ASVS Checklist
- DAST
- Pentest
- High / Critical未解決0
- 法務・会計Blocker解消

---

## Phase 7: Master Import・Release Candidate

### 作業

- Master Data Freeze
- Export Manifest
- Asset Copy
- V2 Production相当DBへImport
- Import checksum
- Owner Bootstrap
- Environment Secret
- Release Manifest
- Deployment Manifest
- RC E2E
- Cutover Rehearsal

### Gate G7

- Import Record Count一致
- Checksum一致
- Probability Validation
- Asset Validation
- User / Payment / Pointが0
- Owner MFA
- Production Config Validation
- Release Candidate承認
- Rollback条件記載

---

## Phase 8: Production Cutover

### 作業

1. V1編集停止
2. 最終Master Export
3. V2 fresh DB確認
4. 最終Import
5. Secret / Provider疎通
6. Backup取得
7. V2 Image Digest確認
8. Migration
9. Smoke Test
10. DNS / Nginx切替
11. Storefront / Admin / Webhook確認
12. Monitoring確認
13. Deployment Manifest確定

### Gate G8

- User Registration
- Login
- Admin MFA
- Catalog
- Draw Test
- Payment Test
- Mail
- Webhook
- Shipping
- Audit
- Backup
- Monitoring

すべてPASS後に商用利用を開始する。

---

## Phase 9: V1 Retirement

### 作業

- V1をNetworkから外す
- Archive Branch / Tag確認
- V1 DBをRead-only化
- Secret破棄・Rotation
- V1 Infrastructure削除
- Legacy FrontendをV2 Production Build対象から除外
- Final Migration Report
- V1 Asset残件確認

### Gate G9

- V2安定版Release
- V1参照手段あり
- V1 Secret無効化
- V1からのTrafficなし
- V2 Backup Restore確認
- 未移行Masterなし
- Migration Closure承認

---

# 21. Point of No Return

## 21.1 V2にLive Transactionがない段階

Rollback可能:

```text
DNS / NginxをV1参照環境へ戻す
V2を修正
再度Cutover
```

ただしV1は商用決済を受け付けない。

## 21.2 V2に最初のLive Dataが入った後

次のいずれかが発生した時点をPoint of No Returnとする。

- Production User Registration
- Production Payment
- Production Point Grant
- Production Draw
- Production User Prize
- Production Shipping Request

この後は、V1 DBへ戻して運用を続けてはならない。

理由:

- V2 User / Admin Realmが異なる
- V2 Wallet / Operation / Ledgerが異なる
- V2 Payment / Adjustmentが異なる
- V1への逆変換を設計していない
- Transaction喪失の危険がある

この後のRollbackは次のいずれかとする。

```text
V2 Storefront Image Rollback
V2 Admin Image Rollback
V2 Platform Application Rollback
Feature Flag停止
Forward Fix
V2 Backup Restore
Maintenance Mode
```

Release Manifestで許可された範囲だけをRollbackする。

---

# 22. No-Go条件

次のいずれかに該当する場合、次Phaseへ進まない。

- Local未コミット差分が未確認
- V1 Tag / Archive Branchなし
- SecretがGitへ存在
- V1 Test基準が未保存
- 確定済みV2文書がRepositoryへ未反映
- API Contractと実装が不一致
- User / Admin Sessionが共有
- Admin MFA未実装
- `@oripa/storefront-client`を迂回する直接API実装
- V2 Production DBにV1 Test User / Point / Paymentが存在
- Master Import checksum不一致
- Probability total不一致
- mock PaymentがProductionで有効
- Payment二重Grant Test失敗
- Draw / Point同時実行Test失敗
- Refund / CB Test失敗
- Backup Restore未確認
- Migration fresh失敗
- Critical / High Security問題
- Provider未確定の処理を推測実装
- 法務・会計の公開Blocker未解決
- Release Manifestなし
- Rollback可否が未記載
- Production Server上でBuildが必要

---

# 23. V1機能Parity Matrix

次の状態を別々に記録する。

```text
Specification Confirmed
V1 Characterized
V2 Implemented
Unit Test
Feature Test
Contract Test
E2E
Migration
Git Commit
Git Push
Staging
Production
```

## 23.1 必須Domain

- User Registration
- Email Verification
- Password Login
- SMS Verification
- Google OIDC
- LINE Linking / Reward
- Referral
- Category / Tag
- Gacha
- Prize / Rank
- Probability / Stage
- Presentation Asset
- Draw
- Point Grant / Spend / Expiry
- Daily Limit
- User Prize
- Point Exchange
- Shipping
- Banner / Notice / Static Page
- Contact
- Sales
- Export
- Snapshot
- Refund
- Chargeback
- QA Draw
- Admin / Role / MFA
- Audit / Security
- Provider / Mail / Notification

Parity Matrixは`Implemented`だけで完了にしない。

---

# 24. PR・Task分割

推奨Task ID:

```text
MIG-000  V1 Local / Remote差分確認
MIG-001  V1 Evidence Bundle
MIG-002  Archive Branch / Tag

MIG-010  V2 Architecture文書Commit
MIG-011  Root AGENTS / Governance
MIG-012  CI / Branch Protection / CODEOWNERS

MIG-020  Workspace Skeleton
MIG-021  backend → apps/api Mechanical Move
MIG-022  frontend → legacy/v1-frontend Mechanical Move
MIG-023  apps/admin Skeleton

MIG-030  OpenAPI Skeleton
MIG-031  Storefront Client
MIG-032  Site Schema
MIG-033  Storefront Testkit

MIG-040  V2 DB Baseline
MIG-041  Identity / Admin Realm
MIG-042  Audit / Outbox
MIG-043  Point Model
MIG-044  Payment Model

MIG-050  Catalog / Probability
MIG-051  Draw Vertical Slice
MIG-052  Prize / Shipping
MIG-053  QA
MIG-054  Reporting / Export
MIG-055  Payment Provider

MIG-060  New Admin App
MIG-061  Site Template
MIG-062  Luxe Pack Storefront

MIG-070  Master Exporter
MIG-071  Master Importer
MIG-072  Asset Migrator

MIG-080  Full E2E / Load / Security
MIG-081  RC / Rehearsal
MIG-082  Production Cutover
MIG-083  V1 Retirement
```

各Taskは、原則として一つの責任だけを持つ。

---

# 25. Codexの担当

## 25.1 Platform Codex

担当:

- V1 Inventory
- V1 Characterization
- Platform Repository
- Laravel
- DB Migration
- Public / Admin / Webhook API
- OpenAPI
- Storefront Client
- Site Schema
- Testkit
- Admin App
- Importer
- Infrastructure
- Release / Deployment Manifest
- Cutover Runbook

禁止:

- V1差分の無断破棄
- Provider固有処理の推測
- Stable Migration編集
- Site固有Designの無断変更

## 25.2 Luxe Pack専用Codex

担当:

- `oripa-site-luxe-pack`
- Page Design
- UI Primitive
- Design Token
- Layout
- Brand Asset
- Responsive
- Animation
- Site Visual / E2E

禁止:

- Platform RepositoryのLaravel
- Admin App
- Storefront Client内部
- API Contract
- Cookie / CSRF
- Payment / Point / Draw Logic
- 他Site Repository

## 25.3 並行作業

同じFileを両Codexが編集しない。

Platform CodexがContract / Client Alphaを公開し、Site CodexがExact Versionを導入する。

未確定InterfaceはMockで勝手に固定せず、Platform Change Requestを作成する。

---

# 26. Migration Deliverables

移行完了時に次が存在する。

## Architecture

- V2 Architecture
- API / Client Contract
- DB / Point / Payment Baseline
- Identity / Security Baseline Rev1
- Version / Compatibility Policy
- V1 → V2 Migration Plan
- Provider ADR
- Feature ADR

## V1 Evidence

- V1 Baseline SHA
- Archive Branch
- Annotated Tag
- Git Bundle
- Schema Dump
- Migration Checksum
- Feature Inventory
- API Inventory
- Screen Inventory
- Test Results
- Asset Inventory

## V2 Product

- Platform 2.0.0
- Public / Admin / Webhook API v2
- Storefront Client 2.0.0
- Site Schema 2.0.0
- Testkit 2.0.0
- Site Template 1.0.0
- Luxe Pack Storefront 1.0.0
- V2 DB Baseline
- Admin App
- Importer
- Release Manifest
- Deployment Manifest

## Operations

- Install Guide
- Upgrade Guide
- Backup / Restore
- Incident Runbook
- Break-glass
- Cutover Runbook
- Rollback Matrix
- Monitoring
- Security Checklist
- Legal / Accounting Sign-off Record

---

# 27. V2完成条件

V2への移行は、次をすべて満たした時点で完了とする。

1. V1がTag、Archive Branch、Evidence Bundleで保全されている。
2. V1 Local差分が失われていない。
3. V2 Productionが新しい独立DBで稼働している。
4. V1 Test User / Point / PaymentがV2 Productionに存在しない。
5. UserとAdminが別Realmである。
6. Public / Admin / Webhook Runtimeが分離されている。
7. Storefrontと管理画面が別Appである。
8. Luxe Pack Storefrontが別Repositoryである。
9. Point / Payment / AdjustmentがV2基準に従う。
10. 本番Payment Providerが接続されている。
11. mock PaymentがProductionで無効である。
12. Draw / Point / Inventoryの同時実行TestがPASSしている。
13. Refund / CB / Webhook Replay TestがPASSしている。
14. Admin MFAが有効である。
15. QA識別、Audit、Reporting Filterが機能している。
16. Master ImportとAsset checksumが一致している。
17. Backup / Restoreが確認済みである。
18. Security GateがPASSしている。
19. 法務・会計の公開Blockerが解消している。
20. Platform / Site Release Manifestが確定している。
21. V1へのProduction Trafficがない。
22. V1 Secretが失効している。
23. Migration Closureが人間に承認されている。

---

# 28. 実作業の最初の順序

最初の実装は次の順から変更しない。

```text
1. MIG-000 Local / Remote差分確認
2. MIG-001 V1 Evidence Bundle
3. MIG-002 Archive Branch / Annotated Tag
4. V2確定文書をRepositoryへ保存
5. Root AGENTS.mdをV2へ切替
6. CI / Branch Protection
7. Workspace Skeleton
8. Mechanical Move
9. OpenAPI / Client / DB Baseline
10. Core Vertical Slice
```

V1保全前に、`backend`や`frontend`を移動しない。

---

# 29. 最終確定要旨

V1は消去せず、動作・仕様・Testを含むReference Baselineとして固定する。

V2はV1 DBへ継ぎ足すのではなく、新しいPackage Productとして構築する。

```text
V1
├── Archive Branch
├── Immutable Tag
├── V1 DB / Schema Evidence
├── V1 Frontend
└── Characterization Test

V2 Platform
├── Laravel API
├── Admin App
├── OpenAPI
├── Storefront Client
├── V2 DB
├── Importer
└── Release Manifest

Luxe Pack Site
├── Separate Repository
├── Site専用UI
├── UI Primitive
├── Design Token
└── Site Deployment Manifest
```

移すもの:

```text
確定した業務Rule
Backend Test
承認済みMaster Data
承認済みAsset
確定文言
```

移さないもの:

```text
V1 mock Payment
Test User
Test Point
Test Draw
V1 Session
V1 Admin Account
V1巨大Frontend構造
V1 Secret
```

この方式により、V1の複雑な業務資産を失わず、V2をサイトごとに完全独立して配布・更新できるPackageへ移行する。

本書を、V1からV2への移行作業の正式な基準とする。
