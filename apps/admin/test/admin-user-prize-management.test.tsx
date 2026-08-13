import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { AdminUserPrizeDetail } from "@/components/user-prizes/admin-user-prize-detail";
import { AdminUserPrizeList } from "@/components/user-prizes/admin-user-prize-list";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminUserPrizeCollection,
  AdminUserPrizeDetailResponse,
  AdminUserPrizeSummary,
} from "@/lib/admin-api/generated";

describe("Admin User Prize management", () => {
  beforeEach(() => {
    vi.spyOn(AdminApiClient.prototype, "listAdminUserPrizes").mockResolvedValue(collection());
    vi.spyOn(AdminApiClient.prototype, "getAdminUserPrize").mockResolvedValue(detail());
  });

  afterEach(() => vi.restoreAllMocks());

  it("renders the canonical list columns and primary relationships", async () => {
    render(<AdminUserPrizeList />);

    await screen.findByText("取得景品A");
    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "User",
      "景品",
      "ランク",
      "取得元Gacha",
      "取得日時",
      "現在状態",
      "Fulfillment",
      "詳細",
    ]);
    const row = screen.getAllByRole("row")[1];
    expect(within(row).getByText("テストユーザー")).toBeVisible();
    expect(within(row).getByText("Sランク")).toBeVisible();
    expect(within(row).getByText("配送: 依頼済み")).toBeVisible();
    expect(within(row).getByRole("link", { name: "取得景品Aの詳細" })).toHaveAttribute(
      "href",
      `/user-prizes/${uuid("1")}`,
    );
  });

  it("submits User, Prize, Gacha, and status filters to the Backend", async () => {
    const list = vi.mocked(AdminApiClient.prototype.listAdminUserPrizes);
    render(<AdminUserPrizeList />);
    await screen.findByText("取得景品A");

    fireEvent.change(screen.getByRole("textbox", { name: "ユーザー" }), {
      target: { value: " テストユーザー " },
    });
    fireEvent.change(screen.getByRole("textbox", { name: "景品名" }), {
      target: { value: " 景品A " },
    });
    fireEvent.change(screen.getByRole("textbox", { name: "ガチャ" }), {
      target: { value: " TESTGACHA01 " },
    });
    fireEvent.change(screen.getByRole("combobox", { name: "状態" }), {
      target: { value: "stored" },
    });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(
      expect.objectContaining({
        gacha: "TESTGACHA01",
        prize_name: "景品A",
        status: "stored",
        user: "テストユーザー",
      }),
      expect.any(AbortSignal),
    ));
  });

  it("renders Snapshot, Draw, allowed actions, and Fulfillment detail read-only", async () => {
    render(<AdminUserPrizeDetail userPrizeId={uuid("1")} />);

    await screen.findByRole("heading", { name: "景品情報" });
    expect(screen.getByText("取得景品A")).toBeVisible();
    expect(screen.getByText("要求 5／実行 5")).toBeVisible();
    expect(screen.getByRole("heading", { name: "現在可能な操作" })).toBeVisible();
    expect(screen.getAllByText("現在状態では操作できません")).toHaveLength(3);
    expect(screen.getByRole("heading", { name: "配送" })).toBeVisible();
    expect(screen.getByText((_content, element) =>
      element?.tagName === "ADDRESS"
      && element.textContent?.includes("〒1000001 東京都千代田区千代田1-1") === true,
    )).toBeVisible();
    expect(screen.getByText("ポイント交換依頼はありません。")).toBeVisible();
    expect(screen.queryByRole("button", { name: /配送|交換/u })).toBeNull();
  });

  it("announces empty and Not Found states", async () => {
    vi.mocked(AdminApiClient.prototype.listAdminUserPrizes).mockResolvedValue({
      items: [], next_cursor: null, request_id: uuid("9"),
    });
    const { unmount } = render(<AdminUserPrizeList />);
    expect(await screen.findByText("該当する保有景品はありません")).toBeVisible();
    unmount();

    vi.mocked(AdminApiClient.prototype.getAdminUserPrize).mockRejectedValue(
      Object.assign(new Error("Not Found"), { code: "ADMIN_USER_PRIZE_NOT_FOUND", status: 404 }),
    );
    render(<AdminUserPrizeDetail userPrizeId={uuid("8")} />);
    expect(await screen.findByRole("heading", { name: "保有景品を表示できません" })).toBeVisible();
  });
});

function collection(): AdminUserPrizeCollection {
  return { items: [summary()], next_cursor: null, request_id: uuid("9") };
}

function summary(): AdminUserPrizeSummary {
  return {
    acquired_at: "2026-08-10T00:00:00Z",
    allowed_actions: {
      point_exchange: { allowed: false, unavailable_reason: "status_not_actionable" },
      selection: { allowed: false, unavailable_reason: "status_not_actionable" },
      shipping: { allowed: false, unavailable_reason: "status_not_actionable" },
    },
    exchange_points: 500,
    exchanged_points: null,
    fulfillment: { point_exchange_status: null, shipping_status: "requested" },
    gacha: { id: "TESTGACHA01", title: "取得元ガチャ", version_id: uuid("5") },
    id: uuid("1"),
    prize: {
      id: uuid("2"),
      image: null,
      name: "取得景品A",
      rank: { code: "S", id: uuid("3"), name: "Sランク" },
    },
    status: "shipping_requested",
    status_updated_at: "2026-08-10T01:00:00Z",
    storage_expires_at: "2026-10-10T00:00:00Z",
    terminal_at: null,
    user: { display_name: "テストユーザー", id: uuid("4") },
  };
}

function detail(): AdminUserPrizeDetailResponse {
  return {
    data: {
      ...summary(),
      draw: {
        completed_at: "2026-08-10T00:00:00Z",
        consumed_points: 500,
        executed_count: 5,
        request_id: uuid("6"),
        requested_count: 5,
        result_id: uuid("7"),
      },
      point_exchange: null,
      shipping: {
        carrier_code: null,
        id: uuid("8"),
        prize_count: 1,
        prize_ids: [uuid("1")],
        requested_at: "2026-08-10T01:00:00Z",
        shipped_at: null,
        shipping_address: {
          building: null,
          city: "千代田区",
          phone_number: "0000000000",
          postal_code: "1000001",
          prefecture: "東京都",
          recipient_name: "テストユーザー",
          street: "千代田1-1",
        },
        status: "requested",
        status_history: [],
        tracking_number: null,
      },
      status_history: [{
        from_status: "stored",
        occurred_at: "2026-08-10T01:00:00Z",
        reason_code: "shipping_requested",
        to_status: "shipping_requested",
      }],
    },
    request_id: uuid("9"),
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
