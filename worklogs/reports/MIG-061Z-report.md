# MIG-061Z Report

## Issue／PR／Commit

- Issue: #222
- PR: #223
- Base: `cea1fd97ddfcc60861b1650b434f43a60f95d810`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `9caef5ce3fd263ba9b5c9cff9139881951d25b9880c39cc0b8bcb490ee40e497`

## Public Origin／Proxy

- Public Originは既存DNSでPreview用に割り当て済みの`https://test.luxe-pack.biz`とした。V2 APIの外部URLは同一Originの`https://test.luxe-pack.biz/api/v2/...`である。
- 新規vhost `/etc/nginx/conf.d/test.luxe-pack.biz.conf`の`/api/v2/`だけを既存Preview API Upstreamへ透過転送する。`/admin/api/`はNginxでHTTP 404とし、Admin APIと完全分離した。Storefront本体はSITE-004配備前のため`/`をHTTP 404のまま閉じた。
- API Containerへ`V2_PUBLIC_ORIGIN=https://test.luxe-pack.biz`を明示適用した。非本番ComposeはLocal／CI用のlocalhost既定値を持ち、Policy GateでOrigin mapping自体の欠落を拒否する。API Image DigestとRuntime Commitは変更していない。
- `test.luxe-pack.biz`専用Let’s Encrypt証明書を既存Canonical DNS-01方式で発行した。既存証明書、既存vhost、V1／V2 Admin Upstreamは変更していない。

## Cookie／CSRF／CORS

- Browserは同一Origin `/api/v2/...`へ`credentials: include`で接続する。CORS Allowlistや広いCross-Origin許可は追加しない。
- Canonical CSRF初期化は`GET /api/v2/auth/session`。`__Host-oripa_user_xsrf`はSecure、Host-only、Path `/`、SameSite=Laxを維持した。
- NginxはBackendの`Set-Cookie`、`Cache-Control`、`Vary`を上書きしない。Presentation成功Responseの`private, no-store`／`Vary: Cookie`はBackend正本のまま透過する。

## Smoke／非影響

- Auth SessionはHTTP 200、register／loginは取得済みCSRF Cookie＋Header＋正しいOriginでApplication ValidationのHTTP 422 `INVALID_REQUEST`まで到達した。Cross-Origin RequestはHTTP 403で拒否した。CredentialやDB Writeを伴う新規登録は実施していない。
- Public CatalogはHTTP 200。公開Gachaが0件のためPresentationは有効UUID形式でRFC 9457 HTTP 404 `CATALOG_NOT_FOUND`まで到達し、Route／Proxyを確認した。実在Gachaの200はSITE-004または公開Test Data準備後の確認事項である。
- Public OriginからAdmin APIはHTTP 404。`luxe-pack.biz`はHTTP 200、`ad.luxe-pack.biz/login`は既存Login Redirect、`admin.luxe-pack.biz/login`はHTTP 200を維持した。DB／Migration、Admin Container、V1、Storefront Repository、Payment Providerは非変更。
- SITE-004は同一Origin Public API接続を再開可能。認証の実ユーザーBrowser FlowはStorefront UI配備後に既存Synthetic Credentialで確認する。
- Policy Unit 112件、Policy／Quality／Security Gate、Compose config、`git diff --check`はPASS。Application Suite／BuildはTask範囲外のため実行していない。

- Gate G4／G5: `NOT COMPLETE`
