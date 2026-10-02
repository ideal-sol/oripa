import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import GachaCreatePage from "@/app/catalog/gachas/new/page";
import { LoginGachaWorkspace } from "@/components/catalog/login-gacha-workspace";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogGacha, AdminCatalogGachaCoreVersion, AdminCatalogGachaVersion, AdminCatalogPresentationAsset, AdminGachaType, AdminManagedBanner } from "@/lib/admin-api/generated";
import { drawStateCountLabel, emptyGachaComposition, fixedPercentageScale, jstInput, jstTimestamp, percentageTotal, percentageUnits, standardCoreVersion, standardGachaVersion } from "@/lib/catalog/login-gacha";

const callbacks = vi.hoisted(() => ({ expireSession: vi.fn(), push: vi.fn() }));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: callbacks.push }) }));
vi.mock("@/components/shell/admin-shell", () => ({ AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/protected-admin-route", () => ({ ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock("@/components/permissions/permission-provider", () => ({ usePermissions: () => ({ hasPermission: () => true }) }));
vi.mock("@/components/auth/admin-auth-provider", () => ({ useAdminAuth: () => ({ expireSession: callbacks.expireSession }) }));

afterEach(() => { vi.restoreAllMocks(); });

function selections() {
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({ items: [] });
  vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogRanks").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogPresentationAssets").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogCategories").mockResolvedValue({ items: [], next_cursor: null });
  vi.spyOn(AdminApiClient.prototype, "listCatalogTags").mockResolvedValue({ items: [], next_cursor: null });
}

describe("Login Gacha composition", () => {
  it("narrows standard capacity without turning a login null into a numeric fallback", () => {
    const core: AdminCatalogGachaCoreVersion = {
      id: "version", version_number: 1, status: "draft", title: "Standard", description: null, notices: null,
      price_points: 1, total_count: 6, daily_draw_limit: 0, audience_code: "all_users", presentation_asset: null,
      publish_start_at: "2026-10-01T00:00:00Z", publish_end_at: null,
    };
    const version: AdminCatalogGachaVersion = {
      ...core, published_probability_version: null, cloned_from_version: null, published_at: null, prizes: [],
      is_archived: false, revision: 1, archived_at: null, created_at: "", updated_at: "",
    };
    expect(standardCoreVersion(core)).toBe(core);
    expect(standardGachaVersion(version)).toBe(version);
    expect(() => standardCoreVersion({ ...core, total_count: null, price_points: 0 })).toThrow();
    expect(() => standardGachaVersion({ ...version, total_count: null, price_points: 0 })).toThrow();
    expect(core.total_count).toBe(6);
    expect(version.total_count).toBe(6);
  });

  it("keeps standard draw counters numeric and distinguishes login capacity from zero or absent state", () => {
    expect(drawStateCountLabel({ status: "selling", sold_count: 2, total_count: 6 })).toBe("2 / 6");
    expect(drawStateCountLabel({ status: "selling", sold_count: 2, total_count: 6 }, " of ")).toBe("2 of 6");
    expect(drawStateCountLabel({ status: "selling", sold_count: 2, total_count: null })).toBe("2（総口数なし）");
    expect(drawStateCountLabel(null)).toBe("未設定");
  });

  it("preserves exact decimal strings without floating point or ppm conversion", () => {
    expect(percentageUnits("0.0000000001")).toBe(1n);
    expect(percentageUnits("99.9999999999")).toBe(fixedPercentageScale - 1n);
    expect(percentageTotal(["0.0000000001", "99.9999999999"])).toEqual({ valid: true, label: "100%" });
    expect(percentageTotal(["0.0000000001", "99.9999999998"]).valid).toBe(false);
    for (const value of ["0", "1e-10", "1.00000000001", "100.0000000001", "1x5", "01", " 1", "+1"]) {
      expect(percentageUnits(value)).toBeNull();
    }
    expect(percentageTotal(["0", "100"]).valid).toBe(false);
  });

  it("interprets publication inputs as JST independent of browser timezone", () => {
    expect(jstTimestamp("2026-10-01T00:00")).toBe("2026-10-01T00:00:00+09:00");
    expect(jstInput("2026-09-30T15:00:00Z")).toBe("2026-10-01T00:00");
    expect(jstTimestamp("")).toBeNull();
  });

  it("offers three distinct registration routes without a common type selector", () => {
    render(<GachaCreatePage />);
    expect(screen.getByRole("link", { name: "通常ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/standard");
    expect(screen.getByRole("link", { name: "新規登録限定ガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/signup");
    expect(screen.getByRole("link", { name: "ログインガチャを登録" })).toHaveAttribute("href", "/catalog/gachas/new/login");
    expect(screen.queryByRole("combobox")).not.toBeInTheDocument();
  });

  it("shows daily price and minimum, but no total, category, shipping or audience fields", async () => {
    selections();
    render(<LoginGachaWorkspace type="login_daily" />);
    expect(await screen.findByLabelText("消費ポイント")).toHaveValue(0);
    expect(screen.getByLabelText("最低保証（交換ポイント）")).toHaveValue(0);
    for (const label of ["総口数", "カテゴリ", "タグ", "発送専用", "対象ユーザー", "ガチャ種別"]) {
      expect(screen.queryByLabelText(label)).not.toBeInTheDocument();
    }
    expect(screen.getByRole("button", { name: "構成を一括保存" })).toBeDisabled();
  });

  it("keeps signup free and omits paid configuration", async () => {
    selections();
    render(<LoginGachaWorkspace type="signup_once" />);
    expect(await screen.findByText("消費ポイント: 無料（0）")).toBeInTheDocument();
    expect(screen.queryByLabelText("消費ポイント")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("最低保証（交換ポイント）")).not.toBeInTheDocument();
    expect(emptyGachaComposition("signup_once").price_points).toBe(0);
  });

  it("loads a copy into an unsaved draft without creating a Canonical or retaining periods", async () => {
    selections();
    const composition = { ...emptyGachaComposition("signup_once"), title: "Copied signup" };
    const read = vi.spyOn(AdminApiClient.prototype, "getGachaComposition").mockResolvedValue({ data: composition });
    vi.spyOn(AdminApiClient.prototype, "getCatalogGacha").mockResolvedValue({ data: {
      id: "source", code: "source", slug: "source", state: "active", category: null, tags: [], published_version: null,
      version_count: 1, sold_count: 0, has_draw_history: true, is_archived: false, revision: 1, archived_at: null, created_at: "", updated_at: "",
    } });
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaComposition");
    render(<LoginGachaWorkspace sourceId="source" copy />);
    expect(await screen.findByLabelText("ガチャ名")).toHaveValue("Copied signup");
    expect(screen.getByLabelText("公開開始日時（JST）")).toHaveValue("");
    expect(read).toHaveBeenCalledWith("source", true, expect.any(AbortSignal));
    fireEvent.change(screen.getByLabelText("ガチャ名"), { target: { value: "Unsaved title" } });
    await waitFor(() => expect(create).not.toHaveBeenCalled());
  });
});

const thumbnail: AdminCatalogPresentationAsset = {
  id: "0198a001-0000-7000-8000-000000000005", media_type: "image", mime_type: "image/png", is_public: true,
  public_path: "/synthetic-thumbnail.png", alt_text: "現在のサムネイル",
  checksum_sha256: "a".repeat(64), byte_size: 68, revision: 1, is_archived: false, archived_at: null, created_at: "", updated_at: "",
};

function prizeBanner(categoryId: string, assetId: string): AdminManagedBanner {
  return { id: `banner-${assetId}`, title: `Banner ${assetId}`, status: "published", show_on_top: false, link_url: null,
    category: { id: categoryId, name: categoryId }, asset: { id: assetId, public_url: "/qa-banner.png" },
    version_id: "banner-version", version_number: 1, created_at: "", updated_at: "" };
}

describe.each(["login_daily", "signup_once"] as const)("%s standard Banner picker", (type) => {
  it("loads category-scoped paginated Banners and saves only the selected Asset without changing the thumbnail", async () => {
    const { update, upload } = thumbnailFixture(type);
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockResolvedValue({ items: [{ id: "cards", name: "Cards" }, { id: "empty", name: "Empty" }] });
    const list = vi.mocked(AdminApiClient.prototype.listManagedBanners).mockImplementation(async (query) => query?.category_id === "cards"
      ? { items: [prizeBanner("cards", query.cursor ? "chosen" : "first")], next_cursor: query.cursor ? null : "next" }
      : { items: [], next_cursor: null });
    render(<LoginGachaWorkspace type={type} sourceId="source" />);
    const category = await screen.findByLabelText("Banner Category");
    await screen.findByText(/一意に特定できませんでした/u);
    expect(screen.queryByLabelText("景品画像")).not.toBeInTheDocument();
    fireEvent.change(category, { target: { value: "cards" } });
    fireEvent.click(await screen.findByRole("button", { name: "Banner chosen" }));
    expect(screen.getByRole("button", { name: "Banner chosen" })).toHaveAttribute("aria-pressed", "true");
    expect(list).toHaveBeenCalledWith({ category_id: "cards", cursor: "next" }, expect.any(AbortSignal));
    fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledWith("source", expect.objectContaining({ composition: expect.objectContaining({
      presentation_asset_id: thumbnail.id, prizes: [expect.objectContaining({ presentation_asset_id: "chosen", percentage: "100.0000000000" })],
    }) }), expect.any(String)));
    expect(upload).not.toHaveBeenCalled();
    fireEvent.change(category, { target: { value: "empty" } });
    await screen.findByText("このCategoryに選択可能なBannerはありません。");
    expect(screen.queryByRole("button", { name: "Banner chosen" })).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
    expect(screen.getByText("選択したBanner CategoryからBannerを選択してください。")).toHaveAttribute("role", "alert");
    expect(update).toHaveBeenCalledTimes(1);
  });

  it("keeps an unresolved existing Copy Asset unless a different Banner is selected", async () => {
    const { create, upload } = thumbnailFixture(type, "published", true);
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockResolvedValue({ items: [{ id: "cards", name: "Cards" }] });
    render(<LoginGachaWorkspace type={type} sourceId="source" copy />);
    await screen.findByText(/一意に特定できませんでした/u);
    fireEvent.change(screen.getByLabelText("公開開始日時（JST）"), { target: { value: "2026-10-02T00:00" } });
    fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
    await waitFor(() => expect(create).toHaveBeenCalledWith(expect.objectContaining({
      presentation_asset_id: thumbnail.id, prizes: [expect.objectContaining({ presentation_asset_id: thumbnail.id })],
    }), expect.any(String)));
    expect(upload).not.toHaveBeenCalled();
  });

  it("preserves the second prize picker state when the first prize is removed", async () => {
    thumbnailFixture(type);
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockResolvedValue({ items: [{ id: "cards", name: "Cards" }] });
    vi.mocked(AdminApiClient.prototype.listManagedBanners).mockResolvedValue({ items: [prizeBanner("cards", "second")], next_cursor: null });
    const { container } = render(<LoginGachaWorkspace type={type} sourceId="source" />);
    await screen.findByLabelText("Banner Category");
    fireEvent.click(screen.getByRole("button", { name: "景品を追加" }));
    const second = within(container.querySelectorAll<HTMLElement>(".catalog-prize-fieldset")[1]);
    await second.findByRole("option", { name: "Cards" });
    fireEvent.change(second.getByLabelText("Banner Category"), { target: { value: "cards" } });
    fireEvent.click(await second.findByRole("button", { name: "Banner second" }));
    fireEvent.click(screen.getAllByRole("button", { name: "景品を削除" })[0]);
    expect(screen.getByLabelText("Banner Category")).toHaveValue("cards");
    expect(screen.getByRole("button", { name: "Banner second" })).toHaveAttribute("aria-pressed", "true");
  });

  it("shows the existing shared picker error without creating or uploading data", async () => {
    const { update, upload } = thumbnailFixture(type);
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockRejectedValue(new Error("QA network error"));
    render(<LoginGachaWorkspace type={type} sourceId="source" />);
    expect(await screen.findByRole("alert")).toHaveTextContent("選択肢を取得できませんでした。");
    expect(update).not.toHaveBeenCalled();
    expect(upload).not.toHaveBeenCalled();
  });
});

function thumbnailFixture(type: AdminGachaType, status: "draft" | "published" | "sales_paused" = "draft", copy = false) {
  selections();
  vi.spyOn(AdminApiClient.prototype, "getCatalogPresentationAsset").mockResolvedValue({ data: thumbnail });
  const rankImage = { id: thumbnail.id, path: thumbnail.public_path!, mime_type: "image/png", alt_text: "QA rank" };
  vi.mocked(AdminApiClient.prototype.listCatalogRanks).mockResolvedValue({ items: [{ id: "rank", rank_name: "A", lineup_image: rankImage, result_image: rankImage,
    show_total_stock: false, status: "active", display_order: 0, revision: 1, revision_number: 1, has_usage: false, used_by_published_gacha: false, created_at: "", updated_at: "" }], next_cursor: null });
  const composition = { ...emptyGachaComposition(type), title: "QA thumbnail", presentation_asset_id: thumbnail.id,
    publish_start_at: copy ? null : "2026-10-02T00:00:00+09:00",
    prizes: [{ name: "QA prize", presentation_asset_id: thumbnail.id, rank_id: "rank", exchange_points: 1, cost_price: 0, initial_inventory: 1, shipping_only: false, percentage: "100.0000000000" }],
  };
  const gacha: AdminCatalogGacha = { id: "source", code: "source", slug: "source", gacha_type: type, state: "active", category: null,
    tags: [], published_version: null, version_count: 1, sold_count: 0, has_draw_history: false, is_archived: false,
    revision: 1, archived_at: null, created_at: "", updated_at: "", publication_status: status, first_published_at: status === "draft" ? null : "2026-10-01T00:00:00Z",
    current_version: { id: "version", version_number: 1, revision: 1, status: status === "draft" ? "draft" : "published", title: composition.title,
      description: null, notices: null, price_points: 0, total_count: null, daily_draw_limit: 1, audience_code: "all_users",
      presentation_asset: thumbnail, publish_start_at: "2026-10-02T00:00:00+09:00", publish_end_at: null },
  };
  vi.spyOn(AdminApiClient.prototype, "getGachaComposition").mockResolvedValue({ data: composition });
  vi.spyOn(AdminApiClient.prototype, "getCatalogGacha").mockResolvedValue({ data: gacha });
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({ items: [], version_revision: 1 });
  const upload = vi.spyOn(AdminApiClient.prototype, "uploadGachaThumbnail").mockResolvedValue({ data: { ...thumbnail, id: "uploaded-thumbnail" }, idempotent_replay: false });
  const create = vi.spyOn(AdminApiClient.prototype, "createGachaComposition").mockRejectedValue(new Error("QA save boundary"));
  const update = vi.spyOn(AdminApiClient.prototype, "updateGachaComposition").mockRejectedValue(new Error("QA save boundary"));
  return { upload, create, update };
}

describe.each(["login_daily", "signup_once"] as const)("%s shared thumbnail form", (type) => {
  it("uses the standard white card, fields and upload input with an empty preview on create", async () => {
    selections();
    const { container } = render(<LoginGachaWorkspace type={type} />);
    const input = await screen.findByLabelText(/サムネイル画像/u);
    expect(input).toHaveAttribute("type", "file");
    expect(input).toHaveAttribute("accept", "image/gif,image/jpeg,image/png,image/webp");
    expect(input).toBeRequired();
    expect(container.querySelector(".catalog-core-form-card .catalog-mutation-form .catalog-thumbnail-field")).toContainElement(input);
    expect(screen.getByRole("img", { name: "Previewなし" })).toBeInTheDocument();
    expect(screen.queryByRole("combobox", { name: "サムネイル" })).not.toBeInTheDocument();
  });

  for (const status of ["published", "sales_paused"] as const) {
    it(`${status} retains the current preview and disables upload and save`, async () => {
      const { upload, update } = thumbnailFixture(type, status);
      render(<LoginGachaWorkspace type={type} sourceId="source" />);
      expect(await screen.findByLabelText(/サムネイル画像/u)).toBeDisabled();
      expect(screen.getByLabelText("Banner Category")).toBeDisabled();
      expect(screen.getAllByAltText("現在のサムネイル")[0]).toHaveAttribute("src", thumbnail.public_path);
      expect(screen.queryByRole("button", { name: "構成を一括保存" })).not.toBeInTheDocument();
      expect(upload).not.toHaveBeenCalled();
      expect(update).not.toHaveBeenCalled();
    });
  }

  for (const copy of [false, true]) {
    it(`${copy ? "copy" : "draft edit"} preserves the asset reference without reupload`, async () => {
      const { upload, create, update } = thumbnailFixture(type, copy ? "published" : "draft", copy);
      render(<LoginGachaWorkspace type={type} sourceId="source" copy={copy} />);
      expect(await screen.findByLabelText(/サムネイル画像/u)).toBeEnabled();
      expect(screen.getAllByAltText("現在のサムネイル")[0]).toHaveAttribute("src", thumbnail.public_path);
      expect(create).not.toHaveBeenCalled();
      expect(update).not.toHaveBeenCalled();
      if (copy) fireEvent.change(screen.getByLabelText("公開開始日時（JST）"), { target: { value: "2026-10-02T00:00" } });
      fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
      await waitFor(() => expect(copy ? create : update).toHaveBeenCalledTimes(1));
      if (copy) expect(create.mock.calls[0][0].presentation_asset_id).toBe(thumbnail.id);
      else expect(update.mock.calls[0][1].composition.presentation_asset_id).toBe(thumbnail.id);
      expect(upload).not.toHaveBeenCalled();
    });

    it(`${copy ? "copy" : "draft edit"} uploads only the replacement and reuses it after a failed composition save`, async () => {
      const { upload, create, update } = thumbnailFixture(type, copy ? "published" : "draft", copy);
      render(<LoginGachaWorkspace type={type} sourceId="source" copy={copy} />);
      const input = await screen.findByLabelText(/サムネイル画像/u);
      fireEvent.change(input, { target: { files: [new File(["synthetic-image"], "qa.png", { type: "image/png" })] } });
      expect(await screen.findByAltText("選択したサムネイルのPreview")).toHaveAttribute("src", "data:image/png;base64,c3ludGhldGljLWltYWdl");
      expect(upload).not.toHaveBeenCalled();
      if (copy) fireEvent.change(screen.getByLabelText("公開開始日時（JST）"), { target: { value: "2026-10-02T00:00" } });
      fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
      await waitFor(() => expect(copy ? create : update).toHaveBeenCalledTimes(1));
      expect(upload).toHaveBeenCalledWith({ file_name: "qa.png", mime_type: "image/png", content_base64: "c3ludGhldGljLWltYWdl" }, expect.any(String));
      if (copy) expect(create.mock.calls[0][0].presentation_asset_id).toBe("uploaded-thumbnail");
      else expect(update.mock.calls[0][1].composition.presentation_asset_id).toBe("uploaded-thumbnail");
      await waitFor(() => expect(screen.getByRole("button", { name: "構成を一括保存" })).toBeEnabled());
      fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
      await waitFor(() => expect(copy ? create : update).toHaveBeenCalledTimes(2));
      expect(upload).toHaveBeenCalledTimes(1);
    });
  }

  for (const invalid of [new File(["invalid"], "qa.svg", { type: "image/svg+xml" }), new File([new Uint8Array(5 * 1024 * 1024 + 1)], "qa.png", { type: "image/png" })]) {
    it(`rejects invalid ${invalid.type} / ${invalid.size} before any upload or save`, async () => {
      const { upload, update } = thumbnailFixture(type);
      render(<LoginGachaWorkspace type={type} sourceId="source" />);
      fireEvent.change(await screen.findByLabelText(/サムネイル画像/u), { target: { files: [invalid] } });
      fireEvent.click(screen.getByRole("button", { name: "構成を一括保存" }));
      expect(await screen.findByText("サムネイルはGIF、JPEG、PNG、WebPの5 MB以下にしてください。")).toBeInTheDocument();
      expect(upload).not.toHaveBeenCalled();
      expect(update).not.toHaveBeenCalled();
    });
  }
});
