# SEC-009 Report

## Issue／PR／Commit

- Issue: #229
- PR: #230（Draft。別Security FindingとGate parser不整合の解消待ち）
- Base: `0f5687dce15a197db429c22e8927343caf04d3ce`
- Dependency／Baseline Head: `ce08cf7c820df85d5670ecf47ba5f1df5d3d1746`
- Final Head／Squash Commit: Required Checks成功後のCloseout時に確定
- Task Policy SHA-256: `60ee4c2174d2f009aa18017dd24f934e98eb03509d207b9dc6bdcbb2ba65fa64`
- Policy再発行: 旧SHA-256 `936d471dc381f786ceba370e791275729d123f7379985ec324dfb82ce42b38d4`から、解消済みBaseline EntryのUnit Assertionを更新する`tests/ci/security/test_security_gate.py`だけを追加許可した。

## Advisory／対応

- Package: `mtdowling/jmespath.php`
- Advisory: `PKSA-mnyp-475s-ywph`／`GHSA-pcw8-m77r-2528`／`CVE-2026-54133`
- Severity: GitHub Security Advisory `Critical 9.8`。Composer Advisoryのseverity fieldはnullだったため、旧Baselineでは`unknown`として記録されていた。
- Affected: `<2.9.1`。旧Lock `2.8.0`から最小修正版`2.9.1`へ限定更新した。親`aws/aws-sdk-php 3.384.6`の`^2.8.0`制約内で、他Packageは更新していない。
- 期限切れ原因は、SEC-007で残した単一JMESPath Findingの短期期限`2026-08-07`到来後も`2.8.0`がLockされていたことである。Advisoryは2026-08-10時点でも有効だった。

## Baseline／Verification

- Fresh Composer AuditでFinding 0を確認後、解消済みJMESPath EntryをBaselineから削除した。脆弱性のBaseline追加・延長はない。
- 空Baseline管理情報はFresh Audit日を正本として`SEC-009`へ更新し、Review期限を`2026-08-17`とした。新規Findingは専用Security Taskなしに追加・無視できない。
- PHP 8.4 ContainerでComposer Frozen Install PASS、`composer validate --strict --no-check-publish` PASS、Composer Audit 0件、Legacy pnpm Audit 0件、Security Unit 6件PASSを確認した。
- Root pnpm Auditは、SEC-009開始後に検出された別Finding `nanoid`／`GHSA-2v37-7h3g-55p8`（High、`<3.3.17`）1件のためFAILした。このTaskでは関係ないDependency更新もBaseline追加も禁止されているため変更していない。
- Local Security Gateは、Finding 0件時のComposer 2.9.8 JSONが`advisories: []`を返す一方、既存Gateがobjectを前提とするparser不整合で停止した。このGate実装修正もSEC-009の対象外であり、Gate弱体化や入力改変は行っていない。
- 上記2件によりRequired Security Check成功条件を満たせないため、PRはDraft、IssueはOpenのまま停止する。Policy Unit 112件、PR本文補正後のPull Request event相当Policy Gate、`git diff --check`はPASSした。
- Application Source、Runtime、DB／Migration、Nginx、V1、Storefront Repository、MIG-062B PRは変更していない。

## 残課題

- 別Security Taskで`nanoid`を最小安全Versionへ更新し、clean Composer Audit JSONを扱えるようSecurity Gate parserと対象Unitを補正する。その後、SEC-009のRequired ChecksとCloseoutを再開する。
