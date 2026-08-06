import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminPointPurchasePlan } from "@/lib/admin-api/generated";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, refresh: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(["payment.plan.read", "payment.plan.manage"]) }) }));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({ FreshMfaDialog: () => null }));

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listPointPurchasePlans").mockResolvedValue({
    items: [plan()],
    next_cursor: null,
    request_id: uuid("9"),
  });
  vi.spyOn(AdminApiClient.prototype, "getPointPurchasePlan").mockResolvedValue({
    data: plan(),
    request_id: uuid("9"),
  });
});

afterEach(() => {
  vi.restoreAllMocks();
  push.mockReset();
});

describe("Point purchase management", () => {
  it("renders the V1 list columns plus the audience category", async () => {
    render(<PointPurchaseManagementWorkspace mode="list" />);
    expect(await screen.findByText("スタンダード")).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "状態", "編集",
    ]);
    expect(screen.getByText("すべてのユーザー")).toBeVisible();
    expect(screen.getByRole("link", { name: "スタンダードを編集" })).toHaveAttribute(
      "href",
      `/purchase-plans/${plan().id}`,
    );
  });

  it("defaults a new product to all users and submits the V1 fields", async () => {
    const create = vi.spyOn(AdminApiClient.prototype, "createPointPurchasePlan")
      .mockResolvedValue({ data: plan(), idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="create" />);
    expect(await screen.findByLabelText("対象カテゴリ")).toHaveValue("all_users");
    fireEvent.change(screen.getByLabelText("商品名"), { target: { value: "スタンダード" } });
    fireEvent.change(screen.getByLabelText("支払金額"), { target: { value: "1000" } });
    fireEvent.change(screen.getByLabelText("付与有償ポイント"), { target: { value: "1000" } });
    fireEvent.click(screen.getByRole("button", { name: "登録" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toMatchObject({
      audience_code: "all_users",
      free_point_amount: 0,
      paid_point_amount: 1000,
    });
    expect(push).toHaveBeenCalledWith(`/purchase-plans/${plan().id}`);
  });

  it("loads and updates the selected audience with revision OCC", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updatePointPurchasePlan")
      .mockResolvedValue({ data: { ...plan(), audience_code: "first_purchase_users" }, idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="edit" planId={plan().id} />);
    expect(await screen.findByLabelText("商品名")).toHaveValue("スタンダード");
    fireEvent.change(screen.getByLabelText("対象カテゴリ"), { target: { value: "first_purchase_users" } });
    fireEvent.click(screen.getByRole("button", { name: "更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][1]).toMatchObject({
      audience_code: "first_purchase_users",
      expected_revision: 1,
    });
  });
});

function plan(): AdminPointPurchasePlan {
  return {
    amount: 1000,
    audience_code: "all_users",
    available_from: "2026-08-01T00:00:00+09:00",
    available_until: "2026-09-01T00:00:00+09:00",
    created_at: "2026-08-01T00:00:00Z",
    free_point_amount: 100,
    id: uuid("1"),
    is_active: true,
    name: "スタンダード",
    paid_point_amount: 1000,
    revision: 1,
    sort_order: 10,
    status: "published",
    updated_at: "2026-08-01T00:00:00Z",
    version: 1,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
