# SEC-006 V1 Frontend Dependency Security Backport 提出用レポート

## 基本情報

- Task ID: `SEC-006`
- Risk: `R3`
- Issue: `#157`
- PR: `#158`
- Branch: `security/SEC-006-v1-frontend-dependency-backport`
- Base／PR Base: `v1/early-release`
- Base SHA: `fcaf0b9bb320aa479738e9be6d7b8465114f6226`
- Final Head／Squash Commit: PR Closeout時に確定する。
- Task Policy:
  `/etc/ideal-sol/github-app/task-policies/SEC-006.json`
- Task Policy SHA-256:
  `1ccfda1fbb332732e582fa3af9daf4e972fdd70f6bc84fe401e3eafd4af72cff`
- Repository外Evidence: `/var/lib/oripa-v2-evidence/SEC-006/`

## 目的／実装内容

SEC-005で確定・検証済みのV1 Frontend依存修正を、現在のProduction
Application Sourceと一致する`v1/early-release`へBackportした。

- `frontend/package.json`
- `frontend/pnpm-lock.yaml`

上記2 Fileは`main`のSEC-005 Merge済み正本とByte単位で一致する。
Backport Patch SHA-256は
`ed2850446726c63cdec63a6aa177e8487cba61cd4574530a0652fc24626a584b`。
Lockfileは手編集せず、SEC-005でpnpm `10.12.1`により生成・検証された正本を使用した。

## Dependency／互換性

| Package | Production現行 | Backport後 |
| --- | --- | --- |
| `next` | `16.2.9` | `16.2.11` |
| `sharp` | `0.34.5` | `0.35.3` |
| `js-yaml` | `4.2.0` | `4.3.0` |

- Node `22.22.3`、pnpm `10.12.1`でFrozen InstallとBuildが成功した。
- Nextの実解決Contextからsharp `0.35.3`／libvips `8.18.3`を確認した。
- Next Image Optimizerは既存`coin.png`を32px PNGへ変換し、HTTP `200`、
  1,572 byteを返した。
- SEC-005で確定した限定Overrideをそのまま維持し、追加Dependency Upgradeは
  行っていない。

## Application／Production非変更

- Active Production Frontend Source Commitは
  `0c5262d42babc1bf3a63bd991ab07afb014a03c2`。
- `v1/early-release` BaseとのFrontend Application Source差分は、Manifest／
  Lockfileを除いて0件。
- V1 Application Source、Backend、Migration、V2 Application／DB／Draw、
  CI Baseline、Nginx、TLS、Domainを変更していない。
- Production Container、DB、Redis、StorageへWrite操作を行っていない。
- 本TaskではImmutable Artifactを作成せず、Production Deploymentも実行していない。
- Active Production RuntimeはNext `16.2.9`、sharp `0.34.5`、
  js-yaml `4.2.0`のままである。

## Test／Audit

| 検証 | 結果 | 所要／補足 |
| --- | --- | --- |
| Clean Frozen Install | PASS | 338 Package、4.69秒、Peak RSS 205,484KB |
| Frozen Lockfile再確認 | PASS | Offline、差分なし |
| pnpm Audit | PASS | Critical／High／Moderate／Lowすべて0 |
| Typecheck | PASS | 14.98秒、Peak RSS 408,836KB |
| Lint Characterization | PASS | Active Productionと同じ8 Error／1 Warning、新規0 |
| Production Build | PASS | Next 16.2.11、24 Route、31.20秒 |
| Runtime Health | PASS | `/api/health` HTTP 200、`app=ok` |
| Public Top Smoke | PASS | HTTP 200 |
| Next Image／sharp Smoke | PASS | HTTP 200、sharp 0.35.3 |
| Secret Candidate Scan | PASS | 2 File、Candidate 0 |
| `git diff --check` | PASS | Whitespace Error 0 |

Frontend PackageにはUnit Test Script／Test Suiteが存在しないため、Unit Testは
未実行であり、PASSとは記録しない。Application／Migration／DBを変更していないため、
Backend、Persistent／Ephemeral DB、Draw性能、Backup／Restoreは重複実行していない。

## Lint Baseline判断

`v1/early-release`にはV2 MainのCI Baselineが存在せず、Main側Baselineは古い
Legacy Sourceを対象とするため、そのまま適用できない。そこで現在稼働中のProduction
Imageから同一Application SourceのESLint JSONをRead-only取得し、Task Worktreeの
結果と比較した。

絶対Pathだけを正規化した9 FindingのFile、Rule、Severity、Line、Column、
Messageは完全一致し、正規化SHA-256は双方
`670d52a330ebaa351cc4a035bcbedeec00554480cc457fe771a04a7f12fbc5bc`。
BaselineやSecurity Gateを変更・緩和していない。

## Security／Evidence

- 新規Critical／High Advisory: 0件
- Secret／PII Candidate: 0件
- SEC-005確定Manifest SHA-256:
  `ed75219ce4e06e5241fd54c2cd07d8267f12ef4495b9ac5bd2d60ec8396d5c49`
- SEC-005確定Lockfile SHA-256:
  `c7097ac90e5ea19e03bf2af7f7945e2ecc56d66182af0bd7710621f882f0d8e1`
- Full Evidence: `/var/lib/oripa-v2-evidence/SEC-006/`
- GitHub Required CheckとFresh Self-reviewはFinal Head固定後に同Evidenceへ確定する。

## 時間を要した作業

| 作業 | 所要 | 原因／改善 |
| --- | ---: | --- |
| Lint Characterization | 約1分＋比較調整 | Production ImageとWorktreeでMessage内絶対Pathが異なった。行頭のRepository Rootだけを正規化し、Finding本文を改変せず完全一致を確認した。 |
| Production Build | 31.20秒 | Next 16.2.11のCompile／Typecheck／24 Route生成。成功Logは要約しFull LogをRepository外へ保存した。 |
| sharp Version確認 | 数分 | pnpm strict graphでRootからの直接`require`が拒否された。Dependency追加をせずNextの解決ContextとImage Optimizer実経路で確認した。 |

- Root空き9.1GB、`/tmp`空き2.9GBで安全範囲だったためDocker Cache Cleanupは
  実施しなかった。
- 最初のsharp確認はPackage export制約で失敗したが、同一Install／Buildを
  やり直さず、Nextの`createRequire` Contextで再確認した。
- 成功Logは件数、所要、PASS／FAILへ要約し、詳細はRepository外Evidenceへ保存した。

## Gate／次工程

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- DEP-001のArtifact Build／Production Deploymentは開始していない。
- SEC-006の全Check、Fresh Self-review、Squash Merge、Cleanup完了後も、
  DEP-001は新Artifact作成前で停止する。
