# MIG-061A V2 Admin／API Preview Cutover Report

## Task

- Task ID: `MIG-061A`
- Risk: `R4`
- Base: `main@de941ddcedda58adcf9cc54efa7c271c4ee7b866`
- Branch: `chore/MIG-061A-v2-admin-preview-cutover`
- Issue: `#163`
- PR: Draft作成前
- Status: 実施中

## Scope

- `admin.luxe-pack.biz`のAdmin画面とSame-origin Admin APIだけをV2へ切り替える。
- V2 Admin／API、専用PostgreSQL、専用Redis、専用Networkを分離して起動する。
- `luxe-pack.biz`、V1 Public Frontend／Backend、V1 DB／Redis／Storageは変更しない。
- `ad.luxe-pack.biz`、`test.luxe-pack.biz`、Storefront、Production Paymentは対象外。
- 追加Security機能、既存Security境界の再設計・無効化、UI機能修正は行わない。

## Preflight

- Task Policy SHA-256:
  `f60ff33f9c109b99231e25e68e6865534ca53998bcce70c362c42d5ffe30b206`
- Local `main`と`origin/main`はBase SHAで一致し、Working Treeはcleanだった。
- 現行Admin vhost checksumは
  `4276714d4dc80e383518ba64bc78ff95e211454f4290c9a1294b6202c2a18411`
  で人間指定値と一致した。
- 切替前のV1 AdminはLoopback Port `3130`のFrontendと`8140`のBackendを使用する。
- `luxe-pack.biz`、V1 Frontend、V1 BackendのHealthは切替前にHTTP 200だった。
- V2 Preview用Loopback Port `3611`／`8611`に競合はなかった。

## V2 Isolation

- Compose Project: `mig061a-v2-preview`
- Network: `mig061a-v2-preview_v2_private`
- Admin Loopback: `127.0.0.1:3611`
- API Loopback: `127.0.0.1:8611`
- Database: `oripa_v2_mig061a_preview`
- DB Marker: `mig061a`
- Migration Root: `apps/api/database/migrations-v2`
- Credential値はRepository、Log、Reportへ保存しない。

## Implementation／Verification

実測Artifact Digest、Migration数、DB Guard、Admin／API Health、Browser確認、
Nginx切替前後Checksum、Fresh Self-review、GitHub Check、Final Head、
Squash Commit、Cleanup結果を完了時に追記する。

## Gate

- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`

