# Release Process

## Principles

- `main`から直接Releaseしない。
- Build Once／Digest Promoteを維持し、Environmentごとの再Buildを行わない。
- Stable TagはStable Tag RulesetとRelease Gateを通過した場合だけ新規作成する。
- Stable Tagの移動、上書き、削除を行わない。
- Release Manifest、Deployment Manifest、SBOM参照、Migration RevisionをEvidenceとして残す。

## Release Classes

### Alpha

- Contractと主要Unit Testが実行可能である。
- 未完成機能、未解決Risk、未実行Testを明記する。
- `platform-staging`だけを対象とする。
- Production用途として配布しない。

### Beta

- ApplicableなRequired Checkがすべて成功している。
- Migration、Contract、Security、Rollbackの検証結果が存在する。
- Known FindingはOwner、期限、修正Taskを持つ。
- `platform-staging`でRelease Candidateを検証する。

### Stable

- Release Gateの必須項目がすべて成功している。
- Stable Tag Rulesetに従う新規Tagで固定する。
- Release Manifest、Deployment Manifest、SBOM、Migration Revisionが相互に一致する。
- Rollback基準と手順を検証する。
- 初回商用Production GOは人間が明示する。

## Automated Decisions

Codexは次を自動判定できる。

- Head SHA、Tag、Artifact Digestの一致
- Required Checkの成功
- Scope、Secret／PII、Manifest Schema、Self-reviewの合否
- Migration／Contract／Security Testの実行結果
- Environment PolicyとRelease Gateの機械可読条件

Codexは次を自動決定しない。

- 初回商用Production GO
- 法務、会計、返金、資金決済法上の最終判断
- 未確定Providerの選定や契約判断
- 未定義Riskの受容

## Promotion

1. PRをRequired Checkと固定Head Self-review後にSquash Mergeする。
2. Release Gateを満たすCommitからArtifactを一度だけBuildする。
3. Artifact Digest、SBOM参照、Migration RevisionをRelease Manifestへ記録する。
4. 同一Digestを`platform-staging`へPromoteして検証する。
5. Stable条件と人間のProduction GOを満たした場合だけ同一Digestを
   `platform-production`へPromoteする。
6. Deployment結果をDeployment Manifestへ記録する。

Example Manifestは構造説明用であり、実Release、実Deployment、実Credentialを示さない。
