# M9 Admin payment adjustments — M8 handoff

Status: Source candidate / Draft only. Risk R4, Strict Change, Application Runtime Activation deferred.
Human approval for Ready/Merge is absent. M9 alone must not be activated or deployed before M8 completion.

## Authority and scope

- Task `MEMBERRANK-M9-20261020`; source base `f4ce205643949f9e4f548b5141b11bc9c59614e6`.
- Feature specification v1.5 SHA-256 `688c1b727483e22fef1c091a571f6490f30183d46e7d70121367b54d3f3805bc`.
- Phased plan REV2 SHA-256 `b918b3b4ec95b0ce1ceeaf7a5cab2cf601ab4042aeefc41bc263b6171831f633`.
- Prompts REV2 SHA-256 `83270c7a37a7bdc4279232fba2ce44295f49b7a776ddfa33fb2c881f966cdb62`.
- Latest Human decision permits non-conflicting M9 work with server-side M8 guards. This does not approve rank reduction, bonus recovery, provider communication or runtime activation.
- V2 only. No V1 service reuse, migration source, dependencies, Public API/Client, Storefront, deployment, operational database or environment changes.

## Admin entry points

`V2AdminPaymentAdjustmentService` owns fresh-MFA authorization, validation, actual Admin-scoped idempotency and audit. Routes under `/admin/api/v2/payments/{paymentId}`:

| Method / suffix | Operation |
| --- | --- |
| GET (no suffix) | Financial-read payment detail, related lots and history |
| POST `/refunds` | Full unused/unexpired point reservation, no provider request |
| POST `/refunds/{adjustmentId}/complete` | Explicit provider-refund confirmation, consume reservations |
| POST `/refunds/{adjustmentId}/cancel` | Normal `canceled`, release reservations, no sales reversal |
| POST `/chargebacks/preview` | Read-only current cancellation plan |
| POST `/chargebacks` | Recalculate and record actual cancellation/shortfall |
| POST `/chargebacks/{adjustmentId}/reversals` | Parent-bound `manual_review`, no automatic restoration |

Mutations require `payment.adjustment.manage` (Owner/Admin); detail preserves `reporting.financial.read`. Existing Admin realm, MFA, CSRF, rate limits, no-store and noindex remain. IDs are public UUIDs; lots use their public grant-operation ID, never internal lot IDs.

`reason_code=admin_manual`, actual `requested_by_admin_id` and `source_provider_event_id=NULL` distinguish manual adjustments. Histories use the Admin actor; each mutation's reason, before/after status, actual cancellation and shortfall are recorded in the audit transaction. Provider references are optional identifiers, not credentials. Completion requires `provider_refund_confirmed=true`.

## Transaction and accounting connection points

- `V2PointTransactionRunner::run` retains the three-attempt policy for SQLSTATE 40001/40P01. A real serializable race exposed Laravel wrapping a nested audit conflict in `DeadlockException`; M9 unwraps the exception chain and retries only the outermost transaction, never a poisoned inner savepoint. Other SQLSTATEs remain non-retryable. Real PostgreSQL deadlock and serialization tests cover both cases.
- Admin idempotency claim precedes the domain locks. Supported domain writers serialize on User, then Payment, then the parent Adjustment where applicable, Wallet, ordered Lots and Reservations. Provider event processing additionally locks its event first; manual paths never create or depend on a synthetic event.
- `V2PaymentService::reserveFullRefund` reuses the existing full-refund reservation model. `assertRefundEligible` is shared by detail and the locked reservation path. Manual preparation does not enqueue `payment.refund.requested`.
- `resolveRefund(..., 'succeeded', adminId, reason)` consumes active reservations, writes Lot/Wallet/Ledger/impact/history/audit and marks the adjustment succeeded inside the same transaction. Explicit cancellation releases reservations and is not `failed`. No automatic timeout is introduced.
- `executeChargeback` is shared by verified provider events and manual Admin recording. `chargebackPlan` is shared with preview; execution recomputes under locks. Origin lots precede other lots; paid lots remain FIFO. Available balances exclude all reservations/point holds. Insufficient points become explicit shortfall, never negative balances.
- The exact M8 insertion points are immediately before `transitionAdjustment(..., 'succeeded', ...)` in refund resolution and chargeback execution, while their `V2PointTransactionRunner` transaction is still open. M8 must keep point cancellation, rank-entry reduction, rank recomputation/recovery and adjustment success atomic. Do not add an asynchronous rank correction after financial commit.
- Adjustment IDs, original payment, paid/free amounts and impact rows remain available. Idempotency replay returns the stored response snapshot; new keys cannot bypass the Payment lock and conflict checks. Reversal records cannot re-grant the original payment.

## M8 hold — not implemented or passed by M9

`assertRankAdjustmentReady` deliberately raises HTTP 409 `PAYMENT_RANK_ADJUSTMENT_NOT_READY`:

1. An actual M4 purchase bonus exists (`member_rank_purchase_bonuses.bonus_amount > 0`), regardless of current feature flag.
2. A payment rank-point entry exists, regardless of current feature flag.
3. Rank is enabled and the grant falls within/after the activation JST month, where historical rank accounting may be required.

The guard runs for preparation, refund success and CB execution, including the shared provider domain; preview also refuses unsafe execution plans. Completion cannot bypass it by preparing while OFF. Explicit reservation cancellation remains available. A zero-bonus row with no extra operation/lot is not itself a blocker; independent rank-accounting conditions still apply.

Detail includes both `payment_point_grants.point_operation_id` lots and the separate `member_rank_purchase_bonuses.point_operation_id` lots, with the bonus marker and Japanese `M8連動未対応` reason. Existing `paymentLots` remains the original-payment lot lookup; M9 does not pretend it includes the separate M4 operation.

M8 must replace the guard only after incorporating all related lots (product free points, limited bonus and M4 purchase bonus), original payment rank entries, downstream promotions and applicable rank bonuses. Reuse `V2MemberRankCorrectionService::applyDecrease`, `recoverable` and `spendableFree`, already used by `V2MemberRankAdminService`, rather than creating another ledger authority; preserve its no-negative-balance/shortfall rules and immutable history. M9 does not implement or validate those M8 reductions or recovery outcomes.

## Provider cancellation review

Verified, normalized `payment.canceled` events received for already-succeeded payments are stored as ignored. Admin review flags require both succeeded payment state and the ignored cancellation event type; unrelated ignored events do not qualify. Payment is not forced from succeeded to canceled.

Historical immutable events saved only as generic `payment.status_changed` cannot safely be reclassified from the event type alone. They are not all flagged, and this is not a retroactive provider reconciliation. No immutable provider event rewrite, signature bypass or raw provider payload exposure occurs.

After signature verification and canonical retrieval/validation, an exact existing Fincode event ID retains its historical `payment.status_changed` identity on cancellation replay. The unchanged event recorder still checks payload hash, payment/adjustment identity and occurrence timestamp. Newly received cancellations use `payment.canceled`. Regression tests cover replay, immutable row preservation, invalid signature and conflicting identity rejection; all provider requests in these webhook tests are HTTP fakes, separate from the manual-path zero-I/O assertion.

## Contract and database

- Existing unpublished bundle candidate alpha.46 retained; Admin contract alpha.41 → alpha.42, seven additive operations. `AdminPayment.provider` now reports the real provider string; `fincode` remains valid. Provider-specific literal assumptions in old Admin consumers must be regenerated with the paired Admin build.
- The requested provider-literal widening is a deliberate Alpha response-contract change, not claimed universally backward-compatible for old typed Admin clients. The canonical contract gate records the exact Human task authority, exact old/new versions and exact old/new provider schemas, while rejecting any other existing path/schema change. Positive/negative regression tests preserve fail-closed behavior. It does not authorize artifact publication or a general compatibility bypass.
- Public alpha.42 and its 83 operations unchanged. Published alpha.45, artifact 12031381227 and all 25 immutable history records remain unchanged. No publication/dispatch of contract artifacts.
- Existing adjustment, reason, history, impact and reservation tables suffice. No migration created/reserved; no migration allocation lock. Payment serialization plus existing idempotency/business-key constraints protect supported writers; out-of-band SQL is not an approved writer.
- Artifact Release Lock protects candidate edits. No Source Lock selected after semantic collision review; no foreign task changes imported.

## Validation and release boundary

Development evidence: existing PaymentModel 23 tests/111 assertions; first complete M9 functional run 11/125; initial actual PostgreSQL race suite 8/239. Later expanded and exact-head runs supersede these development receipts, not vice versa. Final source SHA, local/Required CI results, machine-readable Draft self-review and any failures/not-run cases belong to the M9 Draft PR and final handoff. Do not infer final-head acceptance from these preliminary counts.

M9 tests explicitly distinguish M8 rejection from M8 functionality. Browser route-fixture testing is not deployed/provider acceptance; real Laravel API, isolated concurrency and database receipts are recorded separately. Failed/interrupted runs remain failures/interrupted, not PASS.

Rollback before merge is source revision/discard of the unmerged candidate. There is no deployed migration or runtime to roll back. Retain the branch/worktree for Human review; Ready, Merge, operational migration, Worker/Feature activation and Production GO require separate authority. Neither passing M9 tests nor later source merge permits M9-only activation before M8.
