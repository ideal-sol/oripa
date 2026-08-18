# Storefront Testkit Alpha

## Responsibility

`@oripa/storefront-testkit`は、Site StorefrontがPublic API、Storefront Client、
Site SchemaのPlatform Contractへ適合するかを非Production環境で検証する。
Public OpenAPI BundleをHTTP Contractの正本とし、実Networkへ接続しない。

## Ownership

OwnerはPlatform Codex。親[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

本Alphaは次を提供する。

- FIFO応答QueueとRequest Recorderを持つ決定的なMock `fetch`
- JSON、RFC 9457 Problem Details、Network Error、Abort／Timeout用応答
- Public OpenAPI Bundleから生成するOperation数とBundle SHA-256
- Public-safeなSite Manifest、Compatibility、Response Metadata Fixture
- Browser／Server／Public Surface／Site CompatibilityのBoundary Assertion
- Anonymous／Authenticated Session、Pending Registration、Accepted ResponseのAuth Fixture

認証Fixtureは実Credential、Cookie、Token、PIIを含めず、Public OpenAPIの型に
compile-timeで適合する。架空Endpointや認証判断は提供しない。

## Entry Points

```text
@oripa/storefront-testkit
@oripa/storefront-testkit/assertions
@oripa/storefront-testkit/fixtures
@oripa/storefront-testkit/mock
```

Export Surfaceは固定し、Admin／Webhook／Provider型を公開しない。

## Deterministic Mock

`createMockFetch`が返す`fetch`だけをStorefront Clientへ注入する。RequestはMethod、
URL、Header、Body、Credentialsを順序どおり記録する。応答未登録、期待Requestとの
不一致、Queue残存は即時Failureとなるが、Error MessageへRequest、Cookie、Token、
Credential値を含めない。

`enqueuePending`はClientのAbort／Timeout検証専用で、実Networkや外部Timerを開始
しない。

## Contract Fixtures

`src/generated/public-contract.ts`は
`openapi/bundled/public.openapi.json`から決定的に生成するため直接編集禁止である。
Site Manifest Fixtureは`@oripa/site-schema`の公開型とValidatorを使い、実顧客情報、
Secret、Cookie、Token、Credentialを含めない。

## Validation

```text
pnpm testkit:generate:check
pnpm testkit:typecheck
pnpm testkit:lint
pnpm testkit:build
pnpm testkit:test
pnpm testkit:exports:check
pnpm testkit:network:check
```

Unit TestはMockの決定性、Queue、Problem Details、Network Error、Abort／Timeout、
Browser Credentials／Version Header、Authorization非付与、Server GET／HEAD、
Public Surface、Site Schema／Compatibility、Export Surfaceを明示的にAssertion
する。

## Allowed Scope

非Productionの決定的なContract／Integration Test支援だけを扱う。

## Forbidden Scope

Production Credential、実PII、Business Authority、Admin／Webhook Surface、
実Network、No-op Test、V1 Codeを含めない。TestkitをProduction Runtimeへ組み込ま
ない。

## Status

Versionは`2.0.0-alpha.23`。トップ表示対象／対象外Banner、Footer表示対象／対象外Static Page、Point商品一覧／
Eligibility／CTA、Gacha Catalogの販売状態／Eligibility／表示制御、User Prize Presentation、LINE Friend Stateの未連携／友だち追加待ち／確認済みPresentationとTyped Problem、型付きDraw／Fulfillment Problem Contract、
残口数に合わせた端数Draw Fixture、Current User Draw Historyの複数／空履歴・Stable Ordering・Cursor continuation・Typed Problem、Current User Pointの正数／0残高、加算／減算／空履歴、
Stable Ordering、Cursor continuation、Typed Problem Fixtureを含む非公開AlphaでありProduction利用不可。
Canonical Site Templateや認証以外のPublic API Operationの実装済みを意味しない。
