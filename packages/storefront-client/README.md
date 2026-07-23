# Storefront Client

## Responsibility

Public API v2を利用する薄い`@oripa/storefront-client`の境界を管理する。

## Ownership

OwnerはPlatform Codex。親[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

Generated Type、Transport、Error Mapping、Idempotency Header支援を配置予定。

## Allowed Scope

承認済みOpenAPI Contractから生成・検証される薄いClient。

## Forbidden Scope

Draw／Point／Payment／Auth判断、大型SDK、直接Contract推測、V1 CodeをCopyしない。

## Status

現時点はREADMEだけのSkeleton。Package実装・公開済みではなくProduction利用不可。
