import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaRankPrizeManager } from "@/components/catalog/catalog-gacha-rank-prize-manager";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminCatalogGachaVersion } from "@/lib/admin-api/generated";

const GACHA_ID = "01910191-0191-7191-8191-019101910191";
const VERSION_ID = "01910191-0191-7191-8191-019101910192";
const RANK_ID = "01910191-0191-7191-8191-019101910193";
const PRIZE_ID = "01910191-0191-7191-8191-019101910194";
const ASSET_ID = "01910191-0191-7191-8191-019101910195";

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionRanks").mockResolvedValue({
    items: [rank],
    version_revision: 3,
  });
  vi.spyOn(AdminApiClient.prototype, "listGachaVersionPrizes").mockResolvedValue({
    items: [prize],
    version_revision: 3,
  });
  vi.spyOn(AdminApiClient.prototype, "listCatalogPresentationAssets").mockResolvedValue({
    items: [asset],
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
  alt_text: "SS thumbnail",
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
