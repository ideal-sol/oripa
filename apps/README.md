# Apps

## Responsibility

V2 PlatformのDeploy可能Application境界を管理する。

## Ownership

OwnerはPlatform Codex。Root [`AGENTS.md`](../AGENTS.md)と各Applicationの
`AGENTS.md`に従う。

## Planned Components

- `api`: Laravel modular monolith
- `admin`: 共通Admin Next.js Application

## Allowed Scope

承認済みContractに基づくApplication、Test、Build設定だけを配置する。

## Forbidden Scope

Site固有実装、顧客Credential、Production Secret、V1 CodeをCopyしない。

## Status

現時点は責任境界だけを定義するSkeletonで、Application実装済みではない。
Production利用不可。`backend`／`frontend`は本Taskで移動・変更しない。
