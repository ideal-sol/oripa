# V2 Catalog／Probability Read-only Vertical Slice

## Status

MIG-050で追加する`2.0.0-alpha.1`の非Production Foundationである。V2のCatalogと
Probabilityを読取専用Public APIへ接続する。Admin Mutation、Draw Transaction、
在庫減算、Point消費、User Prize、Production Deploymentは含まない。

## Responsibility

PostgreSQLのV2専用TableをCategory、Tag、Rank、Presentation Asset、Prize、Gacha、
Version、Probabilityの正本とする。公開中かつ公開期間内のGacha Versionと、そのVersionへ
紐づく公開済みProbability VersionだけをLaravel Application層から返す。

`tenant_id`は使用しない。内部`id`はRepository内Relationに限り、Public APIはUUIDv7の
Opaque Public IDを使う。V1 Table名やV1 Codeを機械的にCopyしない。

## Schema

- `catalog_categories`／`catalog_tags`: 公開分類、Slug、表示順。
- `catalog_ranks`／`catalog_rank_assets`: Rankと公開Presentation Asset。
- `catalog_prizes`: 景品Master。公開表示値だけをPublic Responseへ変換する。
- `catalog_presentation_assets`: Storage識別子、Public Path、SHA-256、Media Metadata。
- `catalog_gachas`／`catalog_gacha_tags`: Gacha MasterとTag Relation。
- `catalog_gacha_versions`／`catalog_gacha_version_prizes`: 表示・価格・販売口数のVersion。
- `catalog_probability_versions`／`catalog_probability_stages`: 公開確率VersionとStage。
- `catalog_probability_entries`: `prize`または`point_back`の整数ppm Entry。
- `catalog_minimum_guarantees`: StageごとのMinimum Guaranteeを明示する。
- `catalog_import_runs`: Fixture ImportのSource、Checksum、件数、結果。

各StageはEntryとMinimum Guaranteeを合わせて`1,000,000 ppm`にする。`no_prize`は
作らない。Point、ppm、数量は整数で、価格・在庫初期値・販売口数は負数を許可しない。
Published Gacha VersionとPublished Probability Version、その子RecordはApplicationと
DB Triggerの両方で変更を拒否する。

## Public Contract

Public OpenAPIはCategory／Tag、Gacha一覧、Public IDまたはSlugによるGacha詳細を提供する。
一覧はOpaque Cursor、既定20件、最大100件である。Cache可能なMaster／一覧／詳細は
`Cache-Control`を明示し、ErrorはRFC 9457 Problem Detailsを返す。

公開する確率情報はStage、Rank別合計ppm、Point Back合計ppm、Minimum Guaranteeの
表示情報に限定する。景品別の個別ppm、内部在庫、原価、Storage識別子、内部`id`、
Snapshot Checksum、Secret、Credentialは返さない。

## Fixture Import

決定的FixtureはCategory／Tag、Rank、Asset、Prize、Gacha、Gacha Version、
Probability Stage／Entryの順でImportする。Manifest SHA-256、Record数、Asset SHA-256、
FK、重複Code／Slug、Stage合計を検査する。同じManifestの再実行は同じ完了結果を返し、
Recordを重複作成しない。

MIG-070／MIG-071の本番Exporter／Importerではない。Production Data、実顧客情報、
実PII、SecretをFixtureへ含めない。

## V1 Migration Delta

V1のCatalog／Probability実装はCharacterizationの参照元に限る。V1の最新承認差分である
お知らせ拡張と100／1000回Bulk Drawは移行差分として記録し、本TaskでV2へCopyしない。
V1 Runtime、本番DB、Nginx、`v1/early-release`、Archive Branch、Annotated Tagは
変更しない。

## Deferred Scope

在庫減算、Row Lock、Draw Transaction、Point消費、Draw History、User Prize、
Idempotency、並行実行はMIG-051へ延期する。Admin Mutation API／UI、Shipping、
Payment Provider、Luxe Pack固有Design、Production Deploymentも本Task外である。
