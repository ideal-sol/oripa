import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { BannerManagementWorkspace } from "@/components/banners/banner-management-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminManagedBanner } from "@/lib/admin-api/generated";

const permissionSet = vi.hoisted(
  () => new Set(["content.read", "content.manage", "content.publish"]),
);

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: () => true,
    permissions: permissionSet,
    role: "admin",
    status: "ready",
  }),
}));
vi.mock("next/image", () => ({
  default: ({ unoptimized: _unoptimized, ...properties }:
    React.ImgHTMLAttributes<HTMLImageElement> & { unoptimized?: boolean }) =>
    createElement("img", { alt: properties.alt ?? "", ...properties }),
}));

const category = { id: uuid("1"), name: "トップ", created_at: "2026-08-05T00:00:00Z" };
beforeEach(() => {
  permissionSet.clear();
  permissionSet.add("content.read");
  permissionSet.add("content.manage");
  permissionSet.add("content.publish");
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
  it("previews changed local files without a blob URL and preserves registration", async () => {
    const upload = vi.spyOn(AdminApiClient.prototype, "uploadBannerAsset")
      .mockResolvedValue({
        byte_size: 12,
        id: uuid("6"),
        idempotent_replay: false,
        mime_type: "image/png",
        public_url: `/api/v2/content/assets/${uuid("6")}`,
      });
    const create = vi.spyOn(AdminApiClient.prototype, "createManagedBanner")
      .mockResolvedValue({ ...banner(), idempotent_replay: false });
    render(<BannerManagementWorkspace />);

    await screen.findByText("メインバナー");
    fireEvent.change(screen.getByLabelText("カテゴリ"), {
      target: { value: category.id },
    });
    fireEvent.change(screen.getByLabelText("タイトル"), {
      target: { value: "Preview Banner" },
    });
    const firstFile = new File(["first-image"], "first.png", { type: "image/png" });
    Object.defineProperty(firstFile, "arrayBuffer", {
      value: vi.fn().mockResolvedValue(new TextEncoder().encode("first-image").buffer),
    });
    fireEvent.change(screen.getByLabelText("画像"), {
      target: { files: [firstFile] },
    });

    const preview = await screen.findByAltText("登録するバナー画像のプレビュー");
    await waitFor(() => expect(preview.getAttribute("src")).toMatch(/^data:image\/png;base64,/u));
    const firstSource = preview.getAttribute("src");
    expect(firstSource).not.toMatch(/^blob:/u);

    const secondFile = new File(["second-image"], "second.png", { type: "image/png" });
    Object.defineProperty(secondFile, "arrayBuffer", {
      value: vi.fn().mockResolvedValue(new TextEncoder().encode("second-image").buffer),
    });
    fireEvent.change(screen.getByLabelText("画像"), {
      target: { files: [secondFile] },
    });
    await waitFor(() => expect(
      screen.getByAltText("登録するバナー画像のプレビュー").getAttribute("src"),
    ).not.toBe(firstSource));
    expect(screen.getByAltText("登録するバナー画像のプレビュー").getAttribute("src"))
      .toMatch(/^data:image\/png;base64,/u);

    fireEvent.submit(
      screen.getByRole("button", { name: "バナー登録" }).closest("form")!,
    );
    await waitFor(() => expect(upload).toHaveBeenCalledOnce());
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0]?.[0]).toMatchObject({
      asset_id: uuid("6"),
      category_id: category.id,
      title: "Preview Banner",
    });
  });

  it("renders registration, exact list columns, category filter, and public URL actions", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners");
    render(<BannerManagementWorkspace />);

    expect(await screen.findByRole("heading", { name: "バナー管理" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "バナー登録" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "アップロード画像", "タイトル", "カテゴリ", "状態", "Version", "トップ表示", "画像URL", "登録日", "公開", "編集", "削除",
    ]);
    expect(screen.getByText("Draft")).toBeVisible();
    expect(screen.getByText("v1")).toBeVisible();
    expect(screen.getByText(uuid("5"))).toBeVisible();
    expect(screen.getByText("/gachas")).toBeVisible();
    expect(screen.getByText("メインバナー")).toBeVisible();
    expect(screen.getByText(
      "https://storefront.example.test/api/v2/content/assets/01910191-0191-7191-8191-019101910192",
    )).toBeVisible();

    fireEvent.change(screen.getByLabelText("カテゴリ絞り込み"), {
      target: { value: category.id },
    });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined },
      expect.any(AbortSignal),
    ));

    fireEvent.click(screen.getByRole("button", { name: "メインバナーの画像URLをコピー" }));
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
      "https://storefront.example.test/api/v2/content/assets/01910191-0191-7191-8191-019101910192",
    );
  });

  it("publishes a Draft once and reloads the canonical Published banner", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners")
      .mockResolvedValueOnce({ items: [banner()], next_cursor: null })
      .mockResolvedValue({ items: [{ ...banner(), status: "published" }], next_cursor: null });
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner")
      .mockResolvedValue({
        id: banner().id,
        identifier: "main-banner",
        is_legal: false,
        status: "published",
        versions: [],
      });
    render(<BannerManagementWorkspace />);
    const button = await screen.findByRole("button", { name: "公開する" });

    fireEvent.click(button);
    fireEvent.click(button);

    await waitFor(() => expect(publish).toHaveBeenCalledOnce());
    expect(publish).toHaveBeenCalledWith(banner().id, banner().version_id);
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
    expect(await screen.findByText("Published")).toBeVisible();
    expect(screen.queryByRole("button", { name: "公開する" })).not.toBeInTheDocument();
  });

  it("does not offer publish without content.publish permission", async () => {
    permissionSet.delete("content.publish");
    render(<BannerManagementWorkspace />);

    expect(await screen.findByText("Draft")).toBeVisible();
    expect(screen.queryByRole("button", { name: "公開する" })).not.toBeInTheDocument();
  });

  it("shows the existing typed conflict error and re-enables publish", async () => {
    vi.spyOn(AdminApiClient.prototype, "publishContentBanner")
      .mockRejectedValue(new AdminApiError(409, "CONTENT_VERSION_CONFLICT", null, null, false));
    render(<BannerManagementWorkspace />);
    const button = await screen.findByRole("button", { name: "公開する" });

    fireEvent.click(button);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "同名カテゴリ、再利用Key、または別更新と競合しました。最新状態を再取得してください。",
    );
    expect(screen.getByRole("button", { name: "公開する" })).toBeEnabled();
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
    expect(within(edit).getByLabelText("トップに表示")).toBeChecked();
    expect(within(edit).getByLabelText("クリック先URL")).toHaveValue("/gachas");
    fireEvent.change(within(edit).getByLabelText("タイトル"), { target: { value: "更新バナー" } });
    fireEvent.click(within(edit).getByLabelText("トップに表示"));
    expect(within(edit).queryByLabelText("クリック先URL")).not.toBeInTheDocument();
    fireEvent.click(within(edit).getByRole("button", { name: "更新" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0]?.[1]).toMatchObject({ link_url: null, show_on_top: false });

    fireEvent.click(await screen.findByRole("button", { name: "メインバナーを削除" }));
    const deletion = screen.getByRole("dialog", { name: "バナー削除" });
    expect(within(deletion).getByText(/Versionと共有画像Assetは保持/)).toBeVisible();
    fireEvent.click(within(deletion).getByRole("button", { name: "削除" }));
    await waitFor(() => expect(remove).toHaveBeenCalledOnce());
  });
});

function banner(): AdminManagedBanner {
  return {
    asset: {
      id: uuid("2"),
      public_url: `https://storefront.example.test/api/v2/content/assets/${uuid("2")}`,
    },
    category,
    created_at: "2026-08-05T00:00:00Z",
    id: uuid("3"),
    link_url: "/gachas",
    show_on_top: true,
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
