# V2 Repository Layout

## Status

MIG-020で確立するRepository Skeletonの運用正本。ApplicationやPackageの実装完了を
示さず、Production利用不可。

## Inventory Before MIG-020

- `apps/api`、`apps/admin`、`packages`、`openapi`、`infrastructure`、
  `legacy/v1`にはGovernanceまたはOIDC Exampleだけが存在した。
- `deployments/`、Root `package.json`、`pnpm-workspace.yaml`、Manifest Schemaは
  存在しなかった。
- Nodeは`22.22.3`、pnpmは`10.12.1`だった。
- LockfileはV1 `frontend/pnpm-lock.yaml`と`backend/composer.lock`だけだった。

## Responsibility Map

| Path | Responsibility | Governing instructions |
| --- | --- | --- |
| `apps/api` | Laravel modular monolith | `apps/api/AGENTS.md` |
| `apps/admin` | Shared Admin Next.js | `apps/admin/AGENTS.md` |
| `packages/*` | First-party Package | `packages/AGENTS.md` |
| `openapi` | API Contract | `openapi/AGENTS.md` |
| `infrastructure` | Independent Environment | `infrastructure/AGENTS.md` |
| `deployments` | Deployment definition／evidence | Root and Infrastructure rules |
| `manifests` | Release／Deployment Schema | Root and Release Operations |
| `legacy/v1` | V1 Behavioral Reference | `legacy/v1/AGENTS.md` |

## Workspace

- Root Packageは`private: true`、Versionは`2.0.0-alpha.1`。
- `packageManager`は既存環境と一致する`pnpm@10.12.1`。
- Workspace対象は`apps/admin`と`packages/*`だけで、V1 `frontend`を含めない。
- Dependencyを追加せず、`pnpm install`を実行しない。
- Root Lockfileは実Package／Dependency導入Taskで初めて生成・検証する。
  本Taskでは根拠なく作成しない。
- First-party Packageの公開時VersionはCompatibility Policyに従い完全固定する。

## Migration Boundary

`backend`／`frontend`は変更もCopyもしていない。`MIG-021`以降はMechanical Moveと
Behavior変更を分離し、同一内容をV1とV2へ重複配置しない。
