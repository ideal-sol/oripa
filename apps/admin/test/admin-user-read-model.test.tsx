import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminUserReadData } from "@/components/users/use-admin-user-read-model";
import type { AdminPermissionCode } from "@/lib/admin-api/generated";

const state = {
  data: null as AdminUserReadData | null,
  error: null as string | null,
  loadMore: vi.fn(async () => undefined),
  loading: false,
  loadingMore: false,
  retry: vi.fn(),
};
const permissions = new Set<AdminPermissionCode>();

vi.mock("@/components/users/use-admin-user-read-model", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/components/users/use-admin-user-read-model")>();
  return { ...original, useAdminUserReadModel: () => state };
});
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: AdminPermissionCode) => permissions.has(permission),
    permissions,
    role: "operator",
    status: "ready",
  }),
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({ FreshMfaDialog: () => null }));

import { AdminUserReadWorkspace } from "@/components/users/admin-user-read-workspace";

describe("Admin User Read workspace", () => {
  beforeEach(() => {
    state.data = null;
    state.error = null;
    state.loading = false;
    state.loadingMore = false;
    state.loadMore.mockClear();
    state.retry.mockClear();
    permissions.clear();
  });

  it("renders the fixed list column order, backend balances, and unset display name", () => {
    state.data = { kind: "list", value: {
      items: [userSummary()], next_cursor: "djE6MTA=", request_id: uuid("9"),
    } };
    render(<AdminUserReadWorkspace mode="list" />);

    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "ユーザー名", "状態", "合計残高", "有償P", "無償P", "登録日", "詳細",
    ]);
    const row = screen.getAllByRole("row")[1];
    expect(within(row).getByText("未設定")).toBeVisible();
    expect(within(row).getByText("300 pt")).toBeVisible();
    expect(within(row).getByText("100 pt")).toBeVisible();
    expect(within(row).getByText("200 pt")).toBeVisible();
    expect(within(row).getByRole("link", { name: "未設定の詳細" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}`,
    );
    fireEvent.click(screen.getByRole("button", { name: "次の50件を表示" }));
    expect(state.loadMore).toHaveBeenCalledOnce();
  });

  it("renders V2 detail without owned prizes and links to the separate history route", () => {
    state.data = { kind: "detail", value: {
      ...userSummary(),
      email: "user@example.test",
      email_verified_at: "2026-08-03T00:00:00Z",
      tag_assignment_revision: 1,
      tags: [],
      updated_at: "2026-08-03T01:00:00Z",
    } };
    render(<AdminUserReadWorkspace mode="detail" userPublicId={uuid("1")} />);

    expect(screen.getByRole("heading", { name: "基本情報" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "ポイント残高" })).toBeVisible();
    expect(screen.queryByText("ユーザー保有景品")).toBeNull();
    expect(screen.getByRole("link", { name: "ガチャ履歴を表示" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}/gacha-history`,
    );
    expect(screen.queryByRole("button", { name: /調整|Save|保存/u })).toBeNull();
  });

  it("shows point adjustment only when the canonical permission is effective", () => {
    state.data = { kind: "detail", value: {
      ...userSummary(),
      email: "user@example.test",
      email_verified_at: "2026-08-03T00:00:00Z",
      tag_assignment_revision: 1,
      tags: [],
      updated_at: "2026-08-03T01:00:00Z",
    } };
    permissions.add("point.adjustment.manage");
    render(<AdminUserReadWorkspace mode="detail" userPublicId={uuid("1")} />);

    expect(screen.getByRole("button", { name: "ポイント調整" })).toBeVisible();
  });

  it("renders acquired-prize history on its own route with V1-derived order", () => {
    state.data = { kind: "history", value: {
      items: [historyItem()], next_cursor: null, request_id: uuid("9"), user_id: uuid("1"),
    } };
    render(<AdminUserReadWorkspace mode="history" userPublicId={uuid("1")} />);

    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "ガチャ", "景品", "ランク", "状態", "交換P", "取得日", "保管期限",
    ]);
    expect(screen.getByText("テストガチャ")).toBeVisible();
    expect(screen.getByText("景品A")).toBeVisible();
    expect(screen.getByRole("link", { name: "ユーザー詳細へ" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}`,
    );
  });

  it("announces loading, empty, and recoverable error states", () => {
    state.loading = true;
    const { rerender } = render(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込んでいます");

    state.loading = false;
    state.error = "取得できません。";
    rerender(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("alert")).toHaveTextContent("取得できません。");
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(state.retry).toHaveBeenCalledOnce();

    state.error = null;
    state.data = { kind: "list", value: { items: [], next_cursor: null, request_id: uuid("9") } };
    rerender(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("status")).toHaveTextContent("表示できるユーザーはいません");
  });
});

function userSummary() {
  return {
    created_at: "2026-08-03T00:00:00Z",
    display_name: null,
    id: uuid("1"),
    point_balance: { free_balance: 200, paid_balance: 100, total_balance: 300 },
    status: "active" as const,
  };
}

function historyItem() {
  return {
    acquired_at: "2026-08-03T00:00:00Z",
    draw_result_id: uuid("2"),
    exchange_point_snapshot: 500,
    exchanged_point_amount: null,
    gacha_id: uuid("3"),
    gacha_title: "テストガチャ",
    gacha_version_id: uuid("4"),
    id: uuid("5"),
    prize_id: uuid("6"),
    prize_name: "景品A",
    rank_id: uuid("7"),
    rank_name: "Sランク",
    status: "stored",
    storage_expires_at: "2026-10-02T00:00:00Z",
    terminal_at: null,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
