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

- Artifact `2.0.0-alpha.14`をRepository外Evidenceへ発行し、Manifestと`SHA256SUMS`を検証した。Manifest SHA-256は`fc19acb126c5bc9a822b2de40ccccd365da24ceb0270cbe0f665de716bc6e475`、Clientは`d76d1e03d0772e82d24b37a65b94e1550bd53a809f16fc3e60d1377fc3a284dd`、Testkitは`4ac29fbc037ec711056ff58353e20a08726c7edcff4769fd84aa4f44cfac3176`、Site Schemaは`4b4539bdc199c0ef03e6cdf180f1d6c62e80b8827a92f64812b212b868e6a8bc`、Public OpenAPIは`cc50dbae2f0d55deca43afca7dd3457074a1edf9ae275c46f58f782b1790b393`。
- GitHub-hosted amd64 PipelineでAPI／Admin ImageをBuildし、Artifact外側Digest、内部SHA-256、Image ID、`linux/amd64`、OCI revisionを検証した。HostではBuildせず、検証済みImageだけをloadし、`--no-build --no-deps`でAPI／Adminを更新した。
- DB Target Safety Guard前後PASS後、Preview DBへMigration `000051`だけを適用した。最新Migration、`show_on_top`列、Banner限定Check Constraintを確認し、既存Dataを維持した。
- Synthetic Banner 1件でAdmin登録／編集、Top OFF除外、Top ON公開一覧、`/gachas`へのクリック先、Canonical Public Asset URL、Desktop／Mobileを確認した。Public Assetは200、Console／Page ErrorとHTTP 500／502／504は0件だった。
- Runtime更新時の初回SmokeでDB Guard専用envがRuntimeへ渡されたことを検知し、同一検証済みImageをCanonical Preview envで即時再作成した。最終状態ではHost／Origin／固定IP／Port／Restart Policyを維持し、Admin Login、API Health、Public Banner、V1 URLはいずれも正常だった。
- Nginx、V1、Storefront Repository、Page、Notice、Payment、Point、Drawは変更しない。
- Final Head、PR、Squash Commit、Artifact SHA-256、Preview Image／Smoke、Required Checks、Fresh Self-review、CleanupはCloseout Evidenceを正本とする。
- G4／G5はNOT COMPLETEを維持する。
