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

Deployment Manifestは引き続きSkeletonである。Release Manifest Schema `2.0`は
API／Admin Image、3 Contract、First-party Package、
V2 Migration、Runtime、SBOM、Provenance、Known Issues、Production Gateを分離して
記録する。Exampleは構造検証用で、実Release／実Deploymentを表さない。

実Release ManifestはRepository外のArtifact Builder出力として生成し、Protected Tagと
GitHub Releaseへ固定する。Alpha ArtifactはApplication ArtifactとしてのProduction利用不可。
