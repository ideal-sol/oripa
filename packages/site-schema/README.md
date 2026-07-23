# Site Schema

## Responsibility

Site設定とPlatform Compatibilityを検証する`@oripa/site-schema`の境界を管理する。

## Ownership

OwnerはPlatform Codex。親[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

JSON Schema、Type、Validator、Compatibility Testを配置予定。

## Allowed Scope

Public-safeなSite設定ContractとValidation。

## Forbidden Scope

Secret値、顧客PII、Provider Credential、Business Logic、V1 CodeをCopyしない。

## Status

現時点はREADMEと非公開`package.json`だけのSkeleton。Schema Package実装済みではなく
Production利用不可。ExportとDependencyは定義していない。
