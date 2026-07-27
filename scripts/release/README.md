# Platform Artifact Builder

## Purpose

`platform_artifact.py`は固定Full SHAからInitial Platform Alpha Artifactを生成し、
Manifest、Image Digest、SBOM、Provenance、Checksum、再現性を検証する。

## Boundary

- Versionは`2.0.0-alpha.1`、Tagは`platform-v2.0.0-alpha.1`へ固定する。
- OutputはRepository外の空Directoryへだけ生成する。
- Root／Legacy Lockfileを更新せず、Release Build中にDependency Updateを行わない。
- V1 Source、Runtime、Production Secret、実User／実決済／実PIIを使用しない。
- AlphaはProduction／Commercial利用禁止で、Data保持を保証しない。
- ProvenanceはSourceとAsset Digestを結ぶin-toto Statementである。外部署名Identityを
  使用した署名は本Alphaでは`NOT_STARTED`とし、署名済みと表現しない。

## Commands

```bash
pnpm release:test
pnpm release:validate

python3 scripts/release/platform_artifact.py build \
  --repository . \
  --source-commit <full-sha> \
  --test-evidence /absolute/path/test-evidence.json \
  --output /absolute/new/output/path

python3 scripts/release/platform_artifact.py verify \
  --output /absolute/output/path

python3 scripts/release/platform_artifact.py compare \
  --first /absolute/first/path \
  --second /absolute/second/path
```

`test-evidence.json`には秘密値を含めず、実行済みTestの結果だけを記録する。
未実行項目を`PASS`として入力してはならない。
