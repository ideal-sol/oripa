# INF-001 V1 Frontend Production Dockerfile Foundation 提出用レポート

## 基本情報

- Task ID: `INF-001`
- Risk: `R4`
- Issue: `#159`
- PR: `#160`
- Branch: `chore/INF-001-v1-frontend-production-dockerfile`
- Base／PR Base: `v1/early-release`
- Base SHA: `c4adae79f91a83a2ecb20683844357ba3c21f7c0`
- Final Head／Squash Commit: PR Closeout時に確定する。
- Task Policy:
  `/etc/ideal-sol/github-app/task-policies/INF-001.json`
- Task Policy SHA-256:
  `abafb86e424a9c4f82b88169721c62731c48a23f0a7f0d59eb839d7894cc5641`
- Repository外Evidence: `/var/lib/oripa-v2-evidence/INF-001/`

## 実装内容

`infra/docker/frontend/Dockerfile`を、V1 Frontendの再現可能なproduction
build／runtime用multi-stage Dockerfileへ更新した。

- Base Image: `node:22-alpine`
- Package Manager: pnpm `10.12.1`
- Install: `pnpm install --frozen-lockfile --ignore-workspace`
- Build: `pnpm build`
- Runtime: production dependency、`.next`、`public`、必要な設定Fileだけ
- User: 非rootの`node`
- Start:
  `node node_modules/next/dist/bin/next start --hostname 0.0.0.0 --port 3000`

Build dependencyとproduction dependencyを分離した。Runtime Imageには
Application Source、Lockfile、TypeScript、ESLint、js-yaml等のbuild-only
development dependencyを含めず、起動時のCorepack downloadやDependency Installを
行わない。

## Build／起動Command

```text
DOCKER_BUILDKIT=0 docker build \
  -f infra/docker/frontend/Dockerfile \
  --build-arg NEXT_PUBLIC_APP_NAME=Oripa \
  --build-arg NEXT_PUBLIC_API_BASE_URL=http://127.0.0.1:39999 \
  --build-arg NEXT_PUBLIC_FRONTEND_URL=http://127.0.0.1:3301 \
  -t oripa-inf-001-validation:c4adae79f91a-r2 .
```

非本番ContainerはLoopback `127.0.0.1:3301`だけへBindした。Production Host、
Production Tag、Production Serviceは使用していない。

## Image内依存Version

| 対象 | 結果 |
| --- | --- |
| Node | `22-alpine`系 |
| pnpm | builderで`10.12.1`固定 |
| next | `16.2.11` |
| sharp | `0.35.3` |
| libvips | `8.18.3` |
| js-yaml | builder `4.3.0`、Runtimeには不在 |

検証Imageは
`sha256:fbc8f394c13ec12d76b601031c45dcbe5cc278df3c12b36aa4a6791ab33fbf75`、
Sizeは`669,702,219 byte`。これはINF-001のDockerfile検証物であり、
DEP-001のDeployment Candidate Artifactではない。

## Health／画像処理Smoke

| 検証 | 結果 |
| --- | --- |
| Container start | PASS、非root UID/GID `1000` |
| `/api/health` | HTTP `200` |
| Top Page `/` | HTTP `200`、Brand表示確認 |
| Static `/lp-logo.png` | HTTP `200`、PNG |
| Next Image Optimizer | HTTP `200`、`image/webp` |
| sharp／libvips実経路 | PASS、sharp `0.35.3`／libvips `8.18.3` |

## Test／Audit

| 検証 | 結果 |
| --- | --- |
| Dockerfile Build | PASS |
| Frozen Lockfile | PASS |
| Typecheck | PASS |
| Lint Characterization | PASS、既存8 Error／1 Warningと完全一致、新規0 |
| pnpm Audit | PASS、393 dependency、Advisory 0 |
| Runtime不要File確認 | PASS |
| Frontend Source／Manifest／Lockfile不変 | PASS |
| `git diff --check` | PASS |

Lint 9 Findingの正規化SHA-256は、SEC-006 BaselineとCurrentの双方で
`3a40d9957054d2f309d76d566e26101e4745c3709a63f740b6bc5ca456b2441d`。
Lint設定、Assertion、Gateを緩和していない。

Application、Manifest、Lockfile、DB、Migration、Drawを変更していないため、
Persistent／Ephemeral Guard、DB回帰、Draw性能、Backup／Restore、V2全回帰は
Task指示どおり実行していない。

## Security／非変更範囲

- Secret／PIIをBuild Argument、ENV、Layer、Log、Reportへ追加していない。
- Application Source変更: 0件
- `frontend/package.json` SHA-256:
  `ed75219ce4e06e5241fd54c2cd07d8267f12ef4495b9ac5bd2d60ec8396d5c49`
- `frontend/pnpm-lock.yaml` SHA-256:
  `c7097ac90e5ea19e03bf2af7f7945e2ecc56d66182af0bd7710621f882f0d8e1`
- V2 Application／DB／Migration／Draw変更: 0件
- Production Container／Image Tag／Service変更: 0件
- Production DB／Redis／Storage／Nginx／TLS／Domain変更: 0件
- Production Deployment: 未実施

## 時間を要した作業

| 作業 | 所要 | 原因／改善 |
| --- | ---: | --- |
| 初回Docker build | 約180秒 | Frozen InstallとNext production buildをClean Layerで実行。Full LogはRepository外Evidenceへ保存した。 |
| Runtime CMD修正後Build | 約13秒 | `pnpm start`が非root RuntimeでCorepack downloadを要求したため、Next production CLIを直接起動。成功済みLayer Cacheを維持した。 |
| sharp実Version確認 | 数分 | pnpm strict graphでRoot direct requireが拒否された。依存追加や再Installをせず、Nextの解決ContextとImage Optimizer実経路で確認した。 |

- Root空き約9.1GB、`/tmp`空き約2.9GBで安全だったため、Docker Cache Cleanupは
  実施していない。
- 成功Logは要約し、詳細を
  `/var/lib/oripa-v2-evidence/INF-001/`へ保存した。

## Fresh Self-review／Closeout

- Fresh Self-review、Final Head、Squash Commit、GitHub Check、Issue／PR状態、
  Branch／Worktree／Task Resource CleanupはFinal Head固定後に確定する。
- Production非変更をCloseout時にもRead-onlyで再確認する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- INF-001完了後もDEP-001 Artifact Build／Production Deploymentは開始しない。
