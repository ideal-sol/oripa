# OPS-006 決済審査用 V2 一時切替 Report

## Task

- Task ID: `OPS-006`
- Issue: `#281`
- Risk: `R4`
- Base: `main@acb444db7d8cc61d431b7e41381d36743109f833`
- Branch: `ops/OPS-006-payment-review-v2-cutover`
- Worktree: `/var/www/oripa-worktrees/OPS-006`
- Task Policy SHA-256: `b790c3d2b5d9df72ea73f1001ee88b858d8df37c1af3369fd15cff0f511b46e2`
- Evidence: `/var/lib/oripa-v2-evidence/OPS-006/`
- Final result: `NOT READY`

## Conflict Gate

- 3 lane ledgerをread-only確認した。`plat-main`と`store`はIdle、`plat-contract`の
  `MIG-062W`はPhase B再開待ちでPreview未実施だった。
- Preview Deployment Lock、Integration Lock、Migration Allocation Lockは未取得で、
  共有Storefront／Platform Preview deployment進行中Taskはなかった。
- `OPS-006`がPreview Deployment Lockを取得し、作業中のStorefront／Platform共有
  Preview deployをFreezeした。`MIG-062W`のBranch／Worktree／Sourceは変更していない。
- Repository `main`、task base、Remote mainは開始時
  `acb444db7d8cc61d431b7e41381d36743109f833`で一致した。

## 変更前 Runtime

- Storefront serviceは`luxe-pack-storefront-preview.service`、Main PID `646901`、
  endpoint `127.0.0.1:3200`だった。current releaseは
  `ff490da5ebaaefd748bba7f320688a99c19b0ec3`だった。
- APIは`mig061a-v2-preview-api-1`、Image
  `oripa-v2-api:preview-MIG-062V-2b58e308693f`、Image ID
  `sha256:f19b4bf332f11cc8a24d9c09c7aa9b3e00019b79c0551489e301d8e974a9ef5d`、
  IP `192.168.61.10`、healthyだった。
- DBは`mig061a-v2-preview-postgres-1`、IP `192.168.61.12`、healthy、Database
  `oripa_v2_mig061a`だった。Migrationは53件、max batch 25、latest
  `2026_09_08_000053_operational_gacha_inventory`だった。
- API環境は`V2_PUBLIC_ORIGIN=https://test.luxe-pack.biz`、Admin originは
  `https://admin.luxe-pack.biz`だった。
- `luxe-pack.biz`はV1 Storefront `127.0.0.1:3130`とV1 API
  `127.0.0.1:8140`、`test.luxe-pack.biz`はV2 Storefront／APIだった。

## Backup

- root-only evidenceへ`luxe-pack.biz`／`test.luxe-pack.biz`のNginx設定、Canonical
  Preview env、Compose／override、Runtime情報、Rollback runbookを保存した。
- 初回切替直前DB dumpはPostgreSQL Custom形式、TOC 1331件、SHA-256
  `257da8c8ad0cbc5ff5b16f1aa9ac4283f9ddc89d01e07a606c36f5c6d3b698c4`だった。
- Banner URL正規化後の二回目切替直前にもDB dumpを再取得し、TOC 1331件、SHA-256
  `9588e6bc06386d2e3c645f39c5a800f5a83e55333629ceef5c9820f26375aebd`を確認した。
- Nginx事前checksumはluxe
  `e6dc6fd86cfb7cda2bb4442e50c5e6617e8359f6e92ad8b93b9de5d5e481e710`、test
  `d6aa3a45e8fe8e23d1e35bc7cafe04da63a5ccce43371ecc3ca05cf56a1d8fdf`だった。
- Canonical env事前checksumは
  `b30f55cdd5a141d6c321535b5e770810e1ce00381f84badce0f1b2e066ab8d3d`だった。
- Secret値、Cookie、Password、Token、PIIはReport／Repositoryへ記録していない。

## V2_PUBLIC_ORIGIN／API

- 必須Secretを再現可能なroot-only Canonical env
  `/var/lib/oripa-v2-evidence/MIG-061A/v2-preview.env`から安全に注入されることを確認した。
- `V2_PUBLIC_ORIGIN`の一行だけを`https://luxe-pack.biz`へ変更し、既存Compose project、
  base compose、固定IP overrideを使い`--no-build --no-deps api`でAPIだけを再作成した。
- 更新後は同じImage ID、同じIP `192.168.61.10`、同じDB、Migration 53件／batch 25、
  Docker health healthy、`/api/health` HTTP 200、Origin luxeを確認した。
- Host Build、Storefront Build、Migration、Volume／Network削除は行っていない。

## Nginx変更／Reload

- `luxe-pack.biz`固有の`server_name`、luxe証明書／鍵参照、ACME location、Certbot
  security include／DH params、HTTPからHTTPSへのredirectを維持した。
- Routingだけをexact `/api/v2`と`^~ /api/v2/`から`192.168.61.10:8000`、
  `^~ /admin/api/`からlocal Problem Details 404、`/`から`127.0.0.1:3200`へ変更した。
- `test.luxe-pack.biz.conf`の全体コピーは行わず、test vhost checksumは不変だった。
- 各切替で`nginx -t`成功後だけ`systemctl reload nginx`を実行した。restartは行わず、
  Nginx master PID `1811`は不変だった。

## Smoke結果

### 初回Harness誤判定

- 初回切替はSmoke harnessがGacha一覧の実Contract `data`を`items`と誤読して
  emptyと判定したため、規定どおり即時Rollbackした。read-only再確認でGacha 4件を
  `data`から確認し、Runtime障害ではなくHarness defectと切り分けて修正した。
- 修正後の二回目切替でAPI全項目はPASSし、以下の実Asset failureを検出した。

### PASS

- luxe Top HTTP 200、意図しないredirectなし、HTTPからluxe HTTPSへの301を確認した。
- `GET /api/v2/auth/session`は200だった。
- Canonical luxe Origin＋有効CSRFのLogin invalid payloadはDomain validation 422
  `INVALID_REQUEST`まで到達した。CSRF欠落と旧test Originは403
  `CSRF_TOKEN_MISMATCH`で拒否された。
- Catalog、Gacha一覧／詳細、Banner／Notice／Footer Page APIは200だった。
- Public `/admin/api/`は`application/problem+json`の404だった。
- HTTP 500／502／504は0、test Storefront Topは200だった。

### FAIL

- Desktop BrowserでTop、登録、Login、Gacha一覧／詳細、Notice、Footerを遷移した結果、
  Public Asset endpointで複数404とConsole Errorを再現した。Mobileは重大FAIL確定後に
  実行していない。
- Root CauseはPreview APIが`FILESYSTEM_DISK=local`でAPI storage永続mountを持たず、
  API Container再作成によりwritable layer上のAsset実体が失われたことである。
- この非永続性は既存`MIG-062G` Reportにも残課題として記録されていたが、本Taskの
  変更前Runtime backupにAsset filesystemを含めていなかった。
- DBのAsset metadataは36件残った。既存Evidence／fixtureにCanonical bytesがあり、
  DB checksumと一致する29件だけを同一storage identifierへ復元した。DB更新は0件である。
- 残る7件はCanonical source bytesがなく、checksum不一致・推測画像・Screenshot抽出での
  復元を拒否した。test側でも該当Public Assetは404のままである。

## Fail Closed／Rollback

- 重大Smoke failure検出後、luxe Nginxを事前backupへ戻し、`nginx -t`成功後にreloadした。
- Canonical envを事前backupへ戻し、同じ方法でAPIだけを再作成した。
- 現在のluxe Nginx、test Nginx、Canonical env checksumはすべて事前値と一致する。
- 現在は`luxe-pack.biz` Top 200、luxe V2 Session 404、test V2 Session 200で、
  luxeはV1、testはV2へ戻っている。
- APIは同じImage／IP／DBでhealthy、Migration状態は53件／batch 25のままである。
- 切替準備でBanner `バナー３`のlink URLを一時的に相対`/`へ公開したが、Rollback時に
  既存Admin APIで`https://test.luxe-pack.biz/`へ再公開し、Public値を切替前へ戻した。
  immutable Content Versionはforward／rollbackの2件が追加された。削除は行っていない。

## 影響／Non-change

- V1 DB、V1 Runtime、Payment、Point、Draw、Inventory、Auth policy、Platform／Storefront
  source、OpenAPI、Dependency、DNS、TLS、Firewallは変更していない。
- Migration created 0、Migration applied 0、DB data deletion 0、Payment実処理0、Draw 0、
  Production Host Build 0、Storefront Build 0である。
- test側のRouteとOriginは復元したが、API local Asset 7件の404が残る。この影響は
  `luxe-pack.biz` V1にはない。
- 調査のため停止中だった`oripa-v2-dev-api-1`を一時起動してstorageをread-only確認し、
  Assetなしを確認後すぐ停止して事前状態へ戻した。
- 初回`php artisan migrate:status --database=v2`は未定義connectionのためFAILしたが、
  Migrationは実行されなかった。正しい`pgsql`／V2 migration pathでは全53件Ranを確認した。
- `php artisan db:show --database=pgsql`はDB接続情報を取得後、Containerの`intl` extension
  不足でnon-zero終了した。Docker health、`/api/health`、direct PostgreSQL queryで同じDB接続を
  別途PASS確認した。

## Rollback手順

1. `/var/lib/oripa-v2-evidence/OPS-006/precutover*/luxe-pack.biz.conf.before`を
   `/etc/nginx/conf.d/luxe-pack.biz.conf`へ復元する。
2. `nginx -t`が成功した場合だけ`systemctl reload nginx`する。
3. `v2-preview.env.before`をCanonical envへ復元する。
4. 既存Compose project／base compose／固定IP overrideで
   `--no-build --no-deps api`を実行する。
5. API healthy、Origin test、同じImage／IP／DB／Migration、luxe V1 200、test V2 200、
   Nginx／env checksum一致を確認する。

## 残課題／Ready判定

- V2 Preview API Asset storageをObject Storageまたは永続Volumeへ移し、API Container
  replacement前後のAsset backup／restore gateを追加する必要がある。
- 未復元7件はOriginal upload sourceを人間または安全なCanonical backupから再取得し、
  DB checksum一致を確認して復元する必要がある。推測復元は不可である。
- 全36 Asset HTTP 200／正しいMIME／checksum、Desktop／Mobile全画面、broken image 0、
  Console／Network Error 0を再確認後に、別Taskで一時切替を再実施する。
- 決済審査用V2環境: **NOT READY**。

## GitHub／Closeout

- Application／runtime source変更はなく、本PRはdeployment／Worklog／Reportの記録だけである。
- Local validation、GitHub checks、fresh fixed-head self-review、squash SHA、branch／worktree
  cleanup、local／Remote main一致はexact final headでCloseout時に追記する。
