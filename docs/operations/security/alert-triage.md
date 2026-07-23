# Alert Triage

## Required Record

AlertをBaseline化、Dismiss、Closeする場合は次を必須とする。

- AlertまたはAdvisory ID
- Package／Versionまたは検出箇所
- Severity
- 影響とExploitabilityの根拠
- Owner
- 期限
- 修正Task
- False Positiveの場合の再現可能なEvidence

検出Secret、Credential値、実PII、Alert本文の秘密部分は記録しない。

## Decision

- `OPEN`: 未評価または修正待ち
- `ACCEPTED_UNTIL`: 期限付き完全Fingerprint Baseline
- `FALSE_POSITIVE`: Evidence付きで影響なし
- `FIXED`: 修正とRegression Testが確認済み

無期限Baseline、Blanket Ignore、件数だけのBaselineを禁止する。新規Finding、Severity悪化、
Fingerprint変更、期限切れはGate Failureとする。

## Automation Boundary

Codexは検出、重複照合、Fingerprint比較、期限判定、修正PRを実行できる。
CodexはTask Scope外のAlertを無条件でDismissせず、法務、Provider、顧客通知の最終判断を
行わない。

## Current Baselines

既存CI Baselineは各Baseline FileのOwner、期限、修正Taskに従う。本Taskでは既存Alertを
Dismiss／Closeせず、未解決のまま維持する。
