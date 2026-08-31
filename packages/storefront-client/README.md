# Storefront Client

## Responsibility

Public API v2を利用する薄い`@oripa/storefront-client` Alphaを管理する。
OpenAPIを型の正本とし、HTTP通信の安全な共通処理だけを提供する。

## Ownership

OwnerはPlatform Codex。親[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

- Public OpenAPIから決定的に生成するType
- Browser／Server Entry Point
- JSON TransportとResponse Metadata
- RFC 9457 Problem Details変換
- Timeout／AbortSignal
- Idempotency-Key
- GET／HEADと冪等Mutationだけの限定Retry
- Browser Cookie Session認証とCanonical CSRF初期化
- 登録、Login、Logout、Session、Email Verificationの型付きFacade

## Allowed Scope

承認済みPublic OpenAPI Contractから生成・検証される薄いClient。Browser Clientは
`credentials: include`を強制し、Server ClientはRequest単位で作成してGET／HEAD
だけを許可する。

## Forbidden Scope

Draw／Point／Payment／Auth判断、大型SDK、直接Contract推測、V1 CodeをCopyしない。
Admin／Webhook型、React State、UI、Routing、Cache、LocalStorage Token、Provider
固有処理を持たせない。

## Status

Package Version候補は`2.0.0-alpha.34`、Public OpenAPI Contract Versionは
`2.0.0-alpha.30`。Public OpenAPIから生成した型と、Contractに実在する薄い
Facadeだけを提供する。Packageは非公開Alphaであり、承認されたArtifactをVersionと
SHA-256で固定して導入する。

Content Facadeの`listFooterPages`は、Backendが公開期間と「フッターに表示」を判定した
Static Pageの`id`／`slug`／`title`だけを返す。本文は`getStaticPage`で取得する。

Content Facadeの`listBanners`は、Backendが公開期間と「トップに表示」を判定した
BannerのCanonical画像URLとクリック先URLを返す。

Point Product Facadeの`listPointProducts`は、Backendが販売期間、Audience、Current Userの
成功済みPoint購入履歴を評価した商品順序、Eligibility、CTA Presentationを返す。購入Mutationは
提供せず、Storefront側で初回ユーザー判定を再実装しない。

Current User Point Facadeの`getWallet`／`listPointLedgerEntries`は、利用可能残高と
Backend-authoritativeな理由Presentation／Stable Ordering／Cursorを返す。Client側で
Ledger集計、理由変換、並び替えを行わない。

Identity Facadeの`getLineFriendState`は、LINE連携、友だち追加確認、`is_line_user`、
status label、Primary CTAをBackend-authoritativeなPresentationとして返す。Client側で
LINEユーザー判定を再実装しない。

Draw Facadeの`listDrawHistory`は、Current User自身のGacha利用履歴を
Backend-authoritativeなGacha Presentation／status label／Stable Ordering／Cursorで返す。
Client側で内部status、DB ID、event codeを解釈せず、履歴の並び替えも行わない。

Catalog Facadeの`listGachas`はPublic対象を販売状態やEligibilityで除外せず、Backend判定済み
Sale State、Eligibility、CTA、項目表示可否を返す。匿名Responseだけをpublic cache対象とし、
認証済みResponseは`private, no-store`で分離する。`getGachaPresentation`は日次上限と実行可能な
Draw Countも取得し、Storefront側で日時、在庫、Audienceから再計算しない。

Prize Shipping Facadeの`listPrizes`／`getPrize`は、型付きPrize Presentationと
Backend判定済みのShipping／Point交換／選択可否を取得する。Storefront側でStatus、
期限、交換PointからAction可否を再計算しない。

Browser Draw Facadeの`createBrowserStorefrontDrawClient`は、CanonicalなSession
初期化とCSRF Cookie／Header処理を内部化する。Callerは`Idempotency-Key`だけを明示し、
同じRetryへ同じKeyを渡す。Draw拒否は`isDrawProblemError`でGenerated codeを判別し、
未知のProblem codeは汎用`ApiProblemError`として扱う。

Drawの`requested_count`はGachaで設定されたCountを保持し、残口数だけが不足する場合は
BackendがLock後の残口数を`executed_count`として確定する。Storefrontは残口数から実行数を
再計算せず、成功ResponseとResult取得で端数を扱う。

Browser Fulfillment Facadeの`createBrowserStorefrontPrizeShippingClient`も同じ
CSRF境界を使い、景品交換、配送依頼、配送先作成ではCallerが明示した同一
`Idempotency-Key`だけを自動再試行する。配送先更新／削除は自動再試行せず、通信結果が
不明な場合は`getShippingAddress`または`listShippingAddresses`で状態を照合する。
Shipping成功後は`getShippingRequest`または`getPrize`、Point交換成功後は`getPrize`で
Canonical状態を再取得する。既知の拒否は`isFulfillmentProblemError`、未知Codeは汎用
`ApiProblemError`として扱う。

Browser Contact Facadeの`createBrowserStorefrontContentContactClient`は、匿名または
認証済みの問い合わせ送信前にCanonicalなSession bootstrapを行い、CSRF Cookie読取、
XSRF Header構築、`credentials: include`をClient内部へ閉じ込める。Callerは
`csrf_token`、Cookie名、Header名、bootstrap手順を扱わない。Contact mutationは
Idempotency未対応のため自動再試行せず、HTTP 202 Receipt、Validation Problem Details、
429、transport errorを既存の型付きResponse／Error境界で返す。

```ts
import {
  createBrowserStorefrontContentContactClient,
} from "@oripa/storefront-client/browser";

const contact = createBrowserStorefrontContentContactClient({
  base_url: "/api/v2",
  site_version: "1.0.0",
  default_timeout_ms: 10_000,
});

await contact.submitContact({
  name: "Example User",
  email: "example@example.test",
  phone: null,
  subject: "お問い合わせ",
  body: "Public-safe example body.",
  website: "",
});
```

## Entry Points

```text
@oripa/storefront-client
@oripa/storefront-client/browser
@oripa/storefront-client/server
@oripa/storefront-client/types
```

`browser`は`createBrowserStorefrontClient`、`server`は
`createServerStorefrontClient`を公開する。`types`はPublic Bundleから生成された
`paths`、`components`、`operations`だけを再Exportする。

## Browser Session Guide

Public認証のCanonical Base Pathは`/api/v2`で、Bearer TokenやLocalStorageは使わない。
Browser Clientは常に`credentials: "include"`を設定する。

1. `createBrowserStorefrontClient`を作成する。最初のMutation前にClientが
   `GET /api/v2/auth/session`を1回呼び、CSRF Cookieを初期化する。
2. Backendは`__Host-oripa_user_xsrf`を設定する。Clientは各Mutation直前に現在値を
   読み、`X-XSRF-TOKEN`へ設定する。Site側でCookie名やHeader名を再定義しない。
3. `createStorefrontIdentityClient(transport).login(...)`を呼ぶ。成功時は
   `__Host-oripa_user_session`がRotationされる。
4. `getCurrentSession()`は認証済みならUser、Cookieなしなら
   `{ authenticated: false, user: null }`を返す。期限切れCookieは401
   `SESSION_EXPIRED`となり、ClientはLogin用CSRF初期化を継続できる。
5. `logout()`はSessionを失効し、Session Cookieを期限切れにする。
6. `register(...)`は202とVerification待ちUser IDを返す。
7. `resendEmailVerification(...)`はAccount存在有無を開示せず202を返す。
8. `completeEmailVerification(...)`は一度限りのURL Tokenを検証しSessionを発行する。

Cookie属性は次で固定する。

| Cookie | Secure | HttpOnly | SameSite | Domain | Path |
| --- | --- | --- | --- | --- | --- |
| `__Host-oripa_user_session` | yes | yes | Lax | なし（Host-only） | `/` |
| `__Host-oripa_user_xsrf` | yes | no | Lax | なし（Host-only） | `/` |

Unsafe JSON Requestは正確な`Origin`、`Content-Type: application/json`、同値のCSRF
Cookie／Headerが必須で、CORSは使わない。`Sec-Fetch-Site: cross-site`は拒否される。

Errorは`ApiProblemError`と`isAuthProblemError`で扱う。主要Codeは
`INVALID_CREDENTIALS`、`EMAIL_VERIFICATION_REQUIRED`、`INVALID_REQUEST`、
`CSRF_TOKEN_MISMATCH`、`SESSION_EXPIRED`、`INVALID_VERIFICATION_LINK`、
`VERIFICATION_LINK_EXPIRED`、`EMAIL_ALREADY_CLAIMED`、`RATE_LIMITED`である。
構成不備、Redirect拒否、Content-Type拒否もそれぞれ`AUTH_SERVICE_UNAVAILABLE`、
`INVALID_REDIRECT`、`UNSUPPORTED_MEDIA_TYPE`として型付きで判別できる。
HTTP 401は未認証／期限切れ、403はCSRF／Email未認証、422はValidation、429は
Rate Limitとして、Statusだけで分岐しない。

## Payment Return Handoff

Credit Card選択時は購入操作より先に`getPaymentCardUiBootstrap()`を呼び、返された
`public_api_key`と`is_live_mode`を公式`initFincode`へ渡してCard UIをcreate／mountする。
このGETはPayment、Provider Session、Registration Intent、Card、Coinを作成せず、CSRFまたは
Idempotency-Keyを要求しない。購入操作時に初めて`startPayment()`を呼び、新規Cardの
`next_action.public_api_key`／`is_live_mode`がBootstrapと一致しなければ実行を停止する。

保存せず購入はBootstrap→mount→`startPayment(source=new, save=false)`でPayment 3DS2へ進む。
保存する場合はBrowser UIが一時生成した`card_token`を`startCardRegistration()`へ渡し、
`next_action`のProvider Registration 3DS2を完了する。Browser Returnは成功Authorityではない。
`getCardRegistration()`でPlatform状態を読み、必要な場合だけ
`reconcileCardRegistration()`でProvider Payment Method exact GETとCard exact GETを実行する。
`status=completed`かつ`saved_card_id`が返った後だけ、そのPlatform-owned IDを
`startPayment(source=saved, card_id=saved_card_id)`へ渡して別のPayment 3DS2を開始する。

`createCardRegistrationIntent()`と`completeCardRegistration()`は旧Client互換のdeprecated surfaceで、
Browser `registerCard()`または`provider_card_id`だけではRegistration 3DS2 proofにならない。
`completeCardRegistration()`と`startPayment(source=new, save=true)`はBackendが
`CARD_REGISTRATION_3DS_REQUIRED`でFail Closedする。保存確認をcancelする場合は
`cancelCardRegistration()`を使い、failure／cancel／expiryではPaymentを開始しない。

`listPaymentCards().limits.remaining`は従来どおりundeleted rowだけを数える。
新規登録可否は`registration_remaining`を使用し、0の原因がlive pending Registrationなら
`next_capacity_at`を安全な再試行時刻として扱う。`cards.length < 3`だけで判定しない。

Canonical normal returnは`/points/purchase/thanks?pid={Payment.id}`、failure／cancelは
`/points/purchase/{PointProduct.id}?pid={Payment.id}`である。両方の`pid`はPlatform生成の
Canonical Public Opaque `Payment.id`で、Storefrontは即時`getPayment(pid)`を実行する。
Browser routeはstatus Authorityではなく、`succeeded`なら完了、`failed`／`canceled`／
`expired`なら商品購入ページ、`created`／`requires_action`／`processing`なら2秒間隔かつ
最大30秒を目安にpollする。429では`Retry-After` headerまたはProblem Detailsの
`retry_after_seconds`を2秒より優先する。Client transportはGETのNetwork Errorと
502／503／504だけを限定retryし、429を自動retryしないため、Payment pollingとは別責任である。

Konbini／Virtual Accountの期限内未払いは`getPayment`で状態を読み、User action時だけ
`resumeUnpaidPayment(pid)`で保存済み既存redirectを再取得する。`getPayment().next_action.url`を
durable URLとして扱わず、resumeは新規Payment、fincode Session、支払情報、Virtual Accountを
作成せずProvider status再照会も行わない。Credit Card／PayPayにはresumeを使用しない。

購入履歴詳細は既存`getPayment(paymentId)`の`payment.grant`を使用する。`paid_points`は確定した
有償コイン、`bonus_points`は期間限定分を含まない通常無償ボーナス、
`limited_bonus_points`はPayment成功時に適用・確定した期間限定ボーナス（適用なしは0）、
`total_points`は3fieldのCanonical合計である。過去実績を現在のPointProductやCampaignから
再計算しない。

## Origins

- Local: `V2_PUBLIC_ORIGIN`へLocal Storefrontの正確なHTTPS Originを設定する。Secure
  Cookieのため平文HTTPではBrowser Session検証を完了できない。
- Preview: Storefront専用Previewが構築された場合、その同一Originを設定する。現在の
  `admin.luxe-pack.biz`はAdmin RealmでありPublic User Originとして使用しない。
- Production: V2 Storefront切替が別Taskで承認された場合のみ、その同一Origin
  `https://luxe-pack.biz`を設定する。本Package導入だけではV1 Runtimeを変更しない。

Environmentを跨ぐCookie共有、Domain属性、Cross-origin Credential通信は非対応である。

## Validation

```text
pnpm storefront:generate:check
pnpm storefront:typecheck
pnpm storefront:lint
pnpm storefront:build
pnpm storefront:test
```

`src/generated/public.ts`は手動編集禁止。Public Bundleを変更して`pnpm
storefront:generate`で再生成し、生成差分をReviewする。
