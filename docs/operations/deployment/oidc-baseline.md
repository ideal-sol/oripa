# OIDC Baseline

## Trust Boundary

- IssuerはGitHub Actionsの正式なOIDC Issuerだけを許可する。
- AudienceはProviderとEnvironmentごとに固定し、StagingとProductionで共有しない。
- SubjectはRepository、Environment、Refをすべて検証する。
- `ideal-sol/oripa`以外のRepository、Fork、Pull Request ContextをProduction Trustへ含めない。
- Productionは`platform-production` Environmentと正式Release Tagの組合せだけを許可する。
- Stagingは`platform-staging` Environmentと承認済みBranch／Tag Policyに限定する。

## Claim Requirements

最低限、次を検証する。

| Claim | Requirement |
| --- | --- |
| `iss` | GitHub Actions OIDC Issuerと完全一致 |
| `aud` | 対象Provider／Environmentの固定Audienceと完全一致 |
| `repository` | `ideal-sol/oripa`または対象Site Repositoryと完全一致 |
| `environment` | 対象GitHub Environmentと完全一致 |
| `ref` | EnvironmentのDeployment Policyで許可したRefと一致 |
| `event_name` | ProductionではPull Requestを拒否 |
| `sub` | RepositoryとEnvironmentを含む固定Patternと一致 |

任意Claimの存在だけで許可せず、完全一致または明示したPatternで検証する。

## Workflow Permissions

- Repository全体のDefault PermissionはRead-onlyとする。
- `id-token: write`はOIDC Tokenを要求するDeployment Jobだけに付与する。
- Fork PRとPull Request JobへDeployment Credentialを渡さない。
- OIDC Token、Access Token、Authorization Header、Claim全体をLogへ出力しない。
- 実Cloud LoginはProvider Onboarding Taskで検証し、本Baseline作成では実行しない。

## Environment Separation

- StagingとProductionでAudience、Subject、Role、Credential、Environmentを分離する。
- Production Branch直接Deploymentを禁止し、正式Release Tagだけを使用する。
- 同一Artifact DigestをBuild Once／Digest Promoteで移送する。
- OIDC Trust設定の存在をDeployment成功やProduction GOとみなさない。

## Rotation and Revocation

OIDC Trust PolicyもCredentialと同様に定期Reviewする。

1. Repository、Environment、Ref、Audienceの現行値を棚卸しする。
2. 不要なSubjectとProvider Trustを削除する。
3. Incident時はProvider側TrustまたはRole Session発行を即時停止する。
4. GitHub Environment ProtectionとDeployment Workflowを停止する。
5. Audit Logと対象Release／Deployment Manifestを保全する。
6. 原因解消後に最小Scopeで再発行し、旧Trustが無効であることを検証する。

長期Credential例外を使用する場合は、発行日、期限、Owner、Rotation、Revocation、
例外理由、廃止Taskを必須記録とする。値自体は記録しない。
