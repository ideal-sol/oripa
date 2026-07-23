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
- MIG-023でNode `22.22.3`、pnpm `10.12.1`をRoot Manifestへ固定した。
- Root Workspaceは`apps/admin`と`packages/*`だけを含む。
- V1 `legacy/v1-frontend`は独立Manifest／Lockfileを維持し、Root Workspaceへ
  含めない。Laravel `apps/api`もpnpm Workspace対象外とする。
- Root `pnpm-lock.yaml`はpnpm `10.12.1`の実Install結果から生成し、CIでは
  `pnpm install --frozen-lockfile`を必須とする。
- `apps/admin`はBuild／Health確認用Skeletonだけで、Auth、MFA、API接続、
  Business Logicは未実装である。
- 4 First-party Packageは非公開Manifestだけを持ち、ExportとDependencyは
  定義していない。
- First-party Packageの公開時VersionはCompatibility Policyに従い完全固定する。

## Compose Boundary

- `docker-compose.yml`はV1 Behavioral Referenceを非Productionで起動する正本。
- `docker-compose.v2.yml`はV2 API／Admin／PostgreSQL／Redisの構造Smoke専用。
- V2 ComposeとRoot Build Contextへ`legacy/v1-frontend`を含めない。
- Compose Project名でNetwork／VolumeをTaskごとに分離し、検証後は
  `docker compose down -v`で破棄する。

## Migration Boundary

`MIG-021`で`backend`を`apps/api`へ、`MIG-022`で`frontend`を
`legacy/v1-frontend`へ内容不変でMechanical Moveした。後続TaskでもMechanical
MoveとBehavior変更を分離し、同一内容を旧PathとV2 Pathへ重複配置しない。

`legacy/v1-frontend`はV1 Behavioral Referenceであり、V2 Production Image、
V2 Runtime Dependency、V2 Admin App、Site Templateへ組み込まない。
