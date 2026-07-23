# Security Operations

## Purpose

GitHub Security機能、CI Security Gate、Vulnerability Reportの責任境界を管理する。

## Documents

- [`SECURITY.md`](../../../SECURITY.md)
- [Vulnerability Response](vulnerability-response.md)
- [Alert Triage](alert-triage.md)

## Continuous Controls

- `security-gate`はRepository内の高確度Secret、危険Path、Workflow Permission、
  Floating Action、Dependency Baseline、`.codex` Ruleを検証する。
- CodeQLはJavaScript／TypeScriptを解析し、SARIFをGitHub Code ScanningへUploadする。
- PHPはCodeQL対応Languageではないため、既存`security-gate`、Dependency Audit、
  Review、Testを維持し、CodeQLで検査済みとは記録しない。
- Dependency ReviewはPull RequestのDependency差分をHigh Severity以上で拒否する。
- DependabotはComposer、npm、GitHub Actionsを週次確認する。

## Repository Settings

利用可能な範囲で次を有効化し、API Readbackする。

- Dependency Graph
- Dependabot Alerts
- Dependabot Security Updates
- Secret Scanning
- Push Protection
- Code Scanning
- Private Vulnerability Reporting
- Security Policy

FeatureがGitHub Plan／Repository種別で提供されない場合は、`UNAVAILABLE`または
`UNSUPPORTED`として記録し、成功とみなさない。

Alert本文、検出Secret、Credential、実PIIを本運用記録へCopyしない。
