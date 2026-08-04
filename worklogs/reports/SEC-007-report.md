# SEC-007 最小Dependency更新 提出用レポート

## 基本情報

- Task ID: `SEC-007`
- Risk: `R3`
- Verification: `FAST-TRACK-DEPENDENCY`
- Issue: `#186`
- PR: Closeout時に確定する。
- Branch: `security/SEC-007-minimal-dependency-refresh`
- Base: `b41f9e9ec460ba1c8413b2bdbc46b11ebde277c8`
- Final Head／Squash Commit: Closeout時に確定する。
- Evidence: `/var/lib/oripa-v2-evidence/SEC-007/`

## Task Policy

- 初回Policy SHA-256:
  `5308085a0ca692273d2d904d93908791364934be8e44daffaedbbccd2dd0c8a6`
- Policy GateがRootの厳密Override値を検査しているため、既存制約を維持して
  `scripts/ci/policy_gate.py`と`tests/ci/policy/test_policy_gate.py`だけを追加許可し、
  Atomic再発行した。
- 最終Policy SHA-256:
  `b7465b9e7e09abb275a63decf06e4a0957b04179e707a26236d2ff5432147c25`
- Wildcard、Directory単位、Application Source、Migration、Docker、Nginx、Productionの
  許可はない。

## Dependency更新

| Package | 更新前 | 更新後 | 範囲 |
| --- | --- | --- | --- |
| `guzzlehttp/guzzle` | 7.11.1 | 7.15.2 | Composer Patch |
| `guzzlehttp/promises` | 2.5.0 | 2.5.1 | Guzzle依存 |
| `guzzlehttp/psr7` | 2.11.0 | 2.13.0 | Guzzle依存 |
| `brace-expansion` | 5.0.8 | 5.0.9 | Root／Legacy exact override |
| `fast-uri` | 3.1.4 | 3.1.5 | Root exact override |
| `postcss` | 8.5.18 | 8.5.23 | Root／Legacy exact override |

- ComposerはPHP 8.4以上のCanonical Containerで
  `--with-all-dependencies --minimal-changes`を使用した。
- pnpm 10.12.1でRoot／Legacy Lockfileを再生成し、手編集していない。
- `fast-uri`は親`ajv 8.20.0`の許容範囲内だが、pnpmの通常Transitive Updateでは
  Lockが更新されなかったため、Rootだけに完全一致Overrideを追加した。
- Lockfile差分はComposer 24行追加／24行削除、Root pnpm 25行追加／17行削除、
  Legacy pnpm 14行追加／14行削除。不要なPackage更新はない。

## Advisory／Baseline

- Root pnpm Audit: 0件。
- Legacy pnpm Audit: 0件。
- Composer Audit: JMESPathの既存Unknown 1件だけ。Critical／Highは0件。
- Guzzle／PSR-7の解消済み9 Baseline項目を削除した。
- 残存は`mtdowling/jmespath.php 2.8.0`の
  `PKSA-mnyp-475s-ywph`だけで、新規Critical／HighをBaselineへ追加していない。
- Baseline期限は`2026-08-07`のまま維持し、機械的延長を行っていない。

## Verification

- Frozen Install: Composer、Root pnpm、Legacy pnpm PASS。
- API Health Smoke: 1 test／6 assertions、4.322秒、PASS。
- Admin Typecheck／Production Build: PASS、Next 16.2.11。
- Legacy Typecheck／Production Build: PASS、Next 16.2.11、24 Route。
- Security Unit／Baseline Unit: 6 tests、PASS。
- Local Security Gate: PASS、Composer 1、Root pnpm 0、Legacy pnpm 0、
  Secret Candidate 0。
- Policy Unit: 95 tests、PASS。Policy Gate／Quality Gate／`git diff --check`: PASS。
- GitHub Required Checks／Fresh Self-review: Final HeadでCloseout時に確定する。

## Scope／非変更

- Application Source、Migration、DB Schema、Docker、Nginx、Production、Previewは変更していない。
- 全V2 Suite、DB Guard、Draw負荷、Backup／Restore、全Admin E2E、Preview Deploymentは
  指定どおり重複実行していない。
- MIG-061I Issue `#184`、PR `#185`、Branch／Worktree／Task DB／Previewを保持し、
  本Taskから変更していない。SEC-007完了後もMIG-061I Closeoutは開始しない。
- Gate G4／G5は`NOT COMPLETE`を維持する。

## 時間を要した作業

- ComposerのHost PHP 8.3はRepository要件を満たさないため、Host Toolchainを変更せず
  PHP 8.4以上のComposer Containerで解決・検証した。
- `fast-uri`は通常のTransitive UpdateでLockが変わらず、親Graphを確認して限定Overrideを
  選択した。
- Policy Gateが旧Override値を厳密固定していたため、Gateを緩和せずPolicyとFixtureの
  exact値を更新した。
