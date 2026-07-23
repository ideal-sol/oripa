# Site Credential Boundary

## Isolation Rule

一つの顧客Siteごとに次を独立させる。

- 一つのRepository
- 一つのCodex Environment
- 一つのGitHub Environment
- 一つのDeploy Identity
- 一つのProvider Account／Project境界
- 独立したSecret、Storage、Backup、Monitoring

Platform、Luxe Pack、Customer Site、別Customer SiteのCredentialを共有しない。

## Access Matrix

| Identity | Platform | Assigned Site | Other Site | Production |
| --- | --- | --- | --- | --- |
| Platform Codex | Platform管理 | Platform Taskで必要なContractのみ | Writeなし | 商用GO不可 |
| Site Codex | Writeなし | Assigned Repositoryのみ | Read／Writeなし | Site Gateと人間GOが必要 |
| Deploy Identity | 対象Artifact読取 | 対象Environmentのみ | Accessなし | Environment Protectionに従う |

SiteからPlatform変更が必要な場合はPlatform Change Requestを作成し、Platform
CredentialをSite Codexへ渡さない。

## Credential Rules

- GitHub App Installationは`Only selected repositories`を使用する。
- SubjectとAudienceを対象Site／Environmentへ限定する。
- Customer間でSecret名、値、Deploy Key、Provider Roleを再利用しない。
- Production CredentialをStaging、PR、Fork、Local Developmentへ渡さない。
- Secret値をIssue、PR、Worklog、Artifact、Logへ記録しない。
- ProviderがOIDC非対応の場合のCredentialは対象Siteだけに限定し、期限とRotationを持つ。

## Incident

侵害が疑われるSiteのIdentityだけを即時無効化する。他SiteやPlatformのCredentialを
一括共有していないことを前提とし、影響範囲をRepository、Environment、Provider
Account、Artifact Digest単位で特定する。
