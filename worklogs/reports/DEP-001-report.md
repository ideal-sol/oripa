# DEP-001 V1 Frontend Security Deployment 提出用レポート

## 基本情報

- Task ID: `DEP-001`
- Risk: `R4`
- Issue: `#161`
- PR: `#162`（Phase B成功、Closeout前）
- Branch: `chore/DEP-001-v1-frontend-security-deployment`
- Base／PR Base: `v1/early-release`
- Base SHA: `acecccbc96e756e9521c70f5f497109456cb20bb`
- Policy SHA-256:
  `228ac2919e09c94544bc7db1daad0ae05df96de1606964e86f7cc36016e84d93`
- Phase A Record Head: `0a24428200826fde99c39fb842bfd9bc13d7b6af`
- Final Head／Squash Commit:
  本レポートを含むCloseout HeadとGitHub Squash Merge結果を正本とする。
- Evidence:
  `/var/lib/oripa-v2-evidence/DEP-001/phase-a/`、
  `/var/lib/oripa-v2-evidence/DEP-001/phase-b/`

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

## Phase B Production Deployment

人間が明示承認したCandidate Digest
`sha256:c30e14454ca32c871fd1853c0ea1b4bcece1a32b49d18fb909297f110d028e1a`
だけをV1 Frontendへ反映した。切替完了時刻は
`2026-07-31T09:42:03Z`。Production Host上でSource編集、Dependency Install、
Migrationは実施していない。

| 項目 | 切替前 | 切替後 |
| --- | --- | --- |
| Runtime Commit | `0c5262d42babc1bf3a63bd991ab07afb014a03c2` | `acecccbc96e756e9521c70f5f497109456cb20bb` |
| Image Digest | `sha256:17c7a72a5ce461e0f78d489cc4a0d75d98f74e59e882232a4767467d6e02fa8c` | `sha256:c30e14454ca32c871fd1853c0ea1b4bcece1a32b49d18fb909297f110d028e1a` |
| Container | `oripa-draw-1000b-frontend` | `oripa-draw-1000b-frontend` |
| Network／Port | `oripa_default`／`127.0.0.1:3130` | 不変 |
| Restart Policy／Mount | `unless-stopped`／0件 | 不変 |

旧Production Containerは
`oripa-draw-1000b-frontend-rollback-dep001`へRenameし、停止状態で保持した。
旧Image `sha256:17c7a72...`と第二Rollback Runtime
`oripa-notice-002d-frontend`／`sha256:9a3957f7...`は削除していない。

## Phase B Verification

| 検証 | 結果 |
| --- | --- |
| Internal `/api/health` | HTTP `200` |
| Internal Top | HTTP `200` |
| Internal Static Asset | HTTP `200` |
| Internal Image Optimizer | HTTP `200`、`image/webp` |
| External `https://luxe-pack.biz/api/health` | HTTP `200` |
| External Top | HTTP `200` |
| External Static Asset | HTTP `200` |
| External Image Optimizer | HTTP `200`、`image/webp` |
| Runtime Version | Node `22.23.1`、Next `16.2.11`、sharp `0.35.3`、libvips `8.18.3` |
| js-yaml | Builderで`4.3.0`、Runtime不要Dependencyとして非搭載 |
| 新規500／502／504 | `0` |
| Nginx Critical | `0` |
| Runtime Critical | `0` |
| Nginx Config Checksum | 切替前後一致 |
| DB／Redis／Storage／V2 Container | ID、Image、状態が切替前後一致 |

Nginx error logには切替試行期間全体で非Criticalの追記が1行あったが、
500／502／504および`crit`／`alert`／`emerg`／`panic`は0件。
Nginx、TLS、Domain設定は変更していない。

## Safe Rollback Evidence

切替検証スクリプトの誤判定で2回Fail Closedし、どちらも新Containerを除去して
旧Containerを元の名前へ戻し、HTTP `200`まで確認した。

1. pnpm standalone配置でrootから`require('sharp')`できると仮定したVersion
   Probeが失敗した。Candidate内の実Package Pathでsharp `0.35.3`、
   libvips `8.18.3`を確認し、Image Optimizer実経路もHTTP `200`だった。
2. Nginx error logの非Criticalな1行をCritical扱いした。500／502／504は0、
   Critical Patternも0であることを分離して確認した。

いずれもCandidate Artifact、Application Source、Production設定は変更していない。
検証方法だけを正本要件へ合わせ、3回目の同一Digest切替と全Smokeが成功した。

## Phase B Security／Scope

- Production Deployment対象: V1 Frontend Serviceのみ
- DB Migration: `NONE`
- DB／Redis／Storage Change: `NONE`
- Nginx／TLS／Domain／Firewall Change: `NONE`
- V2 Change／Restart: `NONE`
- Application Source／Manifest／Lockfile Change: `NONE`
- Production上のSource編集／Dependency Install: `NONE`
- 旧Container／旧Image／Rollback Artifact削除: `NONE`
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`

## Phase Bで時間を要した作業

| 作業 | 影響 | 改善 |
| --- | --- | --- |
| Runtime sharp Version Probe | 初回切替を自動Rollback | pnpm実配置のPackage PathとImage Optimizer実経路を使用し、Artifact再Buildを回避した。 |
| Nginx Error分類 | 2回目切替を自動Rollback | HTTP 500／502／504、Critical Pattern、非Critical行を分離し、Security閾値は緩和せず誤判定だけを除いた。 |
| Production切替 | 各試行は数秒、全試行でRollback Health 200 | Guarded Scriptで失敗時の旧Container復旧を自動化し、旧Artifactを一度も削除しなかった。 |

成功済みPhase A Build、Frozen Install、Audit、Typecheck、Lint Characterizationは
同一Digestのため重複実行していない。Phase BではRuntime／内外HTTP／Resource
非変更境界に検証を限定した。
