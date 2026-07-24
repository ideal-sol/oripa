# Site Schema Alpha

## Responsibility

`@oripa/site-schema`は、Public-safeなSite Manifestの構造とCore
Compatibility Familyを検証する。JSON Schema Draft 2020-12の
[`schema/site-manifest.schema.json`](schema/site-manifest.schema.json)を契約の
正本とし、TypeScript型はこのSchemaから決定的に生成する。

## Ownership

OwnerはPlatform Codex。Root `AGENTS.md`と親[`AGENTS.md`](../AGENTS.md)に従う。
Site CodexはこのPackageの契約を変更せず、Platform Change Requestを使用する。

## Planned Components

JSON Schema、生成Type、Runtime Validator、Validation Error、Compatibility判定、
Fixture、Unit Testを本Alphaの管理対象とする。

## Public Contract

Manifestが公開できるFieldは次だけである。

- `schema_version`: Site Schema PackageのExact SemVer
- `site_version`: Site固有ReleaseのExact SemVer
- `compatibility.family`: Core Compatibility Family Major
- `compatibility.storefront_client_version`: 固定したStorefront Client SemVer
- `compatibility.required_capabilities`: Platformへ要求するCapability
- `public.locale`: 公開Locale
- `public.timezone`: 公開Timezone
- `public.features.enabled`: 明示的に有効化する公開Feature Capability

Featureは`<domain>.<feature>.v<number>`形式で列挙し、空配列をSecure Defaultと
する。Capability名の意味や業務設定はこのPackageで作らない。

## Validation

`parseSiteManifest`はUnknown Field、SemVer Range、不正Capability名、Secret風の
追加Fieldを拒否し、失敗時に`SiteManifestValidationError`を返す。Errorには入力値を
含めず、JSON Path、Keyword、Messageだけを含める。

`assessSiteCompatibility`はFamily Major、Schema Version、Storefront Clientの
最低Version、Required Capabilityを判定する。現在は初回AlphaだけをTest対象とし、
将来のN／N-1は`testedSchemaVersions`へ明示的に追加して拡張する。未作成のN-1を
対応済みとは扱わない。

## Generation

`src/generated/site-manifest.ts`は直接編集禁止。次でSchemaから再生成する。

```text
pnpm --filter @oripa/site-schema generate
pnpm --filter @oripa/site-schema generate:check
```

CIは再生成差分、Typecheck、Lint、Build、Unit Test、Fixture、Compatibilityを
継続検査する。

## Allowed Scope

Public-safeなSite設定Contract、型、Validation、Compatibility Testだけを扱う。

## Forbidden Scope

Secret、Credential、Cookie、Token、DB接続情報、顧客PII、Provider設定、Site固有
Design、Draw／Point／Payment判断、Laravel／OpenAPI実装、V1 Codeを含めない。

## Status

`2.0.0-alpha.1`のAlpha基盤であり、Package Publish前かつProduction利用不可。
Business Capability、Site Template、V2 DB／Redisは未実装である。
