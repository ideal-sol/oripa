# OPS-004 Non-Production Preview Image Build Pipeline

## Task

- Issue: #239
- PR: Closeout時に確定
- Risk: R4
- Base: `f66209549dfc9c8fae4acaa51645710040694d1c`
- Final Head／Squash Commit: Closeout時に確定
- Runner／Architecture: `ubuntu-24.04-arm`／`linux/arm64`

## Pipeline

- `workflow_dispatch`でTask ID、内部PR番号、Exact Head SHAを受け取り、PRのOpen状態、`main` Base、同一Repository、Task Branch／Title、Head一致をBuild前に検証する。
- API／Adminをnative ARM64でBuildし、OCI revisionへExact Head SHAを設定する。`docker save`後にzstd圧縮し、Manifestと`SHA256SUMS`を同梱する。
- GitHub Artifactは1日保持し、GitHub標準のpinned `checkout`／`upload-artifact`だけを使用する。新Secret、GHCR、Cloud Resourceは追加しない。

## Host Boundary

- GitHub App read wrapperへArtifact list／downloadを追加した。Task PolicyとPRを再検証し、外側Artifact digest、ZIP member、内側checksum、Image ID、ARM64、OCI revisionをFail Closedで照合する。
- Host helperは検証後の`docker image load`だけを許可し、`docker build`、Image削除、Container更新を実装しない。Deploy Runbookは`--no-build --no-deps`を必須とする。
- 現行Preview Hostは`x86_64`のためARM64 loadを実行前に拒否する。OPS-004ではBuild／Artifact生成／Runtime Deployを実行していない。

## Verification

- Focused Unit 11件、Policy Unit 116件、Local Policy／Quality Gate、Workflow YAML Parse、`git diff --check`がPASSした。
- Artifact外側／内側checksum改ざん、PR Head／外部PR、Workflow／Required Checks不一致、Host Architecture不一致の拒否を対象Testで確認した。
- 初回GitHub `policy-gate`はcheckout時のRunner CA検証失敗でSource取得前に停止した。実装Failureではなく、同一Head再実行では旧Failureがcheck履歴に残るため、Report更新後のFinal HeadでRequired Checksを再実行する。

## MIG-062F

- PR #238／Head `8daba6e6ce547e81c90e767e8fcdfdb2b38b0e2b`は非変更。
- ARM64 Preview Hostが承認・用意された後、Exact HeadでWorkflow dispatch、wrapper download、verified load、repository外overrideによる`--no-build --no-deps`更新の順で再開できる。現行x86 Hostのままでは再開不可。
