# MIG-062F Banner Public URL Canonicalization

## Task

- Issue: #237
- PR: Closeout時に確定
- Base: `main@f66209549dfc9c8fae4acaa51645710040694d1c`
- Branch: `fix/MIG-062F-banner-public-url`
- Risk: R3
- Task Policy SHA-256: `4750435c5c1a19a1b825bb78dd668ad9d69305e66674aec2efb96a8e7b369503`
- Final Head／Squash Commit: Closeout時に確定

## URL生成

- Banner Uploadは既存Asset基盤と保存先を維持し、Asset Public IDから`/api/v2/content/assets/{asset_id}`をCanonical Public Pathとして生成する。
- Admin Banner APIは`V2_PUBLIC_ORIGIN`とCanonical Public PathをBackendで結合した絶対URLを返す。一覧表示、画像表示、新規Tab、Copyは同じ`public_url`を使用し、Admin Originの文字列置換は行わない。
- 既存Banner AssetもDB BackfillなしでPublic IDから同じURLへ解決する。Storage identifier、物理画像、Banner relation、Upload validationは変更しない。
- Public Asset GETはPublic指定かつ非Archiveの画像だけを返し、UUID URLへ`public, max-age=31536000, immutable`と`nosniff`を付与する。Problem responseは既存RFC 9457形式を維持する。
- Admin CSPは環境の`V2_PUBLIC_ORIGIN`をURL parseした単一Originだけを`img-src`へ許可する。Productionは`https://luxe-pack.biz`、Previewは`https://test.luxe-pack.biz`をEnvironment正本とする。

## Verification

- PHP 8.4／Task専用DBでBackend対象14 tests／107 assertionsを実行し、Cache-Control順序非依存Test補正後の対象3 tests／42 assertionsがPASSした。
- Admin Banner Unit 3件、CSP Security Unit、Typecheck、Lint、Production Buildを含むDesktop／Mobile Browser 2件がPASSした。画像URL表示とCopy値はCanonical Public URLで一致し、Console／Page／500／502／504は0件。
- Admin／Public OpenAPI lint・bundle、Generated Admin Client、Generated Public Types、Storefront Testkit同期、Storefront Client／Testkit TypecheckがPASSした。
- DB Schema／Migration、Nginx、DNS、TLS、V1、Storefront Repositoryは変更していない。全Suiteは対象外のため実行していない。

## Preview

- API／Admin Candidateへ同じApplication Headを反映し、`mig061a-v2-preview`のNetwork、Port、DB、Nginxを維持する。
- Admin API一覧が`https://test.luxe-pack.biz/api/v2/content/assets/{asset_id}`を返し、URL表示、Copy、画像GET 200、Admin Origin非混入を確認する。
- Preview結果、Image Digest、Final Head、PR、Squash CommitはCloseout時に追記する。

## 残課題
