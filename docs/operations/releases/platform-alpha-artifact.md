# Initial Platform Alpha Artifact

## Purpose

`2.0.0-alpha.1`を、Source、Contract、Package、Image、Migration、SBOM、
Provenance、Checksumへ一意に結び付ける。

## Release Identity

- Version: `2.0.0-alpha.1`
- Compatibility Family: `2`
- Channel: `alpha`
- Tag: `platform-v2.0.0-alpha.1`
- Production／Commercial利用: 禁止
- Stable Data保持保証: なし

## Build Once

1. PR Final HeadでLocal ValidationとArtifact再現性を検証する。
2. Required CheckとFresh Self-review後にSquash Mergeする。
3. Squash CommitのTreeがReviewed Headと一致することを確認する。
4. Squash CommitをSourceとしてArtifactを2回生成し、全FileのSHA-256一致を確認する。
5. Protected TagをSquash Commitへ新規作成する。
6. GitHub Pre-releaseを作成し、Assetを一度だけ添付する。
7. Release Asset、Manifest、Image Digest、Provenance、ChecksumをReadbackする。

Tag、Release、Assetは移動、削除、差替え、同一Versionでの再利用を行わない。

## Artifact Set

- API／Admin Docker Image ArchiveとOCI Image Config Digest
- Public／Admin／Webhook OpenAPI Bundle
- Storefront Client／Site Schema／Storefront Testkit Tarball
- V2 Migration Archive
- Release Manifest／Compatibility Matrix
- Changelog／Known Issues
- Test／Migration／Security Summary
- CycloneDX SBOM
- in-toto SLSA Provenance Statement
- `SHA256SUMS`

## Attestation Boundary

Provenance Statementは全AssetのSHA-256と固定Sourceを機械可読に結び付ける。
外部署名Identityを使用したCryptographic Signatureは初回Alphaでは`NOT_STARTED`であり、
署名済みAttestationとは表現しない。Protected Tag、GitHub App操作、Release Asset
Readback、Checksumを本Alphaの改ざん検出境界とする。

## Stable-only Gates

以下はAlphaで自動的にPASS扱いしない。

- N／N-1 Compatibility: `NOT_STARTED`
- Site Staging Deployment: `NOT_STARTED`
- Production Rollback Rehearsal: `NOT_STARTED`
- Human Commercial Production GO: `NOT_STARTED`
- Legal／Accounting／Provider Decision: `NOT_STARTED`

## Rollback

AlphaはProductionへDeployしない。Artifact不一致、Critical／High Finding、
Migration不整合、SEV-0／SEV-1を検出した場合はRelease作成を停止し、同じTagやVersionを
再利用しない。修正は新しいPre-release Versionで行う。
