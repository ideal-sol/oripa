# Refund / Chargeback Migration Plan

## Position

返金・チャージバック機能は v1.6 の重要な決済後処理として設計する。

この文書作成時点ではMigration作成・実行は行わない。

## Current Schema Findings

Existing useful columns:

- `payments.status`
- `payments.refunded_at`
- `payments.chargeback_at`
- `payments.metadata`
- `point_lots.source_type`
- `point_lots.source_id`
- `point_lots.granted_amount`
- `point_lots.remaining_amount`
- `point_ledgers.ledger_type`
- `point_ledgers.related_type`
- `point_ledgers.related_id`
- `wallets.paid_balance`
- `wallets.free_balance`
- `user_prizes.status`
- `shipping_items.status`
- `audit_logs.metadata`

Missing:

- Formal refund/chargeback process record.
- Point reversal detail record.
- Shortfall record.
- Prize hold / return request record.
- Status values for held or return requested prizes/items.
- Notification failure/retry tracking for Discord and return request mails.

## Phase 0: Design Only

Status: current task.

Actions:

- Investigate existing schema/code.
- Create design, impact, migration plan, test plan.

No changes:

- Code
- Migration
- DB
- Docker
- Dependencies

## Phase 1: Add Reversal Tables

Status:

- Implemented as a migration file in Phase 1.
- The migration has not been executed by this documentation update.
- `payment_reversals.payment_id` is unique in the initial implementation.
- If future partial refunds or multiple payment adjustments become required, remove the unique constraint in a separate approved migration.

Candidate migration:

- `2026_06_30_000001_create_payment_reversal_tables.php`

Create:

- `payment_reversals`
- `payment_reversal_point_entries`
- `payment_reversal_prize_actions`

### `payment_reversals`

Columns:

- `id`
- `payment_id` foreign key to `payments`
- `user_id` foreign key to `users`
- `admin_user_id` nullable foreign key to `admin_users`
- `type` string
- `status` string
- `reason` text nullable
- `payment_amount` integer
- `paid_point_amount` integer
- `free_point_amount` integer
- `paid_reversed_amount` integer default 0
- `free_reversed_amount` integer default 0
- `shortfall_paid_amount` integer default 0
- `shortfall_free_amount` integer default 0
- `occurred_at` timestamp nullable
- `metadata` json nullable
- timestamps

Constraints:

- `type IN ('refund', 'chargeback')`
- `status IN ('pending', 'completed', 'failed', 'canceled', 'review_required')`
- non-negative amount columns
- unique `payment_id` if only one final reversal is allowed per payment

Indexes:

- `payment_id`
- `user_id, created_at`
- `type, status, occurred_at`

### `payment_reversal_point_entries`

Columns:

- `id`
- `payment_reversal_id`
- `payment_id`
- `user_id`
- `point_lot_id` nullable
- `point_ledger_id` nullable
- `point_type`
- `bucket`
- `amount` integer default 0
- `shortfall_amount` integer default 0
- `created_at`

Constraints:

- `point_type IN ('paid', 'free')`
- `bucket IN ('paid_purchase_from_paid', 'free_bonus_from_free', 'paid_purchase_shortfall_from_free', 'shortfall')`
- `amount >= 0`
- `shortfall_amount >= 0`

Indexes:

- `payment_reversal_id`
- `point_lot_id`
- `point_ledger_id`
- `user_id, created_at`

### `payment_reversal_prize_actions`

Columns:

- `id`
- `payment_reversal_id`
- `user_prize_id`
- `shipping_item_id` nullable
- `action_type`
- `previous_user_prize_status` nullable
- `previous_shipping_item_status` nullable
- `status`
- `note` text nullable
- `mail_sent_at` nullable timestamp
- `mail_last_error` nullable text
- `mail_last_attempted_at` nullable timestamp
- `discord_last_error` nullable text
- `discord_last_attempted_at` nullable timestamp
- timestamps

Constraints:

- `action_type IN ('hold', 'return_requested', 'hold_released', 'no_action')`
- `status IN ('pending', 'completed', 'released', 'canceled')`

Indexes:

- `payment_reversal_id`
- `user_prize_id`
- `shipping_item_id`
- `action_type, status`

## Phase 2: Extend Status Constraints

Recommended migration:

- Included in `2026_06_30_000001_create_payment_reversal_tables.php` for Phase 1 implementation.

Changes:

- Drop/recreate `user_prizes_status_check` to include `held`.
- Drop/recreate `shipping_items_status_check` to include `hold`, `return_requested`.

Do not change `shipping_requests.status` unless required.

Phase 1 implementation:

- `user_prizes_status_check` includes `held`.
- `shipping_items_status_check` includes `hold` and `return_requested`.
- `shipping_requests_status_check` is unchanged.
- Rollback is guarded and fails clearly if `held`, `hold`, or `return_requested` rows still exist.

Rationale:

- `user_prizes.status=held` prevents existing shipping/exchange services because they only operate on `stored`.
- Item-level status preserves景品単位配送.
- side tableだけでは、既存発送依頼・ポイント変換APIがside tableを見ない場合にすり抜ける可能性がある。
- statusを追加することで、既存guardでもhold中景品を操作不可にできる。

Rollback:

- Only possible after all `held`, `hold`, `return_requested` records are resolved or migrated back.
- Migration should guard rollback by checking no rows remain in new statuses.

## Phase 3: Notification Retry Support

Notification sending runs after DB commit.

Required persistence:

- `payment_reversals.metadata` should be able to store Discord notification status.
- `payment_reversal_prize_actions.mail_sent_at` already captures successful mail sending.
- Add retry metadata if needed:
  - `mail_last_error`
  - `mail_last_attempted_at`
  - `discord_last_error`
  - `discord_last_attempted_at`

Do not rollback refund/chargeback DB operations when Discord or mail sending fails.

Admin UI should be able to retry failed notifications.

## Phase 4: Optional Provider Webhook Idempotency Table

If production provider sends multiple refund/chargeback event types that cannot be safely represented by `payments.webhook_event_id`, add:

- `payment_webhook_events`

Columns:

- `provider`
- `event_id`
- `event_type`
- `payment_id`
- `processed_at`
- `payload_hash`
- `metadata`

This is optional until provider is selected.

## Phase 5: Backfill

Existing `payments.status=refunded` or `chargeback` may exist from demo/admin operations.

Backfill choices:

1. Do not backfill reversal tables. Treat existing rows as legacy/manual status changes.
2. Create `payment_reversals` with `metadata.legacy=true` and no point entries.

Recommendation:

- For production readiness, run a read-only report first.
- If real data exists, create legacy reversal rows to make admin screens consistent.
- Do not attempt automatic point reversal for already-refunded/chargeback rows without human review.

## Phase 6: Model Layer

Add Models:

- `PaymentReversal`
- `PaymentReversalPointEntry`
- `PaymentReversalPrizeAction`

Update Models:

- `Payment`: relation to reversal(s)
- `User`: relation to payment reversals
- `PointLot`: relation to reversal point entries
- `PointLedger`: relation to reversal point entries
- `UserPrize`: relation to reversal prize actions
- `ShippingItem`: relation to reversal prize actions

## Phase 7: Deployment / Migration Execution Rules

Before running migration:

- Confirm maintenance window if production data exists.
- Backup DB.
- Confirm no long-running draw/payment operations.
- Run migration in staging first.
- Confirm constraints on enum-like statuses.

Commands:

- Do not run `php artisan migrate --force` without human approval.
- Do not run destructive DB commands.

## Phase 8: Rollback Plan

Safe rollback before data usage:

- Drop new reversal tables.
- Restore status constraints if no new statuses are used.

Unsafe rollback after data usage:

- Requires resolving all hold/return statuses.
- Requires preserving reversal audit records externally.
- Do not drop reversal tables containing production records without export and explicit approval.

## Index Considerations

Likely useful:

- `payments(status, refunded_at)`
- `payments(status, chargeback_at)`
- `point_lots(user_id, point_type, remaining_amount)`
- `point_lots(source_type, source_id)`
- `payment_reversals(type, status, occurred_at)`
- `payment_reversal_prize_actions(action_type, status)`

Existing:

- `point_lots(source_type, source_id)` exists.
- `point_lots(user_id, point_type, expire_at)` exists but not optimized for paid-first chargeback cancellation.

Index additions should be based on query plan or observed slowness, except new table core indexes.

## No Migration In This Task

This planning task does not create migrations. Actual Migration creation should begin only after this design is approved.
