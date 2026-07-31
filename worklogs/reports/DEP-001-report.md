# DEP-001 V1 Frontend Security Deployment 提出用レポート

## 基本情報

- Task ID: `DEP-001`
- Risk: `R4`
- Issue: `#161`
- PR: `#162`（Draft／Phase A）
- Branch: `chore/DEP-001-v1-frontend-security-deployment`
- Base／PR Base: `v1/early-release`
- Base SHA: `acecccbc96e756e9521c70f5f497109456cb20bb`
- Policy SHA-256:
  `228ac2919e09c94544bc7db1daad0ae05df96de1606964e86f7cc36016e84d93`
- Phase A Head／Final Head／Squash Commit:
  Phase A PR更新後／Phase B Closeout時に確定する。
- Evidence: `/var/lib/oripa-v2-evidence/DEP-001/phase-a/`

## Phase A結果

INF-001で正本化された`infra/docker/frontend/Dockerfile`を変更せず、
SEC-006の依存修正を含む固定BaseからV1 Frontend CandidateをBuildした。

| 項目 | 値 |
| --- | --- |
| Candidate Tag | `oripa-v1-frontend:dep-001-acecccbc96e7` |
| Content Digest／Image ID | `sha256:c30e14454ca32c871fd1853c0ea1b4bcece1a32b49d18fb909297f110d028e1a` |
| Source Commit | `acecccbc96e756e9521c70f5f497109456cb20bb` |
| Dockerfile Blob | `4a1825e6077b72a54412b7c5169860d08f1b46cf` |
| Image Size | `669,702,182 byte` |
| Build Time | `145.82秒` |
| Runtime User | `node`（UID／GID 1000） |

BuildにはClassic Builder、pnpm `10.12.1`、
`pnpm install --frozen-lockfile`、`pnpm build`を使用した。
Production Tagの切替、Production Host上のInstall／Source編集は行っていない。

## Dependency Version

| Package | Production現行 | Candidate |
| --- | --- | --- |
| Node | 旧Image固定 | `22.23.1` |
| pnpm | 旧Image固定 | `10.12.1`（builder） |
| next | `16.2.9` | `16.2.11` |
| sharp | `0.34.5` | `0.35.3` |
| js-yaml | `4.2.0` | `4.3.0`（builder） |
| libvips | 旧Image内Version | `8.18.3` |

js-yaml、ESLint、TypeScriptはbuild-only dependencyであり、Runtime Imageへ
含めていない。

## Test／Gate

| 検証 | 結果 |
| --- | --- |
| Docker Image Build | PASS |
| Frozen Lockfile Install | PASS |
| Production Build | PASS |
| Typecheck | PASS、15.79秒 |
| Dependency Audit | PASS、全Severity 0 |
| Lint Characterization | PASS、既存8 Error／1 WarningとByte一致、新規0 |
| Candidate Container Start | PASS、Loopback `3311` |
| Health | HTTP `200` |
| Top Page | HTTP `200` |
| Static Asset | HTTP `200`、PNG |
| Next Image Optimizer／sharp | HTTP `200`、WebP |
| Runtime Version | PASS |
| Runtime不要File／Credential確認 | PASS |
| Secret／PII Candidate | 0 |
| Policy Gate | PASS |
| Security Gate | PASS |
| Quality Gate | PASS |
| `git diff --check` | Final Phase A Headで再確認 |

V2、DB、Migration、Drawを変更していないため、V2全回帰、DB Guard、
Draw性能、Backup／Restoreは指示どおり実行していない。

## Source／Artifact境界

- Frontend Application Source変更: 0件
- Dockerfile変更: 0件
- `frontend/package.json` SHA-256:
  `ed75219ce4e06e5241fd54c2cd07d8267f12ef4495b9ac5bd2d60ec8396d5c49`
- `frontend/pnpm-lock.yaml` SHA-256:
  `c7097ac90e5ea19e03bf2af7f7945e2ecc56d66182af0bd7710621f882f0d8e1`
- Candidate History／ConfigのSecret Candidate: 0件
- Runtime `.env`／Key／Credential／Source directory: 0件

## Production Read-only状態

- Target: `oripa-draw-1000b-frontend`
- Runtime Commit: `0c5262d42babc1bf3a63bd991ab07afb014a03c2`
- Runtime Image:
  `sha256:17c7a72a5ce461e0f78d489cc4a0d75d98f74e59e882232a4767467d6e02fa8c`
- Port: `127.0.0.1:3130`
- Health／Top／Static Asset: HTTP `200`
- Nginx Frontend Upstream: `127.0.0.1:3130`
- Production changed: `NO`

第一Rollback Artifactは現在稼働中のImage ID `sha256:17c7a72...`。
Phase Bでは現行Containerを停止後も削除せず、
`oripa-draw-1000b-frontend-rollback-dep001`へRenameして保持する。

第二Rollback Runtime:

- Container: `oripa-notice-002d-frontend`
- Image:
  `sha256:9a3957f777e38ac9ba9c8a86a842d6060a684bd71ee660099cc8fbfbf23365e5`
- Port: `127.0.0.1:3120`
- Health: HTTP `200`

## Phase B予定

Production承認後はNginxを変更せず、現行Frontendを停止・Renameし、Candidate
Digestを同じService名／Network／Portで起動する。想定停止時間は数秒、
最大30秒以内を目標とする。

予定Command:

```text
docker stop --time 30 oripa-draw-1000b-frontend
docker rename oripa-draw-1000b-frontend \
  oripa-draw-1000b-frontend-rollback-dep001
docker run -d \
  --name oripa-draw-1000b-frontend \
  --restart unless-stopped \
  --network oripa_default \
  --label oripa.release=acecccbc96e7 \
  --label oripa.task=DEP-001 \
  -p 127.0.0.1:3130:3000 \
  -e INTERNAL_API_BASE_URL=http://oripa-draw-1000b-backend:8000/api \
  -e INTERNAL_ADMIN_API_BASE_URL=http://oripa-draw-1000b-backend:8000/admin/api \
  -e NEXT_PUBLIC_API_BASE_URL=https://luxe-pack.biz/api \
  -e NEXT_PUBLIC_APP_NAME=Oripa \
  -e NEXT_PUBLIC_FRONTEND_URL=https://luxe-pack.biz \
  sha256:c30e14454ca32c871fd1853c0ea1b4bcece1a32b49d18fb909297f110d028e1a
```

Post-deployment確認:

```text
curl --fail http://127.0.0.1:3130/api/health
curl --fail http://127.0.0.1:3130/
curl --fail http://127.0.0.1:3130/lp-logo.png
curl --fail -H 'Accept: image/webp' \
  'http://127.0.0.1:3130/_next/image?url=%2Fcoin.png&w=64&q=75'
docker inspect oripa-draw-1000b-frontend
```

Rollback:

```text
docker stop --time 30 oripa-draw-1000b-frontend
docker rm oripa-draw-1000b-frontend
docker rename oripa-draw-1000b-frontend-rollback-dep001 \
  oripa-draw-1000b-frontend
docker start oripa-draw-1000b-frontend
curl --fail http://127.0.0.1:3130/api/health
```

Rollback TriggerはHealth／Top／Static／Image Optimizer失敗、Version不一致、
500／502／504、新規Critical Error、Runtime Digest不一致である。

## 非変更

- DB Migration: `NONE`
- DB／Redis／Storage Change: `NONE`
- Nginx／TLS／Domain Change: `NONE`
- V2 Change: `NONE`
- Production Deployment performed: `NO`

## 時間を要した作業

| 作業 | 所要 | 原因／改善 |
| --- | ---: | --- |
| Candidate Build | 145.82秒 | Frozen InstallとNext production build。Full LogをRepository外Evidenceへ保存した。 |
| Version収集 | 数分 | 初回Shell引用符Error後、成功済みTestを再実行せずVersion取得だけ個別化した。 |
| Runtime内容確認 | 数分 | BusyBox非対応のGNU `find -printf`をportable Commandへ置換し、Image再Buildを避けた。 |

Root／`/tmp`容量は安全範囲だったため、Docker Cacheや稼働Resourceを削除していない。

## Approval／Gate

- Human approval required: `YES`
- Production approved: `NO`
- Production Deployment performed: `NO`
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Issue／PR／Branch／Worktree、Candidate／Builder Image、Task Containerは
  Phase B Closeoutまで保持する。
