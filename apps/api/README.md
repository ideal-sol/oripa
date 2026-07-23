# API Application

## Responsibility

V2 Laravel modular monolithとPublic／Admin／Webhook APIの実行境界を担う。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../../AGENTS.md)に従う。

## Planned Components

Laravel Domain Module、Route、Migration、Queue、Scheduler、Contract Testを配置予定。

## Allowed Scope

Laravelが権威を持つDraw、Point、Inventory、Payment、Authの承認済み実装。

## Forbidden Scope

未確定Providerの推測、Production DB操作、Frontend Authority、V1 CodeをCopyしない。

## Status

現時点はREADMEとGovernanceだけのSkeleton。Production利用不可。
V1 `backend`のMechanical Moveは後続`MIG-021`で別途実施する。
