# MIG-063A Limited Bonus Domain Core

## Task

- Issue: #297
- Risk: R3
- Base SHA: `80fa6d7c7202e800ab4da50665c466cc63f893a8`
- Branch: `feat/MIG-063A-limited-bonus-domain-core`
- Worktree: `/var/www/oripa-worktrees/MIG-063A`
- Migration Allocation: `000055`（Allocation Lock取得後に採番）
- PR／Final Head／Squash SHA: Closeout時に確定する。

## Domain Core

- Exact Point Purchase Plan VersionへLimited Bonus CampaignのON/OFF、start、end、bonus amountを追加した。期間は`start < end`、amountは正数、同一Version内は`[start,end)`で重複禁止、adjacent許可である。
- Campaign create／updateはPoint Purchase Plan parent rowを先にLockし、Campaign rowをID順でLockする。DB triggerもVersion単位のtransaction advisory lockとoverlap checkを持ち、同時overlapは一方だけ成功する。
- Payment作成Transaction内で対象Versionの全Campaign設定をimmutable snapshotへ保存する。以後のCampaign変更は既存Payment／Grantへ影響しない。

## Canonical Success／Grant

- verified `payment.succeeded` ingressは`provider_occurred_at`必須で、UTC秒精度へ正規化して保存する。確定処理もevent type、署名検証、Payment紐付け、Canonical時刻を再検証する。
- `payments.succeeded_at`とstatus historyはprovider時刻を使用する。Campaign判定は`start <= succeeded_at < end`で、`received_at`と処理時`now()`は使用しない。Canonical欠落はFail Closedする。
- 通常freeとLimited Bonusを同一`payment_grant` Operation、単一`payment_point_grants`、単一free Lotへ合算する。Paymentへ確定Limited Bonus額を保存し、成功後の変更をDBで拒否する。
- 既存`V2CoinExpiryPolicy`を再利用し、paid／通常free／Limited BonusはGrant確定時刻＋180日である。snapshotのないLegacy PaymentはLimited Bonus 0で、遡及Grantしない。

## Refund／Chargeback

- RefundとChargebackの`required_free_amount`へ実際に確定・GrantしたLimited Bonusを含める。
- origin-first、paid取消優先、free取消、不足free記録、Chargeback Reversal manual reviewと自動Restore禁止は変更していない。

## Migration

- Created: `apps/api/database/migrations-v2/2026_09_10_000055_add_v2_limited_bonus_domain_core.php`
- Added: Campaign table、Payment snapshot table、`payments.limited_bonus_point_amount`、value／immutability／overlap guards。
- Task専用PostgreSQL: 55件fresh apply PASS、`000055` rollback／reapply PASS。
- Preview／ProductionへMigrationは適用していない。

## Verification

- Focused API: `LimitedBonusDomainCoreTest`、`PaymentModelFoundationTest`、`AdminPointPurchasePlanManagementTest`の合計36 tests／181 assertions PASS。
- Covered: start/end境界、ON/OFF、overlap／adjacent、concurrent overlap、Payment snapshot、設定変更後不変、provider／received時刻不一致、Canonical欠落、duplicate／replay、単一Grant、180日Expiry、Refund／Chargeback total、Reversal manual review、Legacy非遡及。
- Policy focused: 4 tests PASS。Local Policy Gate、変更PHP syntax、Python compile、`git diff --check` PASS。
- Focused API image build PASS。全Suite／Repository全BuildはTask指示により未実行。

## Scope／Impact

- API／Auth／Draw／Inventory／Infrastructure contract変更なし。Public／Admin HTTP、Admin UI、OpenAPI、Client、Site Schema、Testkit、Artifact、Storefront Repository、実Provider接続、Preview／Production Deployは変更・実行していない。
- Provider再設計は不要。現Repository内のverified event ingressでCanonical provider時刻を必須化できた。
- RollbackはApplication rollback後に`000055`をdownし、Campaign／snapshot／Payment確定額を削除する。Production data rollbackは未実施である。

## Review

- Pre-PR review: SEV-0 0、SEV-1 0。
- Final exact-head checks、fresh self-review、squash merge、branch／worktree／Lock cleanupはCloseoutで確定する。
