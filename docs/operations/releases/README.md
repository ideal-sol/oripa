# Release Operations

## Purpose

このDirectoryは、Platform RepositoryのRelease、Deployment、Environment保護、
Rollbackに関する運用基準を管理する。

## Documents

- [Environment Protection](environment-protection.md)
- [Release Process](release-process.md)
- [Rollback](rollback.md)

Manifestの非秘密Example:

- [`release-manifest.example.json`](../../../manifests/examples/release-manifest.example.json)
- [`deployment-manifest.example.json`](../../../manifests/examples/deployment-manifest.example.json)

## Responsibility Boundary

- CodexはRequired Check、Scope、Self-review、Release Gateを機械的に検証できる。
- Codexは承認済みRelease Gateを満たしたArtifactの作成とStaging Deploymentを実行できる。
- 初回商用Production GO、法務、会計、未確定Provider判断は人間だけが行う。
- Production EnvironmentのRequired Reviewerは人間Ownerとし、CodexのSelf-reviewでは
  代替しない。
- Production Secret、Credential値、実PIIはRepository、Issue、PR、Worklogへ記録しない。

## Current State

GitHub Environmentとして`platform-staging`と`platform-production`を管理する。
Environment設定の存在は、実在するStaging／Production Runtime、Credential、Deployment
成功を意味しない。Runtimeの構築とDeploymentは別Task、別Evidenceとして扱う。
