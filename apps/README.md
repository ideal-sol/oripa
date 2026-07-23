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

`apps/api`にはMIG-021でV1由来Laravel Applicationを内容不変で移動した。
V2向けBehavior変更やApplication Skeleton実装を完了した状態ではなく、
Production利用不可。`frontend`はRootに維持する。
