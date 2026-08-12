# MIG-062G Gacha Catalog Display Public Contract

## Task

- Issue: #243
- PR: #244
- Base: `main@21f58399e8869a00249398dac3b06a74e1e9da97`
- Branch: `feat/MIG-062G-gacha-catalog-display-contract`
- Risk: R3
- Task Policy SHA-256: `6db64cdbd6c87aa2a9ab0aadb1d7af1c4993b83b35368a86b2a042d0c183739f`（初版 `2389161c5871c8ddd26b12c7b7f39ac516fbc1fdc3ea1e42166090a75d90703c`）
- Application／Artifact Source Head: `36220b5c08820741b4763363a7e86c18274b9688`
- Final Head／Squash Commit: Final Checks／Merge後に確定

## Public Contract

- Public対象としてPublished Versionを持つGachaは、`on_sale`、`coming_soon`、`ended`、`sold_out`、User Eligibility不一致にかかわらずCatalogへ残す。非公開、未公開Version、非表示Categoryだけを従来どおり除外する。
- Catalog ItemへDetail／Drawと同じBackend Eligibility ServiceによるSale State、認証状態、Eligibility、不適格理由、Allowed Draw Counts、JST日次制限、CTAを返す。Frontendで日時、在庫、Audienceから再判定しない。
- `ended`／`sold_out`は`display.show_price_points`、`show_total_count`、`show_drawn_count`をfalseとして、表示制御をBackend Contractへ固定する。
- Anonymous Catalogは既存Public Cache、認証済みCatalogは`private, no-store`と`Vary: Cookie`を使用し、User固有状態をPublic Cacheへ混入させない。
- Category、Tag、Opaque Cursor、Public ID順のStable Orderingは維持する。

## Asset／ID

- Gacha Assetは保存済み内部Pathを公開せず、Asset Public IDから`/api/v2/content/assets/{asset_public_id}`を生成する。Public Assetの既存公開・Archive・画像種別境界は維持する。
- Gacha Detail／Presentation RouteはCanonical 11文字Public Codeと既存Link互換UUIDを受理する。OpenAPIの`GachaPublicId`も同じ形式へ同期し、内部DB IDは公開しない。

## Contract／Artifact

- Public OpenAPI、Generated Types、`@oripa/storefront-client`、`@oripa/site-schema`、`@oripa/storefront-testkit`を`2.0.0-alpha.9`へ同期する。
- Testkitへon-sale、coming-soon、ended、sold-out、認証済みeligible／ineligible、anonymousのFixtureを追加する。
- Artifactは`/var/lib/oripa-v2-evidence/MIG-062G/artifacts/2.0.0-alpha.9/`へ新規生成し、Source CommitをApplication Head `36220b5c08820741b4763363a7e86c18274b9688`へ固定した。既存Artifactは上書きしていない。
- Manifest: `artifact-manifest.json`／`b1ecfc891070045bc9729421a88562fc0d8fafbb86c4b9add60eb99cc6341ee2`
- Client: `oripa-storefront-client-2.0.0-alpha.9.tgz`／`8ff4ed2be8c5ce6905cde05c2b414d14bb74b4c1f853c623606eb401b60ef515`
- Testkit: `oripa-storefront-testkit-2.0.0-alpha.9.tgz`／`8d71703cf90353ac0f53f371b93cdf345c6eaac77189fa06e4f0c36e3a2de582`
- Site Schema: `oripa-site-schema-2.0.0-alpha.9.tgz`／`2c2dea7ca10a884550c47e96a4c43c2f3634d1a843d84023f2446cd1c93e1477`
- Public OpenAPI: `public.openapi.json`／`737c6e174f9e47a0543a6b39a0e778fb46c50b24c20564dd7a8636439010e702`

## Verification

- Task専用DBでBackend対象30 tests／277 assertionsがPASSした。
- Public OpenAPI bundle／lint相当Check、OpenAPI Unit 4件、Policy Unit 117件、Local Policy Gate、Storefront Client 24件、Generated Type同期、Client／Schema／TestkitのTypecheck／LintがPASSした。
- Host共有依存Cacheに`fast-uri`が欠落していたためLocal実行できなかったSite Schema／Testkit Testは、Fresh Installを行うGitHub Integration GateでPASSした。GateやDependencyは変更していない。
- exact Application HeadのRequired Checksはmanual dispatch Run `31574624473`でPolicy／Quality／Security／Integration／CI GateがすべてPASSした。Preview Image Build Run `31575994537`はGitHub-hosted amd64でPASSした。
- 全SuiteはTask対象外のため実行しない。

## Preview

- 検証済みArtifactの外側digest、内部SHA-256、Image ID、OCI revision、`linux/amd64`を確認し、Host BuildなしでAPI Image `sha256:1b8b62fa5d2446041396bd09e3d147b6e8df06c3eac4672db2ee337d98152259`をloadした。`--no-build --no-deps`でAPI Containerだけを更新し、AdminはMIG-062F Imageを維持した。
- 既存SITE-014 Synthetic Gacha 5件はCatalog HTTP 200で全件残り、実測Stateは`on_sale`、`coming_soon`、`ended`、`sold_out`、`on_sale`。匿名ResponseはPublic Cache、認証済みResponseは`private, no-store`／`Vary: Cookie`で、LINE限定Gachaは一覧に残ったまま`audience_not_eligible`を返した。
- `ended`／`sold_out`の3表示Flag false、他2 StateのFlag true、5件の11文字Detail／Presentation Route HTTP 200、Public Asset PathのAdmin Origin非混入を確認した。
- Previewで既存QA Asset実体がContainer local storageから欠落していたため、QA作成時のCanonical upload元66-byte PNGを既存checksum一致で同じstorage identifierへ復元した。Public AssetはHTTP 200、`image/png`、immutable cache、SHA-256 `e8aee0cff0ea61bb705a18ee40399fff3f9fc71644544f16f03dfcf2bf1d6e0c`を返した。新規Asset／DB更新は行っていない。
- API／Admin／PostgreSQL／Redisはhealthy、HTTP 500／502／504は0。DB、Migration、Nginx、V1、Storefront Repository、Paymentは変更せず、`luxe-pack.biz`、`ad.luxe-pack.biz/login`、`admin.luxe-pack.biz/login`はHTTP 200を維持した。

## 残課題

- Preview Assetはlocal container filesystem保存で永続Volumeがなく、将来のAPI Container置換時に再度失われ得る。Object Storageまたは永続Volumeへの移行は本Contract Task外の運用課題として分離する。
