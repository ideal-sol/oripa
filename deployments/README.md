# Deployments

## Responsibility

Environment別Deployment定義とDeployment Evidenceの境界を管理する。

## Ownership

OwnerはPlatform Codex。Root [`AGENTS.md`](../AGENTS.md)と
[`infrastructure/AGENTS.md`](../infrastructure/AGENTS.md)に従う。

## Planned Components

Staging／Production Promotion定義、Digest参照、Rollback metadataを配置予定。

## Allowed Scope

Release Gateを満たす再現可能なDeployment定義と非秘密Evidence。

## Forbidden Scope

Production Secret、Branch直接Production Deploy、Provider推測、V1 CodeをCopyしない。

## Status

現時点はREADMEだけのSkeleton。Deployment Workflowは未作成でProduction利用不可。
