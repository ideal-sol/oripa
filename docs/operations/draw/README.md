# V2 Draw Vertical Slice

## Responsibility

`MIG-051`は、Published Catalog／Probabilityを参照するV2 DrawのTransaction境界を管理する。対象回数は`1`、`5`、`10`、`100`、`1000`で、全回数を単一Request、単一DB Transaction、必須`Idempotency-Key`として処理する。

## Transaction

Lock順は`Idempotency Record／Draw Request`、`Gacha Mutable State`、`Wallet`、`Point Lots`、内部ID昇順の`Prize Inventory`、`Draw Results／User Prizes`である。Point、Inventory、履歴、所有権、Audit、Outboxは同一Transactionで確定し、途中Failureでは全てRollbackする。外部通信はTransaction内で行わない。

## Probability

各DrawでLaravel BackendのCSPRNGを1回使用し、Draw順にProbability Stageを進める。Stage単位のRange Cacheと順方向Pointerを使用し、当選数の近似計算は行わない。在庫切れEntryのppmは、V1 Characterizationと同じくそのStageのMinimum Guaranteeへ移す。`no_prize`は存在しない。

## Persistence

`draw_results`、`user_prizes`、Point BackのLot／Ledgerは250件単位のSet-based Insertで保存する。個別結果、Sequence、Snapshot、Point Back由来は省略しない。DRAW-20260925以降の新規Drawは100／1000回でも全件を`results`へ収録する。個別ppm、CSPRNG値、内部ID、原価は公開しない。

## Representative Presentation And Full Results

Public Gacha Rank assets and newly created Draw presentation snapshots use
`/api/v2/catalog/presentation-assets/{assetId}/content`, never the stored Admin
management path. Admin upload/content routes remain separate and authenticated.
The existing Public route retains its public/non-archived/revision-reference
checks, checksum verification, MIME type, ETag and immutable caching. Range
requests retain the existing full-content HTTP 200 behavior; no new streaming
or partial-content implementation is introduced.

Existing Draw responses, including already saved Admin paths, are not rewritten
on GET/replay or backfilled. After API activation, create a new Draw for browser
acceptance; Storefront must not rewrite or infer asset URLs. This is an
implementation correction to the existing Public contract, not a schema/package
change; Contract/Client/Testkit alpha.40 remains unchanged.

新規Drawでは全`draw_results`の`rank_master_revision_id`が指すimmutable revisionの
`display_order`最小値を最高Rankとする。異なるRankが最小順位で重複した場合は
`presentation: null`。同一最高Rank内だけで`request_sequence ASC`の先頭を選び、
そのResultの保存済み`display_snapshot.rank`と`video_snapshot`を公開する。
snapshot欠落・不正形式は`null`へfail-safeし、次点、別Result、現在の動画へのfallbackはしない。
Catalogの動画必須条件とDB制約は維持され、動画未設定のDrawは従来どおり拒否される。

`results`は既存のrequest_sequence順で全件を返す（1／5／10／100／1000）。
`high_rank_results`の高Rankfilter、20件上限、truncate flagは維持する。
代表演出selectionは両Public配列から独立する。
既存のGET画像補完も新規Draw作成時に実施し、代表演出、全結果、画像情報を
`draw_requests.response_data`へ同一Transactionで固定する。
以降のPOST／Replay／GETはこの保存値を正本とし、GETで画像や演出を再投影しない。
Replayの既存`idempotent_replay` flagだけは従来どおり変わる。
旧responseの`presentation`／bulk `results`は欠落を許容し、後付け・backfill・保存値更新はしない。
旧GETの既存画像補完は維持する。

Public OpenAPI alpha.36、Client／Testkit bundle alpha.40の生成型:

```ts
import type { PublicComponents } from "@oripa/storefront-client/types";

type DrawResponse = PublicComponents["schemas"]["DrawResponse"];
type DrawPresentation = PublicComponents["schemas"]["DrawPresentation"];
type Rank = PublicComponents["schemas"]["RankReference"];
type VideoSnapshot = PublicComponents["schemas"]["PresentationAsset"];
```

`DrawResponse.presentation?: DrawPresentation | null`。
`DrawPresentation = { rank: Rank; video_snapshot: VideoSnapshot }`。
`Rank = { id: string; name: string }`。
`VideoSnapshot = { id: string; path: string; checksum_sha256: string;
media_type: "image" | "video"; mime_type: string; alt_text: string | null }`。
既存PresentationAsset型を再利用するが代表演出はvideoだけを投影する。
`DrawResponse.results?: PublicComponents["schemas"]["DrawResult"][]`、maxItemsは1000。
optionalは旧response互換のためで、新規Drawには全件が必ず存在する。

Storefrontは`loading → presentation objectならvideo → result`とし、
`onEnded`、Skip、`onError`の全てで結果一覧へ進む。
404、codec／unsupported media／network／media load errorでも同じ扱いとする。
absentは旧response、nullは安全な代表演出なしで、いずれも直接結果一覧へ進む。
結果一覧は`results`を使い、100連は100件、1000連は1000件を取得する。
Rank ID／name／配列順／sequenceの比較、`high_rank_results`からの選択、
現在Rank Master参照、動画fallbackは禁止。各景品カード内の動画再生廃止と描画最適化は
後続Storefront Taskで実施する。

Migration作成0、共有Test／Production適用0。隔離fixture DBの初期化では既存migrationを使う。
旧compact payload向け100KB性能assertionは全件仕様に合わせ4MBへ変更し、
1000件p95 ≤ 2000ms、query ≤ 100、件数に比例するSELECT増加禁止の既存assertionは維持する。
rollbackは前API imageへの復帰で、新たに保存したJSONを削除・書換えしない。

## Idempotency

同一User、同一Key、同一RequestはCanonical Resultを返し、CSPRNGやPoint消費を再実行しない。同一Keyを異なるRequestへ再利用した場合はConflictとする。Retentionは24時間で、期限切れ`idempotency_records`のCleanupは後続運用Taskで実装する。

## Deferred Scope

Prize交換、Shipping状態機械、Storefront UI、Admin Mutation、QA Draw、Production Deploymentは対象外である。`payment_adjustment_prize_actions`は有効な`user_prizes` FKだけを追加し、Chargeback時の自動取消や自動復元は実装しない。

## Production

本機能はAlphaでありProduction利用不可である。V1 Runtime、V1本番DB、Nginx、Archive Refへ変更を加えない。
