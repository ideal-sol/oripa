# SEC-005 Legacy Dependency Security Remediation 提出用レポート

## 基本情報

- Task ID: `SEC-005`
- Risk: `R3`
- Issue: `#155`
- PR: `#156`
- Branch: `security/SEC-005-legacy-dependency-remediation`
- Base: `f4e6187f46ee7cb4d120e1a2015be8577ec5e3da`
- Final Head／Squash Commit: PR Closeout時に確定する。
- Repository外Evidence: `/var/lib/oripa-v2-evidence/SEC-005/`

## 実装内容

- Legacy Frontendだけを対象に、`next`と`eslint-config-next`を
  `16.2.9`から`16.2.11`へ更新した。
- Next 16.2.11のoptional dependency範囲`^0.34.5`では0.35系を解決できないため、
  `sharp`を0.35系最新の`0.35.3`へ完全一致Overrideした。
- `@eslint/eslintrc`は`js-yaml ^4.1.1`を許容するが、pnpm 10.12.1のtransitive
  updateが直接devDependencyまで更新するため、不要なGraph更新を避けて
  `js-yaml 4.3.0`を完全一致Overrideした。
- pnpm 10.12.1でLockfileを元Lockから再生成した。手編集は行っていない。
- Application Source、Node Runtime、Container、Nginx、Deploymentは変更していない。

## Lockfile差分／互換性

- Manifest差分はNext／ESLint ConfigのPatch更新とsharp／js-yamlの限定Overrideだけ。
- Lockfile差分はNext 16.2.11一式、sharp 0.35.3、libvips 1.3.2、
  js-yaml 4.3.0、sharp依存のsemver 7.8.5に限定した。
- `sharp 0.35.3`はNode `>=20.9.0`で、既存Node `22.22.3`と互換。
- Linux x64では`@img/sharp-linux-x64 0.35.3`と
  `@img/sharp-libvips-linux-x64 1.3.2`を解決した。
- Next Image Optimizerで既存`coin.png`を32px WebPへ変換し、HTTP 200、
  `image/webp`、950 byteを確認した。Application Source修正は不要だった。

## Advisory／Baseline再審査

- Legacy pnpm: 更新前11件、更新後0件。
- Root Workspace pnpm: 0件を維持。
- `next 16.2.9`、`sharp 0.34.5`、`js-yaml 4.2.0`に対する対象Highをすべて解消した。
- Baselineからpnpm 11件を削除し、Dependency ReviewのHigh allowlist 6件も削除した。
- Composerは従来と同じ10件で、新規Critical／Highは0件。
- 維持項目はGuzzle 7件、PSR-7 2件、JMESPath 1件。Severityは
  Medium 9件／Unknown 1件である。
- 修正版はGuzzle `7.15.1`以上、PSR-7 `2.12.3`以上、
  JMESPath `2.9.1`以上。Composer LockはTask Policy外のため本Taskでは更新しない。
- Review日を`2026-07-31`、短期期限を`2026-08-07`とした。
  期限延長にはFresh Composer Auditと別Security Taskが必要である。

## Test／Security Gate

- Clean Frozen Install: PASS、338 Package、5.56秒、Peak RSS 294,572KB。
- Dependency Audit: Legacy 0、Root 0、Composer既存10。
- Legacy Typecheck: PASS、15秒。
- Legacy Lint raw: 8 Error／1 Warning。既存の完全一致・期限付きFingerprintと一致し、
  `lint-baseline`はPASS。Application Sourceは変更していない。
- Legacy Production Build: PASS、Next 16.2.11、24 Route、33秒。
- 起動Health: PASS、`{"app":"ok"}`。
- Next Image／sharp Smoke: PASS、WebP変換、sharp 0.35.3／libvips 1.3.2。
- Legacy Unit Test: Package ManifestにTest Script／Test Suiteが存在しないため未実行。
  Unit TestをPASSとは記録しない。起動HealthとImage OptimizerはIntegration Smokeとして
  区別して記録する。
- Security Unit／Dependency Baseline Unit: PASS。
- Local Security Gate: PASS、Composer 10、pnpm 0、Workspace pnpm 0、
  Secret Candidate 0、期限`2026-08-07`。
- Policy Gate／Quality Gate／`git diff --check`: PASS。
- GitHub Required Checks、CodeQL、Dependency Review、Fresh Self-review:
  Final HeadでPR Closeout時に確定する。

## Security／非変更

- Critical／新規High Advisory: 0件。
- Secret／PII Candidate: 0件。
- V2 Application、Migration、Database、Draw、Backup／Restore、Infraは非変更。
- V1 Application Source、Backend Migration 40件、Runtime、本番DB／Redis／Storage、
  Nginx、Domain、TLS、Archive Branch、Annotated Tagは非変更。
- V1 Migration 40件の正本Checksum:
  `a35cb6b04d243673de87aa5d8d70633309213dce80bea9bb6b9416f929fa0d33`
- MIG-060O Issue `#153`／PR `#154`／Head
  `d13d4be7631efd0c84fab091f874a7fc3ef9c1a6`／Worktree／Task DBを保持した。

## Production

- 本TaskではProduction Deploymentを実施しない。
- 現在のV1 Runtimeは脆弱版を継続稼働しているため、SEC-005 Merge後に
  Production Security Deploymentが必要である。
- Production DB、Redis、Storage、Nginx、TLS、Domainは変更していない。

## 時間を要した作業

| 作業 | 所要 | 原因／改善 | 結果 |
| --- | ---: | --- | --- |
| Lockfile選択更新 | 約20秒＋試行 | transitive js-yaml更新が直接Dependencyまで拡大 | Repository外試行後、限定Overrideで405行へ縮小 |
| Legacy Lint | 約33秒／回 | 既知9 Fingerprintの全Source解析 | raw結果を再実行せずJSON結果をBaseline判定へ再利用 |
| Production Build | 約33秒 | Next 16.2.11のCompile／Typecheck／24 Route生成 | PASS |
| Quality Gate | 約28秒 | Tracked PHP 707件、JSON／YAML／Contract検査 | PASS |

- Root空き8.7GB、`/tmp`空き2.9GBで安全閾値内のためDocker Cache Cleanupは
  実施していない。
- 最初の直接sharp importはpnpm strict graphにより失敗した。Applicationへ直接依存を
  追加せず、実利用経路のNext Image Optimizer Smokeへ切り替えた。
- Health SmokeはRequest成功後、検証側が`status`を期待して失敗したが、既存Contractは
  `app=ok`だった。取得済みEvidenceを再評価し、Server再起動を重複実行しなかった。
- 成功ログは要約し、詳細LogはRepository外Evidenceへ保存した。

## Gate／Closeout

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- Issue Close、Squash Merge、Final Head／Squash Commit、Branch／Worktree Cleanupは
  全GitHub CheckとFresh Self-review成立後に確定する。
- MIG-060OのRebase／Merge／Closeoutは本Task内で実施しない。
