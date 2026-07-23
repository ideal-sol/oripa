# セキュリティポリシー

## 対応Version

| Version | 対応 |
| --- | --- |
| `main`および最新Stable Release | 対応 |
| 旧Alpha／Beta、Superseded Release | 原則対象外 |
| V1 Archive | 保全対象。修正は承認済みHotfix Taskのみ |

## 報告方法

GitHubのPrivate Vulnerability Reportingを優先してください。Public Issue、Pull Request、
Discussion、Commitへ脆弱性の詳細、Secret、Credential、Token、Private Key、実PIIを
書かないでください。

正式なSecurity専用Emailは未確定です。存在しない連絡先を使用せず、GitHub Repositoryの
Security画面からPrivate Reportを作成してください。

## 初動目標

- 受領確認: 2営業日以内
- 初期Severity評価: 3営業日以内
- SEV-0／SEV-1のContainment開始: 確認後直ちに
- 修正予定とDisclosure方針: 影響とProvider連携状況を確認後に提示

目標時間は修正完了を保証するSLAではない。

## Severity

- `SEV-0`: 進行中の重大侵害、広範なSecret／資産流出、不可逆な資金・抽選不整合
- `SEV-1`: Exploit可能なAuth Bypass、Production Credential漏えい、重大なData Integrity Risk
- `SEV-2`: 条件付きExploit、限定的な権限昇格、重要Dependency脆弱性
- `SEV-3`: 低影響、Defense-in-depth、情報不足のCandidate

## Disclosure

- 修正、Rotation、影響評価、顧客／Provider連絡の準備が整う前に詳細を公開しない。
- Reporterと公開時期を調整し、必要最小限の情報だけを開示する。
- 法務判断、規制当局報告、顧客通知は人間予約事項とする。

## Secret漏えい時

1. 対象Credentialを即時無効化またはRotationする。
2. Push、Deployment、Releaseを停止し、Audit Evidenceを保全する。
3. Repository、Environment、Site、Providerの影響範囲を特定する。
4. Git履歴書換えは独断で行わず、漏えい値を先に無効化する。
5. Incident Taskで修正、検証、再発防止を記録する。

Payment Provider関連はSecret値を共有せず、正式なProvider窓口と人間Ownerが連携する。
