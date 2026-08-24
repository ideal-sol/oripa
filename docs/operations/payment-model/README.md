# V2 Payment Model Foundation

## Status

MIG-044で追加した非Production Alpha Foundationへ、MIG-079でCanonical Provider
`fincode`のBackend Core、Public／Admin／Webhook Contract、3D Secure境界を接続した。
Provider有効化、Storefront／Admin UI、Production Deploymentは含まない。

Provider固有の現行正本は[FINCODE.md](FINCODE.md)とする。

## Responsibility

PostgreSQLとLaravel Domain層がPayment、Adjustment、Point Grant、Reservationの正本で
ある。Browser Returnだけを成功根拠にせず、署名検証済みProvider Eventまたは
Server-side照合済み情報だけを受け付ける。Provider通信はDB Transaction外で行う。

## Schema

- `point_purchase_plans`: JPYの購入Plan Version。published後の金額とPoint数はimmutable。
- `payments`: 7状態のPaymentと購入時Snapshot。Refund／Chargebackは状態へ混在させない。
- `payment_status_histories`: append-onlyなPayment状態遷移。
- `payment_point_grants`: PaymentとPoint Operationの一対一対応。
- `payment_provider_events`: 検証済みEvent。PayloadはApplication暗号化しHeaderをRedactする。
- `payment_provider_event_attempts`: append-onlyな処理試行。
- `payment_provider_operations`: Transaction外Provider呼出の結果・不明状態境界。
- `payment_adjustments`: 全額Refund、Chargeback、manual reviewのReversal。
- `payment_adjustment_status_histories`: append-onlyなAdjustment状態遷移。
- `payment_adjustment_point_impacts`: 取消量とShortfall。負残高は作らない。
- `payment_adjustment_point_operations`: AdjustmentとPoint Operationの対応。
- `point_lot_reservations`: Refund Provider処理中のLot予約。

`financial_state`はAdjustmentから導出し、保存しない。`tenant_id`、Card Data、Provider
Secret、Production Credentialは保存しない。

`payment_adjustment_prize_actions`は正しいV2 `user_prizes`が存在しないため延期する。
Draw／Prize実装後に、`payment_adjustment_id`と`user_prize_id`の双方へRESTRICT FKを
持つ形で追加する。架空RelationやFKなしTableは作成しない。

## Payment Success

Lock順はProvider Event、Payment、Wallet、Point Lot／Ledgerである。Payment成功、
paid／free Lot、Wallet、Point Operation／Ledger、`payment_point_grants`、Audit、
Outboxを同じDB Transactionで確定する。同じPaymentの再処理は同じGrantを返す。

購入額とpaid Pointは`1 JPY = 1 paid point`で一致し、Bonusはfree Pointへ分離する。
正本はfree Pointの期限必須だけを定め、購入Bonusの具体的な期限日数を定めていない。
そのため`V2_PURCHASE_BONUS_EXPIRY_DAYS`の明示設定を必須とし、未設定時はFail Closedに
する。Productionへ推測値を持ち込まない。

## Refund

V2.0は成功Paymentに対する全額Refundを1回だけ扱う。Payment由来paid／free Lotが
全額未使用、未失効、未予約の場合だけ予約する。予約、Wallet／Lot予約残高、
Adjustment、Provider Refund Outboxを同じTransactionで作成する。

Provider結果不明時は予約を維持し、明確な失敗時だけ解放する。成功時は新しい
Point Operation／Ledgerで取消し、予約をconsumeする。通知失敗はRefund本体を
Rollbackしない。

## Chargeback

取消順は対象Paymentのpaid、その他paid FIFO、対象Paymentのfree Bonus、その他free、
paid不足分に対する残りfreeである。残額はShortfallとして記録し、Wallet／LotやLedger
を負数化しない。free Bonusをpaid Lotから取り消さない。Chargeback Reversalは
`manual_review`に固定し、自動Restorationしない。

## Audit And Outbox

Payment成功、Refund、Chargeback、ReversalをMIG-042のHash Chain Auditへ接続する。
外部処理要求はTransactional Outboxへ保存する。Password、Token、Full Email、Raw
Session ID、Card Data、Provider Secret、不要なPIIはAudit／Outboxへ含めない。

## Verification

V2 DB操作は`/etc/oripa-v2/dev.env`と`scripts/db/v2_database.py`のGuardだけを使用する。
V1 Migration 40件、V1 Runtime、本番DB、Redis、Storage、Archive Refは変更しない。

## Deferred Scope

fincode Sandbox実通信／Activation、Storefront Payment UI、Admin Payment History UI、
Refund／Chargeback Provider接続、Production Enable／Commercial Gateは後続Taskで実装する。
Activation前は`FINCODE_ENABLED=false`でFail Closedする。
