# Provider Onboarding Checklist

## Decision

- [ ] Provider選定が人間により明示されている
- [ ] 法務、会計、契約、Data Residency要件が確認されている
- [ ] OIDC対応状況と正式Documentationが確認されている
- [ ] Production商用GOの承認主体が定義されている

## Identity

- [ ] Repository、Environment、Ref、Audience、Subjectを限定している
- [ ] StagingとProductionのIdentityを分離している
- [ ] Pull RequestとForkをProduction Trustから除外している
- [ ] Session Durationを必要最小限にしている
- [ ] Long-lived Access KeyをGitHub Secretへ追加していない
- [ ] OIDC非対応例外には期限、Owner、Rotation、廃止Taskがある

## Permissions

- [ ] Deploy Identityは対象Site／EnvironmentだけにAccessできる
- [ ] Runtime、DB、Storage、Backupの権限を用途別に分離している
- [ ] Production SecretをCodexが読取できない
- [ ] Deny条件と権限境界をProvider側で検証している

## Verification

- [ ] Positive Claimで短期Credentialを発行できる
- [ ] Repository違い、Environment違い、Ref違い、Audience違いを拒否する
- [ ] Pull Request／Fork Contextを拒否する
- [ ] Token、Claim全体、Authorization HeaderがLogへ出ない
- [ ] RevocationとIncident停止をRehearsalした
- [ ] Audit Log、Release Manifest、Deployment Manifestを関連付けた

## Operations

- [ ] Rotation日程とOwnerがある
- [ ] Provider障害時のRollbackと停止手順がある
- [ ] 退役時にTrust、Role、Credential、Environmentを無効化する
- [ ] 未実行項目をPASSと記録していない

本ChecklistはProvider-neutralであり、実Provider固有設定の代用品ではない。
