# SEC-009 Report

## Issue／PR／Commit

- Issue: #229
- PR: #230
- Base: `0f5687dce15a197db429c22e8927343caf04d3ce`
- Dependency／Baseline Head: `ce08cf7c820df85d5670ecf47ba5f1df5d3d1746`
- Final Head／Squash Commit: Required Checks成功後のCloseout時に確定
- Task Policy SHA-256: `b60ce3a2b1e3bdecf490790f5226431a71720346185242670d4c1442e0d7c7b5`
- Policy再発行: 旧SHA-256 `936d471dc381f786ceba370e791275729d123f7379985ec324dfb82ce42b38d4`から、Baseline Unit、Composer parser、Root Manifest／Lock、exact override Policy検査の個別Pathだけを追加許可した。

## Advisory／対応

- Package: `mtdowling/jmespath.php`
- Advisory: `PKSA-mnyp-475s-ywph`／`GHSA-pcw8-m77r-2528`／`CVE-2026-54133`
- Severity: GitHub Security Advisory `Critical 9.8`。Composer Advisoryのseverity fieldはnullだったため、旧Baselineでは`unknown`として記録されていた。
- Affected: `<2.9.1`。旧Lock `2.8.0`から最小修正版`2.9.1`へ限定更新した。親`aws/aws-sdk-php 3.384.6`の`^2.8.0`制約内で、他Packageは更新していない。
- 期限切れ原因は、SEC-007で残した単一JMESPath Findingの短期期限`2026-08-07`到来後も`2.8.0`がLockされていたことである。Advisoryは2026-08-10時点でも有効だった。
- 追加Finding: `nanoid`／`GHSA-2v37-7h3g-55p8`（High、Affected `<3.3.17`）。`3.3.16`から同一3.x系列の最小修正版`3.3.17`へ限定更新した。

## Baseline／Verification

- Fresh Composer AuditでFinding 0を確認後、解消済みJMESPath EntryをBaselineから削除した。脆弱性のBaseline追加・延長はない。
- 空Baseline管理情報はFresh Audit日を正本として`SEC-009`へ更新し、Review期限を`2026-08-17`とした。新規Findingは専用Security Taskなしに追加・無視できない。
- Composer 2.9.8のclean結果`advisories: []`だけを正常化し、key欠落、非空配列、型不正はFail Closedとした。非空Advisory objectの抽出条件は変更していない。
- Root `nanoid`は`apps/admin > vitest > vite > postcss 8.5.23 > nanoid 3.3.16`の経路だった。既存Root exact override規約で最小修正版`3.3.17`へ固定し、Manifest追加はoverride 1件だけ、Lock差分は同Packageの解決だけに限定した。
- PHP 8.4 Composer Frozen Install、Root pnpm Frozen Install、`composer validate --strict --no-check-publish`、Composer／Root pnpm／Legacy pnpm Audit 0件、Security Unit 10件、Local Security GateはPASSした。
- Policy Unit 112件、PR event Policy Gate、`git diff --check`はPASSした。Required Checksは最終Headで記録する。
- Application Source、Runtime、DB／Migration、Nginx、V1、Storefront Repository、MIG-062B PRは変更していない。

## 残課題

- 新規Dependency FindingはBaselineへ追加せず、期限内にFresh Auditで再確認する。
