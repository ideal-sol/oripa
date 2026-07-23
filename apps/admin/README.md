# Admin Application

## Responsibility

V2共通Admin Next.js ApplicationのUIとAdmin API Client境界を担う。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../../AGENTS.md)に従う。

## Planned Components

Admin Route、Feature、MFA UI、Permission UI、Audit表示を配置予定。

## Allowed Scope

承認済みAdmin API Contractを利用するSite非依存のAdmin Application。

## Forbidden Scope

Draw／Point／Payment判断、Site固有Design、User Cookie、V1 CodeをCopyしない。

## Status

現時点はBuild／Health検証用の最小Next.js Skeletonであり、Production利用不可。
表示PageとHealth Endpoint以外のApplication機能、API接続、Auth、MFA、
Business Logicは実装していない。

## Local Verification

Root Workspaceで次を実行する。

```text
pnpm install --frozen-lockfile
pnpm admin:typecheck
pnpm admin:lint
pnpm admin:build
```

非ProductionのV2 Composeでは`GET /api/health`だけを起動確認に使用する。
