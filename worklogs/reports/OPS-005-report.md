# OPS-005 Preview Image Build Architecture Fix

## Task

- Issue: #241
- PR: Closeout時に確定
- Risk: R4
- Base: `0b0915bbaf9cc4420acc5b66bd24c83e65a6dfb4`
- Final Head／Squash Commit: Closeout時に確定

## Architecture

- Preview machine: `x86_64` (`amd64`)
- Preview Docker daemon: `x86_64` (`amd64`)
- GitHub-hosted runner: `ubuntu-24.04` x64
- Image platform: `linux/amd64`
- `scripts/ops/preview_image_artifact.py`の`TARGET_ARCHITECTURE`／`TARGET_PLATFORM`をCanonical値とし、CI runner検証、Build platform、Artifact検証、Host load guardで共有する。

## Preserved Boundaries

- OPS-004のexact Task／internal PR／head SHA／Required Checks検証、OCI revision、Image ID、Manifest、`SHA256SUMS`、外側Artifact digest、1日retentionを維持する。
- Host helperは検証済みImageの`docker image load`だけを許可し、BuildとDeployを行わない。
- 新Secret、GHCR、Cloud Resourceは追加しない。
- MIG-062F PR #238／Head `8daba6e6ce547e81c90e767e8fcdfdb2b38b0e2b`は変更しない。

## Verification

- Focused workflow／artifact／wrapper／policy tests、Policy／Quality Gate、`git diff --check`を実行する。
- GitHub Required ChecksとFinal HeadのFresh self-review後にCloseoutする。
- OPS-005ではImage Build／Artifact生成／Runtime Deployを実行しない。
