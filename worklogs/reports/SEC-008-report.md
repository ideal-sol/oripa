# SEC-008 CommonMark Advisory最小更新

## 基本情報

- Task ID: `SEC-008`
- Risk: `R4`
- Issue: `#218`
- PR: `#219`
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
- `js-yaml`: Root／Legacyとも`4.3.0`から最小修正版`4.3.1`。
- Composerの`--with-dependencies --minimal-changes`によるLockfile解決で、Package version差分は上記1件だけ。
- pnpm 10.12.1による両Lockfile再生成で、Package version差分は`js-yaml` 1件だけ。
- `composer.json`、Advisory Baseline、Application Sourceは変更していない。

## Task Policy追加

- 旧SHA-256: `6145f521a7558440fe913da9c354adc414445e9523769cfb19d26038b8af4470`
- pnpm Path追加後SHA-256: `102d255ba8afd8f0f1ea89adb21662fc714a71c370ad2c536f4ba6dc7c341ae8`
- `package.json`、`pnpm-lock.yaml`、`legacy/v1-frontend/package.json`、
  `legacy/v1-frontend/pnpm-lock.yaml`だけを個別追加した。
- Policy Gate exact値同期のため、`scripts/ci/policy_gate.py`と
  `tests/ci/policy/test_policy_gate.py`だけを追加し、最終SHA-256を
  `d8db95f18cadcfda6c44466d94bdcfe6699aacd7f5538cd2e0dcad033132b10b`とした。
- Gateの検査項目、比較方式、失敗条件は変更していない。

## Verification

- Composer validate: PASS。
- Clean Frozen Install: PASS（100 packages、`league/commonmark 2.9.0`）。
- Composer Audit: CommonMark 0件。既存Baseline対象のJMESPath 1件だけ。
- Security Unit: 6 tests、PASS。
- 追加対応前のLocal Security Gate: FAIL。`js-yaml 4.3.0`の新規High Advisory
  `GHSA-5p4m-2wfm-xmqj`（影響`>=4.0.0 <4.3.1`、修正版`>=4.3.1`）を
  Root／Legacy pnpm Auditで検出した。
- Root／Legacy Frozen Install: PASS。
- Root／Legacy pnpm Audit: 0件。
- Security Gate: PASS（pnpm 0件、Legacy pnpm 0件、CommonMark 0件、
  Composerは既存Baseline対象1件、Secret Candidate 0件）。
- Policy Unit: 110 tests、PASS。Policy Gate／Quality Gate: PASS。
- GitHub Required ChecksはFinal Headで確認する。
- `git diff --check`: PASS。
- GitHub Required Checks: Draft PRのFinal Headで確認する。
- 全Suite、Application E2E、Build、Migration、Preview／Production変更は対象外として実行しない。

## 残課題

- Composer Auditに残る`mtdowling/jmespath.php`の1件は既存Baseline対象であり、SEC-008の新規Findingではない。
- `js-yaml`は追加の明示承認によりSEC-008 Scopeへ含めた。Baseline追加、Advisory無視、Gate弱体化は行っていない。
- Gate G4／G5は`NOT COMPLETE`のまま変更しない。
