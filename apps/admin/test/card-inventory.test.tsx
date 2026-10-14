import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const permission = vi.hoisted(() => ({ allowed: true }));
const expireSession = vi.hoisted(() => vi.fn());
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/auth/admin-auth-provider", () => ({ useAdminAuth: () => ({ expireSession }) }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ status: "ready", hasPermission: () => permission.allowed }) }));

import { CardInventoryWorkspace } from "@/components/inventory/card-inventory-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import { navigationItem } from "@/lib/permissions/admin-navigation";

const card = { external_id: "CARD-001", name: "Synthetic card", asset: null, allocation: 10, stored: 2, required_quantity: 12, shipped: 3, returned: 1, notion_on_hand: null, notion_available: null, notion_read_at: null };

describe("card inventory", () => {
  beforeEach(() => {
    permission.allowed = true;
    vi.spyOn(AdminApiClient.prototype, "listInventoryCards").mockResolvedValue({ items: [card], next_cursor: "page2", unlinked_prize_count: 1 });
    vi.spyOn(AdminApiClient.prototype, "getInventoryCard").mockResolvedValue({ data: card, breakdown: [], next_cursor: null, status_counts: { stored: 2 } });
    vi.spyOn(AdminApiClient.prototype, "listInventoryUnlinkedPrizes").mockResolvedValue({ items: [], total_count: 0, next_cursor: null });
  });

  it("shows backend totals, disconnected fields, detail and the unlinked warning", async () => {
    render(<CardInventoryWorkspace />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込んでいます");
    await screen.findByRole("button", { name: /CARD-001/ });
    expect(screen.getByRole("checkbox", { name: /空き在庫がマイナス/ })).toBeDisabled();
    expect(screen.getByText("12")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: /CARD-001/ }));
    await screen.findByText("確保必要数：12枚");
    expect(screen.getByText("2件")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "カード一覧へ戻る" }));
    fireEvent.click(await screen.findByRole("button", { name: "管理ID未設定景品を確認" }));
    await screen.findByText("管理ID未設定の対象景品はありません。");
  });

  it("paginates and resets cursor when search conditions change", async () => {
    render(<CardInventoryWorkspace />);
    await screen.findByRole("button", { name: /CARD-001/ });
    fireEvent.click(screen.getByRole("button", { name: "次へ" }));
    await waitFor(() => expect(AdminApiClient.prototype.listInventoryCards).toHaveBeenLastCalledWith(expect.objectContaining({ cursor: "page2" }), expect.any(AbortSignal)));
    fireEvent.change(screen.getByLabelText("管理ID", { exact: true }), { target: { value: "002" } });
    fireEvent.change(screen.getByLabelText("カード名", { exact: true }), { target: { value: "other" } });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));
    await waitFor(() => expect(AdminApiClient.prototype.listInventoryCards).toHaveBeenLastCalledWith(expect.objectContaining({ external_id: "002", name: "other", cursor: undefined }), expect.any(AbortSignal)));
  });

  it("uses catalog.read and never loads a denied page", () => {
    permission.allowed = false;
    expect(navigationItem("inventory-cards").permission).toBe("catalog.read");
    render(<CardInventoryWorkspace />);
    expect(screen.getByText("アクセスできません")).toBeVisible();
    expect(AdminApiClient.prototype.listInventoryCards).not.toHaveBeenCalled();
  });

  it("shows error, retry and empty states", async () => {
    vi.mocked(AdminApiClient.prototype.listInventoryCards).mockRejectedValueOnce(new AdminApiError(503, "UNAVAILABLE", null, null, true));
    render(<CardInventoryWorkspace />);
    await screen.findByRole("alert");
    vi.mocked(AdminApiClient.prototype.listInventoryCards).mockResolvedValue({ items: [], next_cursor: null, unlinked_prize_count: 0 });
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    await screen.findByText("該当するカードはありません。");
  });
});
