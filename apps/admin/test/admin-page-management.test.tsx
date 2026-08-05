import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { PageManagementWorkspace } from "@/components/pages/page-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminManagedPage } from "@/lib/admin-api/generated";

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, refresh: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ permissions: new Set(["content.read", "content.manage"]), role: "admin", status: "ready" }) }));

const category = { created_at: "2026-08-05T00:00:00Z", id: uuid("1"), name: "ご利用案内", visibility: "visible" as const };

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listPageCategories").mockResolvedValue({ items: [category] });
  vi.spyOn(AdminApiClient.prototype, "listManagedPages").mockResolvedValue({ items: [managedPage()], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "getManagedPage").mockResolvedValue(managedPage());
});
afterEach(() => { vi.restoreAllMocks(); push.mockReset(); });

describe("Page management", () => {
  it("renders the V1-based list order and page visibility", async () => {
    render(<PageManagementWorkspace mode="list" />);
    expect(await screen.findByText("ご利用ガイド")).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ページ", "URL", "カテゴリ", "表示状態", "更新日時", "編集",
    ]);
    expect(screen.getByText("/guide")).toBeVisible();
    expect(screen.getByRole("link", { name: "ご利用ガイドを編集" })).toHaveAttribute("href", `/settings/pages/${managedPage().id}`);
  });

  it("creates a visible category and immediately selects it", async () => {
    const created = { ...category, id: uuid("3"), name: "規約", idempotent_replay: false };
    vi.spyOn(AdminApiClient.prototype, "createPageCategory").mockResolvedValue(created);
    render(<PageManagementWorkspace mode="create" />);
    await screen.findByRole("heading", { name: "ページ新規登録" });
    fireEvent.click(screen.getByRole("button", { name: "カテゴリ追加" }));
    const dialog = screen.getByRole("dialog", { name: "カテゴリ追加" });
    fireEvent.change(within(dialog).getByLabelText("カテゴリ名"), { target: { value: "規約" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "登録" }));
    await waitFor(() => expect(screen.getByLabelText("カテゴリ")).toHaveValue(created.id));
  });

  it("loads current values and updates an immutable version", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedPage")
      .mockResolvedValue({ ...managedPage(), title: "更新ガイド", idempotent_replay: false });
    render(<PageManagementWorkspace mode="edit" pageId={managedPage().id} />);
    expect(await screen.findByLabelText("タイトル")).toHaveValue("ご利用ガイド");
    fireEvent.change(screen.getByLabelText("タイトル"), { target: { value: "更新ガイド" } });
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(push).toHaveBeenCalledWith(`/settings/pages/${managedPage().id}`);
  });
});

function managedPage(): AdminManagedPage {
  return { body_html: "<p>本文</p>", category, created_at: "2026-08-05T00:00:00Z", id: uuid("2"), slug: "guide", title: "ご利用ガイド", updated_at: "2026-08-05T01:00:00Z", version_id: uuid("4"), version_number: 1, visibility: "visible" };
}
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
