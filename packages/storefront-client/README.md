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

Versionは`2.0.0-alpha.6`。Public OpenAPIから生成した型と、Contractに実在する薄い
Facadeだけを提供する。Packageは非公開Alphaであり、承認されたArtifactをVersionと
SHA-256で固定して導入する。

Catalog Facadeの`getGachaPresentation`は、販売状態、Audience、日次上限、実行可能な
Draw Count、CTA状態をBackend判定済みの`private, no-store` Responseとして取得する。
Storefront側でEligibilityを再計算しない。

Prize Shipping Facadeの`listPrizes`／`getPrize`は、型付きPrize Presentationと
Backend判定済みのShipping／Point交換／選択可否を取得する。Storefront側でStatus、
期限、交換PointからAction可否を再計算しない。

Browser Draw Facadeの`createBrowserStorefrontDrawClient`は、CanonicalなSession
初期化とCSRF Cookie／Header処理を内部化する。Callerは`Idempotency-Key`だけを明示し、
同じRetryへ同じKeyを渡す。Draw拒否は`isDrawProblemError`でGenerated codeを判別し、
未知のProblem codeは汎用`ApiProblemError`として扱う。

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
