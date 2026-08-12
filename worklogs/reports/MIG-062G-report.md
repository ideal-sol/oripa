# MIG-062G Gacha Catalog Display Public Contract

## Task

- Issue: #243
- PR: Closeout時に確定
- Base: `main@21f58399e8869a00249398dac3b06a74e1e9da97`
- Branch: `feat/MIG-062G-gacha-catalog-display-contract`
- Risk: R3
- Task Policy SHA-256: `2389161c5871c8ddd26b12c7b7f39ac516fbc1fdc3ea1e42166090a75d90703c`
- Final Head／Squash Commit: Closeout時に確定

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
- Artifact directory、Source Commit、Manifestと各SHA-256はArtifact生成後に確定する。既存Artifactは上書きしない。

## Verification

- Task専用DBでBackend対象30 tests／277 assertionsがPASSした。
- Public OpenAPI bundle／lint相当Check、OpenAPI Unit 4件、Policy Unit 117件、Local Policy Gate、Storefront Client 24件、Generated Type同期、Client／Schema／TestkitのTypecheck／LintがPASSした。
- Host共有依存Cacheに`fast-uri`が欠落していたため、Site Schema／Testkitの実行TestはFresh Installを行うGitHub Required Checksで確定する。GateやDependencyは変更しない。
- 全SuiteはTask対象外のため実行しない。

## Preview

- 既存SITE-014 Synthetic Gacha 5件だけでCatalog、Detail、Eligibility、Asset URL、11文字Route、Cache HeaderをSmokeする。新規Synthetic Dataは追加しない。
- Preview APIだけを検証済みImageへ更新する。DB、Migration、Nginx、V1、Storefront Repository、Paymentは変更しない。

## 残課題

- Artifact、Required Checks、Preview Smoke、Closeoutは後続工程で確定する。
