import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { CatalogGachaUsageHistory } from "@/components/catalog/catalog-gacha-usage-history";
import { AdminApiClient } from "@/lib/admin-api/client";

const GACHA_ID = uuid("1");
const REQUEST_ID = uuid("2");

vi.mock("@/components/shell/admin-shell", () => ({
  AdminShell: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock("@/components/permissions/protected-admin-route", () => ({
  ProtectedAdminRoute: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

beforeEach(() => {
  vi.spyOn(AdminApiClient.prototype, "listGachaUsageHistory").mockResolvedValueOnce({
    gacha_id: GACHA_ID,
    items: [{
      executed_count: 10,
      id: REQUEST_ID,
      status_summary: [
        { count: 2, status: "shipping" },
        { count: 8, status: "point_exchange" },
      ],
      used_at: "2026-08-04T00:00:00Z",
      user: { display_name: null, id: uuid("3") },
    }],
    next_cursor: "opaque-next",
    request_id: uuid("4"),
  }).mockResolvedValueOnce({
    gacha_id: GACHA_ID,
    items: [],
    next_cursor: null,
    request_id: uuid("4"),
  });
  vi.spyOn(AdminApiClient.prototype, "getGachaUsageHistory").mockResolvedValue({
    data: {
      consumed_points: 1000,
      executed_count: 10,
      gacha: { id: GACHA_ID, title: "夏のガチャ", version_id: uuid("5") },
      id: REQUEST_ID,
      prizes: [prize("6", "S賞", "stored"), prize("7", "A賞", "converted")],
      status_summary: [
        { count: 1, status: "selection_pending" },
        { count: 1, status: "point_exchange" },
      ],
      used_at: "2026-08-04T00:00:00Z",
      user: { display_name: "テストユーザー", id: uuid("3") },
    },
    request_id: uuid("4"),
  });
});

afterEach(() => vi.restoreAllMocks());

describe("Gacha usage history", () => {
  it("renders the required list columns, status counts, and cursor pagination", async () => {
    const list = vi.spyOn(AdminApiClient.prototype, "listGachaUsageHistory");
    render(<CatalogGachaUsageHistory gachaId={GACHA_ID} />);

    expect(await screen.findByRole("heading", { name: "ガチャ利用履歴" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ガチャ利用ID", "ユーザー名", "何連ガチャ", "状態", "ガチャ利用日時", "詳細",
    ]);
    expect(screen.getByText("未設定")).toBeVisible();
    expect(screen.getByText("10連")).toBeVisible();
    expect(screen.getByText("配送 2")).toBeVisible();
    expect(screen.getByText("ポイント交換 8")).toBeVisible();
    expect(screen.getByRole("link", { name: `${REQUEST_ID}の詳細` }))
      .toHaveAttribute("href", `/catalog/gachas/${GACHA_ID}/history/${REQUEST_ID}`);

    fireEvent.click(screen.getByRole("button", { name: "次の20件を表示" }));
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(GACHA_ID, "opaque-next"));
  });

  it("renders every prize and the canonical detail fields", async () => {
    render(<CatalogGachaUsageHistory drawRequestId={REQUEST_ID} gachaId={GACHA_ID} />);

    expect(await screen.findByRole("heading", { name: "ガチャ利用詳細" })).toBeVisible();
    const summary = screen.getByRole("heading", { name: "利用情報" }).closest("section");
    expect(summary).not.toBeNull();
    expect(within(summary!).getByText("夏のガチャ")).toBeVisible();
    expect(within(summary!).getByText("1,000 pt")).toBeVisible();
    expect(screen.getByRole("heading", { name: "当選景品一覧" })).toBeVisible();
    expect(screen.getByText("S賞")).toBeVisible();
    expect(screen.getByText("A賞")).toBeVisible();
    expect(screen.getByText("選択待ち")).toBeVisible();
    expect(screen.getByText("ポイント交換")).toBeVisible();
    expect(screen.getByRole("link", { name: "履歴一覧へ" }))
      .toHaveAttribute("href", `/catalog/gachas/${GACHA_ID}/history`);
    expect(screen.getByRole("link", { name: "対象ガチャ詳細へ" }))
      .toHaveAttribute("href", `/catalog/gachas/${GACHA_ID}`);
  });
});

function prize(last: string, name: string, status: "stored" | "converted") {
  return {
    draw_result_id: uuid(last),
    exchange_points: 500,
    prize_id: uuid(last),
    prize_name: name,
    rank: { id: uuid("8"), name: "Sランク" },
    sequence: Number(last),
    status,
    status_updated_at: "2026-08-04T00:01:00Z",
    thumbnail: null,
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
