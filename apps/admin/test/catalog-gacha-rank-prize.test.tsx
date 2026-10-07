import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaRankPrizeManager } from "@/components/catalog/catalog-gacha-rank-prize-manager";
import { CatalogBannerAssetPicker } from "@/components/catalog/catalog-prize-asset-mutation-form";
import { RankMasterWorkspace } from "@/components/catalog/rank-master-workspace";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminCatalogPrize,
  AdminGachaRankListItem,
  AdminGachaVersionPrize,
  AdminManagedBanner,
  AdminRankEffect,
} from "@/lib/admin-api/generated";

const GACHA_ID = "01910191-0191-7191-8191-019101910191";
const VERSION_ID = "01910191-0191-7191-8191-019101910192";
const RANK_ID = "01910191-0191-7191-8191-019101910193";
const PRIZE_ID = "01910191-0191-7191-8191-019101910194";
const VIDEO_ASSET_ID = "01910191-0191-7191-8191-019101910195";

vi.mock("next/navigation", () => ({ useRouter: () => ({ replace: vi.fn() }) }));
vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ hasPermission: () => true }),
}));

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listCatalogRanks").mockResolvedValue({
    items: [gachaRank().rank],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listGachaRanks").mockResolvedValue({
    items: [gachaRank()],
  });
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
    items: [],
    version_revision: 3,
  });
  vi.spyOn(AdminApiClient.prototype, "listRankEffects").mockResolvedValue({
    items: [videoEffect()],
    next_cursor: null,
  });
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({
    items: [],
  });
  vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
    items: [],
    next_cursor: null,
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Canonical Gacha Rank and Prize manager", () => {
  it("offers only identified Banners and follows reselection until manually edited, including manual clears", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaRanks").mockResolvedValue({
      items: [gachaRank({ currentVideo: true, canUnset: true, revision: 4 })],
    });
    const category = { id: RANK_ID, name: "Cards", created_at: "2026-10-06T00:00:00Z" };
    vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({ items: [category] });
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
      items: [["Card Banner", "CARD-0001"], ["No ID Banner", null], ["Other Banner", "CARD-0002"]].map(([title, external_id], index) => ({
        id: String(index), title: title!, external_id, category,
        status: "draft" as const, show_on_top: false, link_url: null, asset: { id: VIDEO_ASSET_ID, public_url: "https://example.test/card.png" },
        version_id: VERSION_ID, version_number: 1, created_at: "2026-10-06T00:00:00Z", updated_at: "2026-10-06T00:00:00Z" })),
      next_cursor: null,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);
    fireEvent.click(await screen.findByRole("button", { name: "景品登録" }));
    const dialog = screen.getByRole("dialog", { name: "新規景品登録" });
    const field = within(dialog).getByLabelText("管理ID");
    fireEvent.change(await within(dialog).findByLabelText("Banner Category"), { target: { value: RANK_ID } });
    fireEvent.click(await within(dialog).findByRole("button", { name: "Card Banner" }));
    expect(field).toHaveValue("CARD-0001");
    expect(field).not.toHaveAttribute("readonly");
    fireEvent.click(within(dialog).getByRole("button", { name: "Other Banner" }));
    expect(field).toHaveValue("CARD-0002");
    expect(within(dialog).queryByRole("button", { name: "No ID Banner" })).not.toBeInTheDocument();
    expect(within(within(dialog).getByRole("group", { name: "Banner" })).getAllByRole("button")).toHaveLength(2);
    fireEvent.change(field, { target: { value: "MANUAL" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Card Banner" }));
    expect(field).toHaveValue("MANUAL");
    fireEvent.change(field, { target: { value: "" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Card Banner" }));
    expect(field).toHaveValue("");
    fireEvent.change(field, { target: { value: "日本語" } });
    expect(field).toHaveAttribute("aria-invalid", "true");
    expect(within(dialog).getByRole("alert")).toHaveTextContent("半角英数字");
  });

  it("preserves an existing no-ID Banner Asset on load and unchanged save", async () => {
    const existingAsset = { ...videoEffect(), media_type: "image" as const, mime_type: "image/png" };
    vi.mocked(AdminApiClient.prototype.listGachaVersionPrizes).mockResolvedValue({
      items: [{ ...gachaPrize(), presentation_asset: existingAsset }], version_revision: 3,
    });
    const category = { id: RANK_ID, name: "Cards" };
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockResolvedValue({ items: [category] });
    vi.mocked(AdminApiClient.prototype.listManagedBanners).mockResolvedValue({ items: [
      { id: "legacy", title: "Legacy Banner", external_id: null, category,
        asset: { id: existingAsset.id, public_url: "/legacy.png" }, status: "published", show_on_top: false,
        link_url: null, version_id: "legacy-version", version_number: 1, created_at: "", updated_at: "" },
    ], next_cursor: null });
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaRankPrize")
      .mockResolvedValue({ data: prize(), idempotent_replay: false });
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaRankPrize");
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);
    fireEvent.click(await screen.findByRole("button", { name: "SS景品を編集" }));
    const dialog = screen.getByRole("dialog", { name: "景品編集" });
    expect(await within(dialog).findByText(/変更しなければ既存の値は保持されます/u)).toBeVisible();
    expect(dialog.querySelector('input[name="presentation_asset_id"]')).toHaveValue(existingAsset.id);
    expect(update).not.toHaveBeenCalled();
    expect(create).not.toHaveBeenCalled();
    expect(within(dialog).queryByRole("button", { name: "Legacy Banner" })).not.toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText("変更理由"), { target: { value: "Keep existing image" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][4]).toMatchObject({ presentation_asset_id: existingAsset.id });
  });

  it("displays an existing Prize external ID in its list and form", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
      items: [{ ...gachaPrize(), external_id: "CARD-0001" }], version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);
    expect(await screen.findByText("CARD-0001")).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: `${gachaPrize().name}を編集` }));
    expect(within(screen.getByRole("dialog", { name: "景品編集" })).getByLabelText("管理ID")).toHaveValue("CARD-0001");
  });

  it("renders Rank Master Public snapshots through authenticated Admin asset URLs", async () => {
    render(<RankMasterWorkspace />);

    expect(await screen.findByText("SSランク")).toBeVisible();
    expect(screen.getByRole("img", { name: "SSランク ラインナップ画像" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910196/content`,
    );
    expect(screen.getByRole("img", { name: "SSランク 抽選結果画像" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910197/content`,
    );
  });

  it("renders every active Rank Master without creating a GachaRank row", async () => {
    const setVideo = vi.spyOn(AdminApiClient.prototype, "setGachaRankVideo")
      .mockResolvedValue({ data: savedGachaRank(), idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    expect(await screen.findByText("SSランク")).toBeVisible();
    expect(screen.getByRole("img", { name: "SSランク ラインナップ画像" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910196/content`,
    );
    expect(screen.getByRole("img", { name: "SSランク 抽選結果画像" })).toHaveAttribute(
      "src",
      `/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910197/content`,
    );
    expect(screen.getByText("未設定")).toBeVisible();
    expect(screen.queryByRole("button", { name: /Rank追加/u })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Rank削除/u })).not.toBeInTheDocument();
    expect(screen.queryByText("景品数")).not.toBeInTheDocument();
    expect(screen.queryByText("景品総在庫")).not.toBeInTheDocument();
    expect(screen.queryByText("現在庫")).not.toBeInTheDocument();
    expect(setVideo).not.toHaveBeenCalled();
  });

  it("rejects opening Prize registration until the Rank video is configured", async () => {
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    await screen.findByText("SSランク");
    fireEvent.click(screen.getByRole("button", { name: "景品登録" }));

    expect(screen.getByRole("alert")).toHaveTextContent(
      "抽選演出動画が設定されていません。先に抽選演出動画を選択してください。",
    );
    expect(screen.queryByRole("dialog", { name: "新規景品登録" })).not.toBeInTheDocument();
  });

  it("lazily creates the GachaRank and saves a selected video immediately", async () => {
    const setVideo = vi.spyOn(AdminApiClient.prototype, "setGachaRankVideo")
      .mockResolvedValue({ data: savedGachaRank(), idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    const picker = await screen.findByLabelText("SSランクの動画");
    fireEvent.change(picker, { target: { value: VIDEO_ASSET_ID } });

    await waitFor(() => expect(setVideo).toHaveBeenCalledOnce());
    expect(setVideo).toHaveBeenCalledWith(
      GACHA_ID,
      RANK_ID,
      { video_asset_id: VIDEO_ASSET_ID },
      expect.any(String),
    );
  });

  it("requires confirmation but allows a published Gacha video change", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(true);
    const setVideo = vi.spyOn(AdminApiClient.prototype, "setGachaRankVideo")
      .mockResolvedValue({ data: savedGachaRank(), idempotent_replay: false });
    render(
      <CatalogGachaRankPrizeManager
        canManage
        gachaId={GACHA_ID}
        presentationOnly
        version={version("published")}
      />,
    );

    fireEvent.change(await screen.findByLabelText("SSランクの動画"), {
      target: { value: VIDEO_ASSET_ID },
    });

    await waitFor(() => expect(setVideo).toHaveBeenCalledOnce());
    expect(confirm).toHaveBeenCalledWith(expect.stringContaining("公開中のガチャです"));
  });

  it("unsets a video only when the API reports the Rank can be unset", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaRanks").mockResolvedValue({
      items: [gachaRank({ currentVideo: true, canUnset: true, revision: 4 })],
    });
    const unset = vi.spyOn(AdminApiClient.prototype, "unsetGachaRankVideo")
      .mockResolvedValue({ data: savedGachaRank(), idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    fireEvent.change(await screen.findByLabelText("SSランクの動画"), {
      target: { value: "" },
    });

    await waitFor(() => expect(unset).toHaveBeenCalledOnce());
    expect(unset).toHaveBeenCalledWith(
      GACHA_ID,
      RANK_ID,
      { expected_revision: 4 },
      expect.any(String),
    );
  });

  it("maps the backend Prize-protected video unset error", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaRanks").mockResolvedValue({
      items: [gachaRank({ currentVideo: true, canUnset: true, revision: 4 })],
    });
    vi.spyOn(AdminApiClient.prototype, "unsetGachaRankVideo").mockRejectedValue(
      new AdminApiError(409, "CATALOG_GACHA_RANK_VIDEO_REQUIRED", null, null, false),
    );
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    fireEvent.change(await screen.findByLabelText("SSランクの動画"), {
      target: { value: "" },
    });

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "景品が登録されているため、抽選演出動画を未設定に戻せません。",
    );
  });

  it("creates a Prize through a rank-fixed path without a mutable Rank selector", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaRanks").mockResolvedValue({
      items: [gachaRank({ currentVideo: true, canUnset: true, revision: 4 })],
    });
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaRankPrize")
      .mockResolvedValue({ data: prize(), idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version()} />);

    fireEvent.click(await screen.findByRole("button", { name: "景品登録" }));
    const dialog = screen.getByRole("dialog", { name: "新規景品登録" });
    const rankField = within(dialog).getByLabelText("ランク");
    expect(rankField).toHaveValue("SSランク");
    expect(rankField).toHaveAttribute("readonly");
    expect(within(dialog).queryByRole("combobox", { name: "ランク" })).not.toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText("景品名"), {
      target: { value: "Canonical Prize" },
    });
    fireEvent.change(within(dialog).getByLabelText("総在庫数"), {
      target: { value: "10" },
    });
    fireEvent.change(within(dialog).getByLabelText("交換ポイント"), {
      target: { value: "8000" },
    });
    fireEvent.change(within(dialog).getByLabelText("原価"), {
      target: { value: "5000" },
    });
    const shippingOnly = within(dialog).getByRole("checkbox", { name: /配送のみ・ポイント交換不可/u });
    expect(shippingOnly).not.toBeChecked();
    fireEvent.click(shippingOnly);
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create).toHaveBeenCalledWith(
      GACHA_ID,
      VERSION_ID,
      RANK_ID,
      expect.objectContaining({
        cost_price: 5000,
        external_id: null,
        exchange_points: 8000,
        shipping_only: true,
        expected_version_revision: 3,
        name: "Canonical Prize",
        total_inventory: 10,
      }),
      expect.any(String),
    );
    expect(create.mock.calls[0][3]).not.toHaveProperty("rank_id");
  });

  it("keeps published shipping-only conditions visible and immutable", async () => {
    vi.mocked(AdminApiClient.prototype.listGachaVersionPrizes).mockResolvedValue({
      items: [{ ...gachaPrize(), shipping_only: true }], version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version("published")} />);
    fireEvent.click(await screen.findByRole("button", { name: "SS景品を編集" }));
    const checkbox = screen.getByRole("checkbox", { name: /配送のみ・ポイント交換不可/u });
    expect(checkbox).toBeChecked();
    expect(checkbox).toBeDisabled();
    expect(screen.getByText(/自動発送ではありません/u)).toBeVisible();
  });

  it("keeps Prize mutations hidden for read-only users", async () => {
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
      items: [gachaPrize()],
      version_revision: 3,
    });
    render(
      <CatalogGachaRankPrizeManager
        canManage={false}
        gachaId={GACHA_ID}
        heading="現在公開中の景品ラインナップ"
        version={version("published")}
      />,
    );

    expect(await screen.findByText("SS景品")).toBeVisible();
    expect(screen.queryByRole("button", { name: "景品登録" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "SS景品を編集" })).not.toBeInTheDocument();
  });
});

describe("Prize Banner eligibility", () => {
  const category = { id: "cards", name: "Cards" };
  function banner(id: string, externalId: string | null): AdminManagedBanner {
    return {
      id, title: id, external_id: externalId, category,
      asset: { id: `asset-${id}`, public_url: "/synthetic-banner.png" },
      status: "published", show_on_top: false, link_url: null,
      version_id: `version-${id}`, version_number: 1, created_at: "", updated_at: "",
    };
  }

  beforeEach(() => {
    vi.mocked(AdminApiClient.prototype.listBannerCategories).mockResolvedValue({ items: [category] });
  });

  it("finishes pagination even when the first page contains only no-ID Banners", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockImplementation(async (query) => ({
      items: query?.cursor ? [banner("Eligible", "CARD-001")] : [banner("No ID", null)],
      next_cursor: query?.cursor ? null : "page-2",
    }));
    const selection = vi.fn();
    render(<CatalogBannerAssetPicker assetId={null} onSelectionChange={selection} />);
    await screen.findByRole("option", { name: "Cards" });
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    fireEvent.click(await screen.findByRole("button", { name: "Eligible" }));
    expect(screen.queryByRole("button", { name: "No ID" })).not.toBeInTheDocument();
    expect(list).toHaveBeenCalledTimes(2);
    expect(list).toHaveBeenLastCalledWith({ category_id: category.id, cursor: "page-2" }, expect.any(AbortSignal));
    expect(selection).toHaveBeenLastCalledWith({ assetId: "asset-Eligible", bannerId: "Eligible", externalId: "CARD-001", changed: true });
  });

  it("explains a Category with no eligible Banners and offers no choices", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({ items: [banner("No ID", null)], next_cursor: null });
    const selection = vi.fn();
    render(<CatalogBannerAssetPicker assetId={null} onSelectionChange={selection} />);
    await screen.findByRole("option", { name: "Cards" });
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    expect(await screen.findByText("管理IDが設定されたBannerはありません。")).toBeVisible();
    expect(within(screen.getByRole("group", { name: "Banner" })).queryAllByRole("button")).toHaveLength(0);
    expect(selection).toHaveBeenCalledTimes(1);
    expect(selection).toHaveBeenCalledWith({ assetId: null, bannerId: null, changed: true });
  });

  it("does not mutate a legacy no-ID Asset on load or rerender, and offers only eligible replacements", async () => {
    vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockResolvedValue({
      items: [banner("Legacy", null), banner("Eligible", "CARD-001")], next_cursor: null,
    });
    const selection = vi.fn();
    const view = render(<CatalogBannerAssetPicker assetId="asset-Legacy" onSelectionChange={selection} />);
    expect(await screen.findByText(/変更しなければ既存の値は保持されます/u)).toBeVisible();
    expect(selection).not.toHaveBeenCalled();
    view.rerender(<CatalogBannerAssetPicker assetId="asset-Legacy" onSelectionChange={selection} />);
    await waitFor(() => expect(selection).not.toHaveBeenCalled());
    fireEvent.change(screen.getByLabelText("Banner Category"), { target: { value: category.id } });
    expect(await screen.findByRole("button", { name: "Eligible" })).toBeEnabled();
    expect(screen.queryByRole("button", { name: "Legacy" })).not.toBeInTheDocument();
    expect(within(screen.getByRole("group", { name: "Banner" })).getAllByRole("button")).toHaveLength(1);
  });
});

function asset(id: string, mediaType: "image" | "video" = "image") {
  return {
    id,
    path: `/api/v2/catalog/presentation-assets/${id}/content`,
    mime_type: mediaType === "video" ? "video/mp4" : "image/png",
    alt_text: mediaType === "video"
      ? "共通動画演出"
      : id.endsWith("196")
        ? "SSランク ラインナップ画像"
        : "SSランク 抽選結果画像",
  };
}

function gachaRank(options: {
  currentVideo?: boolean;
  canUnset?: boolean;
  revision?: number | null;
} = {}): AdminGachaRankListItem {
  return {
    rank: {
      id: RANK_ID,
      rank_name: "SSランク",
      lineup_image: asset("01910191-0191-7191-8191-019101910196"),
      result_image: asset("01910191-0191-7191-8191-019101910197"),
      show_total_stock: false,
      status: "active",
      display_order: 0,
      revision_number: 1,
      revision: 1,
      has_usage: false,
      used_by_published_gacha: false,
      created_at: "2026-08-20T00:00:00Z",
      updated_at: "2026-08-20T00:00:00Z",
    },
    gacha_rank_id: options.revision == null ? null : "01910191-0191-7191-8191-019101910198",
    gacha_rank_revision: options.revision ?? null,
    video_revision_number: options.currentVideo ? 1 : null,
    current_video: options.currentVideo ? asset(VIDEO_ASSET_ID, "video") : null,
    can_unset_video: options.canUnset ?? false,
  };
}

function savedGachaRank() {
  return {
    id: "01910191-0191-7191-8191-019101910198",
    gacha_id: GACHA_ID,
    rank: gachaRank().rank,
    current_video: asset(VIDEO_ASSET_ID, "video"),
    video_revision_number: 1,
    revision: 1,
    first_published_at: null,
    created_at: "2026-08-20T00:00:00Z",
    updated_at: "2026-08-20T00:00:00Z",
  };
}

function videoEffect(): AdminRankEffect {
  return {
    id: VIDEO_ASSET_ID,
    media_type: "video",
    mime_type: "video/mp4",
    alt_text: "共通動画演出",
    public_path: "/assets/effect.mp4",
    is_public: true,
    byte_size: 128,
    checksum_sha256: "a".repeat(64),
    revision: 1,
    archived_at: null,
    is_archived: false,
    created_at: "2026-08-20T00:00:00Z",
    updated_at: "2026-08-20T00:00:00Z",
    content_path: `/admin/api/v2/catalog/presentation-assets/${VIDEO_ASSET_ID}/content`,
  };
}

function prize(): AdminCatalogPrize {
  return {
    id: PRIZE_ID,
    code: "canonical-prize",
    name: "Canonical Prize",
    description: null,
    display_price: 0,
    exchange_points: 8000,
    cost_price: 5000,
    is_visible: true,
    rank: { id: RANK_ID, name: "SSランク", sort_order: 0 },
    presentation_asset: null,
    revision: 1,
    archived_at: null,
    is_archived: false,
    created_at: "2026-08-20T00:00:00Z",
    updated_at: "2026-08-20T00:00:00Z",
  };
}

function gachaPrize(): AdminGachaVersionPrize {
  return {
    ...prize(),
    name: "SS景品",
    total_inventory: 10,
    available_inventory: 7,
    awarded_inventory: 2,
    withdrawn_inventory: 1,
    inventory_revision: 4,
    version_sort_order: 0,
    revision: 1,
  };
}

function version(status: "draft" | "published" = "draft"): AdminCatalogGachaVersion {
  return {
    id: VERSION_ID,
    version_number: 1,
    status,
    title: "Canonical Gacha",
    description: null,
    notices: null,
    price_points: 100,
    total_count: 100,
    presentation_asset: null,
    published_probability_version: null,
    cloned_from_version: null,
    publish_start_at: "2026-08-20T00:00:00Z",
    publish_end_at: null,
    published_at: status === "published" ? "2026-08-20T00:00:00Z" : null,
    prizes: [],
    is_archived: false,
    revision: 3,
    archived_at: null,
    created_at: "2026-08-20T00:00:00Z",
    updated_at: "2026-08-20T00:00:00Z",
  };
}
