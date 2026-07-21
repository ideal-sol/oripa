# Refund / Chargeback Impact Review

## Scope

This review summarizes the impact of adding formal normal refund and chargeback processing.

No code, Migration, DB operation, Docker operation, dependency change, production payment connection, or admin refactor was performed for this document.

## Existing Files Investigated

### Payment

- `backend/database/migrations/2026_06_10_000003_create_payments_table.php`
- `backend/app/Models/Payment.php`
- `backend/app/Domain/Payment/Enums/PaymentStatus.php`
- `backend/app/Domain/Payment/Services/PaymentIntentService.php`
- `backend/app/Domain/Payment/Services/PaymentPointGrantService.php`
- `backend/app/Domain/Payment/Services/PaymentStatusService.php`
- `backend/app/Domain/Payment/Services/PaymentWebhookService.php`
- `backend/app/Http/Controllers/Api/PaymentController.php`
- `backend/app/Http/Controllers/Admin/Payment/AdminPaymentController.php`
- `backend/app/Http/Requests/Admin/UpdatePaymentStatusRequest.php`
- `backend/app/Http/Resources/PaymentResource.php`
- `backend/routes/admin.php`
- `backend/routes/api.php`

### Point

- `backend/database/migrations/2026_06_10_000002_create_point_tables.php`
- `backend/database/migrations/2026_06_10_000010_create_point_adjustments_table.php`
- `backend/app/Models/Wallet.php`
- `backend/app/Models/PointLot.php`
- `backend/app/Models/PointLedger.php`
- `backend/app/Models/PointAdjustment.php`
- `backend/app/Domain/Point/Enums/PointType.php`
- `backend/app/Domain/Point/Enums/PointLedgerType.php`
- `backend/app/Domain/Point/Enums/PointLotSourceType.php`
- `backend/app/Domain/Point/Services/PointLotService.php`
- `backend/app/Domain/Point/Services/PointConsumptionService.php`
- `backend/app/Domain/Point/Services/PointExpirationService.php`
- `backend/app/Http/Controllers/Admin/Point/AdminPointAdjustmentController.php`

### Prize / Shipping

- `backend/database/migrations/2026_06_10_000008_create_user_prizes_and_shipping_tables.php`
- `backend/database/migrations/2026_06_19_000001_add_delivery_fields_to_shipping_items.php`
- `backend/database/migrations/2026_06_19_000002_split_grouped_shipping_requests.php`
- `backend/app/Models/UserPrize.php`
- `backend/app/Models/ShippingItem.php`
- `backend/app/Models/ShippingRequest.php`
- `backend/app/Models/ShippingRequestHistory.php`
- `backend/app/Domain/Shipping/Enums/UserPrizeStatus.php`
- `backend/app/Domain/Shipping/Enums/ShippingRequestStatus.php`
- `backend/app/Domain/Shipping/Services/ShippingRequestService.php`
- `backend/app/Domain/Shipping/Services/UserPrizeExchangeService.php`
- `backend/app/Http/Controllers/Admin/Shipping/AdminShippingRequestController.php`
- `backend/app/Http/Controllers/Admin/Shipping/AdminShippingItemController.php`
- `backend/app/Http/Resources/UserPrizeResource.php`
- `backend/app/Http/Resources/ShippingItemResource.php`
- `backend/app/Http/Resources/ShippingRequestResource.php`

### User / Audit / Notification / Sales / Admin UI

- `backend/database/migrations/2026_06_10_000001_create_users_profiles_admins_and_sanctum_tables.php`
- `backend/app/Models/User.php`
- `backend/app/Models/AuditLog.php`
- `backend/app/Domain/Audit/Services/AuditLogService.php`
- `backend/app/Domain/Notification/Services/DiscordNotificationService.php`
- `backend/app/Domain/Notification/Services/AdminDiscordMessageFormatter.php`
- `backend/app/Mail/*`
- `backend/app/Domain/Admin/Services/SalesManagementReportService.php`
- `backend/app/Http/Controllers/Admin/Sales/AdminSalesManagementController.php`
- `frontend/src/app/admin-dashboard.tsx`
- `frontend/src/app/admin/[[...segments]]/page.tsx`

## Current DB / Enum / Status Findings

### Payment

- `payments.status` check constraint supports:
  - `pending`
  - `succeeded`
  - `failed`
  - `canceled`
  - `refunded`
  - `chargeback`
- `payments.refunded_at` and `payments.chargeback_at` already exist.
- `PaymentStatusService` sets these timestamps and marks chargeback users as `suspended`.
- `PaymentStatusService` does not reverse points, create shortfall records, hold prizes, request returns, or send emails.

### Point

- `wallets.paid_balance` and `wallets.free_balance` have non-negative DB constraints.
- `point_lots.remaining_amount` is non-negative and cannot exceed `granted_amount`.
- `point_lots.source_type` currently includes purchase/campaign/minimum_guarantee/compensation/exchange plus later referral/line_friend migrations.
- `PointLedgerType::Cancel` already exists and is suitable for reversal ledgers.
- Existing `PointConsumptionService` consumes free before paid. That is correct for draw spending but wrong for chargeback cancellation where paid-first is required.
- No `shortfall` table exists.

### User / Prize / Shipping

- `users.status` supports `active`, `suspended`, `withdrawn`.
- `user_prizes.status` supports `stored`, `shipping_requested`, `shipped`, `converted`, `expired`.
- `shipping_items.status` supports `requested`, `packing`, `shipped`, `delivered`, `returned`, `canceled`.
- No `held`, `hold`, or `return_requested` status exists.
- Existing shipping is item-level and should remain item-level.

### Sales

- Sales management already separates:
  - gross by `paid_at`
  - refund by `refunded_at`
  - chargeback by `chargeback_at`
- It will automatically reflect payment timestamp changes, as long as refund/chargeback services set `refunded_at` / `chargeback_at`.

## Impact By Area

### Payment Domain

Impact:

- Existing `PaymentStatusService` is insufficient and should either be replaced internally or call new refund/chargeback services.
- Existing admin routes can be preserved, but behavior must change from status-only to full domain processing.
- Webhook support must be extended later for provider refund/CB events.

Risk:

- If current `/refund` and `/chargeback` endpoints remain status-only, points and prizes become inconsistent.
- Existing metadata value `point_reversal=pending_manual_or_followup_process` indicates known gap.

### Point Domain

Impact:

- Need dedicated reversal service, not `PointConsumptionService`.
- Need shortfall persistence.
- Need robust DB locks around wallet and point lots.

Risk:

- Current wallet non-negative constraint prevents negative balances; shortfall must not be represented as negative wallet.
- Charging back by current balances rather than payment-origin lots can cancel unrelated free/paid points, so audit details must be very clear.

### Shipping / Prizes

Impact:

- Need explicit hold statuses plus a hold action table.
- Existing user prize operations only allow shipping/exchange for `stored`; if `held` is added, existing guards naturally block operations.
- Existing shipping transition rules will reject `hold` and `return_requested` unless enums, DB constraints, requests, and controllers are updated.

Risk:

- If hold is stored only in a side table and `user_prizes.status` remains `stored`, users may still request shipping/exchange unless all APIs check the side table.
- Therefore the recommended policy is to add `user_prizes.status=held`, `shipping_items.status=hold`, and `shipping_items.status=return_requested`.
- Adding statuses affects public/admin displays and existing transition validation.

### Admin UI

Impact:

- Current stable `admin-dashboard.tsx` must be extended, not route-split refactored.
- Payment detail/list should show refund eligibility and reversal state.
- User detail and shipping/prize sections should show holds and return requests.
- Sales management adjustment rows can link to reversal detail later.

Risk:

- `admin-dashboard.tsx` is large; changes should be small and isolated.
- Avoid adding heavy API calls to `refreshAll()`.

### Notification / Audit

Impact:

- Add Discord formatter methods for refund/chargeback/shortfall.
- Add Mailable for return request.
- Use `AuditLogService` for admin-triggered operations and hold releases.

Risk:

- DB transaction should commit before Discord/mail notifications are sent.
- Notification failures must not rollback the payment/point/prize DB operation.
- Notification failures should be recorded and exposed for admin retry.

## Additional Migration Candidates

Required or strongly recommended:

1. `create_payment_reversals_table`
2. `create_payment_reversal_point_entries_table`
3. `create_payment_reversal_prize_actions_table`

Likely required if using status fields:

4. extend `user_prizes_status_check` to include `held`
5. extend `shipping_items_status_check` to include `hold`, `return_requested`

Optional:

6. add `payments.refund_reversal_id` / `chargeback_reversal_id` is not necessary if `payment_reversals.payment_id` exists.
7. add provider webhook event table for refund/chargeback idempotency if production provider does not reuse `payments.webhook_event_id` safely.

## Additional Model Candidates

- `PaymentReversal`
- `PaymentReversalPointEntry`
- `PaymentReversalPrizeAction`

Relationships:

- Payment hasMany or hasOne final reversal.
- PaymentReversal belongsTo Payment, User, AdminUser.
- PaymentReversal hasMany point entries and prize actions.
- PaymentReversalPointEntry belongsTo PointLot and PointLedger.
- PaymentReversalPrizeAction belongsTo UserPrize and ShippingItem.

## Additional Service Candidates

- `RefundEligibilityService`
- `PaymentRefundService`
- `ChargebackReversalService`
- `PointReversalService`
- `ChargebackPrizeActionService`
- `PaymentReversalNotificationService`

## Additional API Candidates

- `GET /admin/api/payments/{payment}/refund-eligibility`
- `POST /admin/api/payments/{payment}/refund`
- `POST /admin/api/payments/{payment}/chargeback`
- `GET /admin/api/payment-reversals`
- `GET /admin/api/payment-reversals/{paymentReversal}`
- `POST /admin/api/payment-reversals/{paymentReversal}/release-holds`
- `POST /admin/api/payment-reversal-prize-actions/{action}/mark-returned`

Existing `/refund` and `/chargeback` routes can be reused, but must call the new Services.

## 管理画面変更案

### 売上管理

- 日別返金・CB一覧に「処理詳細」リンクを追加。
- refund/chargeback rowsから `payment_reversal` を確認可能にする。

### 決済/売上管理内Payment Detail

- 返金可否チェック結果。
- 「通常返金」実行ボタン。
- 「チャージバック登録」実行ボタン。
- 取消ポイント明細。
- shortfall表示。
- affected prizes表示。

### ユーザー詳細

- 返金/CB履歴。
- shortfall残高。
- hold中景品。
- 返送依頼中景品。

### 配送/景品

- hold/return_requested状態の表示。
- hold解除操作。
- 返送済み確認操作。

## 通常返金の設計概要

- 対象決済のpurchaseロット全額未使用のみ可。
- 1ptでも使用済みなら不可。
- 対象ロットだけをcancelし、walletを減算。
- shortfallなし。
- Paymentをrefundedに更新。
- audit/Discordを記録。
- 売上管理はrefunded_atで反映。

## チャージバックの設計概要

- 対象Payment由来lotに限定せず、現在残高全体から取消。
- paid purchase分はpaid優先、不足分free。
- purchase bonus free分はfreeのみ。
- 不足はshortfallとして記録。
- Paymentをchargebackに更新。
- Userをsuspendedまたはreview_required相当にする。現行DBはsuspendedのみ対応。
- 未発送景品hold、発送済み景品返送依頼。
- 売上管理はchargeback_atで反映。

## ポイント取消ルール

| Case | Source | Order | Shortfall |
| --- | --- | --- | --- |
| 通常返金 paid | payment-origin paid lots only | exact lot only | 不可 |
| 通常返金 free bonus | payment-origin free lots only | exact lot only | 不可 |
| CB paid purchase | current user balance | paid first, free fallback | 記録 |
| CB free bonus | current user balance | free only | 記録 |

## 景品hold / 返送依頼ルール

チャージバック発生時は、対象ユーザーの未発送景品を一旦すべてholdする。
管理者確認後、ポイント相殺済みで問題ないと判断できればrelease可能。

| Prize / Shipping state | Action |
| --- | --- |
| `user_prizes.stored` | hold |
| `shipping_items.requested` | hold |
| `shipping_items.packing` | hold |
| `shipping_items.shipped` | return_requested |
| `shipping_items.delivered` | return_requested |
| `shipping_items.returned` | no_action |
| `shipping_items.canceled` | no_action |
| `user_prizes.converted` | no_action initially; converted free points are handled by point cancellation/shortfall |
| `user_prizes.expired` | no_action |

## 未解決事項

- User statusは既存 `suspended` で十分か、`review_required` などを追加するか。
- 返送依頼メールの文面、返送期限、送料負担。
- 通常返金完了メールを送るか。
- 本番決済プロバイダのrefund/chargeback webhook payload仕様。
- チャージバックが取り下げられた場合の再付与/hold解除方針。

## Phase 1 Implementation Impact

2026-06-30時点のPhase 1では、Migration、Model、Enum、Relationのみを追加する。

影響範囲:

- `payment_reversals` に返金/チャージバック処理の親レコードを保存できる。
- `payment_reversal_point_entries` にポイント取消明細とshortfallを保存できる。
- `payment_reversal_prize_actions` に景品hold/返送依頼と通知失敗情報を保存できる。
- `user_prizes.status=held` をDB制約とEnumに追加する。
- `shipping_items.status=hold` / `return_requested` をDB制約とEnumに追加する。
- `shipping_requests.status` は今回変更しない。

未実装のまま残るもの:

- 通常返金可否判定Service
- ポイント取消Service
- 通常返金実行Service
- チャージバック実行Service
- 景品hold/返送依頼Service
- Backend API
- 管理画面UI
- 通知再送処理
