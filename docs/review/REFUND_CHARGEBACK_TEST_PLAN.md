# Refund / Chargeback Test Plan

## Scope

This plan covers future implementation of normal refund and chargeback processing.

No tests were added or executed for this document.

## Unit Tests

### Refund Eligibility

| ID | Case | Expected |
| --- | --- | --- |
| RC-U-001 | succeeded payment with all purchase lots unused | eligible |
| RC-U-002 | paid purchase lot partially used | ineligible |
| RC-U-003 | free bonus lot partially used | ineligible |
| RC-U-004 | payment is pending/failed/canceled | ineligible |
| RC-U-005 | payment already refunded | idempotent or ineligible according to final API policy |
| RC-U-006 | payment already chargeback | normal refund impossible |

### Normal Refund

| ID | Case | Expected |
| --- | --- | --- |
| RC-U-010 | refund fully unused paid/free purchase points | payment refunded, lots 0, wallet decreased, cancel ledgers created |
| RC-U-011 | refund with only paid points | paid wallet decreases only |
| RC-U-012 | refund with only free bonus points | free wallet decreases only |
| RC-U-013 | refund after any point use | fails, no mutation |
| RC-U-014 | refund rerun same payment | no duplicate ledger/reversal |
| RC-U-015 | failure during transaction | payment/wallet/lots/ledgers remain unchanged |

### Chargeback Point Reversal

確定した実行順序:

1. 対象paymentの `paid_point_amount` を確認する。
2. 対象paymentの `free_point_amount` を確認する。
3. `paid_point_amount` を、現在保有している paid `point_lots` から優先取消する。
4. `free_point_amount` を、現在保有している free `point_lots` から取消する。
5. `paid_point_amount` でまだ不足がある場合、残っている free `point_lots` から取消する。
6. それでも不足する場合は `shortfall` として記録する。

重要:

- `free_point_amount` の取消は free `point_lots` からのみ行う。
- paid `point_lots` から `free_point_amount` を取消してはいけない。

Expected bucket values:

- `paid_purchase_from_paid`
- `free_bonus_from_free`
- `paid_purchase_shortfall_from_free`
- `shortfall`

| ID | Case | Expected |
| --- | --- | --- |
| RC-U-020 | enough paid balance for paid target | `paid_purchase_from_paid`でpaid points canceled first |
| RC-U-021 | free bonus target | `free_bonus_from_free`でfree lotsのみから取消 |
| RC-U-022 | paid balance insufficient and free remains after free bonus cancellation | remaining paid target canceled as `paid_purchase_shortfall_from_free` |
| RC-U-023 | free bonus target with no free balance but enough paid balance | free bonus is not canceled from paid; shortfall is recorded |
| RC-U-024 | paid and free both insufficient | shortfall recorded, wallet remains non-negative |
| RC-U-025 | expired free lots exist | only remaining/current usable lots are canceled according to final policy |
| RC-U-026 | multiple lots | paid order is FIFO; free order is nearest expiry then FIFO |
| RC-U-027 | rerun same chargeback | no duplicate ledger/reversal |

### Prize Actions

| ID | Case | Expected |
| --- | --- | --- |
| RC-U-030 | stored prize | hold action created and prize held |
| RC-U-031 | requested shipping item | hold action created |
| RC-U-032 | packing shipping item | hold action created |
| RC-U-033 | shipped shipping item | return_requested action created |
| RC-U-034 | delivered shipping item | return_requested action created |
| RC-U-035 | returned/canceled shipping item | no_action |
| RC-U-036 | converted/expired prize | no_action unless final policy changes |
| RC-U-037 | hold release | previous status restored |
| RC-U-038 | chargeback user has multiple unshipped prizes | all unshipped prizes are held |
| RC-U-039 | held prize | `user_prizes.status=held` prevents shipping/exchange through existing guards |
| RC-U-040 | held shipping item | `shipping_items.status=hold` is visible and blocks normal shipping progress |
| RC-U-041 | shipped item return request | `shipping_items.status=return_requested` is set |

## Feature Tests

### Admin Payment APIs

| ID | Endpoint | Case | Expected |
| --- | --- | --- | --- |
| RC-F-001 | `GET /admin/api/payments/{payment}/refund-eligibility` | eligible payment | 200 with eligible=true |
| RC-F-002 | same | used points | 200 with eligible=false and reason |
| RC-F-003 | `POST /admin/api/payments/{payment}/refund` | eligible | 200/201, payment refunded |
| RC-F-004 | same | ineligible | 422 |
| RC-F-005 | `POST /admin/api/payments/{payment}/chargeback` | succeeded payment | 200/201, payment chargeback |
| RC-F-006 | same | shortfall | response includes shortfall |
| RC-F-007 | all admin APIs | unauthenticated | 401 |
| RC-F-008 | all admin APIs | non-admin user | 403 or current middleware behavior |
| RC-F-009 | all mutation APIs | invalid reason | 422 |

### Reversal Detail APIs

| ID | Endpoint | Case | Expected |
| --- | --- | --- | --- |
| RC-F-020 | `GET /admin/api/payment-reversals` | list | reversal rows with payment/user/status |
| RC-F-021 | `GET /admin/api/payment-reversals/{id}` | detail | point entries and prize actions included |
| RC-F-022 | `POST /admin/api/payment-reversals/{id}/release-holds` | valid hold | hold released |
| RC-F-023 | same | no unresolved hold | safe no-op or 422 |
| RC-F-024 | `POST /admin/api/payment-reversal-prize-actions/{id}/mark-returned` | return requested | status completed |

## Integration Tests

| ID | Flow | Expected |
| --- | --- | --- |
| RC-I-001 | create mock payment, succeed, refund before use | points removed, sales refund reflected |
| RC-I-002 | create mock payment, succeed, draw once, normal refund | refund denied |
| RC-I-003 | create mock payment, succeed, draw, chargeback | current balances canceled, shortfall if needed, draw remains |
| RC-I-004 | chargeback after prize stored | prize held, cannot ship/exchange |
| RC-I-005 | chargeback after shipping requested | shipping item held |
| RC-I-006 | chargeback after shipped/delivered | return request record and email |
| RC-I-007 | chargeback user suspended | login/draw/payment behavior follows existing suspended policy |
| RC-I-008 | sales management monthly/daily views after refund | refunded_at reflected |
| RC-I-009 | sales management monthly/daily views after chargeback | chargeback_at reflected |
| RC-I-010 | Discord failure | DB operation remains complete, failure logged, retryable from admin |
| RC-I-011 | Mail failure | DB operation remains complete, failure logged, retryable from admin |

## Concurrent / Idempotency Tests

| ID | Case | Expected |
| --- | --- | --- |
| RC-C-001 | two refund requests same payment | one reversal, one set of ledgers |
| RC-C-002 | refund and chargeback race | one terminal outcome, no partial mutation |
| RC-C-003 | two chargeback webhooks same event | one reversal |
| RC-C-004 | two chargeback webhooks different event id same payment | one reversal or second marked duplicate |
| RC-C-005 | chargeback while user spends points | DB locks prevent inconsistent wallet/lots |
| RC-C-006 | chargeback while shipping request is created | prize lock prevents ship/exchange bypass |

## Browser / Manual QA

管理画面リファクタリング延期中のため、手動確認は安定版 `admin-dashboard.tsx` 構成で行う。

Checklist:

- 決済詳細で返金可否が見える。
- 未使用決済で通常返金を実行できる。
- 使用済み決済で通常返金が拒否される。
- チャージバックを登録できる。
- shortfallがある場合に明確に表示される。
- ユーザー詳細で返金/CB履歴が見える。
- 景品一覧/配送一覧でholdや返送依頼が見える。
- hold解除ができる。
- hold中の景品が発送依頼・ポイント変換できない。
- 返送依頼中の配送景品が管理画面で識別できる。
- 返送依頼メール送信結果が確認できる。
- 通知失敗時にDB処理は完了したまま、管理画面から再送できる。
- 売上管理でrefunded_at/chargeback_at日に反映される。
- 既存の売上管理、配送、ポイント、ユーザー管理が壊れていない。
- sales/reversal APIを管理画面初期表示の `refreshAll()` に混ぜない。

## Mail / Discord Tests

| ID | Case | Expected |
| --- | --- | --- |
| RC-N-001 | refund notification | Discord message formatted and sent/skipped safely |
| RC-N-002 | chargeback notification | Discord message includes payment/user/shortfall |
| RC-N-003 | return request mail | sent to user email with affected prizes |
| RC-N-004 | mailgun/mailpit fake | mailable content verified |
| RC-N-005 | webhook URL missing | notification skipped without failure |
| RC-N-006 | Discord send failure | refund/chargeback DB transaction is not rolled back |
| RC-N-007 | return request mail failure | refund/chargeback DB transaction is not rolled back |
| RC-N-008 | notification retry | failed notification can be resent without duplicating successful sends |

## Test Data Requirements

Minimum fixtures:

- active user
- suspended user
- admin user
- payment purchase plan
- succeeded payment with paid/free points
- payment-origin paid/free point lots
- wallet with enough paid/free balance
- wallet with insufficient balance
- draw_request and draw_results
- stored user_prize
- shipping_requested user_prize
- shipping_items in requested/packing/shipped/delivered/returned/canceled
- existing point ledgers unrelated to the payment

## Test Execution Rules

- Run targeted tests only.
- Do not run parallel tests.
- Do not run destructive migrate commands against production data.
- Do not run Docker build for this feature unless separately approved.

## Acceptance Criteria

- 通常返金はpayment由来ポイントが全額未使用の場合だけ成功する。
- 1ptでも使用済みなら通常返金は失敗する。
- チャージバックは確定順序どおり、paid targetをpaidから、free bonusをfreeから、paid不足分を残freeから取消す。
- free bonusをpaid point_lotsから取消しない。
- shortfallが記録され、walletはマイナスにならない。
- 対象ユーザーの未発送景品はすべてholdされる。
- holdはside tableだけでなく `user_prizes.status=held` / `shipping_items.status=hold` に反映される。
- 発送済み景品は返送依頼対象になる。
- 返送依頼は `shipping_items.status=return_requested` に反映される。
- 返送依頼メールが送られる。
- Discord通知・返送依頼メールはcommit後に送信され、失敗してもDB処理はrollbackしない。
- 通知失敗は記録され、管理画面から再送できる。
- 抽選結果、draw_sequence_number、sold_count、won_countは巻き戻らない。
- 売上管理はrefunded_at/chargeback_atで反映する。
- 監査ログが残る。
- 二重実行で二重取消が起きない。

## Phase 1 Verification Scope

Phase 1はMigration、Model、Relationのみを対象とする。

確認対象:

- `payment_reversals` migrationが存在する。
- `payment_reversal_point_entries` migrationが存在する。
- `payment_reversal_prize_actions` migrationが存在する。
- `payment_reversals.payment_id` は初期実装ではuniqueである。
- `user_prizes.status=held` がEnumとDB制約に追加されている。
- `shipping_items.status=hold` / `return_requested` がEnumとDB制約に追加されている。
- `shipping_requests.status` は変更されていない。
- Payment、User、AdminUser、PointLot、PointLedger、UserPrize、ShippingItemから関連Modelへ辿れる。

Phase 1では未実施:

- Migration実行
- Service実装
- API実装
- 管理画面UI実装
- Feature/Unit Test追加

## Phase 2A Verification Result

Implemented scope:

- `RefundEligibilityService`
- `PointReversalService`
- Unit tests only

Executed tests:

- `docker compose exec -T backend php artisan test tests/Unit/RefundEligibilityServiceTest.php`
  - PASS: 3 tests, 10 assertions
- `docker compose exec -T backend php artisan test tests/Unit/PointReversalServiceTest.php`
  - PASS: 7 tests, 55 assertions

Covered cases:

- Normal refund is allowed when all payment-origin points are unused.
- Normal refund is rejected when at least 1 point has been used.
- Non-succeeded payment is not refund-eligible.
- Normal refund reversal cancels payment-origin lots and creates cancel ledgers.
- Chargeback cancels paid purchase from paid lots, free bonus from free lots, then paid shortfall from remaining free lots.
- `free_point_amount` is not canceled from paid lots.
- Paid purchase shortfall uses only the free lots that remain after free bonus cancellation.
- Expired free lots are excluded from chargeback reversal targets.
- Paid lot reversal order is FIFO by `granted_at`, then `id`.
- Free lot reversal order is by nearest `expire_at`, then `granted_at`, then `id`.
- Normal refund does not change lots from other payments or non-purchase lots.
- Shortfall is recorded without creating point ledger rows.
- Wallet and point lot balances do not become negative.

Still pending:

- `PaymentRefundService`
- `ChargebackReversalService`
- `ChargebackPrizeActionService`
- Admin API
- Admin UI
- Discord notification
- Mail notification
- Production payment webhook

## Phase 2B Verification Result

Implemented scope:

- `PaymentRefundService`
- `ChargebackReversalService`
- `ChargebackPrizeActionService`
- Unit tests only

Executed tests:

- `docker compose exec -T backend php artisan test tests/Unit/PaymentRefundServiceTest.php`
  - PASS: 3 tests, 13 assertions
- `docker compose exec -T backend php artisan test tests/Unit/ChargebackPrizeActionServiceTest.php`
  - PASS: 2 tests, 13 assertions
- `docker compose exec -T backend php artisan test tests/Unit/ChargebackReversalServiceTest.php`
  - PASS: 2 tests, 13 assertions

Covered cases:

- Normal refund completes when payment-origin points are unused.
- Normal refund rejects used payment-origin points.
- Normal refund is idempotent after completion.
- Chargeback marks payment as chargeback, sets `chargeback_at`, suspends the user, and reverses current points.
- Chargeback is idempotent after completion.
- Unshipped stored prizes are held.
- Shipping items in requested state are held.
- Shipped items are marked return-requested.
- Converted prizes are recorded as no-action.
- Prize action application is idempotent.

Still pending:

- Admin API
- Admin UI
- Discord notification actual sending
- Mail actual sending
- Production payment webhook
- Browser/E2E verification

## Phase 3 Verification Result

Implemented scope:

- Admin API
- Request classes
- Resource classes
- Backend Feature Tests

Implemented endpoints:

- `GET /admin/api/payments/{payment}/refund-eligibility`
- `POST /admin/api/payments/{payment}/refund`
- `POST /admin/api/payments/{payment}/chargeback`
- `GET /admin/api/payment-reversals`
- `GET /admin/api/payment-reversals/{paymentReversal}`
- `POST /admin/api/payment-reversals/{paymentReversal}/release-holds`
- `POST /admin/api/payment-reversal-prize-actions/{action}/mark-returned`

Executed tests:

- `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentRefundChargebackApiTest.php`
  - PASS: 5 tests, 39 assertions
- `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReversalApiTest.php`
  - PASS: 4 tests, 22 assertions

Covered cases:

- Admin can check refund eligibility.
- Admin can execute a valid refund.
- Used payment-origin points cause refund API to return 422.
- Admin can execute chargeback without duplicate reversal on repeated call.
- Non-admin user cannot access refund API.
- Admin can list and show payment reversals.
- Admin can release holds.
- Admin can mark return-requested action as returned.
- Invalid mark-returned action returns 422.

Still pending:

- Admin UI
- Browser/E2E verification
- Discord notification actual sending
- Mail actual sending
- Production payment webhook

## Phase 4 Verification Result

Implemented scope:

- Existing stable `admin-dashboard.tsx` UI additions.
- Refund eligibility display.
- Normal refund button.
- Chargeback registration button.
- Payment reversal list/detail display.
- Payment reversal period search by `occurred_at`.
- Hold release action.
- Return requested action mark-returned operation.
- Frontend typecheck.

Executed checks:

- `docker compose exec -T backend php artisan migrate --force`
  - PASS: `2026_06_30_000001_create_payment_reversal_tables` executed.
- `cd frontend && pnpm typecheck`
  - PASS.
- `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReversalApiTest.php`
  - PASS: 6 tests, 29 assertions.

Not executed:

- Browser/E2E manual verification.
- Next.js build.
- Discord actual send.
- Mail actual send.
- Production payment webhook.

## Phase 5 Verification Result

Implemented scope:

- `ChargebackReturnRequestMail`
- `PaymentReturnRequestMailService`
- Chargeback post-commit return request mail send
- Admin return request mail resend API
- Admin UI mail status and resend button

Executed tests:

- `docker compose exec -T backend php artisan test tests/Unit/ChargebackReturnRequestMailTest.php`
  - PASS: 1 test, 6 assertions
- `docker compose exec -T backend php artisan test tests/Unit/PaymentReturnRequestMailServiceTest.php`
  - PASS: 3 tests, 17 assertions
- `docker compose exec -T backend php artisan test tests/Feature/AdminPaymentReturnRequestMailApiTest.php`
  - PASS: 4 tests, 16 assertions
- `docker compose exec -T backend php artisan test tests/Unit/ChargebackReversalServiceTest.php`
  - PASS: 3 tests, 19 assertions
- `cd frontend && pnpm typecheck`
  - PASS

Covered cases:

- Return-requested actions generate one user mail per payment reversal attempt.
- Multiple return-requested prizes are included in one mail.
- Successful send records `mail_sent_at`.
- Failed send records `mail_last_error` and `mail_last_attempted_at`.
- Mail failure does not roll back chargeback processing.
- Already sent actions are not sent twice.
- Unsent or failed actions can be sent from the admin API.
- Reversals without return-requested actions return `422`.
- Non-admin access is rejected by existing admin middleware.

Still pending:

- Browser/E2E manual verification.
- Next.js build.
- Discord notification and Discord resend.
- Normal refund completion mail.
- Production payment webhook.
