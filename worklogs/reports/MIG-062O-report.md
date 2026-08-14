# MIG-062O ページ設定フッター表示／Public Contract拡張

## Task

- Issue: #259
- PR: #260
- Base: `main@3b8445f1cf8f858fb46c0afe9e366faaf5e78f5e`
- Branch: `feat/MIG-062O-static-page-footer-contract`
- Risk: R3
- Task Policy SHA-256: `cd736b315caeeb6980f1e3957c32ca29bbfcc5979fc13aab11d232bcf356e54b`

## Page Schema／Admin

- Migration `000050`で`content_static_pages.show_in_footer boolean default false`と公開一覧用Indexを追加する。既存PageはFooter OFFのまま安全移行する。
- 既存immutable `content_versions.body_html`、HTML Sanitizer、Checksum、公開期間、公開状態をCanonicalとして再利用し、本文形式や公開Ruleを新設しない。
- 既存`content_versions.sort_order`をFooter表示順として使用し、同順ではStatic Page内部IDで決定的に並べる。内部IDはResponseへ返さない。
- Admin登録／編集へ「フッターに表示」と条件付き表示順を追加し、初期値OFF、保存後Canonical再取得、Server-side Sanitize済みPreviewを実装する。

## Public Contract／Artifact

- `GET /api/v2/content/footer-pages`はFooter ONかつ現在公開対象のPageだけを`id`／`slug`／`title`で返す。
- Footer一覧は本文を返さず、選択後は既存`GET /api/v2/content/pages/{slug}`を再利用する。固定Slug、カテゴリ名、タイトル文字列による推測は行わない。
- Anonymous read-only情報のみで既存Public Cache境界を使用する。Errorは既存RFC 9457、Admin APIは`private, no-store`を維持する。
- Public／Admin OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.11`へ同期する。旧Artifact `2.0.0-alpha.10`は上書きしない。
- Artifact filename／SHA-256／Source CommitはRepository外EvidenceのManifestを正本とする。
- `2.0.0-alpha.11`配布物SHA-256: Client `56112482af70ff352b5661ac160ffb00225c8a218c16d7ebc472ffc3aac4aa1b`、Testkit `82d8e41831a214d788f2a612e34dd88ca5e206ef477e6336d00b539267ba6e79`、Site Schema `9e0eaaafb4fe51fd9650cf274674f5cd557499453d574958be4f2af9f4b53e79`、Public OpenAPI `cb00709ad49fb11dd802530d41ac056845730dd3b96ff3613ec36feae1379816`。

## Verification

- Migration: fresh、`000050` rollback／reapply PASS。
- Backend: 5 tests／44 assertions PASS。Default OFF、Footer ON/OFF、公開期間、表示順、Sanitize、既存Static Page Detailを確認した。
- Admin: Unit 4 tests、Desktop／Mobile Browser 2 tests、Typecheck、Lint、Production Build PASS。
- Contract: Public 49／Admin 212／Webhook 1 operations、OpenAPI lint／bundle／Breaking Check、Generated同期 PASS。
- Admin Responseの新規`show_in_footer`／`footer_sort_order`はRuntimeで常に返す一方、OpenAPIでは後方互換なoptional追加とし、既存ClientへのBreaking Changeを回避した。
- Storefront Client 24 tests、Site Schema 10 tests、Testkit 29 tests、Policy Unit 125 tests PASS。
- 全V2 Suite、全Admin E2E、Storefront Repository TestはScope外のため実行しない。

## Preview／Closeout

- 2026-08-13更新の`GHSA-2v37-7h3g-55p8`でRequired Security Gateが停止したため、独立SEC-010で`nanoid 3.3.17 -> 3.3.18`を最小更新し、Squash Commit `66c6be862589f6f1f44e3eef6fc83c8d0f39cacb`をmain経由で取り込んだ。MIG-062O Application差分は不変。
- Required Checks成功後、exact PR HeadのGitHub-hosted amd64 API／Admin Imageを検証し、Host Buildなしでloadする。
- DB Target Safety Guard後にMigration `000050`だけを適用し、Footer ON公開PageとFooter OFF公開Pageを必要最小限のSynthetic Dataで確認する。
- Admin保存／Preview、Public Footer一覧、既存Static Page Detail、Desktop／Mobile、Console／HTTP 500／502／504をSmokeする。
- Nginx、V1、Storefront Repository、Notice、Banner、Payment、Point、Drawは変更しない。
- Final Head、Squash Commit、Artifact SHA-256、Preview Image／Smoke、Required Checks、Fresh Self-review、CleanupはCloseout Evidenceを正本とする。
- G4／G5はNOT COMPLETEを維持する。
