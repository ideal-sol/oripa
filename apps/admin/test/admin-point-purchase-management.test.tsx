import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { PointPurchaseManagementWorkspace } from "@/components/point-purchases/point-purchase-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminLimitedBonusCampaign, AdminPointPurchasePlan, AdminUserTag } from "@/lib/admin-api/generated";

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
  vi.spyOn(AdminApiClient.prototype, "listUserTags").mockResolvedValue({
    items: [tag()],
    next_cursor: null,
    request_id: uuid("9"),
  });
  vi.spyOn(AdminApiClient.prototype, "listLimitedBonusCampaigns").mockResolvedValue({
    items: [campaign()],
    request_id: uuid("9"),
  });
});

afterEach(() => {
  vi.restoreAllMocks();
  push.mockReset();
});

describe("Point purchase management", () => {
  it("renders the V1 list columns plus audience and target tag", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listPointPurchasePlans");
    render(<PointPurchaseManagementWorkspace mode="list" />);
    expect(await screen.findByText("スタンダード")).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "商品名", "支払金額", "有償P", "無償P", "販売期間", "並び順", "対象カテゴリ", "対象タグ", "状態", "編集",
    ]);
    expect(screen.getByText("すべてのユーザー")).toBeVisible();
    expect(screen.getByText("VIP")).toBeVisible();
    expect(screen.getByRole("link", { name: "スタンダードを編集" })).toHaveAttribute(
      "href",
      `/purchase-plans/${plan().id}`,
    );
    expect(screen.getByLabelText("状態")).toHaveValue("published");
    expect(list).toHaveBeenCalledWith({ cursor: undefined, status: "published" });
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "draft" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith({ cursor: undefined, status: "draft" }));
  });

  it("defaults a new product to all users and no target tag", async () => {
    const create = vi.spyOn(AdminApiClient.prototype, "createPointPurchasePlan")
      .mockResolvedValue({ data: plan(), idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="create" />);
    expect(await screen.findByLabelText("対象カテゴリ")).toHaveValue("all_users");
    expect(screen.getByLabelText("対象タグ")).toHaveValue("");
    fireEvent.change(screen.getByLabelText("商品名"), { target: { value: "スタンダード" } });
    fireEvent.change(screen.getByLabelText("支払金額"), { target: { value: "1000" } });
    fireEvent.change(screen.getByLabelText("付与有償ポイント"), { target: { value: "1000" } });
    fireEvent.click(screen.getByRole("button", { name: "登録" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toMatchObject({
      audience_code: "all_users",
      free_point_amount: 0,
      paid_point_amount: 1000,
      target_user_tag_id: null,
    });
    expect(push).toHaveBeenCalledWith(`/purchase-plans/${plan().id}`);
  });

  it("loads and updates audience and target tag with revision OCC", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updatePointPurchasePlan")
      .mockResolvedValue({ data: { ...plan(), audience_code: "first_purchase_users" }, idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="edit" planId={plan().id} />);
    expect(await screen.findByLabelText("商品名")).toHaveValue("スタンダード");
    fireEvent.change(screen.getByLabelText("対象カテゴリ"), { target: { value: "first_purchase_users" } });
    fireEvent.change(screen.getByLabelText("対象タグ"), { target: { value: tag().id } });
    fireEvent.click(screen.getByRole("button", { name: "更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][1]).toMatchObject({
      audience_code: "first_purchase_users",
      expected_revision: 1,
      target_user_tag_id: tag().id,
    });
  });

  it("lists and updates an exact-version limited bonus campaign", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateLimitedBonusCampaign")
      .mockResolvedValue({ data: { ...campaign(), is_enabled: false, bonus_point_amount: 450 }, idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="edit" planId={plan().id} />);
    expect(await screen.findByText("期間限定ボーナスコイン")).toBeVisible();
    expect(screen.getByText("対象商品Version 1 にだけ適用されます。時刻判定と重複判定はBackendが確定します。")).toBeVisible();
    expect(await screen.findByText("300 コイン")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "編集" }));
    fireEvent.click(screen.getByLabelText("期間限定ボーナスコインをONにする"));
    fireEvent.change(screen.getByLabelText("追加ボーナスコイン量"), { target: { value: "450" } });
    fireEvent.click(screen.getByRole("button", { name: "設定を更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][0]).toBe(plan().id);
    expect(update.mock.calls[0][1]).toBe(campaign().id);
    expect(update.mock.calls[0][2]).toMatchObject({
      is_enabled: false,
      bonus_point_amount: 450,
      starts_at: "2026-08-20T09:00:00+09:00",
      ends_at: "2026-08-21T09:00:00+09:00",
    });
  });

  it("registers a limited bonus campaign with user-facing coin wording", async () => {
    vi.spyOn(AdminApiClient.prototype, "listLimitedBonusCampaigns").mockResolvedValue({ items: [], request_id: uuid("9") });
    const create = vi.spyOn(AdminApiClient.prototype, "createLimitedBonusCampaign")
      .mockResolvedValue({ data: campaign(), idempotent_replay: false, request_id: uuid("9") });
    render(<PointPurchaseManagementWorkspace mode="edit" planId={plan().id} />);
    expect(await screen.findByText("期間限定ボーナスコイン設定はありません。")).toBeVisible();
    fireEvent.change(screen.getByLabelText("期間限定ボーナスコイン開始日時"), { target: { value: "2026-08-20T09:00" } });
    fireEvent.change(screen.getByLabelText("期間限定ボーナスコイン終了日時"), { target: { value: "2026-08-21T09:00" } });
    fireEvent.change(screen.getByLabelText("追加ボーナスコイン量"), { target: { value: "300" } });
    fireEvent.click(screen.getByRole("button", { name: "設定を登録" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][0]).toBe(plan().id);
    expect(create.mock.calls[0][1]).toMatchObject({
      is_enabled: true,
      bonus_point_amount: 300,
      starts_at: "2026-08-20T09:00:00+09:00",
      ends_at: "2026-08-21T09:00:00+09:00",
    });
  });
});

function plan(): AdminPointPurchasePlan {
  return {
    amount: 1000,
    audience_code: "all_users",
    target_user_tag: { id: tag().id, is_active: true, name: "VIP" },
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

function tag(): AdminUserTag {
  return {
    created_at: "2026-08-01T00:00:00Z",
    id: uuid("2"),
    is_active: true,
    name: "VIP",
    revision: 1,
    updated_at: "2026-08-01T00:00:00Z",
  };
}

function campaign(): AdminLimitedBonusCampaign {
  return {
    id: uuid("3"),
    point_purchase_plan_id: plan().id,
    point_purchase_plan_version: 1,
    is_enabled: true,
    starts_at: "2026-08-20T00:00:00Z",
    ends_at: "2026-08-21T00:00:00Z",
    bonus_point_amount: 300,
    created_at: "2026-08-18T00:00:00Z",
    updated_at: "2026-08-18T00:00:00Z",
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
