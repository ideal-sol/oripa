# Refund / Chargeback Design

## Purpose

返金・チャージバック処理は、決済状態、ポイント残高、景品配送、監査ログ、通知、売上管理を一貫して更新するための v1.6 追加設計である。

この文書は実装前調査に基づく設計であり、本作業ではコード、Migration、DB、Docker、依存関係は変更しない。

## Source Of Truth

優先順位:

1. 人間による最新の明示決定
2. `docs/md/spec_v1.5.1.md`
3. `docs/md/spec_v1.6_draft.md`
4. `docs/design/SALES_MANAGEMENT_DESIGN.md`

今回の人間決定:

- 通常返金は、対象決済で付与されたポイントが全額未使用の場合のみ可能。
- 対象ポイントが1ptでも使用済みなら、ユーザー問い合わせによる通常返金は不可。
- チャージバックは通常返金とは別扱い。
- チャージバック時は対象payment由来lotだけを取り消さず、ユーザーの現在保有ポイント全体から減算する。
- 有償購入分は有償ポイントから優先取消し、不足分は無償ポイントから取消す。
- 購入時付与された無償ポイントも取消対象。ただし無償ポイント取消は無償ポイントからのみ行う。
- 残高不足分はshortfallとして記録する。
- チャージバック発生時は未発送景品を一旦holdする。
- 管理者確認後、相殺済みで問題なければhold解除可能。
- 発送済み景品は返送依頼対象として記録し、ユーザーへ返送依頼メールを送信する。
- 抽選結果、draw_sequence_number、sold_count、won_countは巻き戻さない。
- 売上管理では、refunded_at / chargeback_at により返金額・チャージバック額へ反映する。

## Current Implementation Summary

### Payment

| Area | Current state |
| --- | --- |
| `payments` | `status`, `amount`, `paid_point_amount`, `free_point_amount`, `paid_at`, `refunded_at`, `chargeback_at`, `metadata` を保持 |
| `PaymentStatus` | `pending`, `succeeded`, `failed`, `canceled`, `refunded`, `chargeback` |
| `PaymentPointGrantService` | 決済成功時にpurchase由来の有償/無償ポイントを付与。二重付与防止あり |
| `PaymentStatusService` | `refunded` / `chargeback` へ状態更新可能。現状はポイント取消未実装でmetadataにpending記録 |
| `PaymentWebhookService` | `payment.succeeded`, `payment.failed`, `payment.canceled` のみ対応。refund/chargeback webhook未対応 |
| Admin Payment API | `/admin/api/payments/{payment}/refund`, `/chargeback` が存在。現状はステータス更新中心 |

### Points

| Area | Current state |
| --- | --- |
| `wallets` | `paid_balance`, `free_balance` は非負制約あり |
| `point_lots` | `point_type`, `granted_amount`, `remaining_amount`, `source_type`, `source_id`, `expire_at` を保持 |
| `point_ledgers` | `point_lot_id`, `point_type`, `ledger_type`, `amount`, `balance_after`, `related_type`, `related_id` を保持 |
| `PointLedgerType` | `purchase`, `grant`, `spend`, `expire`, `compensation`, `cancel`, `exchange` |
| `PointLotService` | 付与専用。purchase paidはexpire_at null、purchase bonus freeはexpire_atあり |
| `PointConsumptionService` | 通常消費専用。無償優先、free内は期限近い順、有償はFIFO |
| Admin Point Adjustment | 管理減算は通常消費Serviceを使用。返金/CB用の取消ルールとは異なる |

### Shipping / Prizes

| Area | Current state |
| --- | --- |
| `user_prizes.status` | `stored`, `shipping_requested`, `shipped`, `converted`, `expired` |
| `shipping_items.status` | `requested`, `packing`, `shipped`, `delivered`, `returned`, `canceled` |
| `ShippingRequestService` | 景品単位配送申請。stored景品のみ申請可能 |
| `AdminShippingItemController` | 景品単位の配送状態更新。hold/返送依頼状態は未実装 |
| `UserPrizeExchangeService` | stored景品を無償ポイントへ交換 |

### Notification / Audit / Sales

| Area | Current state |
| --- | --- |
| `audit_logs` | 管理操作の監査記録あり |
| Discord | 問い合わせ、配送申請、ポイント購入成功通知あり |
| Mail | メール認証、パスワード再設定、問い合わせ受付/返信あり |
| Sales Management | `paid_at`決済一覧、`refunded_at`/`chargeback_at`発生日別調整一覧を表示済み |

## Core Concepts

### Normal Refund

ユーザー問い合わせなどで行う通常返金。

原則:

- 対象Paymentは `succeeded` のみ。
- 対象Payment由来のpurchaseロットが全額未使用の場合のみ返金可能。
- 判定対象は `point_lots.source_type = purchase` かつ `source_id = payments.id`。
- paid/freeどちらか1ptでも使用済みなら通常返金不可。
- 返金可能な場合は対象Payment由来ロットのみを0にし、walletから同額を減算する。
- `payments.status = refunded`、`refunded_at = now()`。
- `point_ledgers.ledger_type = cancel` で取消台帳を残す。
- shortfallは発生しない想定。通常返金でshortfallが必要な状態は仕様上返金不可。
- 景品holdや返送依頼は通常返金では原則発生しない。通常返金はポイント未使用が前提のため、抽選済みではない。

### Chargeback

決済会社・カード会社等からのチャージバック。

原則:

- 対象Paymentは原則 `succeeded`。冪等再実行として既に`chargeback`なら同じ処理結果を返す。
- 通常返金と異なり、対象Payment由来ロットだけを取り消すのではない。
- ユーザーの現在保有ポイント全体から取消す。
- 先に対象Paymentの `paid_point_amount` と `free_point_amount` を確定する。
- `paid_point_amount` は現在保有している有償ポイントから優先取消する。
- `free_point_amount` は現在保有している無償ポイントからのみ取消する。
- `paid_point_amount` の有償取消で不足が残る場合だけ、`free_point_amount` 取消後に残っている無償ポイントから不足分を取消する。
- `free_point_amount` を有償ポイントから取消してはいけない。
- 残高不足分はshortfallとして記録し、walletをマイナスにしない。
- `payments.status = chargeback`、`chargeback_at = now()`。
- 必要に応じてユーザーを `suspended` にする。現行 `PaymentStatusService::markChargeback()` は既にsuspendを行う。
- 対象ユーザーの未発送景品は一旦すべてholdする。
- 管理者確認後、ポイント相殺済みで問題ないと判断できればhold解除可能にする。
- 発送済み/配送中/配達済み景品は返送依頼対象として記録し、ユーザーへ返送依頼メールを送信する。
- 抽選結果、連番、在庫、売上数は巻き戻さない。

## Proposed Data Model

### `payment_reversals`

返金・チャージバック処理の親レコード。

Phase 1 implementation note:

- `payment_reversals.payment_id` is unique in the initial implementation.
- If future partial refunds, multiple adjustments, or chargeback reversal restoration require multiple rows per payment, remove this unique constraint in a separate approved migration.
- Phase 1 adds schema, models, enum values, and relations only. Refund/chargeback execution services and APIs are implemented in later phases.

Candidate columns:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `payment_id` | FK payments | unique推奨。1決済1最終処理 |
| `user_id` | FK users | 検索用 |
| `admin_user_id` | FK admin_users nullable | 管理画面手動処理者 |
| `type` | string | `refund` / `chargeback` |
| `status` | string | `pending`, `completed`, `failed`, `canceled`, `review_required` |
| `reason` | text nullable | 管理者理由、プロバイダ理由 |
| `payment_amount` | integer | 決済金額snapshot |
| `paid_point_amount` | integer | 対象Paymentの有償付与snapshot |
| `free_point_amount` | integer | 対象Paymentの無償付与snapshot |
| `paid_reversed_amount` | integer | 実際に取消した有償ポイント |
| `free_reversed_amount` | integer | 実際に取消した無償ポイント |
| `shortfall_paid_amount` | integer | 有償購入分で取消できなかった額 |
| `shortfall_free_amount` | integer | 購入ボーナス無償分で取消できなかった額 |
| `occurred_at` | timestamp | refunded_at / chargeback_at と同じ基準 |
| `metadata` | json nullable | provider event id, webhook payload excerpt等 |
| `created_at`, `updated_at` | timestamps |  |

Indexes:

- unique `payment_id`
- index `type, status, occurred_at`
- index `user_id, created_at`

### `payment_reversal_point_entries`

どのロットからいくら取り消したかの明細。

Candidate columns:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `payment_reversal_id` | FK payment_reversals |
| `payment_id` | FK payments |
| `user_id` | FK users |
| `point_lot_id` | FK point_lots nullable | shortfall行はnull可 |
| `point_ledger_id` | FK point_ledgers nullable | 作成したcancel ledger |
| `point_type` | string | `paid` / `free` |
| `bucket` | string | `paid_purchase_from_paid`, `free_bonus_from_free`, `paid_purchase_shortfall_from_free`, `shortfall` |
| `amount` | integer | 実際に取消した正数 |
| `shortfall_amount` | integer | 不足分 |
| `created_at` | timestamp |

### `payment_reversal_prize_actions`

チャージバック時の景品hold/返送依頼記録。

Candidate columns:

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigserial | PK |
| `payment_reversal_id` | FK payment_reversals |
| `user_prize_id` | FK user_prizes |
| `shipping_item_id` | FK shipping_items nullable |
| `action_type` | string | `hold`, `return_requested`, `hold_released`, `no_action` |
| `previous_user_prize_status` | string nullable |
| `previous_shipping_item_status` | string nullable |
| `status` | string | `pending`, `completed`, `released`, `canceled` |
| `note` | text nullable |
| `mail_sent_at` | timestamp nullable |
| `mail_last_error` | text nullable |
| `mail_last_attempted_at` | timestamp nullable |
| `discord_last_error` | text nullable |
| `discord_last_attempted_at` | timestamp nullable |
| `created_at`, `updated_at` | timestamps |

### Status Extension Policy

side tableだけでhold/返送依頼を管理する方式は推奨しない。

推奨方針として、既存statusにも以下を追加する。

- `user_prizes.status = held`
- `shipping_items.status = hold`
- `shipping_items.status = return_requested`

理由:

- 既存の発送依頼APIは `user_prizes.status = stored` の景品だけを対象にする。
- 既存のポイント変換APIも `user_prizes.status = stored` の景品だけを対象にする。
- side tableだけでholdを持つと、既存APIがside tableを見ない場合に発送依頼・ポイント変換をすり抜けるリスクがある。
- `user_prizes.status = held` に移すことで、既存guardでも自然に操作不可になる。
- `shipping_items.status = hold` / `return_requested` に移すことで、管理画面と配送処理で状態が明確になる。

補足:

- Keep `shipping_requests` aggregate status unchanged where possible.
- `payment_reversal_prize_actions` は、hold/return_requestedの理由、元状態、解除状態、通知結果を記録する監査用side tableとして使う。

## Proposed Services

### `RefundEligibilityService`

Responsibilities:

- Confirm Payment can be normally refunded.
- Lock payment and purchase lots.
- Ensure all purchase lots from payment are fully unused:
  - `remaining_amount === granted_amount`
  - include both paid and free purchase lots.
- Return clear reason if not eligible.

### `PaymentRefundService`

Responsibilities:

- Execute normal refund in a DB transaction.
- Use `RefundEligibilityService`.
- Set payment `status=refunded`, `refunded_at=now()`.
- Set target payment purchase lots `remaining_amount=0`.
- Decrease wallet paid/free balances by target lot remaining amounts.
- Create `point_ledgers` with `ledger_type=cancel`, `related_type=payment_refund`, `related_id=payment_reversal.id`.
- Create `payment_reversals` and point entry rows.
- Record audit log.
- Send Discord admin notification.

### `ChargebackReversalService`

Responsibilities:

- Execute chargeback in a DB transaction.
- Lock payment, user wallet, related point lots, and relevant user prizes/shipping items.
- Set payment `status=chargeback`, `chargeback_at=now()`.
- Cancel points by rule:
  - confirm `paid_point_amount` and `free_point_amount`.
  - cancel `paid_point_amount` from current paid point lots first.
  - cancel `free_point_amount` from current free point lots only.
  - if `paid_point_amount` still has a remainder, cancel that remainder from remaining free point lots.
  - record shortfall when not enough.
- Create cancel ledgers for every actual deduction.
- Create `payment_reversals`, point entries, prize actions.
- Suspend user or mark review required according to policy.
- Hold未発送景品.
- Mark発送済み景品 as return requested.
- Record audit log.
- Send Discord admin notification.
- Queue or send返送依頼メール.

### `PointReversalService`

Responsibilities:

- Low-level point cancellation.
- Supports strategies:
  - exact payment lots only for normal refund.
  - current paid lots first for chargeback paid purchase cancellation.
  - current free lots only for chargeback free bonus cancellation.
  - remaining current free lots for paid purchase shortfall fallback.
- Never allows wallet negative balances.
- Produces shortfall output.
- Creates point ledgers.

### `ChargebackPrizeActionService`

Responsibilities:

- Identify all unshipped prizes currently owned by the chargeback target user.
- Confirmed rule: all unshipped prizes for the target user are held once, regardless of whether they were acquired before or after the target payment.
- Hold対象:
  - `user_prizes.status = stored`
  - `shipping_items.status = requested` / `packing`
- Return request対象:
  - `shipping_items.status = shipped` / `delivered`
  - `user_prizes.status = shipped`
- Do not touch:
  - already converted prizes, unless business decides converted points must be included in point shortfall analysis.
  - expired prizes.

## API Design

### Admin APIs

Candidate endpoints:

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/admin/api/payments/{payment}/refund-eligibility` | 通常返金可否と理由 |
| POST | `/admin/api/payments/{payment}/refund` | 通常返金実行。既存routeを拡張 |
| POST | `/admin/api/payments/{payment}/chargeback` | チャージバック実行。既存routeを拡張 |
| GET | `/admin/api/payment-reversals` | 返金/CB処理履歴 |
| GET | `/admin/api/payment-reversals/{paymentReversal}` | 詳細 |
| POST | `/admin/api/payment-reversals/{paymentReversal}/release-holds` | hold解除 |
| POST | `/admin/api/payment-reversal-prize-actions/{action}/mark-returned` | 返送確認 |

### Webhook

Future provider webhook events:

- `payment.refunded`
- `payment.chargeback.created`
- `payment.chargeback.reversed`

Webhook processing must be idempotent by provider event id.

Current mock/provider:

- Current `PaymentWebhookService` only supports succeeded/failed/canceled.
- Refund/chargeback webhook support is future work and should call the same domain Services.

## Admin UI Design

### Payment Detail / Sales Management

Add actions:

- 返金可否チェック
- 通常返金実行
- チャージバック登録
- 返金/CB処理履歴へのリンク

Display:

- Payment status
- paid/free granted amount
- paid/free remaining amount from payment-origin lots
- refund eligibility result
- reversal status and shortfall
- held prizes count
- return requested prizes count

### Reversal Detail

Display:

- Payment and user info
- Type and status
- Point cancellation detail
- Shortfall
- Affected prizes
- Mail/Discord/audit status
- Hold release button

### Shipping / User Detail

Display:

- Held prizes with reason.
- Return requested shipped prizes.
- Link to payment reversal.

## Normal Refund Flow

1. Admin opens payment detail.
2. System checks refund eligibility.
3. If any payment-origin lot has been used, return 422 with reason.
4. If eligible, admin submits refund with reason.
5. DB transaction:
   - lock payment
   - lock payment-origin lots
   - lock wallet
   - create `payment_reversals(type=refund)`
   - create cancel ledgers
   - set lots remaining to 0
   - decrease wallet
   - set payment refunded
6. Record audit log.
7. Notify Discord.
8. Sales management reflects refund via `refunded_at`.

## Chargeback Flow

1. Provider webhook or admin manually records chargeback.
2. DB transaction:
   - lock payment
   - lock user and wallet
   - create `payment_reversals(type=chargeback)`
   - confirm target `paid_point_amount`
   - confirm target `free_point_amount`
   - deduct `paid_point_amount` from current paid point lots first
   - deduct `free_point_amount` from current free point lots only
   - if `paid_point_amount` still remains, deduct that remaining paid target from remaining free point lots
   - record any shortfall
   - set payment chargeback
   - suspend user or mark review required
   - hold unshipped prizes
   - mark shipped prizes for return request
3. Commit transaction.
4. Send Discord notification.
5. Send return request mail for shipped prizes.
6. Admin reviews.
7. If resolved, admin releases holds.
8. Sales management reflects chargeback via `chargeback_at`.

## Point Cancellation Rules

### Normal Refund

- Source scope: `point_lots.source_type=purchase`, `source_id=payment.id`.
- Eligibility: every source lot must be fully unused.
- Cancellation:
  - paid purchase lots cancel paid wallet only.
  - free purchase bonus lots cancel free wallet only.
- No shortfall allowed.

### Chargeback

Target amounts:

- Paid target = `payments.paid_point_amount`
- Free bonus target = `payments.free_point_amount`

Order:

1. Cancel paid target from current paid lots.
2. Cancel free bonus target from current free lots only.
3. If paid target remains after step 1, cancel the remaining paid target from remaining current free lots.
4. Remaining paid target or free bonus target becomes shortfall.

Bucket values:

- `paid_purchase_from_paid`: `paid_point_amount` canceled from paid point lots.
- `free_bonus_from_free`: `free_point_amount` canceled from free point lots.
- `paid_purchase_shortfall_from_free`: remaining `paid_point_amount` canceled from free point lots after free bonus cancellation.
- `shortfall`: amount that could not be canceled.

Lot order:

- Paid lots: FIFO by `granted_at`, `id`.
- Free lots: nearest expiry first, then `granted_at`, `id`.

Ledger:

- Use `PointLedgerType::Cancel`.
- `related_type`: `payment_reversal`
- `related_id`: `payment_reversals.id`
- `description`: include refund/chargeback and payment id.

## Prize Hold / Return Rules

### Hold

対象:

- 対象ユーザーの未発送景品すべて。
- `user_prizes.status=stored`
- `shipping_items.status=requested` or `packing`

Action:

- set `user_prizes.status=held`.
- set `shipping_items.status=hold`.
- create `payment_reversal_prize_actions(action_type=hold)`.

解除:

- Admin can release hold after confirming points have been offset and there is no operational risk.
- Restore previous status from `payment_reversal_prize_actions`.

### Return Request

対象:

- `shipping_items.status=shipped` / `delivered`
- `user_prizes.status=shipped`

Action:

- create `payment_reversal_prize_actions(action_type=return_requested)`.
- set `shipping_items.status=return_requested`.
- send user email.
- keep audit log.

## Sales Management Impact

- Existing monthly/daily sales APIs already use:
  - gross by `paid_at`
  - refund by `refunded_at`
  - chargeback by `chargeback_at`
- Refund/chargeback processing must set these timestamp columns.
- Sales management should not read point reversal internals for sales totals.
- Future enhancement can show reversal detail links from adjustment rows.

## Notifications

通知はDBトランザクションcommit後に送信する。

Rules:

- Discord通知、返送依頼メール、その他外部通知の失敗でDB処理をrollbackしない。
- 通知失敗は `payment_reversals.metadata` または `payment_reversal_prize_actions` に記録する。
- 管理画面から通知再送できるようにする。
- 再送APIは冪等にし、送信済みのものを重複送信しないようにする。

### Discord

Add formatter/service methods:

- `notifyPaymentRefunded(PaymentReversal $reversal)`
- `notifyPaymentChargeback(PaymentReversal $reversal)`
- `notifyPaymentReversalShortfall(PaymentReversal $reversal)`

### Mail

Add Mailable:

- `ChargebackReturnRequestMail`

Candidate content:

- chargeback notice
- affected shipped prizes
- return instructions
- contact route

Normal refund completion mail is optional and should be decided by operations.

## Audit

Record actions:

- `admin.payment.refund_eligibility_checked`
- `admin.payment.refunded`
- `admin.payment.chargeback`
- `admin.payment_reversal.hold_released`
- `admin.payment_reversal.return_requested`

Audit metadata should include:

- before/after payment status
- point cancellation summary
- shortfall
- affected prize IDs

## Implementation Phases

1. Migration and Models for reversal records.
2. PointReversalService and RefundEligibilityService.
3. PaymentRefundService for normal refund.
4. ChargebackReversalService and prize actions.
5. Admin API updates.
6. Admin UI updates in stable `admin-dashboard.tsx`.
7. Mail/Discord notifications.
8. Provider webhook integration when production payment provider is selected.

## Explicit Non-Goals

- Do not roll back draw_results, draw_sequence_number, sold_count, or won_count.
- Do not make wallet balances negative.
- Do not implement production payment provider connection in this phase.
- Do not restart admin route-split refactoring.
- Do not use point adjustment as refund processing.
