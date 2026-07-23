# Manifests

## Responsibility

Release／Deployment ManifestのSchema、非秘密Example、Compatibility境界を管理する。

## Ownership

OwnerはPlatform Codex。Root [`AGENTS.md`](../AGENTS.md)と
Release Operationsに従う。

## Planned Components

- `schemas/`: JSON Schema正本
- `examples/`: Schema検証用の非秘密Example

## Allowed Scope

Version、Package、Artifact Digest、Migration、SBOM、Approval参照のmetadata。

## Forbidden Scope

Secret、Credential、実顧客情報、Production Data、V1 CodeをCopyしない。

## Status

このSkeletonのSchemaとExampleは構造検証用で、実Release／実Deploymentを表さない。
Application ArtifactとしてのProduction利用不可。
