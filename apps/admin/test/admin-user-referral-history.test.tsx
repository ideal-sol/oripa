import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { AdminUserReferralHistory } from "@/components/users/admin-user-referral-history";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminUserReferralHistoryCollection } from "@/lib/admin-api/generated";

describe("Admin User referral history", () => {
  afterEach(() => vi.restoreAllMocks());

  it("shows the normal Japanese empty state", async () => {
    const userId = uuid("1");
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValue(collection(userId, []));

    render(<AdminUserReferralHistory userPublicId={userId} />);

    expect(screen.getByRole("status")).toHaveTextContent("紹介履歴を読み込んでいます");
    expect(await screen.findByText("紹介履歴はありません。" )).toBeVisible();
  });

  it("renders only referred User identifiers, names, status, and canonical dates", async () => {
    const userId = uuid("1");
    vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValue(collection(userId, [item("2", "紹介先B", "rewarded")]));

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
    await act(async () => second.resolve(collection(uuid("3"), [item("4", "現在の紹介先", "pending")])))
    expect(await screen.findByText("現在の紹介先")).toBeVisible();

    await act(async () => first.resolve(collection(uuid("1"), [item("2", "古い紹介先", "rewarded")])))
    expect(screen.queryByText("古い紹介先")).toBeNull();
    expect(screen.getByText("現在の紹介先")).toBeVisible();
  });

  it("appends cursor pages without replacing existing rows", async () => {
    const userId = uuid("1");
    const reader = vi.spyOn(AdminApiClient.prototype, "listAdminUserReferralHistory")
      .mockResolvedValueOnce(collection(userId, [item("2", "紹介先B", "pending")], "next"))
      .mockResolvedValueOnce(collection(userId, [item("3", "紹介先C", "canceled")]));

    render(<AdminUserReferralHistory userPublicId={userId} />);
    fireEvent.click(await screen.findByRole("button", { name: "次の50件を表示" }));

    expect(await screen.findByText("紹介先C")).toBeVisible();
    expect(screen.getByText("紹介先B")).toBeVisible();
    expect(reader.mock.calls[1]?.[1]).toBe("next");
    expect(screen.getAllByRole("row")).toHaveLength(3);
  });
});

function collection(
  userId: string,
  items: AdminUserReferralHistoryCollection["items"],
  nextCursor: string | null = null,
): AdminUserReferralHistoryCollection {
  return { user_id: userId, items, next_cursor: nextCursor, request_id: uuid("9") };
}

function item(
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
  return `01910191-0191-7191-8191-${last.padStart(12, "0")}`;
}
