# V2 Point Model Foundation

## Status

MIG-043で追加する非Production Alpha Foundationである。Public／Admin API、Point購入、
Payment、返金、Draw、Production Deploymentは含まない。

## Responsibility

Laravel Domain層とPostgreSQLがPoint残高の正本である。Redis、Frontend、Admin UI、
Client PackageはPoint残高や消費順を決定しない。

## Schema

- `wallets`: Userごとのpaid／free現在残高と予約残高。負数と予約超過を拒否する。
- `point_operations`: 一意な`business_key`を持つimmutableな業務操作。
- `point_lots`: paid／freeを分離した付与単位。paidは無期限、freeは期限必須。
- `point_ledger_entries`: immutableな増減明細。訂正は新Operationで記録する。
- `point_adjustments`: 管理調整の申請・承認状態。残高変更APIは未実装。
- `point_balance_snapshots`: JST日付のLedger Cutoff集計。
- `point_reconciliation_runs`: 照合実行状態。
- `point_reconciliation_discrepancies`: append-onlyな不一致。自動修復しない。
- `idempotency_records`: KeyとRequestのHash、処理状態、安全な結果参照。

すべてのPoint値は`bigint`整数である。APIへ内部`id`を公開せず、公開識別子には
Application生成のUUIDv7を使用する。`tenant_id`は使用しない。

## Transaction And Lock

Point消費はWalletを最初に`SELECT ... FOR UPDATE`し、その後LotをLockする。
`SKIP LOCKED`は使用しない。同種複数Rowは決定的な順序でLockする。

```text
free: expire_at ASC, granted_at ASC, id ASC
paid: granted_at ASC, id ASC
```

Wallet、Lot、Operation、Ledger、Idempotency、Auditは同じDB Transactionで確定する。
SQLSTATE `40001`と`40P01`だけを最大3回、短いJitter付きでRetryする。

## Grant Boundary

MIG-043の通常Serviceが生成できるPointはfreeだけである。paid Pointの通常生成は
MIG-044で成功済みPaymentと同じTransaction境界を実装した後に限る。管理paid調整は
Owner専用Permission、Fresh MFA、理由、Audit、Idempotencyを要求するSchema／Permission
境界だけを持ち、実行APIは作成しない。Ownerによる自己承認を禁止しない。

## Point Lot Reservations

`point_lot_reservations`はMIG-043では作成しない。正本上必須の
`payment_adjustment_id`がMIG-044の`payment_adjustments`へ依存するためである。
MIG-044では次を同一Migration系列で追加する。

- `point_lot_id`から`point_lots`へのRESTRICT Foreign Key
- `payment_adjustment_id`から`payment_adjustments`へのRESTRICT Foreign Key
- 正数`amount`と`active`／`consumed`／`released`状態Constraint
- Active Reservation合計とLot／Wallet予約残高の同一Transaction整合
- Provider結果不明時に自動解放しない境界

## Snapshot

Snapshot Dateの翌日00:00 JSTをCutoffとし、`occurred_at < cutoff`のLedgerだけから
残高と当日増減を再構築する。3月31日と9月30日は`is_base_date = true`である。
同日再生成は前回Checksumと新しいGeneration RunをAuditへ残す。現在残高を任意の
過去日へコピーしない。

MIG-043ではPoint Reservationが存在しないためSnapshot予約残高は0である。MIG-044は
Reservation履歴を正本に沿ってSnapshotへ統合しなければならない。

## Reconciliation

Wallet、Lot残量合計、Ledger合計をpaid／free別に照合する。不一致は
`point_reconciliation_discrepancies`とAuditへ記録し、自動補正しない。修復は調査と
承認済みCorrection Operationを別Taskで実施する。

## Audit And Privacy

Wallet初期化、free付与、消費、free失効、Snapshot、ReconciliationをMIG-042の
Hash Chain Auditへ接続する。Password、Token、Full Email、Raw Session ID、実PII、
不要なMetadataを保存しない。

## Verification

V2 DB操作は`/etc/oripa-v2/dev.env`と`scripts/db/v2_database.py`のGuardだけを使用する。
V1 Migration、V1本番DB、Production DBへ適用しない。
