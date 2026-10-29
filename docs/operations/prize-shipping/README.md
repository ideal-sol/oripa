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

## Shipping-only Rights (SHIPONLY-20260926)

Adminの「配送のみ・ポイント交換不可」は`catalog_prizes.shipping_only`を現在値、
`catalog_gacha_version_prizes.shipping_only`を公開条件、
`user_prizes.shipping_only_snapshot`を当選時権利として保存する。
すべてNOT NULL/default false。既存当選データのMaster再計算・業務backfillは行わない。
公開Versionの条件変更は不可。所有Snapshot変更はDB Triggerで拒否する。

Public `GachaDetail.prizes[].shipping_only`はPublished Version、
`UserPrize.shipping_only`は所有Snapshotを正本とする。交換ポイント0から推測しない。
`prizeAllowedActions()`は共通理由の優先順位を維持した後、配送専用の
`point_exchange`を`unavailable_reason=shipping_only`で拒否する。
shipping/selectionは既存配送条件を維持する。個別・一括交換とも同一Guardを使い、
混在requestは全件rollbackする。DB CHECKも配送専用のexchange_processing/convertedを拒否する。

期限は保存済み`storage_expires_at`のみを使用する。新規獲得時の期限は下記フラグに従う。
`php artisan v2:prizes:expire-shipping-only --limit=1000`は、配送専用・stored・
期限到達済みだけを選び、users → wallets → user_prizesをlockして条件を再確認する。
expired/terminal_at、append-only状態履歴、system Auditを同一Transactionで記録する。
Point、Exchange Request、Inventory、配送への副作用はない。重複実行は無操作。
通常景品は対象外。配送依頼済み景品も失効対象外とする。

status=holdまたはActive Payment Holdは安全のためスキップする。
hold履歴・状態を変更しない。期限到達後は既存allowed_actionsで操作不可、
正規のhold解除でstoredへ戻った後の次回実行でexpiredへ収束する。

実行経路は対象限定Commandで、既存Schedulerへの登録や停止中Workerの起動は含まない。
継続運用にはOLD Testの承認済み実行主体から本Commandを定期実行する必要がある。
1回の上限を超える場合は次回実行で処理を継続する。Scheduler全体の起動で代替しない。

Migration 000077はforward-only。追加前の行は通常景品のまま互換性を維持する。
配送専用設定・発行後は古いAPIへの単純rollbackを禁止する。DB CHECKは旧APIの交換を
fail-closedにするが、旧DrawはSnapshotを保存できず、旧Adminは条件を理解できない。
修正済みImageへのforward recoveryを使う。Human受入まで旧Imageは保持する。

Contract candidate: bundle/client/testkit alpha.41、Public alpha.37、Admin alpha.35。
Storefrontは正式immutable Artifactのexact version/digest確定後に導入し、抽選前と
所有景品に配送専用を表示、交換UIはallowed_actionsに従う。

## Production Scope

このAlpha Vertical SliceはProduction Deployment対象外である。Frontend UI、Admin UI、Carrier連携、QA Modeは後続Taskに残す。


## PRIZE-AUTOEXCHANGE-7D-20261029: deferred activation

Risk R3 / Lane Strict Change / Application Runtime Activation deferred.
Default `V2_PRIZE_SEVEN_DAY_AUTO_CONVERT_ENABLED=false` preserves 60 days for new
draws and disables ordinary-prize automatic conversion. When true, new draws
snapshot exactly seven days; existing deadlines are never rewritten. Positive
ordinary snapshots convert to free coins at the stored deadline; zero snapshots
expire without a point operation. Coin expiry remains grant time plus 180 days.
Shipping-only expiration remains the existing independent command.

The automatic command is `php artisan v2:prizes:auto-exchange-expired --limit=1000`
(valid limit 1–1000). Each candidate has one owning financial transaction; failures
roll back and increment the failed count, returning command exit 1. Retry after
investigation. Repeated failures must be resolved before backlog exhaustion can be
claimed. The command skips inactive/closed members and active payment holds.
The flag is shared by draw and command configuration. Laravel registers the command
every five minutes with overlap prevention only when enabled. The existing runbook
uses targeted commands; do not start the entire Scheduler as an activation shortcut.

Before a separate Human GO, execute only aggregate read-only queries in the approved
environment. No identifiers or personal data should be exported:

```sql
BEGIN READ ONLY;
SELECT p.shipping_only_snapshot, (p.exchange_point_snapshot = 0) AS zero_value,
       u.state, count(*) AS prizes, sum(p.exchange_point_snapshot) AS snapshot_points
FROM user_prizes p JOIN users u ON u.id = p.user_id
WHERE p.status = 'stored' AND p.storage_expires_at <= CURRENT_TIMESTAMP
GROUP BY p.shipping_only_snapshot, (p.exchange_point_snapshot = 0), u.state;
ROLLBACK;
```

This is a conservative backlog inventory, not an exact payable total: review active
payment holds and account closures using the service eligibility predicates before GO.
Also run the same aggregate for future deadlines to estimate the next batches.

OLD Test acceptance requires flag OFF/ON draw deadlines, stored 60-day preservation,
deadline equality, zero/shipping-only outcomes, 180-day lots, ledger/audit reconciliation,
retries, parallel workers, payment holds and closure races. Production requires those
results, required CI and separate Human approval of backlog counts/amounts and runtime
release authority. Configure the same flag in every API and approved command execution
environment, refresh their configuration using the approved deployment procedure, and
verify agreement before enabling the targeted five-minute timer. Do not change existing
shipping-only or coin-expiration execution. No runtime activation occurred in this task.

Rollback: disable the flag consistently for API and command execution. Future draws
return to 60 days; already stored seven-day deadlines, conversions, ledger entries and
180-day coin expirations remain authoritative. Never reverse credits or extend stored
deadlines as an automatic rollback. An in-flight transaction may finish; verify its
result before reporting the worker stopped.
