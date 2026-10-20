import type { AdminPaymentDetail, AdminPaymentAdjustment, AdminPaymentPointImpact } from "../src/lib/admin-api/generated";

export const paymentId = "01910191-0191-7191-8191-019101910191";
export const impact: AdminPaymentPointImpact = { required_paid_amount: 1000, required_free_amount: 100, reversed_paid_from_paid: 1000, reversed_free_from_free: 100, reversed_paid_shortage_from_free: 0, shortfall_paid_amount: 0, shortfall_free_amount: 0 };
export function adjustment(status: AdminPaymentAdjustment["status"] = "points_reserved", type: AdminPaymentAdjustment["type"] = "refund"): AdminPaymentAdjustment {
  return { id: "01910191-0191-7191-8191-019101910192", type, status, parent_id: null, amount: 1000, reason: "確認済み", requested_by_admin_id: paymentId, provider_adjustment_id: null, provider_case_id: null, source: "admin", requested_at: "2026-10-20T00:00:00Z", succeeded_at: status === "succeeded" ? "2026-10-20T00:00:00Z" : null, canceled_at: null, impact: status === "succeeded" ? impact : null, history: [] };
}
export function detail(): AdminPaymentDetail {
  return {
    payment: { id: paymentId, provider: "synthetic", method: "credit_card", status: "succeeded", amount: { amount: 1000, currency: "JPY" }, provider_payment_reference: "synthetic-reference", provider_status: null, user: { id: paymentId, display_name: "検証用" }, grant: { paid_points: 1000, bonus_points: 100, granted_at: "2026-10-20T00:00:00Z" }, created_at: "2026-10-20T00:00:00Z", updated_at: "2026-10-20T00:00:00Z", succeeded_at: "2026-10-20T00:00:00Z", expires_at: null, adjustments: { refund_pending: false, refunded: false, chargeback: false, review_required: false } },
    lots: [{ operation_id: paymentId, point_type: "paid", source: "payment", granted_amount: 1000, remaining_amount: 1000, reserved_amount: 0, expire_at: null, member_rank_purchase_bonus: false }],
    adjustments: [], refund_eligibility: { allowed: true, code: null, reason: null }, rank_adjustment_pending: false,
  };
}
