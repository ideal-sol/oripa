# Packages

## Responsibility

V2 First-party PackageのSource、Contract、Version境界を管理する。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

`platform`、`storefront-client`、`site-schema`、`storefront-testkit`を配置予定。

## Allowed Scope

Compatibility Policyに従う薄いPackageと生成済みContract Artifact。

## Forbidden Scope

Site固有実装、Business Authority、範囲Versionでの公開、V1 CodeをCopyしない。

## Status

現時点は責任境界だけのSkeleton。Package実装・公開済みではなくProduction利用不可。
First-party PackageのRelease時依存Versionは完全固定する。
