import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import type { DashboardSalesView } from "@/components/shell/use-dashboard-sales-data";

const permissionState = {
  error: null as { retryAfter?: number } | null,
  permissions: new Set(["reporting.financial.read"]),
  retry: vi.fn(),
  status: "ready" as "error" | "idle" | "loading" | "rate_limited" | "ready",
};

const loadMore = vi.fn(async () => undefined);
const retry = vi.fn();
const reports = new Map<DashboardSalesView, unknown>();

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({ ...permissionState, role: "owner" }),
}));

vi.mock("@/components/shell/use-dashboard-sales-data", () => ({
  useDashboardSalesData: ({ view }: { view: DashboardSalesView }) => ({
    data: reports.get(view) ?? null,
    error: null,
    loadMore,
    loading: false,
    loadingMore: false,
    retry,
    retryAfter: null,
  }),
}));

import { DashboardHome } from "@/components/shell/dashboard-home";
import { DashboardSalesLayout } from "@/components/shell/dashboard-sales-layout";

describe("Dashboard sales layout", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-03T12:00:00Z"));
    permissionState.error = null;
    permissionState.permissions = new Set(["reporting.financial.read"]);
    permissionState.retry.mockReset();
    permissionState.status = "ready";
    loadMore.mockClear();
    retry.mockClear();
    reports.clear();
    reports.set("monthly-sales", monthlySales());
    reports.set("daily-sales", dailySales());
    reports.set("monthly-points", monthlyPoints());
    reports.set("daily-points", dailyPoints());
    reports.set("reversals", reversals());
  });

  it("preserves the five-tab order and renders real monthly sales", () => {
    render(<DashboardSalesLayout />);

    expect(screen.getAllByRole("tab").map((tab) => tab.textContent)).toEqual([
      "月別売上", "日別売上", "月別ポイント消費", "日別ポイント消費", "返金/CB履歴",
    ]);
    expect(screen.getByLabelText("対象年月")).toHaveValue("2026-08");
    const summary = screen.getByRole("heading", { name: "月別売上Summary" }).parentElement
      ?.nextElementSibling;
    const summaryCards = within(summary as HTMLElement).getAllByRole("article");
    expect(summaryCards.map((card) => card.querySelector("span")?.textContent))
      .toEqual(["総売上", "返金額", "CB額", "純売上"]);
    expect(summaryCards.map((card) => card.querySelector("strong")?.textContent))
      .toEqual(["￥12,000", "￥2,000", "￥500", "￥9,500"]);
  });

  it("renders daily payments, reversals, and point ledger data without internal IDs", () => {
    render(<DashboardSalesLayout />);
    fireEvent.click(screen.getByRole("tab", { name: "日別売上" }));
    expect(screen.getByText("Synthetic Plan")).toBeVisible();
    expect(screen.getByText("返金")).toBeVisible();
    expect(screen.queryByText("private-provider-reference")).toBeNull();

    fireEvent.click(screen.getByRole("tab", { name: "日別ポイント消費" }));
    expect(screen.getByText("テストガチャ")).toBeVisible();
    expect(screen.getByText("70 pt")).toBeVisible();
    expect(screen.getByText("30 pt")).toBeVisible();
  });

  it("shows an explicit empty state instead of fabricated zero values", () => {
    reports.set("monthly-sales", {
      kind: "monthly-sales",
      report: { ...monthlySales().report, days: [], summary: emptySalesSummary() },
    });
    render(<DashboardSalesLayout />);

    expect(screen.getByRole("status")).toHaveTextContent("データがありません");
    expect(screen.queryByText(/¥0|0 pt/u)).toBeNull();
  });

  it("supports period changes, refresh, keyboard tabs, and cursor pagination", () => {
    render(<DashboardSalesLayout />);
    fireEvent.change(screen.getByLabelText("対象年月"), { target: { value: "2026-07" } });
    fireEvent.click(screen.getByRole("button", { name: "再取得" }));
    expect(retry).toHaveBeenCalledOnce();

    const monthly = screen.getByRole("tab", { name: "月別売上" });
    monthly.focus();
    fireEvent.keyDown(monthly, { key: "ArrowRight" });
    expect(screen.getByRole("tab", { name: "日別売上" })).toHaveFocus();
    fireEvent.click(screen.getByRole("button", { name: "次の50件を表示" }));
    expect(loadMore).toHaveBeenCalledWith("primary");
  });

  it("announces loading and error states and supports retry", () => {
    const externalRetry = vi.fn();
    const { rerender } = render(<DashboardSalesLayout state="loading" />);
    expect(screen.getByText("売上管理データを取得しています。")).toBeVisible();

    rerender(<DashboardSalesLayout onRetry={externalRetry} retryAfter={30} state="error" />);
    expect(screen.getByRole("alert")).toHaveTextContent("30秒後に再試行できます。");
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(externalRetry).toHaveBeenCalledOnce();
  });

  it("keeps the Dashboard permission boundary fail closed", () => {
    permissionState.permissions = new Set();
    render(<DashboardHome />);
    expect(screen.getByRole("heading", { name: "ダッシュボード" })).toBeVisible();
    expect(screen.getByRole("alert")).toHaveTextContent("売上管理を表示できません");
  });

  it("keeps wide calendar and tables inside keyboard-scrollable regions", () => {
    const { container } = render(<DashboardSalesLayout />);
    expect(container.querySelectorAll(".dashboard-sales-scroll-region")).toHaveLength(1);
    fireEvent.click(screen.getByRole("tab", { name: "日別売上" }));
    expect(container.querySelectorAll(".dashboard-sales-scroll-region")).toHaveLength(2);
    expect(container.querySelectorAll(".dashboard-sales-scroll-region[tabindex='0']")).toHaveLength(2);
  });
});

function emptySalesSummary() {
  return {
    chargeback_amount: 0, chargeback_count: 0, gross_sales_amount: 0,
    net_sales_amount: 0, payment_count: 0, refund_amount: 0, refund_count: 0,
  };
}

function monthlySales() {
  return {
    kind: "monthly-sales" as const,
    report: {
      basis: "operational_event_aggregation_not_accounting_recognition" as const,
      currency: "JPY" as const,
      days: [{ date: "2026-08-01", summary: {
        chargeback_amount: 500, chargeback_count: 1, gross_sales_amount: 12000,
        net_sales_amount: 9500, payment_count: 2, refund_amount: 2000, refund_count: 1,
      } }],
      month: "2026-08",
      summary: {
        chargeback_amount: 500, chargeback_count: 1, gross_sales_amount: 12000,
        net_sales_amount: 9500, payment_count: 2, refund_amount: 2000, refund_count: 1,
      },
      timezone: "Asia/Tokyo" as const,
    },
  };
}

function dailySales() {
  return {
    kind: "daily-sales" as const,
    report: {
      basis: "operational_event_aggregation_not_accounting_recognition" as const,
      currency: "JPY" as const,
      date: "2026-08-03",
      items: [{
        amount: 12000, currency: "JPY" as const, payment_id: uuid("1"), plan_name: "Synthetic Plan",
        provider: "synthetic", status: "succeeded" as const, succeeded_at: "2026-08-03T01:00:00Z", user_id: uuid("2"),
      }],
      next_cursor: "djE6MTA=",
      summary: monthlySales().report.summary,
      timezone: "Asia/Tokyo" as const,
    },
    reversals: reversals().report,
  };
}

function monthlyPoints() {
  return {
    kind: "monthly-points" as const,
    report: {
      days: [{ date: "2026-08-03", summary: { free_consumed: 30, paid_consumed: 70 } }],
      month: "2026-08", qa_excluded: true as const,
      summary: { free_consumed: 30, paid_consumed: 70 }, timezone: "Asia/Tokyo" as const,
    },
  };
}

function dailyPoints() {
  return {
    kind: "daily-points" as const,
    report: {
      date: "2026-08-03",
      items: [{
        draw_count: 10, draw_request_id: uuid("3"), free_consumed: 30,
        gacha_title: "テストガチャ", gacha_version_id: uuid("4"), occurred_at: "2026-08-03T01:00:00Z",
        operation_id: uuid("5"), paid_consumed: 70, source_type: "draw", user_id: uuid("2"),
      }],
      next_cursor: null, qa_excluded: true as const,
      summary: { free_consumed: 30, paid_consumed: 70 }, timezone: "Asia/Tokyo" as const,
    },
  };
}

function reversals() {
  return {
    kind: "reversals" as const,
    report: {
      end_date: "2026-08-03",
      items: [{
        adjustment_id: uuid("6"), amount: 2000, currency: "JPY" as const,
        occurred_at: "2026-08-03T02:00:00Z", payment_id: uuid("1"),
        status: "succeeded" as const, succeeded_at: "2026-08-03T02:00:00Z", type: "refund" as const,
      }],
      next_cursor: null, start_date: "2026-08-03", timezone: "Asia/Tokyo" as const,
    },
  };
}

function uuid(last: string): string {
  return `01910191-0191-7191-8191-01910191019${last}`;
}
