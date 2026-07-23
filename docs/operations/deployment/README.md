# Deployment Identity

## Purpose

Platform／Site Deploymentで長期CredentialをRepositoryへ保存せず、GitHub OIDC
またはSite別に限定した短期Credentialを使用するための基準を管理する。

## Documents

- [OIDC Baseline](oidc-baseline.md)
- [Site Credential Boundary](site-credential-boundary.md)
- [Provider Onboarding Checklist](provider-onboarding-checklist.md)

Provider-neutralな構造Example:

- [`claims.example.json`](../../../infrastructure/oidc/claims.example.json)
- [`trust-policy.example.json`](../../../infrastructure/oidc/trust-policy.example.json)

## Priority

1. GitHub OIDCで短期Credentialを都度発行する。
2. ProviderがOIDCに対応しない場合だけ、Site別の短期または厳格にRotationする
   Deployment Credentialを例外利用する。
3. Platform、Site、Customer間でCredentialを共有しない。

Cloud Providerは未確定である。Provider固有Role、Audience、API、Credential名は、
正式なProvider選定と人間Decisionの後に別Taskで定義する。
