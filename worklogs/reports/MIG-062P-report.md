# MIG-062P バナートップ表示／クリック先Public Contract拡張

## Task

- Issue: #263
- Base: `main@9dd56e758872286abfdd05cadc7ce0c62e14e0a3`
- Branch: `feat/MIG-062P-banner-top-presentation-contract`
- Risk: R3
- Task Policy SHA-256: `39716ca12ec238a004638172d640bfdde24ad95595e3dac0ca1d39869c82c730`
- Policy再発行: Required Integration Gateで既存性能Fixtureの新しいTop表示条件追従が必要と判明したため、`apps/api/tests/V2/ZContentContactPerformanceTest.php`だけを完全一致で追加した。他Fieldは変更していない。

## Schema／Admin

- Migration `000051`でimmutable `content_versions`へ`show_on_top boolean default false`を追加する。既存BannerはTop OFFのまま安全移行し、Banner以外のVersionでONにできないDB制約を設ける。
- 既存BannerのVersion、公開期間、公開状態、Asset、Canonical Public URLを再利用する。画像保存方式と内部Storage Pathは変更しない。
- Admin登録／編集へ「トップに表示」と条件付き「クリック先URL」を追加する。ONでは安全なHTTP(S) URLまたは単一Origin相対Pathを必須とし、OFFでは保存するVersionのURLを`null`へ正規化する。
- 一覧のTop表示状態とURL、保存後Canonical再取得、既存Category／Upload／編集／削除境界を維持する。

## Public Contract／Artifact

- 既存`GET /api/v2/content/banners`をStorefrontトップ表示用のCanonical一覧とし、Top ONかつ現在公開中でPublic Image Assetと安全なURLを持つBannerだけを返す。
- Responseは`id`、`title`、`image_url`、`link_url`と既存`asset`／公開期間を返す。Category名や固定位置をStorefrontへ推測させない。
- Anonymous read-only情報だけの既存Public Cache境界を維持する。Admin APIは`private, no-store`、Errorは既存RFC 9457を再利用する。
- Public／Admin OpenAPI、Generated Types、Storefront Client、Site Schema、Testkitを`2.0.0-alpha.14`へ同期する。旧Artifact `2.0.0-alpha.11`と未採用`2.0.0-alpha.12`／`alpha.13`は上書きしない。
- Public RuntimeはTop対象で`image_url`と非NULL `link_url`を常に返す一方、既存`ContentBanner`利用者との互換性維持のためOpenAPIの`image_url`はoptional追加、`link_url`はnullableを維持する。
- Admin RuntimeとGenerated Clientは新規Fieldを常に返す一方、既存Admin OpenAPI利用者との互換性維持のため新規Fieldはoptional追加とする。
- Artifact filename／SHA-256／Source CommitはRepository外EvidenceのManifestを正本とする。

## Verification

- Migration: fresh、`000051` rollback／reapply PASS。
- Backend: 14 tests／136 assertions PASS。Default OFF、ON時URL必須、OFF時URL非表示、危険Scheme拒否、Public期間／Top Filter、Category、Permission、Idempotencyを確認した。
- Admin: Unit 3 tests、Desktop／Mobile Browser 2 tests、Typecheck、Lint、Production Build、Generated Client同期 PASS。
- Contract: Public 49／Admin 212／Webhook 1 operations、OpenAPI lint／bundle／Breaking Check PASS。
- Storefront Client 24 tests、Site Schema 10 tests、Testkit 30 tests、Policy Gate、対象Policy Unit、Frozen Install、`git diff --check` PASS。
- Required Integration Gateで検出した既存Banner性能Fixtureを、100件すべて`show_on_top = true`とする新Canonical Contractへ限定追従した。性能閾値や件数Assertionは変更していない。
- 全V2 Suite、全Admin E2E、Storefront Repository TestはScope外のため実行しない。

## Preview／Closeout

- Required Checks成功後、exact PR HeadのGitHub-hosted amd64 API／Admin Imageを検証し、Host Buildなしでloadする。
- DB Target Safety Guard後にMigration `000051`だけを適用し、Top ON公開BannerとTop OFF／期間外BannerのPublic Filter、Admin保存、URL、Desktop／Mobile、Console／HTTP 500／502／504をSmokeする。
- Nginx、V1、Storefront Repository、Page、Notice、Payment、Point、Drawは変更しない。
- Final Head、PR、Squash Commit、Artifact SHA-256、Preview Image／Smoke、Required Checks、Fresh Self-review、CleanupはCloseout Evidenceを正本とする。
- G4／G5はNOT COMPLETEを維持する。
