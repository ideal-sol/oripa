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
- 未確定CSRF仕様を注入するInitializer境界

## Allowed Scope

承認済みPublic OpenAPI Contractから生成・検証される薄いClient。Browser Clientは
`credentials: include`を強制し、Server ClientはRequest単位で作成してGET／HEAD
だけを許可する。

## Forbidden Scope

Draw／Point／Payment／Auth判断、大型SDK、直接Contract推測、V1 CodeをCopyしない。
Admin／Webhook型、React State、UI、Routing、Cache、LocalStorage Token、Provider
固有処理を持たせない。

## Status

Versionは`2.0.0-alpha.1`。Public OpenAPIのOperationが0件のため、Generated
Component TypeとTransport／Error／Retry基盤だけを提供する。Fake Endpoint Method
や架空の業務型はない。Packageは非公開AlphaでありProduction利用不可。

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

## CSRF Boundary

CSRF Endpoint、Cookie名、Header名は未確定値を推測しない。Mutationごとに
`csrf: "required"`を指定した場合だけ、Client Configの`csrf_initializer`を一度
呼ぶ。Initializerが実際の非Production／Production構成に応じた処理を所有する。

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
