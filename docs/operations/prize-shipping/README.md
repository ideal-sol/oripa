# Prize／Exchange／Shipping Vertical Slice

## Responsibility

MIG-052は`user_prizes`を起点に、User Prize参照、Point交換、Shipping Address、Shipping Request、Tracking、PII Access AuditのV2 Alpha境界を提供する。

## Characterization

V1の最終業務状態をCharacterizationし、`stored`から交換または発送へ進むこと、配送が`requested`、`packing`、`shipped`、`delivered`／`returned`を基本とすることを維持する。V1のTable名、平文Address保存、景品ごとのShipping RequestはV2 ContractへCopyしない。

## User Prize

交換PointとStorage期限はDraw時Snapshotを正本とする。所有User、Draw Result、Catalog Relation、Snapshotは変更不可で、状態変更は`user_prize_status_histories`へAppend-onlyで保存する。

Public一覧／詳細はSnapshotから型付きPrize／Asset／Rank Presentationを返し、Action可否はBackendで判定する。`stored`、Storage期限、交換Point、Active Payment HoldをStorefrontで組み合わせて推測してはならない。`selection`はShippingまたはPoint交換の少なくとも一方を選べることを表し、User固有Responseは`private, no-store`かつ`Vary: Cookie`とする。

## Point Exchange

`Idempotency-Key`を必須とし、Wallet、User Prize、Point Lot、Operation、Ledger、Audit、Outboxを同一Transactionで確定する。全景品が交換可能な場合だけfree Pointを付与し、一部成功やReplay時の二重付与を許可しない。

## Shipping Address

Addressと電話番号はApplication-level Encryptionで保存する。一覧はMask済み値だけを返し、User本人またはShipping権限を持つMFA済みAdminの必要な参照だけ復号する。Auditには住所全文、電話番号、Tracking Numberを保存しない。

## Shipping Request

複数User Prizeを1件のShipping Requestへまとめ、暗号化Address Snapshotを保持する。Address更新・削除は既存Snapshotへ影響しない。Carrier API、送料決済、送り状、外部配送通信は実装しない。

## Lock Order

1. `idempotency_records`
2. `users`／`wallets`
3. `user_prizes`を内部ID昇順
4. `point_lots`
5. `shipping_requests`
6. `payment_adjustment_prize_actions`
7. 状態履歴／Audit／Outbox

`SKIP LOCKED`は使用せず、Deadlock／Serialization Failureは同一Idempotency KeyのTransactionで最大3回に限定する。

## Chargeback Hold

Activeな`hold`／`return_request` Actionは交換とShipping Requestを拒否する。自動取消、自動返送完了、自動復元、自動Hold解除は行わない。

## PII Access Audit

Address詳細、作成、更新、削除、Shipping Request作成、Admin Address参照、Tracking登録、状態変更、Point交換、Hold拒否を、Actor、Permission、Target Public ID、Action、Outcome、Reason、Request ID、Timestampだけで記録する。

## Production

このAlpha Vertical SliceはProduction Deployment対象外である。Frontend UI、Admin UI、Carrier連携、QA Modeは後続Taskに残す。
