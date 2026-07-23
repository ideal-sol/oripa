# Infrastructure

## Responsibility

Platform／Siteの独立EnvironmentとProvider-neutral Infrastructure定義を管理する。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

OIDC、Environment、Network、Storage、Observabilityの承認済み定義を配置予定。

## Allowed Scope

Site分離、Build Once／Digest Promote、非秘密Example。

## Forbidden Scope

共有Runtime／DB、Production Secret、未確定Provider推測、V1 CodeをCopyしない。

## Status

現時点はGovernanceとProvider-neutral ExampleだけのSkeleton。Production利用不可。
