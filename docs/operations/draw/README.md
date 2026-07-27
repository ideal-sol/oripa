# V2 Draw Vertical Slice

## Responsibility

`MIG-051`は、Published Catalog／Probabilityを参照するV2 DrawのTransaction境界を管理する。対象回数は`1`、`5`、`10`、`100`、`1000`で、全回数を単一Request、単一DB Transaction、必須`Idempotency-Key`として処理する。

## Transaction

Lock順は`Idempotency Record／Draw Request`、`Gacha Mutable State`、`Wallet`、`Point Lots`、内部ID昇順の`Prize Inventory`、`Draw Results／User Prizes`である。Point、Inventory、履歴、所有権、Audit、Outboxは同一Transactionで確定し、途中Failureでは全てRollbackする。外部通信はTransaction内で行わない。

## Probability

各DrawでLaravel BackendのCSPRNGを1回使用し、Draw順にProbability Stageを進める。Stage単位のRange Cacheと順方向Pointerを使用し、当選数の近似計算は行わない。在庫切れEntryのppmは、V1 Characterizationと同じくそのStageのMinimum Guaranteeへ移す。`no_prize`は存在しない。

## Persistence

`draw_results`、`user_prizes`、Point BackのLot／Ledgerは250件単位のSet-based Insertで保存する。個別結果、Sequence、Snapshot、Point Back由来は省略しない。100／1000回のPublic Responseは集計中心で、個別ppm、CSPRNG値、内部ID、原価を公開しない。

## Idempotency

同一User、同一Key、同一RequestはCanonical Resultを返し、CSPRNGやPoint消費を再実行しない。同一Keyを異なるRequestへ再利用した場合はConflictとする。Retentionは24時間で、期限切れ`idempotency_records`のCleanupは後続運用Taskで実装する。

## Deferred Scope

Prize交換、Shipping状態機械、Storefront UI、Admin Mutation、QA Draw、Production Deploymentは対象外である。`payment_adjustment_prize_actions`は有効な`user_prizes` FKだけを追加し、Chargeback時の自動取消や自動復元は実装しない。

## Production

本機能はAlphaでありProduction利用不可である。V1 Runtime、V1本番DB、Nginx、Archive Refへ変更を加えない。
