import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { BannerManagementWorkspace } from "@/components/banners/banner-management-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminManagedBanner } from "@/lib/admin-api/generated";

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: () => true,
    permissions: new Set(["content.read", "content.manage"]),
    role: "admin",
    status: "ready",
  }),
}));
vi.mock("next/image", () => ({
  default: (properties: React.ImgHTMLAttributes<HTMLImageElement>) =>
    createElement("img", { alt: properties.alt ?? "", ...properties }),
}));

const category = { id: uuid("1"), name: "トップ", created_at: "2026-08-05T00:00:00Z" };

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories")
    .mockResolvedValue({ items: [category] });
  vi.spyOn(AdminApiClient.prototype, "listManagedBanners")
    .mockResolvedValue({ items: [banner()], next_cursor: null });
  Object.defineProperty(navigator, "clipboard", {
    configurable: true,
    value: { writeText: vi.fn().mockResolvedValue(undefined) },
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Banner management", () => {
  it("renders registration, exact list columns, category filter, and public URL actions", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners");
    render(<BannerManagementWorkspace />);

    expect(await screen.findByRole("heading", { name: "バナー管理" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "バナー登録" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "アップロード画像", "タイトル", "カテゴリ", "画像URL", "登録日", "編集", "削除",
    ]);
    expect(screen.getByText("メインバナー")).toBeVisible();
    expect(screen.getByText("/admin/api/assets/banner.png")).toBeVisible();

    fireEvent.change(screen.getByLabelText("カテゴリ絞り込み"), {
      target: { value: category.id },
    });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined },
      expect.any(AbortSignal),
    ));

    fireEvent.click(screen.getByRole("button", { name: "メインバナーの画像URLをコピー" }));
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
      "http://localhost:3000/admin/api/assets/banner.png",
    );
  });

  it("creates a category without reloading and selects it", async () => {
    const created = { id: uuid("4"), name: "キャンペーン", created_at: "2026-08-05T00:00:00Z", idempotent_replay: false };
    const create = vi.spyOn(AdminApiClient.prototype, "createBannerCategory")
      .mockResolvedValue(created);
    render(<BannerManagementWorkspace />);
    await screen.findByText("メインバナー");

    fireEvent.click(screen.getByRole("button", { name: "カテゴリ追加" }));
    const dialog = screen.getByRole("dialog", { name: "カテゴリ追加" });
    fireEvent.change(within(dialog).getByLabelText("カテゴリ名"), {
      target: { value: "キャンペーン" },
    });
    fireEvent.click(within(dialog).getByRole("button", { name: "登録" }));

    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(screen.getByLabelText("カテゴリ")).toHaveValue(created.id);
  });

  it("opens edit and delete confirmations with current values", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner")
      .mockResolvedValue({ ...banner(), title: "更新バナー", idempotent_replay: false });
    const remove = vi.spyOn(AdminApiClient.prototype, "deleteManagedBanner")
      .mockResolvedValue({ id: banner().id, deleted: true, asset_retained: true, idempotent_replay: false });
    render(<BannerManagementWorkspace />);
    await screen.findByText("メインバナー");

    fireEvent.click(screen.getByRole("button", { name: "メインバナーを編集" }));
    const edit = screen.getByRole("dialog", { name: "バナー編集" });
    expect(within(edit).getByLabelText("タイトル")).toHaveValue("メインバナー");
    fireEvent.change(within(edit).getByLabelText("タイトル"), { target: { value: "更新バナー" } });
    fireEvent.click(within(edit).getByRole("button", { name: "更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());

    fireEvent.click(await screen.findByRole("button", { name: "メインバナーを削除" }));
    const deletion = screen.getByRole("dialog", { name: "バナー削除" });
    expect(within(deletion).getByText(/Versionと共有画像Assetは保持/)).toBeVisible();
    fireEvent.click(within(deletion).getByRole("button", { name: "削除" }));
    await waitFor(() => expect(remove).toHaveBeenCalledOnce());
  });
});

function banner(): AdminManagedBanner {
  return {
    asset: { id: uuid("2"), public_url: "/admin/api/assets/banner.png" },
    category,
    created_at: "2026-08-05T00:00:00Z",
    id: uuid("3"),
    status: "draft",
    title: "メインバナー",
    updated_at: "2026-08-05T00:00:00Z",
    version_id: uuid("5"),
    version_number: 1,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
