import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaRankPrizeManager } from "@/components/catalog/catalog-gacha-rank-prize-manager";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogGachaVersion, AdminRankEffect } from "@/lib/admin-api/generated";

const GACHA_ID = "01910191-0191-7191-8191-019101910191";
const VERSION_ID = "01910191-0191-7191-8191-019101910192";
const RANK_ID = "01910191-0191-7191-8191-019101910193";
const PRIZE_ID = "01910191-0191-7191-8191-019101910194";
const ASSET_ID = "01910191-0191-7191-8191-019101910195";
const RANK_IMAGE_ASSET_ID = "01910191-0191-7191-8191-019101910196";
const RANK_VIDEO_ASSET_ID = "01910191-0191-7191-8191-019101910197";
const CATEGORY_A_ID = "01910191-0191-7191-8191-019101910198";
const CATEGORY_B_ID = "01910191-0191-7191-8191-019101910199";
const BANNER_A_ASSET_ID = "01910191-0191-7191-8191-019101910200";
const BANNER_B_ASSET_ID = "01910191-0191-7191-8191-019101910201";

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionRanks").mockResolvedValue({
    items: [rank],
    version_revision: 3,
  });
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
    items: [prize],
    version_revision: 3,
  });
  vi.spyOn(AdminApiClient.prototype, "listBannerCategories").mockResolvedValue({
    items: [
      { id: CATEGORY_A_ID, name: "Category A" },
      { id: CATEGORY_B_ID, name: "Category B" },
    ],
  });
  vi.spyOn(AdminApiClient.prototype, "listManagedBanners").mockImplementation(async (query = {}) => ({
    items: query.category_id === CATEGORY_A_ID ? [banner("A")] : [banner("B")],
    next_cursor: null,
  }));
  vi.spyOn(AdminApiClient.prototype, "listRankEffects").mockResolvedValue({
    items: [rankImageEffect, rankVideoEffect],
    next_cursor: null,
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Gacha Rank and Prize manager", () => {
  it("renders the canonical prize columns and available inventory", async () => {
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);
    expect(await screen.findByRole("heading", { name: "ランク／景品管理" })).toBeVisible();
    const headers = screen.getAllByRole("columnheader").map((cell) => cell.textContent);
    expect(headers).toEqual([
      "ランク", "景品名", "サムネイル", "総在庫数", "現在個数",
      "交換ポイント", "状態", "登録日", "編集",
    ]);
    expect(screen.getByText("7")).toBeVisible();
    expect(screen.getByText("8,000")).toBeVisible();
  });

  it("opens Rank settings and submits a new Rank once", async () => {
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaVersionRank")
      .mockResolvedValue({ data: rank, idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);
    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "ランク設定" }));
    const dialog = screen.getByRole("dialog", { name: "ランク設定" });
    expect(within(dialog).getByText("SSランク")).toBeVisible();
    fireEvent.click(within(dialog).getByRole("button", { name: "追加" }));
    fireEvent.change(within(dialog).getByLabelText("ランクキー"), { target: { value: "a" } });
    fireEvent.change(within(dialog).getByLabelText("ランク表示"), { target: { value: "Aランク" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));
    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][2]).toMatchObject({
      code: "a",
      expected_version_revision: 3,
      name: "Aランク",
    });
  });

  it("uses only matching Rank Effect media candidates and saves their canonical IDs", async () => {
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaVersionRank")
      .mockResolvedValue({ data: rank, idempotent_replay: false });
    const listRankEffects = vi.mocked(AdminApiClient.prototype.listRankEffects);
    listRankEffects.mockReset();
    listRankEffects
      .mockResolvedValueOnce({ items: [rankImageEffect], next_cursor: "rank-effect-page-2" })
      .mockResolvedValueOnce({ items: [rankVideoEffect], next_cursor: null });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "ランク設定" }));
    const dialog = screen.getByRole("dialog", { name: "ランク設定" });
    fireEvent.click(within(dialog).getByRole("button", { name: "追加" }));
    fireEvent.change(within(dialog).getByLabelText("ランクキー"), { target: { value: "a" } });
    fireEvent.change(within(dialog).getByLabelText("ランク表示"), { target: { value: "Aランク" } });

    const imagePicker = within(dialog).getByRole("group", { name: "ランク画像" });
    const videoPicker = within(dialog).getByRole("group", { name: "抽選演出動画" });
    expect(within(imagePicker).getByRole("button", { name: "画像演出" })).toBeVisible();
    expect(within(imagePicker).queryByRole("button", { name: "動画演出" })).not.toBeInTheDocument();
    expect(within(videoPicker).getByRole("button", { name: "動画演出" })).toBeVisible();
    expect(within(videoPicker).queryByRole("button", { name: "画像演出" })).not.toBeInTheDocument();
    expect(within(dialog).queryByRole("button", { name: "一般画像" })).not.toBeInTheDocument();
    expect(listRankEffects.mock.calls.map(([query]) => query.cursor)).toEqual([
      undefined,
      "rank-effect-page-2",
    ]);

    fireEvent.click(within(imagePicker).getByRole("button", { name: "画像演出" }));
    fireEvent.click(within(videoPicker).getByRole("button", { name: "動画演出" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][2]).toMatchObject({
      image_asset_id: RANK_IMAGE_ASSET_ID,
      video_asset_id: RANK_VIDEO_ASSET_ID,
    });
  });

  it("restores existing Rank Effect choices and preserves an unresolved legacy asset", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionRank")
      .mockResolvedValue({ data: rank, idempotent_replay: false });
    const unresolvedRank = {
      ...rank,
      image_asset: { ...asset, id: "01910191-0191-7191-8191-019101910198" },
      video_asset: null,
    };
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionRanks").mockResolvedValue({
      items: [unresolvedRank],
      version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "ランク設定" }));
    const dialog = screen.getByRole("dialog", { name: "ランク設定" });
    fireEvent.click(within(dialog).getByRole("button", { name: "SSランクを編集" }));
    expect(await within(dialog).findByText("現在のAssetはランク演出候補として解決できません。", { exact: false })).toBeVisible();
    fireEvent.change(within(dialog).getByLabelText("ランク表示"), { target: { value: "SSランク更新" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({
      image_asset_id: "01910191-0191-7191-8191-019101910198",
      video_asset_id: null,
    });
  });

  it("restores matching Rank Effect choices during edit", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionRank")
      .mockResolvedValue({ data: rank, idempotent_replay: false });
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionRanks").mockResolvedValue({
      items: [{ ...rank, image_asset: rankImageEffect, video_asset: rankVideoEffect }],
      version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "ランク設定" }));
    const dialog = screen.getByRole("dialog", { name: "ランク設定" });
    fireEvent.click(within(dialog).getByRole("button", { name: "SSランクを編集" }));
    expect(within(dialog).getByRole("button", { name: "画像演出" })).toHaveAttribute("aria-pressed", "true");
    expect(within(dialog).getByRole("button", { name: "動画演出" })).toHaveAttribute("aria-pressed", "true");
    fireEvent.change(within(dialog).getByLabelText("ランク表示"), { target: { value: "SSランク更新" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({
      image_asset_id: RANK_IMAGE_ASSET_ID,
      video_asset_id: RANK_VIDEO_ASSET_ID,
    });
  });

  it("creates a Gacha Prize from the selected Category Banner Asset without general assets", async () => {
    const create = vi.spyOn(AdminApiClient.prototype, "createGachaVersionPrize")
      .mockResolvedValue({ data: prize, idempotent_replay: false });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "新規景品登録" }));
    const dialog = screen.getByRole("dialog", { name: "新規景品登録" });
    await within(dialog).findByRole("option", { name: "Category A" });
    fireEvent.change(within(dialog).getByLabelText("ランク"), { target: { value: RANK_ID } });
    fireEvent.change(within(dialog).getByLabelText("景品名"), { target: { value: "Banner景品" } });
    fireEvent.change(within(dialog).getByLabelText("Banner Category"), {
      target: { value: CATEGORY_A_ID },
    });

    const bannerA = await within(dialog).findByRole("button", { name: "Banner A" });
    expect(bannerA.querySelector("img")).toHaveAttribute("src", "/banners/a.png");
    expect(within(dialog).queryByRole("button", { name: "Banner B" })).not.toBeInTheDocument();
    expect(within(dialog).queryByRole("option", { name: "一般画像" })).not.toBeInTheDocument();
    fireEvent.click(bannerA);
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(create).toHaveBeenCalledOnce());
    expect(create.mock.calls[0][2]).toMatchObject({
      presentation_asset_id: BANNER_A_ASSET_ID,
      rank_id: RANK_ID,
    });
  });

  it("clears the previous Gacha Prize Banner when its Category changes", async () => {
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "新規景品登録" }));
    const dialog = screen.getByRole("dialog", { name: "新規景品登録" });
    await within(dialog).findByRole("option", { name: "Category A" });
    fireEvent.change(within(dialog).getByLabelText("Banner Category"), {
      target: { value: CATEGORY_A_ID },
    });
    fireEvent.click(await within(dialog).findByRole("button", { name: "Banner A" }));
    fireEvent.change(within(dialog).getByLabelText("Banner Category"), {
      target: { value: CATEGORY_B_ID },
    });

    const bannerB = await within(dialog).findByRole("button", { name: "Banner B" });
    expect(within(dialog).queryByRole("button", { name: "Banner A" })).not.toBeInTheDocument();
    expect(bannerB).toHaveAttribute("aria-pressed", "false");
    expect(dialog.querySelector<HTMLInputElement>('input[name="presentation_asset_id"]')?.value).toBe("");
  });

  it("restores a unique Banner for Gacha Prize edit and preserves an unresolved Asset", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionPrize")
      .mockResolvedValue({ data: prize, idempotent_replay: false });
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
      items: [{ ...prize, presentation_asset: { ...asset, id: BANNER_A_ASSET_ID } }],
      version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "SS景品を編集" }));
    const dialog = screen.getByRole("dialog", { name: "景品編集" });
    expect(await within(dialog).findByRole("button", { name: "Banner A" })).toHaveAttribute("aria-pressed", "true");
    fireEvent.change(within(dialog).getByLabelText("在庫変更理由"), { target: { value: "確認保存" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({ presentation_asset_id: BANNER_A_ASSET_ID });
  });

  it("changes a Gacha Prize Asset only after an explicit replacement Banner selection", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionPrize")
      .mockResolvedValue({ data: prize, idempotent_replay: false });
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
      items: [{ ...prize, presentation_asset: { ...asset, id: BANNER_A_ASSET_ID } }],
      version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "SS景品を編集" }));
    const dialog = screen.getByRole("dialog", { name: "景品編集" });
    await within(dialog).findByRole("button", { name: "Banner A" });
    fireEvent.change(within(dialog).getByLabelText("Banner Category"), {
      target: { value: CATEGORY_B_ID },
    });
    fireEvent.click(await within(dialog).findByRole("button", { name: "Banner B" }));
    fireEvent.change(within(dialog).getByLabelText("在庫変更理由"), { target: { value: "Banner差替" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));

    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({ presentation_asset_id: BANNER_B_ASSET_ID });
  });

  it("does not replace an unresolved Gacha Prize Asset during edit", async () => {
    const unresolvedAssetId = "01910191-0191-7191-8191-019101910202";
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionPrize")
      .mockResolvedValue({ data: prize, idempotent_replay: false });
    vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
      items: [{ ...prize, presentation_asset: { ...asset, id: unresolvedAssetId } }],
      version_revision: 3,
    });
    render(<CatalogGachaRankPrizeManager canManage gachaId={GACHA_ID} version={version} />);

    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "SS景品を編集" }));
    const dialog = screen.getByRole("dialog", { name: "景品編集" });
    expect(await within(dialog).findByText("既存のPresentation Assetに対応するBannerを一意に特定できませんでした。", { exact: false })).toBeVisible();
    fireEvent.change(within(dialog).getByLabelText("在庫変更理由"), { target: { value: "既存値保持" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({ presentation_asset_id: unresolvedAssetId });
  });

  it("keeps mutations hidden for read-only users", async () => {
    render(
      <CatalogGachaRankPrizeManager
        canManage={false}
        gachaId={GACHA_ID}
        heading="現在公開中の景品ラインナップ"
        version={version}
        versionLabel="公開済み バージョン 1"
      />,
    );
    await screen.findByText("SS景品");
    expect(screen.getByRole("heading", { name: "現在公開中の景品ラインナップ" }))
      .toBeVisible();
    expect(screen.getByText("公開済み バージョン 1")).toBeVisible();
    expect(screen.queryByRole("button", { name: "ランク設定" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "新規景品登録" })).not.toBeInTheDocument();
  });

  it("submits operational inventory changes for a published Prize", async () => {
    const update = vi.spyOn(AdminApiClient.prototype, "updateGachaVersionPrize")
      .mockResolvedValue({ data: prize, idempotent_replay: false });
    render(
      <CatalogGachaRankPrizeManager
        canManage
        gachaId={GACHA_ID}
        presentationOnly
        version={version}
      />,
    );
    await screen.findByText("SS景品");
    fireEvent.click(screen.getByRole("button", { name: "SS景品を編集" }));
    const dialog = screen.getByRole("dialog", { name: "景品編集" });
    fireEvent.change(within(dialog).getByLabelText("総在庫数"), { target: { value: "12" } });
    fireEvent.change(within(dialog).getByLabelText("現在個数"), { target: { value: "8" } });
    fireEvent.change(within(dialog).getByLabelText("在庫変更理由"), {
      target: { value: "棚卸差異を反映" },
    });
    fireEvent.click(within(dialog).getByRole("button", { name: "保存" }));
    await waitFor(() => expect(update).toHaveBeenCalledOnce());
    expect(update.mock.calls[0][3]).toMatchObject({
      available_inventory: 8,
      expected_inventory_revision: 4,
      inventory_reason: "棚卸差異を反映",
      total_inventory: 12,
    });
  });
});

const asset = {
  alt_text: "一般画像",
  byte_size: 100,
  checksum_sha256: "a".repeat(64),
  created_at: "2026-08-20T00:00:00Z",
  id: ASSET_ID,
  is_archived: false,
  is_public: true,
  media_type: "image" as const,
  mime_type: "image/png",
  public_path: "/assets/ss.png",
  revision: 1,
  updated_at: "2026-08-20T00:00:00Z",
};

function banner(key: "A" | "B") {
  const categoryId = key === "A" ? CATEGORY_A_ID : CATEGORY_B_ID;
  const assetId = key === "A" ? BANNER_A_ASSET_ID : BANNER_B_ASSET_ID;
  return {
    asset: { id: assetId, public_url: `/banners/${key.toLowerCase()}.png` },
    category: { id: categoryId, name: `Category ${key}` },
    created_at: "2026-08-20T00:00:00Z",
    id: `01910191-0191-7191-8191-01910191020${key === "A" ? "3" : "4"}`,
    link_url: null,
    show_on_top: false,
    status: "draft" as const,
    title: `Banner ${key}`,
    updated_at: "2026-08-20T00:00:00Z",
    version_id: `01910191-0191-7191-8191-01910191020${key === "A" ? "5" : "6"}`,
    version_number: 1,
  };
}

const rankImageEffect: AdminRankEffect = {
  ...asset,
  alt_text: "画像演出",
  content_path: "/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910196/content",
  id: RANK_IMAGE_ASSET_ID,
  rank_assignments: [],
};

const rankVideoEffect: AdminRankEffect = {
  ...asset,
  alt_text: "動画演出",
  content_path: "/admin/api/v2/catalog/presentation-assets/01910191-0191-7191-8191-019101910197/content",
  id: RANK_VIDEO_ASSET_ID,
  media_type: "video",
  mime_type: "video/mp4",
  public_path: "/assets/effect.mp4",
  rank_assignments: [],
};

const rank = {
  code: "ss",
  created_at: "2026-08-20T00:00:00Z",
  description: "SS rank",
  id: RANK_ID,
  image_asset: asset,
  is_archived: false,
  is_visible: true,
  name: "SSランク",
  revision: 1,
  sort_order: 0,
  updated_at: "2026-08-20T00:00:00Z",
  video_asset: null,
};

const prize = {
  available_inventory: 7,
  awarded_inventory: 2,
  code: "prize-ss",
  cost_price: 5000,
  created_at: "2026-08-20T00:00:00Z",
  description: null,
  display_price: 0,
  exchange_points: 8000,
  id: PRIZE_ID,
  inventory_revision: 4,
  is_visible: true,
  name: "SS景品",
  presentation_asset: asset,
  rank: { code: "ss", id: RANK_ID, name: "SSランク", sort_order: 0 },
  revision: 1,
  total_inventory: 10,
  updated_at: "2026-08-20T00:00:00Z",
  version_sort_order: 0,
  withdrawn_inventory: 1,
};

const version: AdminCatalogGachaVersion = {
  archived_at: null,
  cloned_from_version: null,
  created_at: "2026-08-20T00:00:00Z",
  description: null,
  id: VERSION_ID,
  is_archived: false,
  notices: null,
  presentation_asset: null,
  price_points: 100,
  prizes: [],
  publish_end_at: null,
  publish_start_at: "2026-08-20T00:00:00Z",
  published_at: null,
  published_probability_version: null,
  revision: 1,
  status: "draft",
  title: "Draft Gacha",
  total_count: 100,
  updated_at: "2026-08-20T00:00:00Z",
  version_number: 1,
};
