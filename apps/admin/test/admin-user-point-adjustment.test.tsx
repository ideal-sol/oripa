import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const adjust = vi.fn();

vi.mock("@/lib/admin-api/client", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/admin-api/client")>();
  return {
    ...original,
    AdminApiClient: class {
      adjustAdminUserPoints = adjust;
    },
  };
});

vi.mock("@/components/auth/fresh-mfa-dialog", () => ({
  FreshMfaDialog: ({ open }: { open: boolean }) => open ? <p>Fresh MFA</p> : null,
}));

import { AdminUserPointAdjustmentModal } from "@/components/users/admin-user-point-adjustment-modal";

describe("Admin user point adjustment modal", () => {
  beforeEach(() => {
    adjust.mockReset();
    adjust.mockResolvedValue({
      data: {
        adjustment_public_id: uuid("2"),
        user_public_id: uuid("1"),
        operation_public_id: uuid("3"),
        point_type: "free",
        direction: "deduct",
        amount: 50,
        reason: "Correction",
        paid_balance_before: 100,
        paid_balance_after: 100,
        free_balance_before: 200,
        free_balance_after: 150,
        executed_at: "2026-08-03T00:00:00Z",
      },
      idempotent_replay: false,
      request_id: uuid("4"),
    });
  });

  it("shows actual balances and an exact-type planned balance", () => {
    renderModal();
    expect(screen.getAllByText("100 pt").length).toBeGreaterThan(0);
    expect(screen.getAllByText("200 pt").length).toBeGreaterThan(0);

    fireEvent.click(screen.getByRole("button", { name: "無償P" }));
    fireEvent.click(screen.getByRole("button", { name: "減算" }));
    fireEvent.change(screen.getByLabelText("調整ポイント数"), { target: { value: "50" } });
    expect(screen.getByText("150 pt")).toBeVisible();

    fireEvent.change(screen.getByLabelText("調整ポイント数"), { target: { value: "201" } });
    expect(screen.getByText("-1 pt")).toHaveClass("is-negative");
    expect(screen.getByRole("button", { name: "内容を確認して実行" })).toBeDisabled();
  });

  it("submits reason and current password once then refreshes the canonical detail", async () => {
    const onClose = vi.fn();
    const onSuccess = vi.fn();
    renderModal({ onClose, onSuccess });
    fireEvent.click(screen.getByRole("button", { name: "無償P" }));
    fireEvent.click(screen.getByRole("button", { name: "減算" }));
    fireEvent.change(screen.getByLabelText("調整ポイント数"), { target: { value: "50" } });
    fireEvent.change(screen.getByLabelText("調整理由"), { target: { value: "Correction" } });
    fireEvent.change(screen.getByLabelText("現在の管理者パスワード"), {
      target: { value: "valid current password" },
    });
    fireEvent.submit(screen.getByRole("button", { name: "内容を確認して実行" }).closest("form")!);

    await waitFor(() => expect(adjust).toHaveBeenCalledOnce());
    expect(adjust.mock.calls[0][0]).toBe(uuid("1"));
    expect(adjust.mock.calls[0][1]).toEqual({
      point_type: "free",
      direction: "deduct",
      amount: 50,
      reason: "Correction",
      current_password: "valid current password",
    });
    expect(adjust.mock.calls[0][2]).toEqual(expect.any(String));
    expect(onSuccess).toHaveBeenCalledOnce();
    expect(onClose).toHaveBeenCalledOnce();
  });

  it("supports Escape close and keeps accessible dialog semantics", () => {
    const onClose = vi.fn();
    renderModal({ onClose });
    const dialog = screen.getByRole("dialog", { name: "ポイント調整" });
    expect(dialog).toHaveAttribute("aria-modal", "true");
    fireEvent.keyDown(dialog.parentElement!, { key: "Escape" });
    expect(onClose).toHaveBeenCalledOnce();
  });
});

function renderModal(overrides: { onClose?: () => void; onSuccess?: () => void } = {}) {
  return render(
    <AdminUserPointAdjustmentModal
      displayName="Synthetic user"
      freeBalance={200}
      onClose={overrides.onClose ?? vi.fn()}
      onSuccess={overrides.onSuccess ?? vi.fn()}
      open
      paidBalance={100}
      userPublicId={uuid("1")}
    />,
  );
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
