# MIG-062A User Prize Presentation／Allowed Actions Public Contract

## Task

- Issue: #224
- PR: #225
- Base: `2e171ff474b6b9103279c1041a31e6b1a87f994d`
- Application／Artifact Source Commit: `1c4b54a893c7c047f247507f970459534907d2b6`
- Final Head／Squash Commit: Closeout時に確定
- Task Policy SHA-256: `4e429a23f67cce3b3206ee90e38a13585e4d30ef7e50f04a581d194fd408e79d`
- Risk／Verification: R3／`TARGETED_PUBLIC_CONTRACT`

## Endpoint／Contract

- 既存の`GET /api/v2/me/prizes`と`GET /api/v2/me/prizes/{prize_id}`を維持し、`presentation`と`allowed_actions`をadditiveに追加した。Client Methodは既存の`listPrizes`／`getPrize`を継続利用する。
- `presentation`はUser Prize Public IDとは別にPrize Public ID、景品名、nullable画像Asset、型付きRank、status、交換Point、取得日時、保管期限をPublic OpenAPIから参照可能にする。後方互換のopen objectはdeprecatedとし、新規Storefront実装では解釈しない。
- `allowed_actions`は`shipping`、`point_exchange`、`selection`ごとに`allowed`とnullableなMachine-readable理由を返す。理由は`payment_hold`、`status_not_actionable`、`storage_expired`、`exchange_points_unavailable`に限定した。
- 現行Domainでは保管中かつ期限内でPayment Holdがない景品はShipping可能で、交換Pointが正の場合はPoint交換も可能である。したがって両方可能、Shippingのみ可能、両方不可は存在するが、Point交換のみ可能な状態は存在しない。`selection`はShippingまたはPoint交換を選択できる集約状態である。
- 判定は既存Shipping／Point Exchange Mutationと同じService関数を使用する。Mutation側のTransaction、Lock、Idempotency、再検証、在庫／Point不変条件は変更していない。

## Pagination／Error／Cache

- 既存のStable Sort／Opaque Cursorを維持した。Active Payment Holdは一覧Queryへ集約JOINし、景品ごとの追加Queryを発生させない。
- User固有の一覧／詳細は`Cache-Control: private, no-store`と`Vary: Cookie`を返す。Public CacheへUser固有状態を混入させない。
- 既存Problem Details、User Realm、401、404、Validation、Rate Limit、Forbidden／Conflict境界を維持する。内部DB ID、内部例外文言、Credential、Admin専用Fieldは公開しない。

## Verification／Artifact

- Backend対象12 tests／156 assertions、Public OpenAPI 48 operations、Client 18 tests、Site Schema 10 tests、Testkit 24 testsがPASSした。Cursor、Presentation、Shippingのみ、両方可能、両方不可、処理中／完了／既存expired、期限境界、Payment Hold、交換Point 0、Mutation再検証、Cache、非認証／Not Foundを確認した。
- 1000件一覧を10 Pageで測定し、Query count 10、p50 28.507 ms、p95 48.134 msでN+1はない。Frozen Install、Typecheck／Lint／Build、OpenAPI生成同期、Policy Unit 112件、Policy／Quality／Security／Release Gate、Secret Scan、`git diff --check`もPASSした。
- Artifact Versionは`2.0.0-alpha.3`。既存`2.0.0-alpha.1`／`.2`を上書きしていない。配置先は`/var/lib/oripa-v2-evidence/MIG-062A/artifacts/`で、Source Commitは`1c4b54a893c7c047f247507f970459534907d2b6`である。
- Manifest: `artifact-manifest.json`／`0b1435a7517e2e833b14773b11f766c3f1c914d7bf9652250171089198c428b7`。
- Client: `oripa-storefront-client-2.0.0-alpha.3.tgz`／`181eeb755da4d923ce03a80e2846cc5d4bd4d772fe5273eadf6e735becad32ce`。
- Testkit: `oripa-storefront-testkit-2.0.0-alpha.3.tgz`／`43a2ab387f20f01e5406ee7e5e3267e8acd64a4faeed59eb9d43045179fd33cf`。
- Site Schema: `oripa-site-schema-2.0.0-alpha.3.tgz`／`d32394cc332b1be68e3f205d279090edf06d86b6a7097accd3e94830a8c3d71b`。
- Public OpenAPI: `public.openapi.json`／`2e27e39b19bb7998f49dda9d60f7519297fae6bd27e8286048a4801183ed9794`。`SHA256SUMS` 4件、Manifest内部整合性、Workspace外Clean Install／ESM Import、Package内Workspace／`file:`／Repository Path非混入がPASSした。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更しない。
- SITE-007は`2.0.0-alpha.3`の3 PackageとPublic OpenAPIを固定導入し、FrontendでAction判定を再実装しない条件でCloseout後に再開可能である。
- 残課題: 未確定の失効条件は追加していない。将来DomainがPoint交換のみ可能な状態を導入する場合は同じBackend判定へ追加する。
- 所要時間: 約2時間。既存Domain Characterization、Mutation判定共通化、Contract／Package同期、Artifact外部導入検証に時間を要した。
