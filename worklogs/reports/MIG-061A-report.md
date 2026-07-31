# MIG-061A V2 Admin／API Preview Cutover Report

## Task

- Task ID: `MIG-061A`
- Risk: `R4`
- Base: `main@de941ddcedda58adcf9cc54efa7c271c4ee7b866`
- Branch: `chore/MIG-061A-v2-admin-preview-cutover`
- Issue: `#163`
- PR: `#164`
- Evidence: `/var/lib/oripa-v2-evidence/MIG-061A/`

## Scope／Non-change

- `admin.luxe-pack.biz`のAdmin画面とSame-origin
  `/admin/api/v2`だけをV2 Admin／APIへ切り替えた。
- `luxe-pack.biz`、V1 Public Frontend／Backend、V1 DB／Redis／Storage、
  他Nginx vhost、TLS、DNS、Firewallは変更していない。
- `ad.luxe-pack.biz`、`test.luxe-pack.biz`、Storefront、Production Paymentは
  使用していない。
- Application機能、MFA／CSRF／Origin等の既存Security境界、Migration、
  Admin UIは変更していない。

## Artifact／Runtime

- Compose Project: `mig061a-v2-preview`
- Internal Network: `mig061a-v2-preview_v2_private`
- Network Subnet: `192.168.61.0/24`
- Admin: `192.168.61.11:3000`
- API: `192.168.61.10:8000`
- PostgreSQL: `192.168.61.20:5432`
- Redis: `192.168.61.21:6379`
- Admin Image:
  `sha256:82984e4e35153b860282bb0710bca050612275ba231ff7388db6d44ef8f586f2`
- API Image:
  `sha256:16d73513f966b3b4c0faad1cb8b237d2a50354b4b5aaf4f38b5a982943f6051b`
- 全Preview Containerは`unless-stopped`、専用Network／Volumeで稼働を維持する。
- Docker HostのInternal NetworkではLoopback Port Publishが成立しなかったため、
  MIG-061A固有の固定IPをRepository外の
  `docker-compose.preview-override.yml`へ分離した。汎用Composeへ固定Subnetを
  埋め込んでいない。

## V2 Database

- Database: `oripa_v2_mig061a`
- Marker: `mig061a`
- Purpose: `v2-persistent`
- Schema: `public`
- DB Timezone: `UTC`
- Migration Root: `apps/api/database/migrations-v2`
- Migration Count: `29`
- DB Target Safety GuardはMigration前後とも`PASS`。
- V1 DBとはDatabase、Marker、Migration集合、Network、Credentialを分離し、
  V1 DBへ接続、Migration、Read／Writeを行っていない。
- Production DataはImportせず、Migration DefaultとSynthetic Preview Ownerだけを
  作成した。

## Admin Initialization

- 既存Console InvitationとAdmin APIだけを使用し、Synthetic Ownerを初期化した。
- Ownerの既存MFA Policyに従い、TOTP 1件とPlaywright Virtual Authenticatorによる
  WebAuthn 1件を登録した。Credential値はroot-only Evidenceに保存し、
  Repository、Report、Logへ出力していない。
- 初回招待UIはMFA未登録状態からEnrollment Routeへ遷移できず、APIによる初期登録が
  必要だった。既存Security境界は緩和せず、UI課題として後続候補へ残す。

## Nginx Cutover

- 変更対象は`/etc/nginx/conf.d/admin.luxe-pack.biz.conf`だけ。
- Checksum Before:
  `4276714d4dc80e383518ba64bc78ff95e211454f4290c9a1294b6202c2a18411`
- Checksum After:
  `9832e492f8995db08a45d72f22566d09111d44539524b6509a79b986909f7347`
- Admin／Static upstreamをV2 Adminへ、`/admin/api/` upstreamをV2 APIへ変更した。
- 既存TLS、他Location、他vhostは維持した。変更前後の`nginx -t`は`PASS`。
- V1 Admin Container／Image／設定Evidenceは削除せず保持した。Rollbackは不要だった。

## Verification

- V2 API Container Health: `PASS`
- V2 Admin Container Health: `PASS`
- `https://admin.luxe-pack.biz/login`: HTTP `200`
- `https://admin.luxe-pack.biz/api/health`: HTTP `200`
- Same-origin Admin Session API: HTTP `200`
- Admin Static Asset: HTTP `200`
- Browser Login／Dashboard／Gacha／Prize／QA: 全Route HTTP `200`
- Browser Console Critical Error: `0`
- Browser Page Error: `0`
- HTTP `500`／`502`／`504`: `0`
- `https://luxe-pack.biz/`: HTTP `200`
- V1 Frontend Direct Health: HTTP `200`
- V1 Backend Direct Health: HTTP `200`
- Auditは初期化／認証EventをAppend-onlyで記録し、Outbox不要操作のため
  Outboxは`0`件である。
- Admin Contract生成差分、Typecheck、Lint、Production Buildは固定
  Node `22.22.3`／pnpm `10.12.1`で`PASS`。
- Policy Gate、Quality Gate、`git diff --check`は`PASS`。
- Dependency／Application差分がないためSEC-005の確定Audit Evidenceを再利用し、
  Local Security Gateは`PASS`、Secret／PII Candidateは`0`件。

## Security／Evidence Handling

- Task Policy SHA-256:
  `f60ff33f9c109b99231e25e68e6865534ca53998bcce70c362c42d5ffe30b206`
- DiagnosticでCompose環境値が内部出力へ含まれた時点で、実Data投入前に
  Task専用Container／Volumeを破棄し、全Task Credentialをローテーションして
  DBを再作成した。旧値はRuntime、Repository、提出Reportに残していない。
- Secret、Cookie、Password、TOTP Secret、WebAuthn Credential、DB／Redis
  Credentialはroot-only Evidenceだけに保持する。
- Fresh Self-reviewの最終結果はFinal Headで確定する。

## 実画面で確認した課題

- 初回招待OwnerはMFA Methodが空のまま`/auth/mfa`へ遷移し、画面から
  TOTP／WebAuthn Enrollmentを開始できない。既存APIでは初期化可能だが、
  Browserだけでの初回Owner Enrollment導線が不足している。
- Catalog／Prize／QAはTest Data未投入のためEmpty Stateを表示する。
  架空Dataは追加していない。
- 本TaskではApplication Source／UI修正を禁止しているため、いずれも修正していない。

## 時間を要した作業

- Admin／API Image Buildは約`100.2秒`を要した。
- Internal NetworkとHost Loopback Publishの組合せを検証し、Edge Network追加時の
  OCI Route競合を確認した。Host Toolchainは更新せず、Internal専用Subnetと
  Repository外Overrideへ整理して再試行を短縮した。
- 初回Owner MFAはTOTP Replay防止とOwnerのWebAuthn必須Policyを正本どおり確認した。
  Task専用DBをGuard付きで再初期化し、Virtual Authenticatorで完了した。
- Browser Runnerは既存Playwright Imageを使用し、Host Node／Docker／Buildxを
  更新していない。

## Final／Gate

- Final Head、Squash Commit、GitHub Check、Fresh Self-review、Cleanup結果は
  PR Closeoutで確定する。
- Preview用V2 Admin／API／PostgreSQL／Redis／Network／Volumeは稼働維持する。
- Gate G4: `NOT COMPLETE`
- Gate G5: `NOT COMPLETE`
- MIG-061B以降の機能実装やUI修正は開始していない。
