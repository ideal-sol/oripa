# MIG-062Y Coin Expiry Core

## Task

- Task ID: MIG-062Y
- Issue: #291
- Risk: R4
- Base SHA: `1bd2cb5015ca50b1798bca4a83f9d6d5125e5dc6`
- Branch: `feat/MIG-062Y-coin-expiry-core`
- Worktree: `/var/www/oripa-worktrees/MIG-062Y`
- Integration Lock: 取得済み。Closeoutで解放する。
- Migration Allocation: `000054`を取得しmaterialize後にAllocation Lockを解放済み。
- Artifact／Preview Lock: 取得なし。
- Final Head／PR／Squash SHA: Closeout時に確定する。

## Summary

- C1a以後のpaid／free全Grantへ、共通`V2CoinExpiryPolicy`によるGrant確定時刻のちょうど180日後を適用した。Payment paid／bonus、通常free Grant、LINE、Referral、Point Exchange、Admin Grant、Draw Point Backが同じPolicyを使う。
- 既存paid NULL Lot／paid Grant Adjustmentだけを`legacy_no_expiry=true`で明示し、期限をBackfillしない。既存free expiryは変更しない。新規Lot NULL、Legacy flag指定、新規Grant Adjustment NULL、expiry変更はDB Trigger／Constraintで拒否する。
- 通常Consume／Draw／Admin deductはpaid／free横断の`expires_at ASC NULLS LAST, granted_at ASC, id ASC`へ統一した。`operation_at == expires_at`は利用不可で、Worker前でもRead／Spend／Draw／QA preflight／新規Reservationから除外する。
- Expirationはpaid／freeの有限期限LotをWallet→ordered Lotの順でRow Lockし、Wallet／Lot／Ledger／Auditを同一Transactionで更新する。Reservation中Lotは飛ばし、release後も元期限を変更せず、次回Workerで失効する。
- Chargebackは通常FEFOへ統合せず、全LotをCanonical順で一度Lockした後に既存のorigin-first、paid取消優先、free取消、不足記録を維持する。Chargeback Reversalは既存どおりmanual reviewで自動Restoreしない。

## Migration

- Created: `apps/api/database/migrations-v2/2026_09_09_000054_add_v2_coin_expiry_core.php`
- Applied foundation Migrationは変更していない。
- `point_lots.legacy_no_expiry`、`point_adjustments.legacy_no_expiry`、`point_balance_snapshots.expired_paid_amount`を追加した。
- 全Lot FEFO partial index、Expiration candidate index、Lot／Adjustment expiry insert guard、expiry immutable guard、Snapshot paid expiry constraintを追加した。
- Task専用PostgreSQLでfresh apply、latest rollback／reapply、function残存状態からのfresh reapplyをPASSした。
- 適用前paid NULL Lot、finite free Lot、paid NULL Grant Adjustmentを実データとして作成後にupgradeし、paid NULLだけLegacy marker化、free expiry不変、新規Lot／Adjustment NULL拒否を確認した。
- Production／Preview Migrationは適用していない。RollbackはApplication rollback後に行い、C1a finite paid rowが存在する状態では旧制約へのdownがFail Closedする。

## Lock／Transaction／Retry

- Consumption、Draw、Admin deduct、Expiration、Reservation、Refund、Chargebackの競合範囲でWallet→LotをCanonical順とし、LotはFEFO stable orderでRow Lockする。
- 既存`V2PointTransactionRunner`、SQLSTATE `40001`／`40P01`限定、最大3回Retryを再利用する。`SKIP LOCKED`、独自分散Lock、無制限Retryは追加していない。
- 既存の負Wallet／負Lot／予約超過ConstraintとApplication guard、append-only Operation／Ledger／Reconciliation履歴保護は維持した。

## Snapshot／Reconciliation

- Snapshotへ`expired_paid_amount`を追加し、paid／free expire Ledgerを同じcutoff集計へ含めた。
- paid／free expiration後のWallet、Lot、Ledger rebuild、Snapshot、Reconciliation discrepancy 0をFocused testで確認した。
- GETはLot集計だけを読み、Expiration mutationを行わない。

## Reservation／Restore

- Reservation時にLot expiryを変更しない。有限期限Lotは`reserved_at < expire_at`だけ新規Reservation可能で、境界一致は拒否する。Legacy NULLは従来どおり予約可能である。
- `reserved_amount > 0`のLotをWorkerは失効しない。Reservation release後も元expiryを維持し、期限切れならRead残高へ戻さず、次回Workerが失効する。
- 自動Chargeback Reversal／新規180日付与は行わない既存manual review仕様を維持した。

## Policy Gate

- 人間の明示承認に基づき、旧paid NULL必須、free→paid固定Lock順、free-only expiration、Payment Bonus個別expiry設定必須をC1aへ置換した。
- 新Migration exact registration、Legacy marker、Lot／Adjustment新規NULL guard、expiry immutable guard、全Lot FEFO NULLS LAST、180日共通Policy、paid Snapshot、Wallet→Lot lock orderをMachine-readableに要求する。
- 負残高、Row Lock、`SKIP LOCKED`禁止、最大3回Retry、対象SQLSTATE、immutable history、Reconciliation non-repair、Payment／Chargeback safety gateは弱めていない。

## Verification Performed

- PHP syntax: 変更PHP全対象PASS。Python compile、`git diff --check` PASS。Local Policy Gate、Quality Gate（PHP 809 filesを含む）PASS。
- Policy Unit: Point／Payment／Identity／Database境界9 tests PASS。旧paid grant禁止、`SKIP LOCKED`禁止、新規NULL guard、NULLS LAST FEFO、Payment共通expiryのmutation testsを含む。
- Point Core: `PointModelFoundationTest` 16 tests／84 assertions PASS。180日、Legacy、全Lot FEFO、境界除外、Draw、paid／free expiration、idempotency、Snapshot、Reconciliation、Consume vs Expire、有限Retryを含む。
- Payment Core: `PaymentModelFoundationTest` 21 tests／90 assertions PASS。paid／free同一180日、Transaction rollback、Reservation境界、expiry維持、Worker skip、Chargeback paid優先／shortfall、Reversal manual reviewを含む。
- B2／Grant回帰: Admin Adjustment、Draw、QA Draw、Point Exchange／Prize Shipping、LINE、Referral、Current User historyの選択77 tests／874 assertions PASS。最終時刻変換修正後にDraw 22 tests／210 assertionsを再実行しPASS。
- Migration: fresh apply、latest rollback／reapply、残存functionを含む再fresh apply、Legacy実データupgrade、新規NULL Lot／Adjustment拒否PASS。
- Command discovery: `v2:points:expire`登録PASS。
- Focused API image buildだけを実行しPASS。Repository全Buildは実行していない。

## Failed Then Fixed

- 初回Point focused runはtest clock timezoneの境界fixture差1件を検出した。offset付きISO DB比較とAsia/Tokyo boundary fixtureへ修正後16 testsを再実行しPASSした。
- 初回Payment focused runはReservation中のglobal worker count期待と、C1a FEFO後のChargeback shortfall旧期待の2件を検出した。対象Lot状態と新しい消費前提を検証するassertionへ修正後21 testsを再実行しPASSした。
- Migration再freshはPostgreSQL function残存を検出した。`CREATE OR REPLACE FUNCTION`へ修正し、再fresh／rollback／reapply／Legacy upgradeをPASSした。
- 最終時刻変換修正後のDraw再実行は、初回が直接`docker run`したためTask DB credential不一致、次回がPHPUnit固定`oripa_test`未作成、作成直後がV2 Migration未適用でApplication実行前にFAILした。Task Composeで専用DBへV2 Migration 54件を適用後、Draw 22 tests／210 assertionsを再実行しPASSした。

## Verification Not Performed

- Task指示に従いLocal全Suite／全Buildは実行していない。
- Public Contract Shape変更がないためOpenAPI／Storefront Client／Testkit／Artifactは生成・変更・検証していない。
- Admin Presentation、表示名変更、7日以内表示、期間限定Bonus、Mail／Content、OPS／Cutover、Preview／Production Deployは実施していない。
- Required Checks、CodeQL、Dependency Review、fixed-head Fresh Self-review、Squash Merge、Issue／branch／worktree／Lock cleanupはFinal Head固定後に実施する。

## Review Findings

- Fresh Self-review前のApplication review: SEV-0 0、SEV-1 0。
- Remaining blocker: なし。GitHub lifecycleとfixed-head gate待ち。
