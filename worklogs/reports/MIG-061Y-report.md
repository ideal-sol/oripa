# MIG-061Y Report

## Issue／PR／Commit

- Issue: #220
- PR: #221
- Base: `5cd779d5918fbc39e8598ccb699863200747aabe`
- Application／Artifact Source Commit: `539ba74a6f16689bd0966e86556226dbb05fed4e`
- Task Policy SHA-256: `8d6f090b6d837a90d124cc71c1394f2912d4cf95f15bced7126b30b6f8dc6091`
- Squash Commit: Closeout時にGitHubで確定

## Endpoint／判定Contract

- `GET /api/v2/gacha-presentations/{gacha_id}`を追加した。Public Detail本体とUser固有Presentation Stateを分離し、後者だけを`private, no-store`、`Vary: Cookie`とした。
- Responseは`coming_soon`／`on_sale`／`sold_out`／`ended`、`anonymous`／`authenticated`、Audience、`eligible`、Machine-readableな不適格理由、Allowed Draw Counts、JST日次上限・使用済み・残数・無制限、CTAの表示／有効状態を返す。内部DB IDは返さない。
- Anonymousは安全に`authentication_required`として表現し、User固有の使用回数や残数はPublic Cacheへ混入させない。Point不足はDetail時点で安全に再利用できるCanonical判定がないため、本Contractには含めていない。
- `first_time_users`は完了済み通常Draw 0回でQAを除外、`line_users`はLINE Identity連携済みかつ友だち確認済みを正本とする。日次境界はAsia/Tokyo 0時で、Draw Transactionと同じ`V2DrawEligibilityService`を再利用する。

## Client／Artifact

- Public OpenAPI、Generated Types、`@oripa/storefront-client`、`@oripa/site-schema`、`@oripa/storefront-testkit`を同期し、Clientへ`getGachaPresentation`を追加した。変更はContract family 2内のnon-breaking additiveである。
- Artifact Versionは`2.0.0-alpha.2`。`2.0.0-alpha.1`は上書きしていない。配置先は`/var/lib/oripa-v2-evidence/MIG-061Y/artifacts/`、Manifest SHA-256は`e25a9f2a4a30fafcaeceb35b34c505c49448936eaa6c7372f0369a7576127605`。
- Client: `df4f2c59f90aefe63feaa07748498977c649a2d25cc51d93026e69a84af7f474`、Testkit: `f09a35946a85b1ed252189b91df297b5072488854cb10e8e52fce8276b4f6d5d`、Site Schema: `adbd0157cc5a1b48c984ad1786cc62c8370d70d49ade6fd5662b74fd300d1136`、Public OpenAPI: `63146915b9440bf96724812ff0fe23a54575072c8fe404a37a597587d51ffd01`。
- `SHA256SUMS` 4件、Manifest整合性、Workspace外Clean Install／ESM ImportがPASSした。

## Test／Preview

- Backend対象18 tests／158 assertionsと既存QA除外Test、Public OpenAPI 48 operations、Client 17 tests、Site Schema 10 tests、Testkit 24 testsがPASSした。Package Typecheck／Lint／Build、Frozen Lockfile、Policy Unit／Gate、Quality Gate、Security Gate、Secret／PII Scan、`git diff --check`もPASSした。
- Preview APIだけを旧`sha256:dd80b68d...`から`sha256:55006860...`へ更新した。新ImageはSource Commit `539ba74...`、Docker HealthはDB／Redis／Storageを含めてPASS。旧ImageはRollback用に保持した。
- Preview公開Gacha一覧はHTTP 200だが0件のため、Presentation実データSmokeはSynthetic Dataを追加せずSkipした。Task DBの対象Contract Testを正本とする。Admin、PostgreSQL、Redis、DB Data／Migration、Nginx Checksum、V1は非変更である。
- SITE-004は`2.0.0-alpha.2`の3 PackageとPublic OpenAPIを固定導入し、Public API経路が同Endpointを公開する環境で再開可能。Storefront側へBusiness Ruleを実装しない。
- 残課題: Point不足CTAはCanonicalな安全判定が整った後続Contractで追加する。PreviewのAPI Loopback PortはCompose定義に存在するがDocker実体へPublishされておらず、現行Nginxは固定IP Upstreamを使用して正常稼働している。
- 所要時間: 約2時間。既存Domain Characterization、共通Eligibility抽出、Contract／Package同期、Artifact作成、Preview Image Buildに時間を要した。
- Gate G4／G5は`NOT COMPLETE`を維持する。
