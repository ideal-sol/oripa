import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { createElement } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import BannersPage from "@/app/banners/page";
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
  const published = { ...banner(), status: "published" as const };
  const publishResult = { id: banner().id, identifier: "main-banner", is_legal: false, status: "published" as const, versions: [] };

  it.each([true, false])("saves published banner fields and publishes the returned version (top=%s)", async (showOnTop) => {
    const secondCategory = { ...category, id: uuid("7"), name: "別カテゴリ" };
    vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({ items: [category, secondCategory] });
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [published], next_cursor: null });
    const upload = vi.spyOn(AdminApiClient.prototype, "uploadBannerAsset").mockResolvedValue({ id: uuid("6"), byte_size: 3, mime_type: "image/png", public_url: "/image.png", idempotent_replay: false });
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner").mockResolvedValue({ ...published, version_id: uuid("8"), idempotent_replay: false });
    let finishPublish!: (value: typeof publishResult) => void;
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner").mockImplementation(() => new Promise((resolve) => { finishPublish = resolve; }));
    render(<BannerManagementWorkspace />);
    fireEvent.click(await screen.findByRole("button", { name: "メインバナーを編集" }));
    const dialog = screen.getByRole("dialog");
    fireEvent.change(within(dialog).getByLabelText("カテゴリ"), { target: { value: secondCategory.id } });
    fireEvent.change(within(dialog).getByLabelText("タイトル"), { target: { value: "変更タイトル" } });
    fireEvent.change(within(dialog).getByLabelText("クリック先URL"), { target: { value: "/gachas/new" } });
    if (!showOnTop) fireEvent.click(within(dialog).getByLabelText("トップに表示"));
    const file = new File(["png"], "new.png", { type: "image/png" });
    Object.defineProperty(file, "arrayBuffer", { value: vi.fn().mockResolvedValue(new TextEncoder().encode("png").buffer) });
    fireEvent.change(within(dialog).getByLabelText("画像（変更する場合のみ）"), { target: { files: [file] } });
    fireEvent.submit(within(dialog).getByRole("button", { name: "更新" }).closest("form")!);
    fireEvent.submit(within(dialog).getByRole("button", { name: "更新中" }).closest("form")!);
    await waitFor(() => expect(publish).toHaveBeenCalledWith(published.id, uuid("8")));
    expect(update).toHaveBeenCalledExactlyOnceWith(published.id, expect.objectContaining({ asset_id: uuid("6"), category_id: secondCategory.id, title: "変更タイトル", link_url: showOnTop ? "/gachas/new" : null, show_on_top: showOnTop }), expect.any(String));
    expect(upload).toHaveBeenCalledOnce();
    expect(update.mock.invocationCallOrder[0]).toBeLessThan(publish.mock.invocationCallOrder[0]!);
    expect(dialog).toBeVisible();
    expect(list).toHaveBeenCalledOnce();
    finishPublish(publishResult);
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
  });

  it("keeps draft edits unpublished", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner").mockResolvedValue({ ...banner(), version_id: uuid("8"), idempotent_replay: false });
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner");
    permissionSet.delete("content.publish");
    render(<BannerManagementWorkspace />);
    fireEvent.click(await screen.findByRole("button", { name: "メインバナーを編集" }));
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "更新" }));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
    expect(update).toHaveBeenCalledOnce();
    expect(publish).not.toHaveBeenCalled();
  });

  it.each([false, true])("retains a saved version after publish failure and retries without another save (close=%s)", async (close) => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [published], next_cursor: null });
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner").mockResolvedValue({ ...published, version_id: uuid("8"), idempotent_replay: false });
    const upload = vi.spyOn(AdminApiClient.prototype, "uploadBannerAsset");
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner").mockRejectedValueOnce(new Error("unavailable")).mockResolvedValue(publishResult);
    render(<BannerManagementWorkspace />);
    fireEvent.click(await screen.findByRole("button", { name: "メインバナーを編集" }));
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "更新" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("更新内容は保存されましたが、公開反映に失敗しました");
    expect(list).toHaveBeenCalledOnce();
    expect(within(screen.getByRole("dialog")).getByLabelText("タイトル")).toBeDisabled();
    if (close) {
      fireEvent.click(screen.getByRole("button", { name: "バナー編集を閉じる" }));
      fireEvent.click(screen.getByRole("button", { name: "最新内容を公開に反映" }));
    } else {
      fireEvent.click(screen.getByRole("button", { name: "公開反映を再試行" }));
    }
    await waitFor(() => expect(publish).toHaveBeenCalledTimes(2));
    expect(publish.mock.calls.map((args) => args.slice(0, 2))).toEqual([[published.id, uuid("8")], [published.id, uuid("8")]]);
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
    expect(update).toHaveBeenCalledOnce();
    expect(upload).not.toHaveBeenCalled();
  });

  it.each([1, 2])("reflects the latest existing version without creating or uploading (v%s)", async (version) => {
    const latest = { ...published, version_id: uuid("8"), version_number: version };
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [latest], next_cursor: null });
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner");
    const upload = vi.spyOn(AdminApiClient.prototype, "uploadBannerAsset");
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner").mockResolvedValue(publishResult);
    render(<BannerManagementWorkspace />);
    fireEvent.click(await screen.findByRole("button", { name: "最新内容を公開に反映" }));
    await waitFor(() => expect(publish).toHaveBeenCalledExactlyOnceWith(latest.id, latest.version_id));
    expect(update).not.toHaveBeenCalled();
    expect(upload).not.toHaveBeenCalled();
  });

  it.each(["content.publish", "content.manage"])("blocks published edits without %s", async (permission) => {
    permissionSet.delete(permission);
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [published], next_cursor: null });
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner");
    const publish = vi.spyOn(AdminApiClient.prototype, "publishContentBanner");
    render(<BannerManagementWorkspace />);
    await screen.findByText("Published");
    expect(screen.queryByRole("button", { name: "メインバナーを編集" })).not.toBeInTheDocument();
    if (permission === "content.publish") expect(screen.queryByRole("button", { name: "最新内容を公開に反映" })).not.toBeInTheDocument();
    expect(update).not.toHaveBeenCalled();
    expect(publish).not.toHaveBeenCalled();
  });

  it("continues listing Banners with and without an external ID", async () => {
    vi.mocked(AdminApiClient.prototype.listManagedBanners).mockResolvedValue({ items: [
      { ...banner(), external_id: "CARD-0001" },
      { ...banner(), id: uuid("8"), title: "管理IDなしバナー", external_id: null },
    ], next_cursor: null });
    render(<BannerManagementWorkspace />);
    expect(await screen.findByText("メインバナー")).toBeVisible();
    expect(screen.getByText("CARD-0001")).toBeVisible();
    expect(screen.getByText("管理IDなしバナー")).toBeVisible();
    expect(screen.getByRole("button", { name: "管理IDなしバナーを編集" })).toBeEnabled();
  });

  it("shows, edits and clears the external ID and reports conflicts", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
      items: [{ ...banner(), external_id: "CARD-0001" }], next_cursor: null,
    });
    const update = vi.spyOn(AdminApiClient.prototype, "updateManagedBanner")
      .mockRejectedValueOnce(new AdminApiError(409, "BANNER_EXTERNAL_ID_CONFLICT", null, null, false))
      .mockResolvedValue({ ...banner(), external_id: null, idempotent_replay: false });
    render(<BannerManagementWorkspace />);
    expect(await screen.findByText("CARD-0001")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "メインバナーを編集" }));
    const dialog = screen.getByRole("dialog", { name: "バナー編集" });
    const field = within(dialog).getByLabelText("管理ID");
    expect(field).toHaveValue("CARD-0001");
    fireEvent.change(field, { target: { value: " CARD-0002 " } });
    fireEvent.submit(field.closest("form")!);
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][1].external_id).toBe("CARD-0002");
    expect(await within(dialog).findByRole("alert")).toHaveTextContent("この管理IDは別のバナーで使用されています。");
    fireEvent.change(field, { target: { value: "" } });
    fireEvent.submit(field.closest("form")!);
    await waitFor(() => expect(update).toHaveBeenCalledTimes(2));
    expect(update.mock.calls[1][1].external_id).toBeNull();
  });

  it.each([undefined, "all", "published", "draft", ["draft", "published"]])("honors the page status query %j", async (status) => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners");
    render(await BannersPage({ searchParams: Promise.resolve({ status }) }));
    await screen.findByText("メインバナー");
    const expected = Array.isArray(status) ? status[0] : status ?? "all";
    expect(screen.getByLabelText("状態")).toHaveValue(expected);
    expect(list).toHaveBeenCalledWith(
      { category_id: undefined, cursor: undefined, status: expected === "all" ? undefined : expected },
      expect.any(AbortSignal),
    );
  });

  it("shows published and draft banners together by default", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
      items: [banner(), { ...banner(), id: uuid("8"), title: "公開バナー", status: "published" }],
      next_cursor: null,
    });
    render(<BannerManagementWorkspace />);
    expect(await screen.findByText("メインバナー")).toBeVisible();
    expect(screen.getByText("公開バナー")).toBeVisible();
    expect(screen.getByText("Draft")).toBeVisible();
    expect(screen.getByText("Published")).toBeVisible();
  });

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
      external_id: null,
    });
  });

  it("renders registration, exact list columns, category filter, and public URL actions", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners");
    render(<BannerManagementWorkspace />);

    expect(await screen.findByRole("heading", { name: "バナー管理" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "バナー登録" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "アップロード画像", "タイトル", "管理ID", "カテゴリ", "状態", "Version", "トップ表示", "画像URL", "登録日", "公開", "編集", "削除",
    ]);
    expect(screen.getByText("Draft")).toBeVisible();
    expect(screen.getByText("v1")).toBeVisible();
    expect(screen.getByTitle(uuid("5"))).toHaveTextContent(`${uuid("5").slice(0, 8)}…${uuid("5").slice(-8)}`);
    expect(screen.getByText("/gachas")).toBeVisible();
    expect(screen.getByText("メインバナー")).toBeVisible();
    expect(screen.getByText(
      "https://storefront.example.test/api/v2/content/assets/01910191-0191-7191-8191-019101910192",
    )).toBeVisible();
    expect(screen.getByLabelText("状態")).toHaveValue("all");
    expect(list).toHaveBeenCalledWith(
      { category_id: undefined, cursor: undefined, status: undefined },
      expect.any(AbortSignal),
    );

    fireEvent.change(screen.getByLabelText("カテゴリ絞り込み"), {
      target: { value: category.id },
    });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined, status: undefined },
      expect.any(AbortSignal),
    ));

    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "published" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined, status: "published" },
      expect.any(AbortSignal),
    ));
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "draft" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined, status: "draft" },
      expect.any(AbortSignal),
    ));
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "all" } });
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      { category_id: category.id, cursor: undefined, status: undefined },
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
