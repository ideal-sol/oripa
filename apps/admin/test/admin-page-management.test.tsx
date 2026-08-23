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
  vi.spyOn(AdminApiClient.prototype, "previewManagedPage").mockResolvedValue({ body_html: "<p>安全な本文</p>", title: "ご利用ガイド" });
});
afterEach(() => { vi.restoreAllMocks(); push.mockReset(); });

describe("Page management", () => {
  it("renders the V1-based list order and page visibility", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedPages");
    render(<PageManagementWorkspace mode="list" />);
    expect(await screen.findByText("ご利用ガイド")).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ページ", "URL", "カテゴリ", "表示状態", "フッター", "更新日時", "編集",
    ]);
    expect(screen.getByText("表示（20）")).toBeVisible();
    expect(screen.getByText("/guide")).toBeVisible();
    expect(screen.getByRole("link", { name: "ご利用ガイドを編集" })).toHaveAttribute("href", `/settings/pages/${managedPage().id}`);
    expect(screen.getByLabelText("公開状態")).toHaveValue("published,draft");
    expect(list).toHaveBeenCalledWith(
      { cursor: undefined, status: "published,draft" },
      expect.any(AbortSignal),
    );
    fireEvent.change(screen.getByLabelText("公開状態"), { target: { value: "draft" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { cursor: undefined, status: "draft" },
      expect.any(AbortSignal),
    ));
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
    expect(screen.getByLabelText("フッターに表示")).toBeChecked();
    expect(screen.getByLabelText("フッター表示順")).toHaveValue(20);
    fireEvent.change(screen.getByLabelText("タイトル"), { target: { value: "更新ガイド" } });
    fireEvent.click(screen.getByRole("button", { name: "プレビュー" }));
    expect(await screen.findByRole("dialog", { name: "ご利用ガイド" })).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "ページプレビューを閉じる" }));
    fireEvent.click(screen.getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(AdminApiClient.prototype.getManagedPage).toHaveBeenCalledTimes(2);
    expect(push).toHaveBeenCalledWith(`/settings/pages/${managedPage().id}`);
  });

  it("defaults new pages to footer off", async () => {
    render(<PageManagementWorkspace mode="create" />);
    await screen.findByRole("heading", { name: "ページ新規登録" });
    expect(screen.getByLabelText("フッターに表示")).not.toBeChecked();
    expect(screen.queryByLabelText("フッター表示順")).not.toBeInTheDocument();
  });
});

function managedPage(): AdminManagedPage {
  return { body_html: "<p>本文</p>", category, created_at: "2026-08-05T00:00:00Z", footer_sort_order: 20, id: uuid("2"), show_in_footer: true, slug: "guide", title: "ご利用ガイド", updated_at: "2026-08-05T01:00:00Z", version_id: uuid("4"), version_number: 1, visibility: "visible" };
}
function uuid(last: string): string { return `01910191-0191-7191-8191-01910191019${last}`; }
