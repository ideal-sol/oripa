import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { AgencyAggregateTable, type AggregateResult } from "@/components/agencies/agency-aggregate-table";
import { AdminApiClient } from "@/lib/admin-api/client";
import { navigationForPermissions } from "@/lib/permissions/admin-navigation";

const period = { start_date: "2026-09-01", end_date: "2026-09-30", timezone: "Asia/Tokyo" as const };
const result: AggregateResult = { period, next_cursor: null, items: [{ company_name: "QA代理店", advertising_code: "AD000001", temporary_users: 2, full_users: 0 }] };

describe("Agency aggregates", () => {
  it("uses server current month, applies arbitrary dates, rejects reversed range and resets month", async () => {
    const load = vi.fn().mockResolvedValue(result);
    render(<AgencyAggregateTable kind="users" admin load={load} />);
    expect(screen.getByRole("status")).toHaveTextContent("読み込んでいます");
    expect(await screen.findByText("QA代理店")).toBeVisible();
    expect(screen.getByLabelText("開始日")).toHaveValue("2026-09-01");
    expect(screen.getByLabelText("終了日")).toHaveValue("2026-09-30");
    expect(load).toHaveBeenCalledWith({}, expect.any(AbortSignal));
    fireEvent.change(screen.getByLabelText("開始日"), { target: { value: "2026-10-01" } });
    fireEvent.submit(screen.getByRole("form", { name: "集計期間" }));
    expect(screen.getByRole("alert")).toHaveTextContent("開始日");
    expect(load).toHaveBeenCalledTimes(1);
    fireEvent.change(screen.getByLabelText("終了日"), { target: { value: "2026-10-31" } });
    load.mockResolvedValueOnce({ ...result, period: { ...period, start_date: "2026-10-01", end_date: "2026-10-31" } });
    fireEvent.submit(screen.getByRole("form", { name: "集計期間" }));
    await waitFor(() => expect(load).toHaveBeenLastCalledWith({ start_date: "2026-10-01", end_date: "2026-10-31" }, expect.any(AbortSignal)));
    await screen.findByText("対象期間：2026-10-01 ～ 2026-10-31");
    fireEvent.click(screen.getByRole("button", { name: "当月" }));
    await waitFor(() => expect(screen.getByLabelText("開始日")).toHaveValue("2026-09-01"));
    expect(load).toHaveBeenLastCalledWith({}, expect.any(AbortSignal));
  });

  it("renders payer counts and JPY including zero amount without dropping the row", async () => {
    const load = vi.fn().mockResolvedValue({ period, next_cursor: null, items: [{ company_name: "QA代理店", advertising_code: "AD000001", temporary_paying_users: 1, temporary_amount: 0, full_paying_users: 2, full_amount: 8000 }] });
    render(<AgencyAggregateTable kind="sales" admin load={load} />);
    expect(await screen.findByText("￥8,000")).toBeVisible();
    expect(screen.getByText("￥0")).toBeVisible();
    expect(screen.getByText("1人")).toBeVisible();
    expect(screen.getByText("2人")).toBeVisible();
    expect(screen.getByRole("columnheader", { name: "代理店名" })).toBeVisible();
  });

  it("handles errors, retry, empty and pagination with a pinned period", async () => {
    const load = vi.fn().mockRejectedValueOnce(new Error("network")).mockResolvedValueOnce({ ...result, next_cursor: "cursor" }).mockResolvedValueOnce({ ...result, items: [] });
    render(<AgencyAggregateTable kind="users" load={load} />);
    expect(await screen.findByRole("alert")).toHaveTextContent("取得できません");
    fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
    await screen.findByText("AD000001");
    fireEvent.click(screen.getByRole("button", { name: "次のページ" }));
    expect(await screen.findByText("表示できる広告コードはありません。")).toBeVisible();
    expect(load).toHaveBeenLastCalledWith({ start_date: period.start_date, end_date: period.end_date, cursor: "cursor" }, expect.any(AbortSignal));
    expect(screen.getByRole("button", { name: "先頭へ" })).toBeVisible();
    expect(screen.queryByText("QA代理店")).toBeNull();
  });

  it("aborts old period requests so stale results cannot replace the selected period", async () => {
    let resolveOld: (value: AggregateResult) => void = () => {};
    const load = vi.fn().mockImplementationOnce(() => new Promise<AggregateResult>(resolve => { resolveOld = resolve; })).mockResolvedValue(result);
    const view = render(<AgencyAggregateTable kind="users" load={load} />);
    view.unmount();
    expect(load.mock.calls[0][1].aborted).toBe(true);
    render(<AgencyAggregateTable kind="users" load={load} />);
    await screen.findByText("AD000001");
    resolveOld({ ...result, items: [] });
    expect(screen.getByText("AD000001")).toBeVisible();
  });

  it("uses Admin read transport and exposes all three agency navigation links to agency.read", async () => {
    const fetcher = vi.spyOn(globalThis, "fetch").mockImplementation(async () => new Response(JSON.stringify(result), { status: 200, headers: { "Content-Type": "application/json" } }));
    const client = new AdminApiClient();
    await client.agencyUserAggregate({ month: "2026-09" });
    await client.agencySalesAggregate({ start_date: "2026-09-01", end_date: "2026-09-30" });
    expect(String(fetcher.mock.calls[0][0])).toContain("/admin/api/v2/agencies/aggregates/users?month=2026-09");
    expect(String(fetcher.mock.calls[1][0])).toContain("/admin/api/v2/agencies/aggregates/sales?");
    const group = navigationForPermissions(new Set(["agency.read"])).find(node => node.id === "agencies");
    expect(group?.kind).toBe("group");
    if (group?.kind === "group") expect(group.children.map(child => child.label)).toEqual(["代理店一覧", "ユーザー集計", "売上集計"]);
  });
});
