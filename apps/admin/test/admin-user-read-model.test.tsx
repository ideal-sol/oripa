import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { AdminUserReadData } from "@/components/users/use-admin-user-read-model";
import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminPaymentCollection,
  AdminPermissionCode,
  AdminUserReferralHistoryCollection,
} from "@/lib/admin-api/generated";

const state = {
  data: null as AdminUserReadData | null,
  error: null as string | null,
  loadMore: vi.fn(async () => undefined),
  loading: false,
  loadingMore: false,
  retry: vi.fn(),
};
const permissions = new Set<AdminPermissionCode>();
const readModel = vi.hoisted(() => vi.fn());

vi.mock("@/components/users/use-admin-user-read-model", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/components/users/use-admin-user-read-model")>();
  return { ...original, useAdminUserReadModel: readModel };
});
vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    hasPermission: (permission: AdminPermissionCode) => permissions.has(permission),
    permissions,
    role: "operator",
    status: "ready",
  }),
}));
vi.mock("@/components/auth/fresh-mfa-dialog", () => ({ FreshMfaDialog: () => null }));
import {
  AdminUserReadWorkspace,
  AdminUserReferralHistory,
} from "@/components/users/admin-user-read-workspace";

describe("Admin User Read workspace", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValue(referralCollection(uuid("1"), []));
    state.data = null;
    state.error = null;
    state.loading = false;
    state.loadingMore = false;
    state.loadMore.mockClear();
    state.retry.mockClear();
    readModel.mockClear();
    readModel.mockImplementation(() => state);
    permissions.clear();
  });

  it("renders the fixed list column order, backend balances, and unset display name", () => {
    state.data = { kind: "list", value: {
      items: [userSummary()], next_cursor: "djE6MTA=", request_id: uuid("9"),
    } };
    render(<AdminUserReadWorkspace mode="list" />);

    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "ユーザー名", "状態", "合計残高", "有償P", "無償P", "登録日", "詳細",
    ]);
    const row = screen.getAllByRole("row")[1];
    expect(within(row).getByText("未設定")).toBeVisible();
    expect(within(row).getByText("300 pt")).toBeVisible();
    expect(within(row).getByText("100 pt")).toBeVisible();
    expect(within(row).getByText("200 pt")).toBeVisible();
    expect(within(row).getByRole("link", { name: "未設定の詳細" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}`,
    );
    fireEvent.click(screen.getByRole("button", { name: "次の50件を表示" }));
    expect(state.loadMore).toHaveBeenCalledOnce();
  });

  it("uses active defaults, applies explicit compound filters, and resets canonically", () => {
    state.data = { kind: "list", value: {
      items: [{ ...userSummary(), status: "verification_failed" }],
      next_cursor: null,
      request_id: uuid("9"),
    } };
    render(<AdminUserReadWorkspace mode="list" />);

    expect(screen.getByLabelText("状態")).toHaveValue("active");
    expect(screen.getAllByText("認証失敗")).toHaveLength(2);
    fireEvent.change(screen.getByLabelText("User ID"), { target: { value: uuid("1") } });
    fireEvent.change(screen.getByLabelText("状態"), { target: { value: "verification_failed" } });
    fireEvent.change(screen.getByLabelText("登録日（開始）"), { target: { value: "2026-08-01" } });
    fireEvent.change(screen.getByLabelText("登録日（終了）"), { target: { value: "2026-08-31" } });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));

    expect(readModel.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      listFilters: {
        date_from: "2026-08-01",
        date_to: "2026-08-31",
        status: "verification_failed",
        user_id: uuid("1"),
      },
    }));
    fireEvent.click(screen.getByRole("button", { name: "条件を解除" }));
    expect(screen.getByLabelText("状態")).toHaveValue("active");
    expect(screen.getByLabelText("User ID")).toHaveValue("");
    expect(readModel.mock.calls.at(-1)?.[0]).toEqual(expect.objectContaining({
      listFilters: {
        date_from: undefined,
        date_to: undefined,
        status: "active",
        user_id: undefined,
      },
    }));
  });

  it("rejects an inverted registration date range before loading", () => {
    render(<AdminUserReadWorkspace mode="list" />);
    fireEvent.change(screen.getByLabelText("登録日（開始）"), { target: { value: "2026-08-31" } });
    fireEvent.change(screen.getByLabelText("登録日（終了）"), { target: { value: "2026-08-01" } });
    fireEvent.click(screen.getByRole("button", { name: "検索" }));
    expect(screen.getByRole("alert")).toHaveTextContent("開始日は終了日以前");
  });

  it("renders V2 detail without owned prizes and links to the separate history route", () => {
    state.data = { kind: "detail", value: {
      ...userSummary(),
      email: "user@example.test",
      email_verified_at: "2026-08-03T00:00:00Z",
      sms_verified: true,
      phone: "+819012345678",
      verified_at: "2026-08-03T00:30:00Z",
      state_revision: 1,
      tag_assignment_revision: 1,
      tags: [],
      updated_at: "2026-08-03T01:00:00Z",
    } };
    render(<AdminUserReadWorkspace mode="detail" userPublicId={uuid("1")} />);

    expect(screen.getByRole("heading", { name: "基本情報" })).toBeVisible();
    expect(screen.getByText("SMS認証")).toBeVisible();
    expect(screen.getByText("認証済み")).toBeVisible();
    expect(screen.getByText("+819012345678")).toBeVisible();
    expect(screen.getByText("SMS認証日時")).toBeVisible();
    expect(screen.getByRole("heading", { name: "コイン残高" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "紹介履歴" })).toBeVisible();
    expect(screen.getByText("合計コイン")).toBeVisible();
    expect(screen.getByText("有償コイン")).toBeVisible();
    expect(screen.getByText("ボーナスコイン")).toBeVisible();
    expect(screen.getByText("次回失効コイン数")).toBeVisible();
    expect(screen.getByText("次回失効日時")).toBeVisible();
    expect(screen.getByText("300 コイン")).toBeVisible();
    expect(screen.getByText("2026/08/20 9:30")).toBeVisible();
    expect(screen.queryByText("ユーザー保有景品")).toBeNull();
    expect(screen.queryByRole("heading", { name: "決済履歴" })).toBeNull();
    expect(screen.getByRole("link", { name: "ガチャ履歴を表示" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}/gacha-history`,
    );
    expect(screen.queryByRole("button", { name: /調整|Save|保存/u })).toBeNull();
  });

  it("shows only the selected User Payment history for the canonical permission", async () => {
    state.data = { kind: "detail", value: {
      ...userSummary(),
      email: "user@example.test",
      email_verified_at: "2026-08-03T00:00:00Z",
      sms_verified: false,
      phone: null,
      verified_at: null,
      state_revision: 1,
      tag_assignment_revision: 1,
      tags: [],
      updated_at: "2026-08-03T01:00:00Z",
    } };
    permissions.add("reporting.financial.read");
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminUserPayments")
      .mockResolvedValue(paymentCollection(uuid("1")));

    render(<AdminUserReadWorkspace mode="detail" userPublicId={uuid("1")} />);

    expect(await screen.findByRole("heading", { name: "決済履歴" })).toBeVisible();
    expect(screen.getByText("決済成功")).toBeVisible();
    expect(screen.getByText("PayPay")).toBeVisible();
    expect(screen.getByText(/2,000/u)).toBeVisible();
    expect(reader).toHaveBeenCalledWith(
      uuid("1"),
      expect.objectContaining({ limit: 20 }),
      expect.any(AbortSignal),
    );
    expect(screen.queryByRole("columnheader", { name: "User" })).toBeNull();
  });

  it("shows point adjustment only when the canonical permission is effective", () => {
    state.data = { kind: "detail", value: {
      ...userSummary(),
      email: "user@example.test",
      email_verified_at: "2026-08-03T00:00:00Z",
      sms_verified: false,
      phone: null,
      verified_at: null,
      state_revision: 1,
      tag_assignment_revision: 1,
      tags: [],
      updated_at: "2026-08-03T01:00:00Z",
    } };
    permissions.add("point.adjustment.manage");
    render(<AdminUserReadWorkspace mode="detail" userPublicId={uuid("1")} />);

    expect(screen.getByRole("button", { name: "ポイント調整" })).toBeVisible();
  });

  it("renders acquired-prize history on its own route with V1-derived order", () => {
    state.data = { kind: "history", value: {
      items: [historyItem()], next_cursor: null, request_id: uuid("9"), user_id: uuid("1"),
    } };
    render(<AdminUserReadWorkspace mode="history" userPublicId={uuid("1")} />);

    expect(screen.getAllByRole("columnheader").map((cell) => cell.textContent)).toEqual([
      "ID", "ガチャ", "景品", "ランク", "状態", "交換P", "取得日", "保管期限",
    ]);
    expect(screen.getByText("テストガチャ")).toBeVisible();
    expect(screen.getByText("景品A")).toBeVisible();
    expect(screen.getByRole("link", { name: "ユーザー詳細へ" })).toHaveAttribute(
      "href",
      `/users/${uuid("1")}`,
    );
  });

  it("announces loading, empty, and recoverable error states", () => {
    state.loading = true;
    const { rerender } = render(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込んでいます");

    state.loading = false;
    state.error = "取得できません。";
    rerender(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("alert")).toHaveTextContent("取得できません。");
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(state.retry).toHaveBeenCalledOnce();

    state.error = null;
    state.data = { kind: "list", value: { items: [], next_cursor: null, request_id: uuid("9") } };
    rerender(<AdminUserReadWorkspace mode="list" />);
    expect(screen.getByRole("status")).toHaveTextContent("表示できるユーザーはいません");
  });
});

describe("Admin User referral history", () => {
  afterEach(() => vi.restoreAllMocks());

  it("shows the normal Japanese empty state", async () => {
    const userId = uuid("1");
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValue(referralCollection(userId, []));

    render(<AdminUserReferralHistory userPublicId={userId} />);

    expect(screen.getByRole("status")).toHaveTextContent("紹介履歴を読み込んでいます");
    expect(await screen.findByText("紹介履歴はありません。")).toBeVisible();
  });

  it("renders only referred User identifiers, names, status, and canonical dates", async () => {
    const userId = uuid("1");
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValue(referralCollection(userId, [referralItem("2", "紹介先B", "rewarded")]));

    render(<AdminUserReferralHistory userPublicId={userId} />);

    const row = (await screen.findAllByRole("row"))[1];
    expect(within(row).getByTitle(uuid("2"))).toBeVisible();
    expect(within(row).getByText("紹介先B")).toBeVisible();
    expect(within(row).getByText("付与済み")).toBeVisible();
    expect(screen.queryByText("紹介者")).toBeNull();
    expect(screen.queryByText("紹介コード")).toBeNull();
  });

  it("clears the previous User immediately and ignores its stale response", async () => {
    const first = deferred<AdminUserReferralHistoryCollection>();
    const second = deferred<AdminUserReferralHistoryCollection>();
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockImplementation((userId) => userId === uuid("1") ? first.promise : second.promise);
    const view = render(<AdminUserReferralHistory userPublicId={uuid("1")} />);

    view.rerender(<AdminUserReferralHistory userPublicId={uuid("3")} />);
    expect(screen.getByRole("status")).toHaveTextContent("紹介履歴を読み込んでいます");
    await act(async () => second.resolve(
      referralCollection(uuid("3"), [referralItem("4", "現在の紹介先", "pending")]),
    ));
    expect(await screen.findByText("現在の紹介先")).toBeVisible();

    await act(async () => first.resolve(
      referralCollection(uuid("1"), [referralItem("2", "古い紹介先", "rewarded")]),
    ));
    expect(screen.queryByText("古い紹介先")).toBeNull();
    expect(screen.getByText("現在の紹介先")).toBeVisible();
  });

  it("appends cursor pages without replacing existing rows", async () => {
    const userId = uuid("1");
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValueOnce(referralCollection(
        userId,
        [referralItem("2", "紹介先B", "pending")],
        "next",
      ))
      .mockResolvedValueOnce(referralCollection(
        userId,
        [referralItem("3", "紹介先C", "canceled")],
      ));

    render(<AdminUserReferralHistory userPublicId={userId} />);
    fireEvent.click(await screen.findByRole("button", { name: "次の50件を表示" }));

    expect(await screen.findByText("紹介先C")).toBeVisible();
    expect(screen.getByText("紹介先B")).toBeVisible();
    expect(reader.mock.calls[1]?.[1]).toBe("next");
    expect(screen.getAllByRole("row")).toHaveLength(3);
  });
});

function userSummary() {
  return {
    created_at: "2026-08-03T00:00:00Z",
    display_name: null,
    id: uuid("1"),
    point_balance: {
      free_balance: 200,
      next_expires_at: "2026-08-20T00:30:00Z",
      next_expiring_amount: 25,
      paid_balance: 100,
      total_balance: 300,
    },
    status: "active" as const,
  };
}

function historyItem() {
  return {
    acquired_at: "2026-08-03T00:00:00Z",
    draw_result_id: uuid("2"),
    exchange_point_snapshot: 500,
    exchanged_point_amount: null,
    gacha_id: uuid("3"),
    gacha_title: "テストガチャ",
    gacha_version_id: uuid("4"),
    id: uuid("5"),
    prize_id: uuid("6"),
    prize_name: "景品A",
    rank_id: uuid("7"),
    rank_name: "Sランク",
    status: "stored",
    storage_expires_at: "2026-10-02T00:00:00Z",
    terminal_at: null,
  };
}

function referralCollection(
  userId: string,
  items: AdminUserReferralHistoryCollection["items"],
  nextCursor: string | null = null,
): AdminUserReferralHistoryCollection {
  return { user_id: userId, items, next_cursor: nextCursor, request_id: uuid("9") };
}

function paymentCollection(userId: string): AdminPaymentCollection {
  return {
    data: [{
      amount: { amount: 2000, currency: "JPY" },
      created_at: "2026-08-24T15:00:00Z",
      expires_at: null,
      grant: { bonus_points: 200, granted_at: "2026-08-24T15:05:00Z", paid_points: 2000 },
      id: uuid("5"),
      method: "paypay",
      provider: "fincode",
      provider_payment_reference: "payment-reference-5",
      provider_status: null,
      status: "succeeded",
      succeeded_at: "2026-08-24T15:00:00Z",
      updated_at: "2026-08-24T15:05:00Z",
      user: { display_name: "対象ユーザー", id: userId },
    }],
    pagination: { has_more: false, limit: 20, next_cursor: null },
    request_id: uuid("9"),
  };
}

function referralItem(
  suffix: string,
  displayName: string,
  status: "pending" | "rewarded" | "canceled",
): AdminUserReferralHistoryCollection["items"][number] {
  return {
    id: uuid(`8${suffix}`),
    referred_user_id: uuid(suffix),
    referred_user_display_name: displayName,
    status,
    referred_at: "2026-08-24T00:00:00Z",
    registered_at: "2026-08-23T00:00:00Z",
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((next) => { resolve = next; });
  return { promise, resolve };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
