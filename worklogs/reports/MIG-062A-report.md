# MIG-062A User Prize Presentation／Allowed Actions Public Contract

## Task

- Issue: #224
- PR: #225
- Base: `2e171ff474b6b9103279c1041a31e6b1a87f994d`
- Application／Artifact Source Commit: Artifact作成時に確定
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
- 初回`.3`候補は既存`UserPrize`へrequired fieldを追加してOpenAPI Breaking Checkに失敗したため配布対象外とした。既存Artifactを上書きせず、両Fieldをoptional additiveに補正した`.4`を別Directoryへ生成する。
- Artifact Versionは`2.0.0-alpha.4`。配置先、Source Commit、Manifestおよび4 ArtifactのFile名／SHA-256、Workspace外Clean Install結果はArtifact作成後に確定する。
- Runtime、Preview、DB、Migration、Nginx、V1、Storefront Repositoryは変更しない。
- SITE-007は最終`.4` Artifact整合性とCloseout完了後に再開可能とする。
- 残課題: 未確定の失効条件は追加していない。将来DomainがPoint交換のみ可能な状態を導入する場合は同じBackend判定へ追加する。
- 所要時間: 約2時間。既存Domain Characterization、Mutation判定共通化、Contract／Package同期、Artifact外部導入検証に時間を要した。
