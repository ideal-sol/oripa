# オリパ・パッケージ V2
# API v2 / Storefront Client 契約 最終確定版

- 文書ID: `V2-API-CLIENT-CONTRACT-001`
- 状態: **FINAL / Architecture Baseline 1.0**
- 確定日: 2026-07-21
- 適用対象: オリパ・プラットフォーム V2、全顧客Storefront、共通管理画面
- 保存推奨先: `docs/architecture/API_V2_AND_STOREFRONT_CLIENT_CONTRACT_FINAL_2026-07-21.md`

---

## 1. 最終決定

### 1.1 Storefront SDKは作らない

`@oripa/storefront-sdk` という大きなSDKは作成しない。

代わりに、次の薄い型付きクライアントを作成する。

```text
@oripa/storefront-client
```

このパッケージは、サイト固有UIとLaravel APIの間に置く、HTTPクライアント境界である。

### 1.2 Storefront Clientは必要

複数のサイト専用Codexがそれぞれ直接APIを呼び出す構成にはしない。

全Storefrontは、プラットフォームAPIへのアクセスに `@oripa/storefront-client` を使用する。

必要な理由:

- APIのRequest / Response型を全サイトで一致させる
- Laravel SanctumのSession CookieとCSRF処理を統一する
- 抽選・決済・交換・配送の冪等性を統一する
- APIエラー形式を統一する
- 通信失敗時の再試行方針を統一する
- サイトごとにAPI呼び出しが別実装になることを防ぐ
- サイト専用Codexが抽選・ポイント・決済ロジックを持つことを防ぐ
- API v2の互換性をパッケージVersionで管理する

### 1.3 二重の契約書は作らない

HTTP API契約とStorefront Client契約を、別々の手書き仕様として二重管理しない。

契約の正本は次の順で扱う。

1. 業務ルール・不変条件: 確定仕様書とADR
2. HTTP APIの形: OpenAPI
3. Storefront Clientの公開インターフェース: OpenAPIから生成した型を利用する薄いFacade
4. 生成コード: 正本ではない。直接編集禁止

Storefront ClientはOpenAPIを包むだけであり、独自の業務仕様を持たない。

---

## 2. パッケージ構成の修正

V2全体設計に記載していたパッケージ構成を、次のように修正する。

```text
oripa-platform/
├── apps/
│   ├── api/
│   └── admin/
│
├── openapi/
│   ├── public-v2/
│   │   └── openapi.yaml
│   ├── admin-v2/
│   │   └── openapi.yaml
│   ├── webhooks-v2/
│   │   └── openapi.yaml
│   └── components/
│       ├── primitives.yaml
│       └── problem-details.yaml
│
└── packages/
    ├── storefront-client/
    ├── admin-client/
    ├── site-schema/
    └── storefront-testkit/
```

### 廃止する構成

```text
@oripa/storefront-sdk
@oripa/contracts
```

### 採用する構成

```text
@oripa/storefront-client
```

公開APIの型はStorefront Clientからexportする。

```ts
import type {
  GachaSummary,
  GachaDetail,
  Wallet,
  Draw,
  UserPrize,
  ShippingRequest,
  ApiProblem,
} from "@oripa/storefront-client";
```

管理APIの型はStorefrontへ配布しない。

```text
@oripa/admin-client
```

は `apps/admin` だけが利用するprivate workspace packageとする。

---

## 3. API面の完全分離

APIは次の3面へ分離する。

```text
/api/v2/*
/admin/api/v2/*
/webhooks/v2/*
```

### 3.1 Public API

```text
/api/v2/*
```

利用者:

- 顧客別Storefront
- `@oripa/storefront-client`

含むもの:

- 公開コンテンツ
- 公開ガチャ情報
- ユーザー認証
- ユーザー本人情報
- ポイント残高・履歴
- 抽選
- ポイント購入
- 獲得景品
- ポイント交換
- 配送依頼
- 問い合わせ

含めないもの:

- 管理者情報
- 管理者権限
- 内部監査情報
- QA排出プラン
- 売上原価
- Provider secret
- 景品別の個別確率
- 内部DB ID
- 管理画面専用状態
- 返金・チャージバック管理操作

### 3.2 Admin API

```text
/admin/api/v2/*
```

利用者:

- 共通管理画面
- `@oripa/admin-client`

Storefrontリポジトリからのimportを禁止する。

### 3.3 Webhook API

```text
/webhooks/v2/*
```

利用者:

- 決済Provider
- LINE
- 将来の配送Provider
- その他の外部Provider

Session Cookie認証は使用しない。

必須要件:

- Provider署名検証
- event IDによる冪等性
- payload保存
- 受信日時保存
- 処理状態保存
- 再実行可能性
- Rate Limit
- request ID

---

## 4. OpenAPIの確定方針

### 4.1 採用Version

```yaml
openapi: 3.1.1
```

V2ではOpenAPI 3.1.1を基準とする。

OpenAPI 3.2固有機能が必要になった場合にのみ、別ADRで更新する。

### 4.2 OpenAPIが定義する内容

各operationについて、最低限次を必須とする。

- `operationId`
- HTTP method
- path
- path parameter
- query parameter
- request body
- success response
- error response
- auth requirement
- idempotency requirement
- pagination
- rate-limit区分
- cache区分
- stability
- deprecation状態

### 4.3 operationId

operationIdはTypeScriptの公開メソッド名の基礎になるため、公開後は変更しない。

例:

```text
getSiteRuntime
listGachas
getGacha
getGachaBySlug
createDraw
getCurrentUser
getWallet
createPayment
exchangeUserPrize
createShippingRequest
```

### 4.4 生成コード

生成先:

```text
packages/storefront-client/src/generated/
packages/admin-client/src/generated/
```

ルール:

- 生成コードは手動編集禁止
- CIで再生成し、差分がある場合は失敗
- GeneratorのVersionを固定
- Generator変更は独立PR
- 生成コードを直接サイトへ公開せず、薄いFacade経由でexportする

---

## 5. HTTP共通契約

### 5.1 通信形式

成功レスポンス:

```text
Content-Type: application/json
```

エラーレスポンス:

```text
Content-Type: application/problem+json
```

本番はHTTPSのみとする。

### 5.2 JSON命名

Wire形式は `snake_case` に統一する。

```json
{
  "point_balance_after": 1200,
  "created_at": "2026-07-21T08:30:00Z"
}
```

Storefront Clientは自動的にcamelCaseへ変換しない。

### 5.3 ID

APIで公開するIDはすべてopaque stringとして扱う。

```json
{
  "id": "01J2Q8ZK3J92C4M7T8N9P0R1S2"
}
```

ルール:

- 数値として計算しない
- 並び順に利用しない
- 形式に依存しない
- 内部DBの連番IDを契約として保証しない
- TypeScriptでは `string`

V1の内部IDが数値であっても、V2 APIではstringとして返せる。

### 5.4 日時

`*_at`:

- RFC 3339
- UTC
- `Z`付き

```json
{
  "created_at": "2026-07-21T08:30:00Z"
}
```

業務日付:

- `YYYY-MM-DD`
- Asia/Tokyo基準

```json
{
  "business_date": "2026-07-21"
}
```

日時と業務日付を混同しない。

### 5.5 金額・ポイント・確率

金額:

```json
{
  "amount": 1000,
  "currency": "JPY"
}
```

- 整数
- 円単位
- 浮動小数点禁止

ポイント:

```json
{
  "paid_points": 500,
  "free_points": 300,
  "total_points": 800
}
```

- 整数
- 残高は0以上
- 台帳差分は正負を許可

確率:

- 内部および管理APIではppm整数
- 公開APIではランク合計・ステージ情報のみ
- 景品別個別ppmはPublic APIへ出さない

### 5.6 null

値が存在しない場合は、Schemaでnullableを明示する。

空文字、0、空配列を「値なし」の代用にしない。

---

## 6. 成功レスポンス

### 6.1 単一Resource

```json
{
  "data": {
    "id": "01J2Q8ZK3J92C4M7T8N9P0R1S2",
    "title": "プレミアムオリパ"
  }
}
```

### 6.2 Collection

Public APIのCollectionはcursor paginationを標準とする。

Request:

```http
GET /api/v2/gachas?limit=20&cursor=...
```

Response:

```json
{
  "data": [],
  "meta": {
    "page_size": 20,
    "has_more": true,
    "next_cursor": "..."
  }
}
```

ルール:

- `limit` default: 20
- `limit` max: 100
- `cursor` はopaque string
- Clientはcursor内容を解析しない
- Offset paginationをPublic APIの標準にしない

### 6.3 Create

正常作成:

```text
201 Created
Location: /api/v2/resource/{id}
```

Body:

```json
{
  "data": {}
}
```

### 6.4 Async処理

大量Export等:

```text
202 Accepted
```

### 6.5 Bodyなし

Logoutや削除等で返却Resourceが不要な場合:

```text
204 No Content
```

---

## 7. エラー契約

RFC 9457 Problem Details形式を採用する。

```json
{
  "type": "urn:oripa:problem:point-balance-insufficient",
  "title": "ポイントが不足しています",
  "status": 422,
  "detail": "この抽選に必要なポイントが不足しています。",
  "instance": "/api/v2/gachas/01J.../draws",
  "code": "POINT_BALANCE_INSUFFICIENT",
  "request_id": "req_01J...",
  "retryable": false,
  "errors": {}
}
```

### 7.1 必須field

```text
type
title
status
code
request_id
retryable
```

### 7.2 任意field

```text
detail
instance
errors
retry_after_seconds
```

### 7.3 安定した判定値

サイトは `title` や `detail` の日本語文字列で分岐しない。

必ず `code` で分岐する。

### 7.4 Validation Error

```json
{
  "type": "urn:oripa:problem:validation-failed",
  "title": "入力内容を確認してください",
  "status": 422,
  "code": "VALIDATION_FAILED",
  "request_id": "req_01J...",
  "retryable": false,
  "errors": {
    "email": [
      "メールアドレスの形式が正しくありません。"
    ]
  }
}
```

### 7.5 HTTP status

| Status | 用途 |
|---:|---|
| 400 | JSON不正、解釈不能なRequest |
| 401 | 未認証、Session失効 |
| 403 | 権限不足、CSRF不正 |
| 404 | Resourceなし、または存在を秘匿 |
| 409 | 状態競合、同時更新、冪等性競合 |
| 422 | Validation、ユーザーが解消可能な業務条件不成立 |
| 429 | Rate Limit |
| 500 | 予期しない内部エラー |
| 502 | 外部Gateway異常 |
| 503 | 一時停止、Provider障害、Maintenance |
| 504 | 外部処理Timeout |

Laravel固有の419をPublic API v2の契約として公開しない。

CSRF不正は403へ正規化し、`CSRF_TOKEN_MISMATCH`を返す。

Session失効は401、`AUTHENTICATION_REQUIRED`を返す。

### 7.6 Stack trace

ProductionのAPIレスポンスへ次を含めない。

- Stack trace
- SQL
- File path
- Secret
- Provider raw credential
- 内部例外class名

---

## 8. 共通Header

### 8.1 Request

Storefront Clientが設定する。

```text
Accept: application/json
X-Oripa-Client-Version: 2.0.0
X-Oripa-Site-Version: 1.0.0
```

JSON Bodyがある場合:

```text
Content-Type: application/json
```

冪等性対象:

```text
Idempotency-Key: <opaque-key>
```

Browser:

```text
credentials: include
```

### 8.2 Response

Laravelが必ず返す。

```text
X-Request-Id: req_...
X-Oripa-Api-Version: 2
```

必要に応じて:

```text
Idempotency-Replayed: true
Retry-After: 5
ETag: "..."
Last-Modified: ...
```

`X-Oripa-Client-Version` と `X-Oripa-Site-Version` は監視・互換性確認用であり、認証・認可には利用しない。

---

## 9. 認証・CSRF境界

認証の詳細なSession期間、MFA、Roleは別の「認証・権限・セキュリティ基準」で確定する。

API / Client境界として次を確定する。

### 9.1 User認証

- Laravel SanctumのSession Cookie方式
- API TokenをLocal Storageへ保存しない
- Browserから同一Originの `/api/v2` を利用
- CookieはHttpOnly、Secure、Host限定
- Public APIとAdmin APIのCookie名を分離
- User Cookieを管理Domainへ共有しない
- Admin CookieをStorefront Domainへ共有しない

### 9.2 CSRF

Browser Clientは、必要時に次を呼ぶ。

```text
GET /sanctum/csrf-cookie
```

その後、Laravelが発行するXSRF Cookieを利用してMutationを実行する。

サイト専用コードからこの処理を直接実装しない。

### 9.3 Server Client

Server Component用Clientは、受け取ったCookie HeaderをLaravelへ転送できる。

ただし、Server Clientの公開APIは原則GET / HEADに限定する。

StorefrontのServer Actionへ抽選・決済・交換・配送の業務Mutationを置かない。

### 9.4 認証Error

```text
401 AUTHENTICATION_REQUIRED
403 AUTHORIZATION_DENIED
403 CSRF_TOKEN_MISMATCH
```

Storefront Clientは401を共通Errorとして返すが、勝手に画面遷移しない。

画面遷移はサイト側が決める。

---

## 10. 冪等性

### 10.1 必須Endpoint

次のMutationでは `Idempotency-Key` を必須とする。

```text
POST /api/v2/gachas/{gacha_id}/draws
POST /api/v2/payments
POST /api/v2/me/prizes/{user_prize_id}/exchange
POST /api/v2/me/shipping-requests
```

将来追加する次の操作でも必須とする。

- 金銭を動かす
- ポイントを付与・消費・取消する
- 在庫を変更する
- 景品状態を変更する
- 外部Providerへ不可逆操作を依頼する

### 10.2 Key

- Clientが生成
- opaque string
- 16〜128文字
- 推奨: UUID
- SiteやUser IDをKeyへ埋め込まない

### 10.3 Server動作

同じUser、同じoperation、同じkey、同じpayload:

- 元の結果を返す
- 二重処理しない
- 元と同じstatus/bodyを返す
- `Idempotency-Replayed: true`

同じkey、異なるpayload:

```text
409 IDEMPOTENCY_KEY_REUSED
```

同じkeyの処理が進行中:

```text
409 IDEMPOTENCY_REQUEST_IN_PROGRESS
Retry-After: 1
```

### 10.4 保存期間

抽選・決済・ポイント交換・配送依頼のkeyは、関連する業務Recordが存在する間、再利用を防ぐ。

単純な一時idempotency cacheだけで保証しない。

### 10.5 Client動作

Storefront Clientは:

- 1回のoperation中に同じkeyを再利用する
- 通信再試行時に新しいkeyを生成しない
- 呼び出し側からkeyを指定可能にする
- `createIdempotencyKey()` を提供する

Storefront Clientだけで二重クリックを完全には防げない。

サイトUIは送信中Buttonを無効化する。

Backendの冪等性を最終防御とする。

---

## 11. Retry方針

### 11.1 GET / HEAD

次の場合、最大2回まで再試行可能。

- Network error
- 502
- 503
- 504

指数Backoffとjitterを使用する。

### 11.2 Mutation

冪等性KeyがないMutation:

- 自動再試行禁止

冪等性KeyがあるMutation:

- 同じkeyを利用する場合だけ、最大1回の再試行を許可
- 409、422、429は自動再試行しない
- 429は`Retry-After`をサイトへ返す

### 11.3 Timeout

Clientは`AbortSignal`を受け取れるようにする。

Timeout値はClient defaultを持つが、operation別に上書き可能とする。

---

## 12. Cache方針

Storefront Client自身はServer State Cacheを持たない。

次をClientへ持たせない。

- React Query cache
- SWR cache
- Redux store
- LocalStorage cache
- Global singleton data state

サイトはNext.js Server Component、TanStack Query等を選択できる。

ただしAPI側のCache-Controlを優先する。

### Default

Authenticated GET:

```text
Cache-Control: private, no-store
```

Mutation:

```text
Cache-Control: no-store
```

Public GET:

- EndpointごとにOpenAPIの`x-cache-policy`で定義
- 未指定は`no-store`
- 在庫・販売数を含むResourceは短いTTLまたはno-store
- 抽選時には必ずLaravelが最新状態を再検証

---

## 13. Public API v2 Endpoint一覧

以下をV2.0のPublic API namespaceとして確定する。

詳細Schemaは既存仕様と各機能設計を基にOpenAPIへ記述する。

### 13.1 Site / Runtime

| Method | Path | operationId | Auth |
|---|---|---|---|
| GET | `/api/v2/site` | `getSiteRuntime` | 不要 |

含むもの:

- `site_id`
- `site_name`
- `timezone`
- `currency`
- `maintenance`
- 有効機能
- 有効な認証方式
- API compatibility

含めないもの:

- Secret
- ブランドの全デザイン値
- Provider credential

ブランド色、UIプリミティブ、デザイントークンはサイトリポジトリが所有する。

### 13.2 Content

| Method | Path | operationId | Auth |
|---|---|---|---|
| GET | `/api/v2/top-banners` | `listTopBanners` | 不要 |
| GET | `/api/v2/announcements` | `listAnnouncements` | 不要 |
| GET | `/api/v2/announcements/{announcement_id}` | `getAnnouncement` | 不要 |
| GET | `/api/v2/pages/{slug}` | `getStaticPage` | 不要 |
| POST | `/api/v2/contact-requests` | `createContactRequest` | 任意 |

### 13.3 Catalog

| Method | Path | operationId | Auth |
|---|---|---|---|
| GET | `/api/v2/gacha-categories` | `listGachaCategories` | 不要 |
| GET | `/api/v2/gacha-tags` | `listGachaTags` | 不要 |
| GET | `/api/v2/gachas` | `listGachas` | 不要 |
| GET | `/api/v2/gachas/{gacha_id}` | `getGacha` | 不要 |
| GET | `/api/v2/gachas/by-slug/{slug}` | `getGachaBySlug` | 不要 |
| GET | `/api/v2/point-purchase-plans` | `listPointPurchasePlans` | 不要 |

### 13.4 Authentication

| Method | Path | operationId | Auth |
|---|---|---|---|
| POST | `/api/v2/auth/register` | `registerUser` | 不要 |
| POST | `/api/v2/auth/login` | `loginUser` | 不要 |
| POST | `/api/v2/auth/logout` | `logoutUser` | 必要 |
| POST | `/api/v2/auth/password/forgot` | `requestPasswordReset` | 不要 |
| POST | `/api/v2/auth/password/reset` | `resetPassword` | 不要 |
| POST | `/api/v2/auth/email/verification-notification` | `resendEmailVerification` | 条件付き |
| GET | `/api/v2/auth/email/verify/{user_id}/{hash}` | `verifyEmail` | Signed |
| GET | `/api/v2/auth/oauth/{provider}/redirect` | `startOauthLogin` | 不要 |
| GET | `/api/v2/auth/oauth/{provider}/callback` | `completeOauthLogin` | 不要 |

OAuthのstate、PKCE、callback、account linkingの詳細は認証・セキュリティ設計で確定する。

それまではOAuth v2実装を開始しない。

### 13.5 Current User

| Method | Path | operationId | Auth |
|---|---|---|---|
| GET | `/api/v2/me` | `getCurrentUser` | 必要 |
| PATCH | `/api/v2/me/profile` | `updateCurrentUserProfile` | 必要 |
| GET | `/api/v2/me/wallet` | `getWallet` | 必要 |
| GET | `/api/v2/me/point-ledgers` | `listPointLedgerEntries` | 必要 |
| GET | `/api/v2/me/draws` | `listDraws` | 必要 |
| GET | `/api/v2/me/draws/{draw_id}` | `getDraw` | 必要 |
| GET | `/api/v2/me/prizes` | `listUserPrizes` | 必要 |
| POST | `/api/v2/me/prizes/{user_prize_id}/exchange` | `exchangeUserPrize` | 必要 |
| GET | `/api/v2/me/shipping-requests` | `listShippingRequests` | 必要 |
| GET | `/api/v2/me/shipping-requests/{shipping_request_id}` | `getShippingRequest` | 必要 |
| POST | `/api/v2/me/shipping-requests` | `createShippingRequest` | 必要 |

配送先Address CRUDが必要な場合は、次のnamespaceを使用する。

```text
/api/v2/me/shipping-addresses
```

### 13.6 Draw

| Method | Path | operationId | Auth | Idempotency |
|---|---|---|---|---|
| POST | `/api/v2/gachas/{gacha_id}/draws` | `createDraw` | 必要 | 必須 |

Bodyの基本形:

```json
{
  "draw_count": 1
}
```

Responseの基本形:

```json
{
  "data": {
    "id": "01J...",
    "gacha_id": "01J...",
    "status": "completed",
    "draw_count": 1,
    "point_cost_total": 100,
    "wallet_after": {
      "paid_points": 500,
      "free_points": 400,
      "total_points": 900
    },
    "results": [
      {
        "sequence_number": 123,
        "result_type": "prize",
        "rank": {
          "key": "A",
          "display_name": "A賞"
        },
        "prize": {
          "id": "01J...",
          "name": "景品名",
          "image_url": "https://..."
        },
        "point_back": null,
        "animation": {
          "image_url": null,
          "video_url": "https://..."
        }
      }
    ],
    "created_at": "2026-07-21T08:30:00Z"
  }
}
```

Public Draw Responseへ次を含めない。

- 選択乱数
- 景品別ppm
- 内部Weight
- QA plan ID
- QA item ID
- DB lock情報
- 内部監査payload

### 13.7 Payment

| Method | Path | operationId | Auth | Idempotency |
|---|---|---|---|---|
| POST | `/api/v2/payments` | `createPayment` | 必要 | 必須 |
| GET | `/api/v2/payments/{payment_id}` | `getPayment` | 必要 | 不要 |

共通状態:

```text
pending
requires_action
processing
succeeded
failed
cancelled
refunded
chargeback
```

ただし、次は本番決済Provider選定後の「DB・決済・ポイント基準設計」で確定する。

- `next_action`の型
- redirect型かembedded型か
- Provider SDK token
- 本人認証
- callback方式
- Payment expires_at
- 決済確定タイミング

この部分は意図的なBLOCKERであり、Codexが推測して実装してはいけない。

開発用 `mock-succeed` EndpointはPublic OpenAPIへ含めない。

Development環境だけの別Routeとして管理する。

---

## 14. Public Resourceの公開制限

### 14.1 Gacha

Public Gacha Detailで公開してよいもの:

- ガチャ名
- 説明
- 注意事項
- 価格
- 販売口数
- 残り口数
- 開始・終了日時
- 現在Stage
- Stage切替条件
- ランク合計確率
- 最低保証
- 景品名
- 景品画像
- 表示価格
- 交換ポイント
- 残り当選数
- 公開用演出情報

公開してはいけないもの:

- 景品別個別ppm
- 内部weight
- 内部原価
- 利益率
- 非公開景品
- Draft probability version
- QA設定
- 内部snapshot payload

### 14.2 User

Public User Resourceへ次を含めない。

- password hash
- remember token
- Provider access token
- Provider refresh token
- 内部memo
- 管理者用flag
- 本人以外の個人情報
- 監査用IP履歴

### 14.3 Payment

Public Payment Resourceへ次を含めない。

- Provider secret
- Webhook payload全文
- 内部risk payload
- 他ユーザーの情報
- 管理者memo

---

## 15. Storefront Clientの公開契約

### 15.1 Package名

```text
@oripa/storefront-client
```

### 15.2 Entry Point

```ts
import {
  createBrowserStorefrontClient,
  createIdempotencyKey,
  ApiProblemError,
} from "@oripa/storefront-client/browser";

import {
  createServerStorefrontClient,
} from "@oripa/storefront-client/server";
```

### 15.3 Browser Client

```ts
const client = createBrowserStorefrontClient({
  base_url: "/api/v2",
  client_version: "2.0.0",
  site_version: "1.0.0",
});
```

Browser Clientの責任:

- `credentials: include`
- CSRF初期化
- JSON serialize / parse
- Problem Details parse
- request header
- idempotency header
- timeout
- AbortSignal
- retry policy
- response header読取
- 型付きoperation

### 15.4 Server Client

```ts
const client = createServerStorefrontClient({
  base_url: process.env.INTERNAL_API_BASE_URL + "/api/v2",
  cookie_header: requestCookieHeader,
  client_version: "2.0.0",
  site_version: "1.0.0",
});
```

Server Clientの責任:

- Server ComponentからのPublic GET
- 必要な場合の本人用GET
- Cookie転送
- Request単位のClient生成
- Server専用Base URL

禁止:

- Server Client singletonへUser Cookieを保存
- User AのCookieをUser Bへ再利用
- 抽選Mutation
- 決済Mutation
- 交換Mutation
- 配送依頼Mutation

### 15.5 Public Facade

概念上、次の構造とする。

```ts
export interface StorefrontClient {
  site: {
    get(): Promise<ResourceResponse<SiteRuntime>>;
  };

  content: {
    listTopBanners(): Promise<CollectionResponse<TopBanner>>;
    listAnnouncements(
      query?: AnnouncementQuery,
    ): Promise<CursorCollectionResponse<AnnouncementSummary>>;
    getAnnouncement(
      announcement_id: string,
    ): Promise<ResourceResponse<Announcement>>;
    getStaticPage(
      slug: string,
    ): Promise<ResourceResponse<StaticPage>>;
    createContactRequest(
      input: ContactRequestInput,
    ): Promise<ResourceResponse<ContactRequest>>;
  };

  catalog: {
    listGachaCategories(): Promise<CollectionResponse<GachaCategory>>;
    listGachaTags(): Promise<CollectionResponse<GachaTag>>;
    listGachas(
      query?: GachaListQuery,
    ): Promise<CursorCollectionResponse<GachaSummary>>;
    getGacha(
      gacha_id: string,
    ): Promise<ResourceResponse<GachaDetail>>;
    getGachaBySlug(
      slug: string,
    ): Promise<ResourceResponse<GachaDetail>>;
    listPointPurchasePlans(): Promise<
      CollectionResponse<PointPurchasePlan>
    >;
  };

  auth: {
    register(input: RegisterInput): Promise<ResourceResponse<CurrentUser>>;
    login(input: LoginInput): Promise<ResourceResponse<CurrentUser>>;
    logout(): Promise<void>;
    requestPasswordReset(input: ForgotPasswordInput): Promise<void>;
    resetPassword(input: ResetPasswordInput): Promise<void>;
  };

  me: {
    get(): Promise<ResourceResponse<CurrentUser>>;
    updateProfile(
      input: UpdateProfileInput,
    ): Promise<ResourceResponse<CurrentUser>>;
    getWallet(): Promise<ResourceResponse<Wallet>>;
    listPointLedgers(
      query?: PointLedgerQuery,
    ): Promise<CursorCollectionResponse<PointLedgerEntry>>;
    listDraws(
      query?: DrawListQuery,
    ): Promise<CursorCollectionResponse<DrawSummary>>;
    getDraw(draw_id: string): Promise<ResourceResponse<Draw>>;
    listPrizes(
      query?: UserPrizeQuery,
    ): Promise<CursorCollectionResponse<UserPrize>>;
    exchangePrize(
      user_prize_id: string,
      options: IdempotentRequestOptions,
    ): Promise<ResourceResponse<UserPrizeExchange>>;
    listShippingRequests(
      query?: ShippingRequestQuery,
    ): Promise<CursorCollectionResponse<ShippingRequestSummary>>;
    getShippingRequest(
      shipping_request_id: string,
    ): Promise<ResourceResponse<ShippingRequest>>;
    createShippingRequest(
      input: CreateShippingRequestInput,
      options: IdempotentRequestOptions,
    ): Promise<ResourceResponse<ShippingRequest>>;
  };

  draws: {
    create(
      gacha_id: string,
      input: CreateDrawInput,
      options: IdempotentRequestOptions,
    ): Promise<ResourceResponse<Draw>>;
  };

  payments: {
    create(
      input: CreatePaymentInput,
      options: IdempotentRequestOptions,
    ): Promise<ResourceResponse<Payment>>;
    get(payment_id: string): Promise<ResourceResponse<Payment>>;
  };
}
```

実際の型はOpenAPIから生成する。

### 15.6 Error

```ts
export class ApiProblemError extends Error {
  status: number;
  code: string;
  type: string;
  title: string;
  detail?: string;
  request_id: string;
  retryable: boolean;
  retry_after_seconds?: number;
  errors?: Record<string, string[]>;
}
```

サイト側は次のように利用する。

```ts
try {
  await client.draws.create(
    gachaId,
    { draw_count: 1 },
    { idempotency_key: key },
  );
} catch (error) {
  if (
    error instanceof ApiProblemError &&
    error.code === "POINT_BALANCE_INSUFFICIENT"
  ) {
    // サイト固有UIを表示
  }
}
```

### 15.7 Clientが行わないこと

Storefront Clientへ次を実装しない。

- 抽選確率計算
- 景品選択
- ポイント消費順計算
- 残高の正否判定
- 在庫判定
- 決済成功判定
- 返金可否判定
- 配送状態遷移
- QA排出選択
- UI Component
- UI primitive
- Design Token
- Routing
- Toast表示
- Modal表示
- React global state
- 文言のブランド化
- 金額・ポイントの業務計算

ClientはAPIを安全かつ一貫して呼ぶだけに限定する。

---

## 16. サイト専用CodexのAPIルール

サイト専用Codexは、プラットフォームAPIについて次を守る。

### 許可

```ts
import {
  createBrowserStorefrontClient,
} from "@oripa/storefront-client/browser";
```

### 禁止

```ts
fetch("/api/v2/gachas");
axios.post("/api/v2/payments");
```

禁止範囲:

- `/api/v2`への直接fetch
- Laravel URLの直接記述
- CSRF処理の独自実装
- Session Cookie名の参照
- Idempotency-Keyの独自再発行
- API Response型の手書き複製
- Error message文字列による分岐
- Admin APIのimport
- Webhook APIの呼び出し
- 抽選・ポイント・決済ロジックのFrontend実装

例外:

- 外部の公開API
- Analytics
- 画像・動画URL
- 明示承認された第三者サービス

例外は`AGENTS.md`へ記録する。

---

## 17. Versionと互換性

### 17.1 API

PathのVersionはmajorのみ。

```text
/api/v2
```

Breaking Change時:

```text
/api/v3
```

### 17.2 Storefront Client

```text
@oripa/storefront-client 2.x
```

Client majorは対応API majorと一致させる。

```text
Client 2.x → API v2
Client 3.x → API v3
```

### 17.3 Site依存

各サイトは完全固定する。

```json
{
  "dependencies": {
    "@oripa/storefront-client": "2.0.0"
  }
}
```

禁止:

```json
{
  "@oripa/storefront-client": "^2.0.0"
}
```

### 17.4 Additive Change

次は原則としてAPI v2内で追加可能。

- 新Endpoint
- Optional request field
- Optional response field
- 新しいProblem code
- 新しいfilter
- 新しいsort option

Clientは未知のresponse fieldを無視する。

### 17.5 Breaking Change

次はAPI v3または明示的な移行が必要。

- Path変更
- operationId変更
- field削除
- field rename
- field type変更
- required field追加
- 意味の変更
- 認証方式変更
- Idempotency意味変更
- Pagination方式変更
- 公開情報の権限拡大・縮小

### 17.6 Enum

Enumは未知値を受け取る可能性を考慮し、サイトUIにはdefault表示を必須とする。

新しい状態を追加する際は、既存Clientへの影響をBreaking Change検査で確認する。

---

## 18. CI契約

Platform CIで必須:

```text
OpenAPI syntax validation
OpenAPI rule lint
breaking-change detection
operationId uniqueness
generated client regeneration
generated diff clean
Laravel route / OpenAPI差分検査
Request schema contract test
Response schema contract test
Problem Details contract test
Idempotency contract test
Auth / CSRF contract test
Public schema leak test
```

Public schema leak testで検出する例:

```text
password
password_hash
remember_token
provider_secret
cost_price
profit_rate
qa_plan_id
qa_item_id
individual_ppm
internal_weight
```

Site CIで必須:

```text
@oripa/storefront-client exact version
/api/v2 直接fetch禁止
/admin/api import禁止
typecheck
lint
build
Storefront Testkit
E2E
```

---

## 19. V1からV2への移行

### 19.1 V1を即時変更しない

V1 Route:

```text
backend/routes/api.php
backend/routes/admin.php
```

をV2契約確定直後に削除しない。

V2用Routeを追加する。

```text
routes/public_v2.php
routes/admin_v2.php
routes/webhooks_v2.php
```

### 19.2 業務Serviceを再利用

V2 Controller / Resourceは、既存Laravel Serviceを利用する。

抽選、ポイント、在庫、配送、返金、QA等を再実装しない。

### 19.3 移行順

```text
1. OpenAPI共通Primitive
2. Public read-only Content / Catalog
3. Site Runtime
4. Auth Session
5. Current User / Wallet
6. Draw
7. User Prize / Exchange
8. Shipping
9. Payment
10. Admin API
11. Webhook
```

### 19.4 V1とV2の比較

同じfixtureに対し、必要な意味が一致することを確認する。

Wire形式はV2で変更してよいが、業務結果を変えない。

### 19.5 Public Probability

V1公開型に景品別ppmが存在しても、V2では引き継がない。

V2 Public APIは次だけを返す。

- ランク合計確率
- Stage
- 切替条件
- 最低保証

景品別個別確率はAdmin APIまたは内部処理だけに置く。

---

## 20. 意図的に未確定の項目

この文書で基盤契約は確定するが、次は別のPro工程で確定する。

### 認証・セキュリティ設計

- Session有効期間
- Remember me
- MFA
- OAuth state / PKCE
- Account linking
- Owner / Admin / Operator
- Reauthentication
- Rate Limit具体値

### DB・決済・ポイント設計

- 本番決済Provider
- Payment next_action
- Payment provider event
- 3D Secure等の本人認証
- Payment adjustment
- Refund / Chargeback provider連携
- 部分返金の将来構造

これらを未確定のまま、CodexがProvider固有のAPIを実装してはいけない。

---

## 21. 最終確定事項一覧

| 項目 | 確定内容 |
|---|---|
| 大型Storefront SDK | 作らない |
| 薄い型付きClient | 作る |
| Package名 | `@oripa/storefront-client` |
| API正本 | OpenAPI |
| OpenAPI Version | 3.1.1 |
| Public API | `/api/v2` |
| Admin API | `/admin/api/v2` |
| Webhook API | `/webhooks/v2` |
| Error | RFC 9457 Problem Details |
| JSON命名 | snake_case |
| ID | opaque string |
| 日時 | UTC RFC 3339 |
| 業務日付 | Asia/Tokyo `YYYY-MM-DD` |
| 金額・ポイント | 整数 |
| Pagination | cursor |
| Auth | Sanctum Session Cookie |
| CSRF | Storefront Clientが処理 |
| Token LocalStorage | 禁止 |
| 重要Mutation | Idempotency-Key必須 |
| Client Cache | 持たない |
| Client Business Logic | 持たない |
| Client UI | 持たない |
| 直接fetch | Platform APIでは禁止 |
| Client Version | API majorと一致 |
| Site依存Version | 完全固定 |
| 景品別ppm | Public APIへ出さない |
| Payment詳細 | Provider選定後に別設計 |
| OAuth詳細 | Security設計で確定 |

---

## 22. この決定の要旨

必要なのは「高機能なSDK」ではない。

必要なのは、複数のサイトと複数のサイト専用Codexが、同じLaravel APIを安全かつ同じ方法で利用するための、薄い境界である。

最終構成:

```text
OpenAPI
    ↓ 型・operation生成
@oripa/storefront-client
    ↓ 安全なHTTP呼び出し
各サイト専用UI
```

責任分担:

```text
Laravel
├── 抽選
├── ポイント
├── 在庫
├── 決済
├── 返金
└── 配送状態

Storefront Client
├── HTTP
├── 型
├── Session Cookie
├── CSRF
├── Idempotency-Key
├── Error変換
└── Retry

サイト専用Codex
├── ページ
├── UIプリミティブ
├── デザイントークン
├── アニメーション
├── 文言
└── レスポンシブ
```

この境界をV2の正式契約とする。
