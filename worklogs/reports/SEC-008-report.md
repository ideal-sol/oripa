# SEC-008 CommonMark Advisory最小更新

## 基本情報

- Task ID: `SEC-008`
- Risk: `R4`
- Issue: `#218`
- Branch: `security/SEC-008-commonmark-advisory`
- Base: `6cf5f2735e7d60915938064ba32c649a9d8b45d6`
- PR／Final Head／Squash Commit: Closeout時に確定する。

## Advisory

`league/commonmark 2.8.2`で次の新規Findingを確認した。

| Advisory ID | Severity | 影響範囲 |
| --- | --- | --- |
| `PKSA-5mzr-szzf-z6cn` | Medium | `>=2.0.0,<2.9.0` |
| `PKSA-cqd6-fg4n-nxpf` | High | `>=2.0.0,<2.9.0` |
| `PKSA-1q6p-sqkj-8mmj` | High | `>=1.5.0,<2.9.0` |
| `PKSA-mc58-w91n-f5gv` | High | `>=1.5.0,<2.9.0` |
| `PKSA-t21r-vtr5-3mdz` / `CVE-2026-71488` | High | `>=0.6.0,<2.9.0` |
| `PKSA-scnn-p8mm-jbft` / `CVE-2026-71478` | Medium | `>=1.5.0,<=2.8.3` |

全件を解消する最小Versionは`2.9.0`である。Laravel FrameworkのLockfile上の制約
`^2.8.1`内で互換性を維持できる。

## Dependency更新

- `league/commonmark`: `2.8.2`から`2.9.0`。
- Composerの`--with-dependencies --minimal-changes`によるLockfile解決で、Package version差分は上記1件だけ。
- `composer.json`、Advisory Baseline、Application Sourceは変更していない。

## Verification

- Composer validate: PASS。
- Clean Frozen Install: PASS（100 packages、`league/commonmark 2.9.0`）。
- Composer Audit: CommonMark 0件。既存Baseline対象のJMESPath 1件だけ。
- Security Unit: 6 tests、PASS。
- Local Security Gate: FAIL。Scope外の`js-yaml 4.3.0`に新規High Advisory
  `GHSA-5p4m-2wfm-xmqj`（影響`>=4.0.0 <4.3.1`、修正版`>=4.3.1`）が
  Root／Legacy pnpm Auditで同時に検出されたため。
- `git diff --check`: PASS。
- GitHub Required Checks: Draft PRのFinal Headで確認する。
- 全Suite、Application E2E、Build、Migration、Preview／Production変更は対象外として実行しない。

## 残課題

- Composer Auditに残る`mtdowling/jmespath.php`の1件は既存Baseline対象であり、SEC-008の新規Findingではない。
- `js-yaml`はSEC-008の明示Scope外である。Baseline追加、Advisory無視、Gate弱体化は行わず、別の明示承認Taskで`4.3.1`以上へ更新するまでSEC-008をMergeしない。
- Gate G4／G5は`NOT COMPLETE`のまま変更しない。
