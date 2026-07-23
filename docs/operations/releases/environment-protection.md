# Environment Protection

## Scope

Platform RepositoryのGitHub Environmentを次の2つに限定して管理する。

| Environment | Purpose | Deployment source | Approval |
| --- | --- | --- | --- |
| `platform-staging` | 非Production検証 | `main`, `release/*`, Alpha／Beta Tag | Codexによる自律実行可 |
| `platform-production` | Production配布 | 正式な`platform-v*` Tagのみ | 人間OwnerのRequired Reviewer |

## `platform-staging`

- Deployment Branch PolicyはProtected Branchへの暗黙依存ではなくCustom Policyで限定する。
- `main`、`release/*`、`platform-v*-alpha*`、`platform-v*-beta*`だけを許可する。
- Production Secretを配置しない。
- 使用するCredentialは非Productionの検証用途に限定する。
- Environment URLは実在し、検証可能になった時点でのみ設定する。
- Environmentの作成だけではStaging Runtimeを稼働済みと扱わない。

## `platform-production`

- Branchからの直接Deploymentを禁止し、正式な`platform-v*` Tagだけを許可する。
- 人間OwnerをRequired Reviewerとする。
- Reviewerの自己承認防止を有効にする。
- CodexのSelf-review、PR Merge、Release Gate判定だけではProduction Deploymentを開始しない。
- Production Secretの名前や値を本Taskでは取得、作成、更新しない。
- Wait Timerは現時点で`0`とする。時間待機を追加する決定が確定した場合に別Taskで変更する。

## Readback and Audit

設定変更後はGitHub APIから次だけを再読取する。

- Environment名
- Wait Timer
- Required Reviewerの公開Account名
- Deployment Branch／Tag Policy
- Reviewer自己承認防止

Environment Secret、Credential、Token、Secret値は読取対象にしない。
想定外のEnvironmentやBranch Policyを自動削除しない。

## Production Human Gate

人間は次を確認してProduction GOを明示する。

- Release ManifestとDeployment Manifest
- Artifact DigestとSBOM参照
- Migration RevisionとRollback可否
- Provider、法務、会計、商用運用上の判断
- Incident対応者と監視体制

この判断がない状態をProduction承認済みと記録しない。
