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
- Lockfileは当時V1 `frontend/pnpm-lock.yaml`と`backend/composer.lock`だけだった。

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

- Platform Versionの開始値は`2.0.0-alpha.1`とする。
- Node `22.22.3`、pnpm `10.12.1`はInventoryとして確認したが、Root
  `package.json`と`pnpm-workspace.yaml`は本Taskでは作成しない。
- Root Workspace設定を置くとV1 Frontend Lockfileを使用する既存CIの
  install／audit解決が変わることをGitHub Checkで確認したため、V1 Lockfileを
  変更せず設定を取り下げた。
- 実Package、Dependency、Root Lockfile、V1分離後のCI Commandは後続Taskで
  確定する。V1 `legacy/v1-frontend`はV2 Workspaceに含めない。
- 本TaskではDependencyを追加せず、`pnpm install`を実行しない。
- First-party Packageの公開時VersionはCompatibility Policyに従い完全固定する。

## Migration Boundary

`MIG-021`で`backend`を`apps/api`へ、`MIG-022`で`frontend`を
`legacy/v1-frontend`へ内容不変でMechanical Moveした。後続TaskでもMechanical
MoveとBehavior変更を分離し、同一内容を旧PathとV2 Pathへ重複配置しない。

`legacy/v1-frontend`はV1 Behavioral Referenceであり、V2 Production Image、
V2 Runtime Dependency、V2 Admin App、Site Templateへ組み込まない。
