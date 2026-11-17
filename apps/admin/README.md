# Admin Application

## Responsibility

V2共通Admin Next.js ApplicationのUIとAdmin API Client境界を担う。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../../AGENTS.md)に従う。

## Planned Components

Catalog、QA、Shipping、Reporting、Content等の業務画面とAudit表示を後続Taskで配置する。

## Allowed Scope

承認済みAdmin API Contractを利用するSite非依存のAdmin Application。

## Forbidden Scope

Draw／Point／Payment判断、Site固有Design、User Cookie、V1 CodeをCopyしない。

## Status

`MIG-060A`で非Production用のAdmin認証と共通Shellを実装した。

* Admin OpenAPIから決定的に生成・検証するAuth型
* Same-origin Cookie／Admin CSRFを使用するAdmin API Client
* Password Pre-auth、TOTP／WebAuthn／Recovery Code、MFA Enrollment
* Session／Logout／Fresh MFA再認証
* Owner／Admin／Operator表示、共通Shell、Loading／Error／403／404
* Unknown Host拒否、CSP、Frame拒否、`private, no-store`、`noindex`

`MIG-060B`でBackend中央Permission Matrixを正本とする共通基盤を追加した。

* `GET /admin/api/v2/auth/permissions`からRoleと有効Permissionだけを取得
* Permission取得失敗時に業務ModuleをFail Closed
* 型付きNavigation Registry、Permission Provider／Gate、Route Guard
* Dashboard、Breadcrumb、Active Navigation、Mobile Drawer
* `/catalog`、`/qa`、`/shipping`、`/reports`、`/content`、`/contacts`
* 未実装Moduleは架空Dataを表示しない準備中画面

`OPS-027`でnative `linux/arm64`のProduction candidate image targetと、Admin
processだけを表すscoped health contractを定義した。Domain／TLS／Routing／Secret、
Storefront、DB／Redis、Production DeploymentとCommercial GOは未実装・未承認であり、
image build成立だけをProduction利用やActivationとは扱わない。

## Local Verification

LINEの画像・動画Uploadは、署名付きURLでBrowserから既存S3へ直接PUTする。
有効化時にはAdminのRuntime ENV `V2_LINE_MEDIA_UPLOAD_ORIGIN` に、署名付きURLと
同じ正確なHTTPS Originを設定する（Bucket/Regionに対応するOrigin、Path・Query・
Wildcard・複数Origin不可）。未設定時は `connect-src 'self'` を維持する。
設定したOriginは許可済みAdmin Hostの各画面で一貫して接続許可する。
CSPは最初のDocumentに適用され、ログイン後のClient-side遷移では置換されないため、
LINE画面だけの許可にはしない。CDN Originとは別設定で、CORS・IAM・署名検証・Admin MFAを代替しない。
設定例や秘密値をRepositoryへ記録せず、既存BucketのCORSと権限を個別に確認する。

Root Workspaceで次を実行する。

```text
pnpm install --frozen-lockfile
pnpm admin:generate:check
pnpm admin:typecheck
pnpm admin:lint
pnpm admin:test
pnpm admin:build
pnpm admin:test:e2e
```

Browser E2Eは固定Test Doubleを使い、実CredentialやProduction Resourceへ接続しない。
非ProductionのV2 Composeでは`GET /api/health`を起動確認に使用する。
