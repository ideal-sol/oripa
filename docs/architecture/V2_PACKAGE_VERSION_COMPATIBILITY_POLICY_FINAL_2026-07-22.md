# オリパ・パッケージ V2
# パッケージVersion・互換性ルール 最終確定版

- 文書ID: `V2-PACKAGE-VERSION-COMPATIBILITY-POLICY-001`
- 状態: **FINAL / Architecture Baseline 1.0**
- 確定日: 2026-07-22
- 適用対象: オリパ・プラットフォーム V2、全顧客サイト、全配布Package、全Container Image
- 保存推奨先: `docs/architecture/V2_PACKAGE_VERSION_COMPATIBILITY_POLICY_FINAL_2026-07-22.md`

## 優先関係

1. 人間による最新の明示決定
2. 本書
3. `V2_IDENTITY_AUTHORIZATION_SECURITY_BASELINE_FINAL_REV1_2026-07-22.md`
4. `V2_DATA_POINT_PAYMENT_BASELINE_FINAL_2026-07-22.md`
5. `API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`
6. V2全体設計
7. V1の確定仕様・実装記録

本書は、API契約で確定済みの次の原則を維持し、Versionと互換性の詳細を補完する。

- Public APIは`/api/v2`
- Admin APIは`/admin/api/v2`
- Webhook APIは`/webhooks/v2`
- `@oripa/storefront-client`を使用する
- Storefront ClientのMajorはAPI Majorと一致させる
- 各サイトはFirst-party Packageを完全固定する
- 共通Package更新を全サイトへ自動反映しない
- 各サイトのServer、DB、Redis、Storage、Secret、Deploymentは完全独立する

---

# 1. 目的

このルールの目的は、次を同時に満たすことである。

1. サイトごとに異なる時期に安全に更新できる
2. Site Aの更新がSite Bへ自動影響しない
3. API、Storefront Client、DB、管理画面の互換性を機械的に判定できる
4. 同じVersion番号のArtifactが後から別内容へ変わらない
5. Productionで使用しているSource、Image、Package、Migrationを特定できる
6. Breaking Changeを通常のMinor / Patchへ混入させない
7. Security Updateを短時間で個別Siteへ展開できる
8. Rollback可能性をRelease前に判断できる
9. 複数CodexがVersion判断を独自解釈しない
10. 将来のPlatform V3へ明確な移行経路を持つ

---

# 2. 採用するVersion方式

Semantic Versioning 2.0.0を採用する。

```text
MAJOR.MINOR.PATCH

例:
2.0.0
2.1.0
2.1.1
```

Pre-release:

```text
2.1.0-alpha.1
2.1.0-beta.1
2.1.0-rc.1
```

本書におけるSemVerの意味:

```text
MAJOR
→ 互換性を壊す変更

MINOR
→ 後方互換性を維持した機能追加

PATCH
→ 後方互換性を維持した修正
```

Stable Releaseを公開した後、同じVersion番号のSource、Package、Release Asset、Container Imageを差し替えてはならない。

変更が必要な場合は必ず新しいVersionを発行する。

---

# 3. Core Compatibility Family

## 3.1 Familyの考え方

V2の中核Componentを、同じMajor番号のCompatibility Familyとして管理する。

```text
Core Compatibility Family 2
├── Platform 2.x
├── Public API v2 / Contract 2.x
├── Admin API v2 / Contract 2.x
├── Webhook API v2 / Contract 2.x
├── @oripa/storefront-client 2.x
├── @oripa/site-schema 2.x
└── @oripa/storefront-testkit 2.x
```

## 3.2 Major同期

中核境界にBreaking Changeが必要になった場合は、次を同じFamilyへ移行する。

```text
Platform 3.x
Public API v3
Admin API v3
Webhook API v3
@oripa/storefront-client 3.x
@oripa/site-schema 3.x
@oripa/storefront-testkit 3.x
```

MinorとPatchはComponentごとに独立してよい。

例:

```text
Platform                 2.4.1
Public API Contract      2.6.0
Storefront Client        2.3.2
Site Schema              2.1.0
Storefront Testkit       2.2.1
```

MajorだけをCompatibility Familyとして揃える。

## 3.3 独立Version

次はCore Familyとは独立したVersionを持つ。

```text
oripa-site-template
各顧客Storefront
各顧客のデザイン
各顧客のコンテンツ
```

例:

```text
oripa-site-template      1.3.0
Luxe Pack Storefront     1.8.2
Customer A Storefront    1.2.0
```

顧客StorefrontのVersionが`1.x`でも、Platform Family 2を利用できる。

---

# 4. Versionを持つ単位

| 対象 | Version所有者 | Version方式 | 配布単位 |
|---|---|---|---|
| Platform Release | Platform Codex | SemVer | GitHub Release / Manifest |
| Laravel API Image | Platform Releaseを継承 | Platform Version | OCI Image |
| Queue / Scheduler | API Imageを共有 | Platform Version | OCI Image |
| Admin Next.js Image | Platform Releaseを継承 | Platform Version | OCI Image |
| Public API Contract | Platform Codex | SemVer、MajorはPathと一致 | OpenAPI |
| Admin API Contract | Platform Codex | SemVer、MajorはPathと一致 | OpenAPI |
| Webhook API Contract | Platform Codex | SemVer、MajorはPathと一致 | OpenAPI |
| Storefront Client | Platform Codex | SemVer | Private Package |
| Site Schema | Platform Codex | SemVer | Private Package |
| Storefront Testkit | Platform Codex | SemVer | Private Package |
| Admin Client | Platform内部 | Platform Versionを継承 | Workspaceのみ |
| Site Template | Platform Codex | 独立SemVer | Template Repository |
| 顧客Storefront | Site専用Codex | 独立SemVer | Site Image / Release |
| DB Schema | Platform Codex | Migration Revision | PostgreSQL |
| Deployment Manifest Schema | Platform Codex | 整数Version | YAML Schema |
| Site Manifest Schema | Site Schema Package | Core Family Major | TypeScript / JSON Schema |

---

# 5. Platform Version

Platform Releaseは次を一つの互換性単位として配布する。

```text
Laravel API
Admin Next.js
Migration
Queue / Scheduler
Nginx / Runtime設定
OpenAPI Contract
運用Script
Release Manifest
```

## 5.1 Platform MAJOR

次のいずれかを含む場合はMAJORを上げる。

- Public / Admin / Webhook APIの既存Path削除・変更
- Request / Response Fieldの削除・Rename・型変更
- Fieldの意味変更
- 認証Realm、Session、Cookie、CSRF方式の非互換変更
- Idempotencyの意味変更
- Point・Payment・Drawの中核業務ルール変更
- Site Manifestの必須FieldをDefaultなしで追加
- Config Keyの削除または非互換Rename
- Storefront ClientのPublic Interface破壊
- 既存Siteのコード変更なしでは起動できない変更
- 既存DBから通常の自動Upgradeができない破壊的変更
- 必須Runtime Major変更により既存Site build / deployが不可能になる変更
- Compatibility Familyの更新

例:

```text
2.8.4
→
3.0.0
```

## 5.2 Platform MINOR

次はMINORとする。

- 後方互換な新機能
- 新しいAPI Endpoint
- Optional Response Field
- Optional Request Field
- 新しいCapability
- 新しい管理画面機能
- 新しいTable / Nullable Column
- Secure Default付きの新Config
- 新しいProvider Adapter
- 新しいExport種別
- 新しい通知
- Additiveな権限・監査Event
- Runtimeの同一互換範囲内での更新

## 5.3 Platform PATCH

次はPATCHとする。

- 仕様どおりに戻すBug Fix
- Security Fix
- Performance Fix
- Error Message修正
- Index追加
- 安全なData Correction
- Retry / Timeoutの修正
- Log / Monitoring修正
- Documentation修正と同時に必要となる実装修正
- Existing Contractを変更しないValidation修正

PATCHで新しい必須Configを追加してはならない。

PATCHにMigrationを含める場合は、次に限定する。

- Index追加
- 既存契約を強制するConstraint追加
- IdempotentなData Repair
- 既存ColumnのDefault修正
- 後方互換な運用修正

新Tableや新機能用Columnは原則MINORとする。

## 5.4 Hotfix

`hotfix`という独自Stable Version形式を作らない。

```text
禁止:
2.3.1-hotfix
2.3.1-final2

採用:
2.3.2
```

---

# 6. API Contract Version

## 6.1 Path

URLへ含めるのはMajorだけとする。

```text
/api/v2
/admin/api/v2
/webhooks/v2
```

Minor / PatchはURLへ含めない。

## 6.2 OpenAPI `info.version`

各OpenAPI Documentは独立したSemVerを持つ。

```yaml
info:
  version: 2.4.0
```

Platform Release Manifestに、3つのContract Versionを記録する。

## 6.3 API MINOR

次はContract MINORを上げる。

- 新Endpoint
- 新Optional Field
- 新Optional Query
- 新Error Code
- 新Capability
- 新Enum値
- 新しい後方互換なWebhook Event

Enum追加はMINORとする。

StorefrontとAdminは未知のEnum値に対するFallbackを持たなければならない。

## 6.4 API PATCH

次はContract PATCHとする。

- Description修正
- Example修正
- 実装と一致させる非意味的Schema修正
- Error説明の明確化
- Contract上の挙動を変えないConstraint表現修正

Accepted / Rejected Requestが変わるValidation変更は、影響に応じてMINORまたはMAJORとする。

## 6.5 API MAJOR

次はMAJORとし、新Pathを作る。

- Endpoint削除
- HTTP Method変更
- Path変更
- Field削除・Rename
- Field型変更
- OptionalからRequiredへの変更
- Pagination方式変更
- Auth方式変更
- Error判定方法変更
- Idempotency意味変更
- Responseの業務的意味変更

## 6.6 Public APIの保証

StableなPublic API v2は、API v3へ移行するまで既存の公開Operationを後方互換で維持する。

Bugとして明らかなSecurity欠陥やData Corruptionを修正する場合は例外とするが、Security AdvisoryとMigration Guidanceを必須とする。

---

# 7. Storefront Client Version

Package:

```text
@oripa/storefront-client
```

## 7.1 MAJOR

- 対応API Major変更
- Public Method削除・Rename
- Parameter / Return Typeの非互換変更
- Error Classの非互換変更
- Browser / Server Entry Point変更
- CSRF / Idempotency Interfaceの非互換変更

## 7.2 MINOR

- 新しいClient Method
- 新しいType
- 新しいOptional Option
- 新Capability対応
- 新しいError Codeの型
- 後方互換なRetry / Header機能

## 7.3 PATCH

- Internal HTTP処理修正
- Typeの非破壊的修正
- Error Parse修正
- Timeout / Retry Bug Fix
- Documentation修正
- Security Fix

## 7.4 Framework依存

Storefront Clientは、可能な限り次に依存しない。

- React
- Next.js
- UI Framework
- State Management
- CSS
- Design Token

Browser FetchとServer Fetchを薄く包むPackageとし、React Major変更をClient Majorへ波及させない。

## 7.5 Package Metadata

Storefront Clientは、公開Package Metadataとして次を持つ。

```json
{
  "oripaCompatibility": {
    "family": 2,
    "apiMajor": 2,
    "minimumPublicApiContract": "2.3.0",
    "requiredCapabilities": [
      "auth.session.v2",
      "draw.idempotency.v1"
    ]
  }
}
```

---

# 8. Site Schema / Testkit

## 8.1 `@oripa/site-schema`

Site Manifest、Feature設定、Client Version、Required CapabilityをValidationする。

MAJOR:

- 既存Site Manifestをそのまま読み込めない変更
- Required Field追加でDefaultなし
- Field削除・Rename・型変更

MINOR:

- Optional Field追加
- Secure Default付きField追加
- 新Capability宣言形式

PATCH:

- Validation Bug Fix
- Error Message
- Typeの非破壊的修正

## 8.2 `@oripa/storefront-testkit`

MAJOR:

- 対応するCore Family変更
- Test Adapter Interface破壊

MINOR:

- 新しいConformance Test
- 新しいOptional Feature Test
- 新しいSecurity Test

PATCH:

- Testの誤判定修正
- Fixture修正
- Browser互換修正

新しい必須Testにより既存Siteが失敗する場合はMINOR以上とし、Release Noteへ理由と対応方法を記載する。

---

# 9. Site Templateと顧客Storefront

## 9.1 Site Template

```text
oripa-site-template 1.x
```

TemplateはCore Familyとは独立してVersion管理する。

TemplateからSiteを作成した後、Template更新を自動適用しない。

各Siteへ次を記録する。

```yaml
template_origin:
  repository: oripa-site-template
  version: 1.2.0
```

Templateの更新を取り込む場合は、専用PRとして変更内容を確認する。

## 9.2 顧客Storefront

各Siteは独立SemVerを持つ。

MAJOR例:

- User URLの大規模変更
- Design System全面変更
- 主要User Flowの非互換変更
- Site固有Configの破壊的変更

MINOR例:

- 新Page
- 新Design
- 新Banner枠
- 新Animation
- 新Feature利用
- 新しいResponsive Layout

PATCH例:

- CSS修正
- 表示崩れ
- 文言修正
- Accessibility修正
- Site固有Bug Fix

Site VersionはPlatform互換性の判定に使用しない。

互換性は、利用しているClient VersionとRequired Capabilityで判定する。

---

# 10. First-party Packageの固定

各Siteの`package.json`は、First-party Packageを完全固定する。

```json
{
  "dependencies": {
    "@oripa/storefront-client": "2.3.2",
    "@oripa/site-schema": "2.1.0"
  },
  "devDependencies": {
    "@oripa/storefront-testkit": "2.2.1"
  }
}
```

禁止:

```json
"@oripa/storefront-client": "^2.3.2"
"@oripa/storefront-client": "~2.3.2"
"@oripa/storefront-client": "latest"
"@oripa/storefront-client": "*"
```

Platform Packageが公開されても、SiteのVersionは自動更新しない。

各Siteへ個別のUpdate PRを作成する。

---

# 11. Dependency Lock方針

## 11.1 Node / pnpm

- `pnpm-lock.yaml`を必ずCommit
- `packageManager`へpnpmの正確なVersionを記録
- `.npmrc`で`save-exact=true`
- CIとBuildでは`pnpm install --frozen-lockfile`
- Release Buildで`pnpm update`を実行しない
- Lockfile変更は独立してReview可能にする
- Production SiteはPre-release dependencyを使用しない
- Registry認証情報をRepositoryへCommitしない

Platform Monorepo内では`workspace:*`を使用できるが、公開Packageおよび顧客SiteのManifestでは具体的Versionへ解決されていなければならない。

## 11.2 Composer

- `composer.lock`を必ずCommit
- Production Buildは`composer install`でLock済みVersionを使用
- Release Buildで`composer update`を実行しない
- Laravel Frameworkは一つのMajor Lineだけを許可
- Stable V2で`^12.0 || ^13.0`のような複数MajorのOR指定を使用しない
- Exact RuntimeとExtensionをContainer / Release Manifestへ記録
- `platform-check`を有効にする
- `minimum-stability`は`stable`

Composerの`composer.json`は互換範囲を表し、実際のDeploy内容は`composer.lock`で固定する。

## 11.3 Third-party Update

Dependency更新は次の単位でPRを分ける。

- Security Patch
- Patch Update
- Minor Update
- Major Update
- Runtime Update
- Build Tool Update

Major Updateを通常機能PRへ混在させない。

---

# 12. Runtime Version

Platform Release Manifestへ次の正確なVersionを記録する。

- PHP
- Composer
- Laravel
- Node.js
- pnpm
- Next.js
- PostgreSQL
- Redis
- Nginx
- Object Storage Client
- Base Container Image Digest

## 12.1 Runtime変更の判定

PATCH:

- 同じMajor / Minor範囲のSecurity Patch
- Base ImageのSecurity更新
- PHP / NodeのPatch更新

MINOR:

- 同じCompatibility範囲のRuntime Minor更新
- Site / Plugin Interfaceを壊さないBuild Tool更新

MAJOR:

- 顧客SiteのBuild要件を壊すNode Major変更
- Laravel Major変更
- PHP Major変更
- PostgreSQL Major変更で手動移行が必須
- 既存Deployment Manifestを読めない変更

ContainerがRuntimeを内包していても、Database UpgradeやSite Buildへ影響する場合はBreaking Changeとして扱う。

---

# 13. CapabilityによるFeature判定

Site UIはPlatform Minor Versionを比較してFeature有無を判断してはならない。

禁止:

```ts
if (platformVersion >= "2.4.0") {
  showFeature();
}
```

採用:

```ts
if (capabilities.includes("qa.reporting.v1")) {
  showFeature();
}
```

## 13.1 Capability命名

```text
<domain>.<feature>.v<number>
```

例:

```text
auth.session.v2
draw.idempotency.v1
payments.redirect.v1
qa.reporting.v1
shipping.prize-unit.v1
```

Capability名の意味を後から変更しない。

意味を変更する場合は新しいCapabilityを作る。

## 13.2 Site Requirement

Site Manifest:

```yaml
compatibility:
  family: 2
  required_capabilities:
    - auth.session.v2
    - draw.idempotency.v1
```

Deploy前にTarget PlatformがすべてのCapabilityを持つか確認する。

---

# 14. Runtime互換性判定

## 14.1 `/api/v2/site`

Public Runtime情報として次を返す。

```json
{
  "data": {
    "compatibility_family": 2,
    "public_api_contract_version": "2.4.0",
    "minimum_storefront_client_version": "2.2.0",
    "recommended_storefront_client_version": "2.3.2",
    "capabilities": [
      "auth.session.v2",
      "draw.idempotency.v1"
    ]
  }
}
```

Public Responseへ次を出さない。

- Git Commit SHA
- Internal Image Digest
- Database Schema Revision
- Secret
- Infrastructure詳細

詳細情報はOwner / Infrastructure専用の内部Health情報で確認する。

## 14.2 Header

Request:

```text
X-Oripa-Client-Version
X-Oripa-Site-Version
```

Response:

```text
X-Oripa-Api-Version: 2
X-Oripa-Api-Contract-Version: 2.4.0
X-Oripa-Platform-Version: 2.3.1
```

Headerは監視・互換性判定用であり、認証・認可に使用しない。

## 14.3 Unsupported Client

通常はAPI v2内で後方互換性を維持するため、古い2.x Clientを即時拒否しない。

重大なSecurity理由で最低Client Versionを引き上げる場合:

- `/api/v2/site`は常に利用可能
- Public Readは可能な範囲で維持
- High-risk Mutationは拒否可能
- `409 CLIENT_VERSION_UNSUPPORTED`
- 最低Versionと更新期限をProblem Detailsで返す
- Security AdvisoryとSite別Update PRを発行

---

# 15. Compatibility Matrix

Platform Releaseごとに、次を`compatibility-matrix.yaml`へ記録する。

```yaml
platform_version: 2.4.1
family: 2

contracts:
  public_api: 2.6.0
  admin_api: 2.5.0
  webhook_api: 2.3.1

storefront:
  minimum_client: 2.3.0
  tested_clients:
    - 2.3.0
    - 2.4.2
  minimum_site_schema: 2.1.0

database:
  minimum_platform_from: 2.3.0
  target_schema_revision: "20260722.004"
  application_rollback_compatible_to:
    - 2.4.0

capabilities:
  - auth.session.v2
  - draw.idempotency.v1
  - qa.reporting.v1
```

## 15.1 Test対象

新しいPlatform Releaseは最低限、次をTestする。

- 最新Storefront Client
- 一つ前のSupported Client Minor
- 最新Site Schema
- 一つ前のSupported Site Schema Minor
- 最新Luxe Pack Site
- Demo Site
- 前Supported Platform VersionからのDB Upgrade
- Same Releaseのfresh install
- Rollback Compatibleと宣言したVersionへのApplication Rollback

---

# 16. Database Schema Version

DB SchemaはSemVerではなく、順序付きMigration Revisionで管理する。

```text
20260722.001
20260722.002
20260722.003
```

Laravelの`migrations` Tableに加え、次を保存する。

```text
platform_schema_state
```

主要項目:

- current platform version
- schema revision
- migration set checksum
- applied release
- applied at
- previous platform version
- rollback compatibility
- deployment ID

## 16.1 Released Migrationの不変性

Stable Releaseへ含めたMigration Fileを後から編集してはならない。

修正は新しいMigrationで行う。

CIはReleased MigrationのChecksumを検証する。

## 16.2 Forward-only

Productionでは原則として次を使用しない。

```text
php artisan migrate:rollback
```

DB修正はForward Migrationで行う。

重大障害時は、事前BackupからのRestoreまたはForward Fixを使用する。

## 16.3 Expand / Contract

非互換Schema変更は複数Releaseに分ける。

```text
Release A
→ 新Column / Table追加
→ 旧新両方を扱う

Release B
→ Data Backfill
→ 新Schemaへ切替

Release Cまたは次Major
→ 旧Column / Table削除
```

旧Schema削除は、少なくとも一つのSupported Minor期間を経過した後に行う。

## 16.4 Patch Migration

Patchで許可するMigration:

- Index
- Idempotent Data Fix
- 既存Contractに沿ったConstraint
- Safe Default
- Performance修正

Data Loss、Column削除、型縮小はPatchで行わない。

---

# 17. Config Version

Configは次に分ける。

```text
Public Site Config
Operational Config
Secret
Deployment Manifest
```

## 17.1 Config変更のVersion判定

MINOR:

- Optional Key追加
- Secure Default付きKey追加
- 新Capabilityの設定
- 未使用時に挙動が変わらない設定

MAJOR:

- Required Key追加でDefaultなし
- Key削除
- Key Rename
- 型変更
- Defaultの意味を非互換に変更
- Secret配置方式の非互換変更

PATCH:

- Description
- Validation Message
- 既存Defaultに沿うBug Fix

## 17.2 Unknown Key

- Unknown KeyはWarning
- Security-sensitive Unknown KeyはDeploy Block
- Removed KeyはMajor移行手順を要求
- Secret値はManifestやRelease Noteへ記録しない

---

# 18. Container Image Version

Platform Image:

```text
ghcr.io/<owner>/oripa-api:2.4.1
ghcr.io/<owner>/oripa-admin:2.4.1
```

Site Image:

```text
ghcr.io/<owner>/oripa-site-luxe-pack:1.8.2
```

## 18.1 Production参照

Production DeploymentはTagではなくDigestへ固定する。

```text
ghcr.io/<owner>/oripa-api@sha256:<digest>
```

TagはRegistry上で変更可能なため、Deployの正本にしない。

## 18.2 Exact Version Tag

Exact Version Tagを移動・上書きしてはならない。

```text
2.4.1
1.8.2
```

`latest`をProduction Manifestへ書かない。

Moving Tagを使用する場合も、Stagingの利便性用途だけとする。

## 18.3 Build Once / Promote

```text
Git Tag
→ CI Build
→ Test
→ Image Digest確定
→ StagingへDeploy
→ 同じDigestをProductionへPromote
```

Staging合格後に同じVersionを再BuildしてProductionへ出してはならない。

Build内容が変わった場合は新しいPatchまたはPre-releaseを発行する。

## 18.4 OCI Label

Imageに次を記録する。

- `org.opencontainers.image.version`
- `org.opencontainers.image.revision`
- `org.opencontainers.image.source`
- `org.opencontainers.image.created`
- `org.opencontainers.image.title`

Base Imageも可能な限りDigest固定する。

---

# 19. Git TagとRelease

## 19.1 Platform Tag

```text
platform-v2.0.0
platform-v2.1.0
platform-v2.1.1
```

## 19.2 First-party Package Tag

```text
storefront-client-v2.3.2
site-schema-v2.1.0
storefront-testkit-v2.2.1
```

## 19.3 Template / Site Tag

```text
site-template-v1.3.0
luxe-pack-site-v1.8.2
```

## 19.4 不変性

Stable Tagを移動・削除・再利用しない。

Release Assetを差し替えない。

GitHubのImmutable Release機能が利用可能な場合は有効化する。

## 19.5 Branch

```text
main
release/2.4
hotfix/2.4.2
```

原則:

- `main`は次のRelease候補
- `release/x.y`はStabilization
- HotfixはSupported Releaseから分岐
- Merge後に同じ修正を`main`へ戻す
- Production ReleaseはProtected Tagからだけ作成

---

# 20. Pre-release Channel

## 20.1 Alpha

```text
2.1.0-alpha.1
```

- Internal Development
- API、Migration、Configは変更可能
- Production禁止
- Data保持保証なし

## 20.2 Beta

```text
2.1.0-beta.1
```

- Feature Completeに近い
- Integration Test
- Site Staging
- Production禁止
- Contract変更は可能だがRelease Note必須

## 20.3 Release Candidate

```text
2.1.0-rc.1
```

- Public Contract凍結
- Migration凍結
- Release Blockerだけ修正
- Production原則禁止
- 修正が入るたび`rc.2`等を発行

## 20.4 Stable

```text
2.1.0
```

Productionで使用可能。

Pre-releaseからStableへArtifactを再Tagするのではなく、Stable Tagから最終Buildし、RCとのSource差分がRelease関連Metadataだけであることを検証する。

---

# 21. Release Manifest

Platform Stable Releaseは、機械可読な`release-manifest.yaml`を必須とする。

例:

```yaml
manifest_schema_version: 1

platform:
  version: 2.4.1
  compatibility_family: 2
  git_tag: platform-v2.4.1
  git_commit: "<full-sha>"
  released_at: "2026-07-22T12:00:00Z"
  channel: stable

contracts:
  public_api:
    version: 2.6.0
    sha256: "<sha256>"
  admin_api:
    version: 2.5.0
    sha256: "<sha256>"
  webhook_api:
    version: 2.3.1
    sha256: "<sha256>"

packages:
  storefront_client:
    version: 2.4.2
    minimum_public_api_contract: 2.4.0
  site_schema:
    version: 2.1.0
  storefront_testkit:
    version: 2.3.0

images:
  api:
    digest: "sha256:<digest>"
  admin:
    digest: "sha256:<digest>"

database:
  target_schema_revision: "20260722.004"
  migration_set_sha256: "<sha256>"
  minimum_upgrade_from_platform: 2.3.0
  application_rollback_compatible_to:
    - 2.4.0

runtimes:
  php: "<exact-version>"
  composer: "<exact-version>"
  laravel: "<exact-version>"
  node: "<exact-version>"
  pnpm: "<exact-version>"
  nextjs: "<exact-version>"
  postgresql: "<exact-version>"
  redis: "<exact-version>"

support:
  status: active
  previous_supported_minor: "2.3"
```

ManifestにはSecretを含めない。

Release Manifest自体のChecksumをRelease Assetへ記録する。

---

# 22. Site Deployment Manifest

各Siteは独立した`deployment-manifest.yaml`を持つ。

```yaml
manifest_schema_version: 1

site:
  id: luxe-pack
  version: 1.8.2
  template_origin_version: 1.3.0

platform:
  version: 2.4.1
  release_manifest_sha256: "<sha256>"

packages:
  storefront_client: 2.4.2
  site_schema: 2.1.0
  storefront_testkit: 2.3.0

images:
  storefront: "sha256:<digest>"
  api: "sha256:<digest>"
  admin: "sha256:<digest>"

compatibility:
  family: 2
  required_capabilities:
    - auth.session.v2
    - draw.idempotency.v1

database:
  schema_revision: "20260722.004"

deployment:
  environment: production
  deployed_at: "2026-07-22T12:30:00Z"
  deployment_id: "<uuid>"
```

各SiteのProduction状態は、このManifestで一意に再現できなければならない。

---

# 23. Site別Update

共通Releaseを発行しても、全Siteへ同時適用しない。

```text
Platform Release
→ Luxe Pack Staging
→ Luxe Pack Production
→ Demo / Low-risk Site
→ Customer A
→ Customer B
```

各Siteで次を個別に行う。

1. Update PR
2. Compatibility Check
3. Site Test
4. Visual Regression
5. E2E
6. Staging
7. Backup
8. Production Deploy
9. Smoke Test
10. Deployment Manifest更新

Platform Package公開だけでSite Deployを自動開始しない。

---

# 24. Update Path

## 24.1 Patch

```text
2.4.0 → 2.4.3
```

中間Patchを飛ばしてよい。

Migrationは累積適用する。

## 24.2 Minor

```text
2.3.x → 2.4.x
```

Supported MinorからのUpgrade Testが通っている場合に許可する。

複数Minorを飛ばす場合、Release Manifestが明示的に許可している必要がある。

## 24.3 Major

```text
2.x → 3.x
```

自動Upgradeだけに依存しない。

必須:

- Migration Guide
- Compatibility Guide
- Site Update Guide
- API v2 / v3共存期間
- Backup / Restore Test
- Staging Rehearsal
- Rollback Plan
- Site別移行承認

Majorを飛ばしてUpgradeしない。

---

# 25. Deprecation

## 25.1 Public API

Deprecated Endpoint / Fieldには次を設定する。

- OpenAPI `deprecated: true`
- `Deprecation` Response Header
- `Link` Headerで移行文書
- 停止予定がある場合は`Sunset` Header
- Changelog
- Replacement
- Site別利用検出

最低告知期間:

```text
180日
かつ
2回のPlatform Minor Release
```

の長い方。

Public APIからの削除は原則として次Majorで行う。

## 25.2 Package API

- TypeScript `@deprecated`
- Release Note
- Replacement Example
- 次Majorまで維持

## 25.3 Config

- 旧KeyをAliasとして一時維持
- Startup Warning
- Admin Health Warning
- 次Majorで削除

## 25.4 Security例外

Critical Security Issueで維持できない場合:

- Ownerによる緊急判断
- Security Advisory
- 明確なDeadline
- Site別Update PR
- High-risk Operationの段階停止
- Incident記録

---

# 26. Support Policy

## 26.1 Platform Minor

```text
最新Minor N
→ Active Support

一つ前のMinor N-1
→ Maintenance Support
```

N-1は、NのStable Releaseから6か月間Maintenance Supportする。

それ以前はEOLとする。

## 26.2 Patch

Supported Minor内では、最新PatchだけをSupportedとする。

例:

```text
2.4.3 Supported
2.4.2 Update Required
```

## 26.3 Previous Major

新MajorのStable Release後、前Majorを12か月間Security-only Supportする。

Data Corruption、Critical Security、重大な決済障害を対象とする。

## 26.4 Client / Schema / Testkit

各Packageは次をTest対象とする。

- 最新Minor
- 一つ前のMinor

それ以前は動作する可能性があっても、保証対象外とする。

## 26.5 Site運用目標

各Siteは次を満たす。

- Platformは最新Minorまたは一つ前
- Supported Minorの最新Patch
- First-party ClientはSupported Version
- Critical Security Patchは72時間以内を目標
- High Security Patchは7日以内を目標
- 通常Patchは30日以内を目標

これは内部運用目標であり、外部顧客向けSLAを自動的に意味しない。

---

# 27. Security Release

Security Fixも通常のSemVerを使用する。

```text
2.4.1 → 2.4.2
```

独自の非公開Version番号を作らない。

Release Note公開前に詳細を限定する必要がある場合も、ArtifactとVersionは正式なものを発行する。

必須:

- CVEまたは内部Advisory ID
- 影響Version
- 修正版
- Site別展開状況
- Secret Rotation要否
- DB Migration要否
- Client更新要否
- Rollback可否

Security FixによってClient Minimumを引き上げる場合は、Compatibility Matrixと`/api/v2/site`を更新する。

---

# 28. Rollback

## 28.1 Site Storefront

Public APIが後方互換である限り、Site Storefront Imageを前Versionへ戻せる。

ただし、Required CapabilityとClient Minimumを再確認する。

## 28.2 Platform Application

Application Rollbackは、Release Manifestの次に対象Versionが記載されている場合だけ許可する。

```yaml
application_rollback_compatible_to:
  - 2.4.0
```

## 28.3 Database

ProductionでMigration Rollbackを通常手段にしない。

優先順位:

1. Application Rollback
2. Feature Flag停止
3. Forward Fix
4. Backup Restore

Dataを失う可能性がある場合は、Backup Restoreと業務停止を含むIncident手順を使用する。

## 28.4 Release前確認

Release Manifestへ必ず次を記載する。

- Rollback可能
- ApplicationのみRollback可能
- DB Restoreが必要
- Forward Fixのみ

曖昧なままProductionへ出さない。

---

# 29. Release CI Gate

Platform Release:

- Working Tree clean
- Version整合性
- SemVer Bump検査
- Git Tag未使用
- Changelog
- OpenAPI lint
- Breaking Change検出
- Generated Client差分なし
- Package Public API差分
- PHP Test
- Admin Test
- E2E
- Security Test
- Migration fresh
- Supported VersionからのMigration Upgrade
- Migration Checksum
- DB Reconciliation
- Compatibility Matrix
- Client N / N-1 Test
- Site Schema N / N-1 Test
- Luxe Pack Test
- Demo Site Test
- Container Scan
- SBOM
- Image Digest
- Release Manifest Schema Validation
- Rollback Compatibility Test
- Secret Scan

Site Release:

- Site SemVer
- Exact First-party Package
- Frozen Lockfile
- Typecheck
- Lint
- Unit Test
- Testkit
- E2E
- Visual Regression
- Accessibility
- Required Capability
- Target Platform Compatibility
- Container Scan
- Storefront Image Digest
- Deployment Manifest Schema Validation

いずれかが失敗したReleaseをStableとして公開しない。

---

# 30. Changelog

各Releaseで次のCategoryを使用する。

```text
Added
Changed
Fixed
Security
Deprecated
Removed
Database
Configuration
Operations
Compatibility
```

必須記載:

- Breaking Change
- Migration
- Required Config
- Required Site変更
- Required Client Version
- Required Capability
- Rollback条件
- Security影響
- Known Issue

「内部修正」だけで済ませず、Site運用者が更新可否を判断できる内容にする。

---

# 31. Version表示と監視

Owner管理画面に次を表示する。

- Site Version
- Platform Version
- Public API Contract Version
- Storefront Client Version
- DB Schema Revision
- Deployment ID
- Support Status
- Available Update
- Security Update Required
- Compatibility Warning

一般ユーザー画面へ詳細なRuntime、Commit、Schema Revisionを表示しない。

Monitoring Label:

```text
site_id
environment
platform_version
site_version
api_contract_version
deployment_id
```

SecretやUser IDをLabelへ含めない。

---

# 32. 現在のRepositoryからV2への変更

現在の公開Repositoryでは、Frontend Package Versionは`0.1.0`であり、Platform Product Versionとしては使用しない。

V2で次を新しく開始する。

```text
Platform                2.0.0
Public API Contract     2.0.0
Admin API Contract      2.0.0
Webhook API Contract    2.0.0
Storefront Client       2.0.0
Site Schema             2.0.0
Storefront Testkit      2.0.0
Site Template           1.0.0
Luxe Pack Storefront    1.0.0
```

現在のBackend Dependencyにある複数Laravel Major許容は、V2 Stable Release前に一つのMajor Lineへ固定する。

現在のComposeで使用しているTag参照はLocal Development用途に限定し、Production ManifestではDigest固定へ変更する。

---

# 33. V2初回Release順

```text
1. platform-v2.0.0-alpha.1
2. storefront-client-v2.0.0-alpha.1
3. site-schema-v2.0.0-alpha.1
4. storefront-testkit-v2.0.0-alpha.1
5. site-template-v1.0.0-alpha.1
6. Luxe Pack Site alpha
7. beta
8. rc
9. platform-v2.0.0
10. Core Package 2.0.0
11. site-template-v1.0.0
12. luxe-pack-site-v1.0.0
```

Stable前にVersionを`0.x`として別管理しない。

V2 FamilyのPre-releaseとして`2.0.0-alpha.*`を使用する。

---

# 34. Codex運用ルール

## 34.1 Platform Codex

Versionを変更できる。

必須:

- 変更内容からBump種別を提示
- Breaking Changeの有無を明記
- Changelog更新
- Compatibility Matrix更新
- Release Manifest更新
- Migration Compatibility更新
- First-party Package Version整合
- Siteへの影響一覧

禁止:

- Stable Versionの再利用
- Tag移動
- Release Asset差し替え
- 同じVersionで再Build
- Breaking ChangeをPatchへ入れる

## 34.2 Site専用Codex

変更可能:

- Site Version
- Exact Client Version
- Site Design / UI / Token
- Required Capability
- Template Origin記録

変更禁止:

- Platform Versionの勝手な変更
- First-party PackageのRange指定
- Compatibility Check回避
- Production Digestの手入力差し替え
- `latest`使用
- API Majorの独自選択
- Storefront Clientを飛ばした直接API実装

Platform Updateが必要な場合は、Platform Change Requestを作る。

---

# 35. 最終決定一覧

| 項目 | 確定内容 |
|---|---|
| Version方式 | SemVer 2.0.0 |
| Core Family | Platform / API / Client / Schema / TestkitのMajorを揃える |
| V2初期Family | 2 |
| 顧客Site Version | 独立SemVer |
| Site Template | 独立SemVer |
| First-party Package | 完全固定 |
| `^` / `~` / `latest` | First-party Packageでは禁止 |
| Lockfile | 必須 |
| Platform Release | API・Admin・Migration・Infraの統合Release |
| API URL | Majorだけを含める |
| Feature判定 | Capability |
| Platform Minor比較によるFeature判定 | 禁止 |
| DB Version | Migration Revision |
| Released Migration編集 | 禁止 |
| Production Migration | Forward-only |
| Production Image | Digest固定 |
| Stable Tag移動 | 禁止 |
| Build | Build Once / Promote |
| Site Update | 個別PR・個別Deploy |
| 自動全Site更新 | 行わない |
| Supported Minor | 最新＋一つ前 |
| 前Minor Maintenance | 6か月 |
| 前Major Security Support | 12か月 |
| Deprecation | 180日かつ2 Minor以上 |
| Public API削除 | 次Major |
| Rollback | Manifestで事前宣言 |
| Release正本 | Release Manifest＋Digest |
| Site Production正本 | Deployment Manifest |

---

# 36. 最終確定要旨

V2では、共通Sourceを使いながら各Siteを完全独立運用する。

```text
Platform Release
├── Version 2.4.1
├── API Image Digest
├── Admin Image Digest
├── Migration Revision
├── API Contract Version
└── Compatibility Matrix

Site A
├── Site Version 1.8.2
├── Platform 2.4.1
├── Client 2.4.2
├── Site固有Image Digest
├── Site A専用DB
└── Site A専用Deployment Manifest

Site B
├── Site Version 1.3.0
├── Platform 2.3.4
├── Client 2.3.1
├── Site固有Image Digest
├── Site B専用DB
└── Site B専用Deployment Manifest
```

Site Aの更新はSite Bへ自動影響しない。

互換性は次で判断する。

```text
Core Compatibility Family
＋
API Contract
＋
Storefront Client
＋
Required Capability
＋
DB Schema Revision
＋
Release Manifest
```

Version番号だけで推測せず、ManifestとCIで機械的に検証する。

本書を、V2のPackage Version、Compatibility、Release、Support、Update、Rollbackに関する正式な基準とする。
