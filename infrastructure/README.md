# Infrastructure

## Responsibility

Platform／Siteの独立EnvironmentとProvider-neutral Infrastructure定義を管理する。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

OIDC、Environment、Network、Storage、Observabilityの承認済み定義を配置予定。

## GitHub Delivery

`github-app/`はPlatform GitHub App wrapperのRepository source、policy helper、
provision manifestを管理する。Git wrapperの運用／rollback手順は
[`docs/operations/codex-access/github-app-git-wrapper.md`](../docs/operations/codex-access/github-app-git-wrapper.md)
をAuthorityとする。

## Allowed Scope

Site分離、Build Once／Digest Promote、非秘密Example。

## Forbidden Scope

共有Runtime／DB、Production Secret、未確定Provider推測、V1 CodeをCopyしない。

## Status

Provider-neutral Infrastructure定義はSkeletonでありProduction利用不可。
GitHub delivery infrastructureは上記runbookとcurrent Governanceに従う。
