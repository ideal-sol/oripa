import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { AdminPaymentDetail } from "@/components/payments/admin-payment-detail";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import { adjustment, detail, impact, paymentId } from "./payment-adjustment-fixture";

const permissions = vi.hoisted(() => ({ allowed: true }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ hasPermission: () => permissions.allowed }) }));

describe("Admin manual payment adjustments", () => {
  beforeEach(() => {
    permissions.allowed = true;
    HTMLDialogElement.prototype.showModal = function () { this.open = true; };
    vi.spyOn(AdminApiClient.prototype, "getAdminPayment").mockResolvedValue({ data: detail(), request_id: paymentId });
  });

  it("shows API labels, provider reference and reference-only progress in JST", async () => {
    const value = detail();
    value.payment.provider_label = "UnivaPay";
    value.payment.method_label = "クレジットカード";
    value.payment.provider_payment_reference = "synthetic-provider-order";
    value.progress = [{type: "redirect_started", source: "client", detail: {}, recorded_at: "2026-10-26T10:02:00Z"}];
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({data: value, request_id: paymentId});
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByRole("region", {name: "決済の経過"})).toHaveTextContent("19:02");
    expect(screen.getByRole("region", {name: "決済の経過"})).toHaveTextContent("会員画面の参考記録");
    expect(screen.getByText(/決済会社：UnivaPay/u)).toHaveTextContent("クレジットカード");
    expect(screen.getByText(/決済会社側の取引ID/u)).toHaveTextContent("synthetic-provider-order");
  });

  it("shows Paidy processing review without enabling manual financial actions", async () => {
    const value = detail();
    value.payment.provider_label = "ペイディ";
    value.payment.provider_payment_reference = "pay_synthetic";
    value.payment.status = "processing";
    value.payment.adjustments = {...value.payment.adjustments!, review_required: true};
    value.refund_eligibility = {allowed: false, code: "PAYMENT_NOT_SUCCEEDED", reason: "未確定"};
    value.progress = [{type: "provider_api_called", source: "server", detail: {reason: "reconciliation_failed"}, recorded_at: "2026-10-27T01:00:00Z"}];
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({data: value, request_id: paymentId});
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("売上確定・付与・返金");
    expect(screen.getByText(/決済会社：ペイディ/u)).toBeInTheDocument();
    expect(screen.getByText(/決済会社側の取引ID/u)).toHaveTextContent("pay_synthetic");
    expect(screen.getByRole("button", {name: "返金の準備"})).toBeDisabled();
    expect(screen.getByRole("button", {name: "チャージバックを記録"})).toBeDisabled();
  });

  it("requires a reason, preserves the key after a transport failure and prevents duplicate clicks", async () => {
    const write = vi.spyOn(AdminApiClient.prototype, "writeAdminPaymentAdjustment")
      .mockRejectedValueOnce(new Error("network"))
      .mockResolvedValue({ data: adjustment(), rank_adjustment_pending: false, idempotent_replay: false, request_id: paymentId });
    render(<AdminPaymentDetail paymentId={paymentId} />);
    fireEvent.click(await screen.findByRole("button", { name: "返金の準備" }));
    expect(screen.getByRole("button", { name: "確認して記録" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("理由（必須）"), { target: { value: "全額返金" } });
    fireEvent.click(screen.getByRole("button", { name: "確認して記録" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("再試行");
    fireEvent.click(screen.getByRole("button", { name: "確認して記録" }));
    fireEvent.click(screen.getByRole("button", { name: "処理中…" }));
    await waitFor(() => expect(write).toHaveBeenCalledTimes(2));
    expect(write.mock.calls[0][3]).toBe(write.mock.calls[1][3]);
    expect(await screen.findByRole("status", { name: "操作結果" })).toHaveTextContent("返金準備中");
  });

  it("shows an expired UnivaPay success as a review item with its reconciliation evidence", async () => {
    const value = detail();
    value.payment.status = "expired";
    value.payment.provider_label = "UnivaPay";
    value.payment.adjustments = {...value.payment.adjustments, refund_pending: false, refunded: false, chargeback: false, review_required: true};
    value.progress = [{type: "provider_api_called", source: "server", detail: {match: "expired", reason: "reconciliation_failed"}, recorded_at: "2026-10-27T10:00:00Z"}];
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({data: value, request_id: paymentId});
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("要確認：決済の照合結果");
    expect(screen.getByRole("region", {name: "決済の経過"})).toHaveTextContent("expired");
  });

  it("requires explicit provider completion and shows actual returned impact", async () => {
    const value = detail(); value.adjustments = [adjustment()];
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({ data: value, request_id: paymentId });
    const write = vi.spyOn(AdminApiClient.prototype, "writeAdminPaymentAdjustment").mockResolvedValue({ data: adjustment("succeeded"), rank_adjustment_pending: false, idempotent_replay: false, request_id: paymentId });
    render(<AdminPaymentDetail paymentId={paymentId} />);
    fireEvent.click(await screen.findByRole("button", { name: "返金済みにする" }));
    fireEvent.change(screen.getByLabelText("理由（必須）"), { target: { value: "実返金確認" } });
    expect(screen.getByRole("button", { name: "確認して記録" })).toBeDisabled();
    fireEvent.click(screen.getByRole("checkbox"));
    fireEvent.click(screen.getByRole("button", { name: "確認して記録" }));
    await waitFor(() => expect(write).toHaveBeenCalledWith(paymentId, "complete", { reason: "実返金確認", provider_refund_confirmed: true }, expect.any(String), adjustment().id));
    expect(await screen.findByRole("status", { name: "操作結果" })).toHaveTextContent("有償 1000pt");
  });

  it("requires CB preview and displays the recalculated execution shortfall", async () => {
    const preview = vi.spyOn(AdminApiClient.prototype, "previewAdminPaymentChargeback").mockResolvedValue({ data: { impact, rank_adjustment_pending: false }, request_id: paymentId });
    const result = adjustment("succeeded", "chargeback"); result.impact = { ...impact, reversed_paid_from_paid: 0, shortfall_paid_amount: 1000 };
    const write = vi.spyOn(AdminApiClient.prototype, "writeAdminPaymentAdjustment").mockResolvedValue({ data: result, rank_adjustment_pending: false, idempotent_replay: false, request_id: paymentId });
    render(<AdminPaymentDetail paymentId={paymentId} />);
    fireEvent.click(await screen.findByRole("button", { name: "チャージバックを記録" }));
    fireEvent.change(screen.getByLabelText("理由（必須）"), { target: { value: "CB通知" } });
    fireEvent.click(screen.getByRole("button", { name: "取消予定額を確認" }));
    await screen.findByRole("region", { name: "取消予定額" });
    expect(preview).toHaveBeenCalledOnce(); expect(write).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "確認して記録" }));
    expect(await screen.findByRole("status", { name: "操作結果" })).toHaveTextContent("不足：有償 1000pt");
  });

  it("holds M8 actions while keeping explicit cancellation available", async () => {
    const value = detail(); value.rank_adjustment_pending = true; value.adjustments = [adjustment()];
    value.lots[0].member_rank_purchase_bonus = true;
    value.refund_eligibility = { allowed: false, code: "PAYMENT_RANK_ADJUSTMENT_NOT_READY", reason: "M8連動未対応" };
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({ data: value, request_id: paymentId });
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByRole("button", { name: "返金の準備" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "返金済みにする" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "チャージバックを記録" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "取りやめる" })).toBeEnabled();
    expect(screen.getByText(/会員ランク購入ボーナス/u)).toBeVisible();
  });

  it("separates purchase-bonus shortfall from total free shortfall and rank effects", async () => {
    const value = detail();
    const result = adjustment("succeeded", "chargeback");
    result.impact = { ...impact, shortfall_free_amount: 20,
      purchase_bonus: { required_amount: 20, reversed_amount: 0, shortfall_amount: 20 },
      rank_adjustment: { month: "2026-10-01", effect: "past_month", requested_points: 1000, applied_points: 0,
        before_rank: "silver", after_rank: "silver", recoveries: [] } };
    value.adjustments = [result];
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockResolvedValue({ data: value, request_id: paymentId });
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByText(/うち購入ボーナス/u)).toHaveTextContent("不足 20pt（無償不足の内数）");
    expect(screen.getByText(/元月 2026-10/u)).toHaveTextContent("実減算 0pt");
    expect(screen.getByText(/過去月の取消/u)).toHaveTextContent("現在ランクは変更しません");
  });

  it("hides mutation controls from financial-read-only operators", async () => {
    permissions.allowed = false;
    render(<AdminPaymentDetail paymentId={paymentId} />);
    await screen.findByRole("region", { name: "決済情報" });
    expect(screen.queryByRole("button", { name: "返金の準備" })).toBeNull();
  });

  it.each([401, 403, 404, 409])("shows %s without disguising errors as empty records", async status => {
    vi.mocked(AdminApiClient.prototype.getAdminPayment).mockRejectedValue(new AdminApiError(status, "PAYMENT_ADJUSTMENT_CONFLICT", paymentId, null, false));
    render(<AdminPaymentDetail paymentId={paymentId} />);
    expect(await screen.findByRole("alert")).toBeVisible();
    expect(screen.queryByText("調整の記録はありません。")).toBeNull();
  });
});
