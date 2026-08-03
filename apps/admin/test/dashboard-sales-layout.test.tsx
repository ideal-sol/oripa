import { fireEvent, render, screen, within } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

const permissionState = {
  error: null as { retryAfter?: number } | null,
  retry: vi.fn(),
  status: "ready" as "error" | "idle" | "loading" | "rate_limited" | "ready",
};

vi.mock("@/components/permissions/permission-provider", () => ({
  usePermissions: () => ({
    error: permissionState.error,
    permissions: new Set(["reporting.financial.read"]),
    retry: permissionState.retry,
    role: "owner",
    status: permissionState.status,
  }),
}));

import { DashboardHome } from "@/components/shell/dashboard-home";
import { DashboardSalesLayout } from "@/components/shell/dashboard-sales-layout";

describe("Dashboard sales layout", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-03T12:00:00Z"));
    permissionState.error = null;
    permissionState.retry.mockReset();
    permissionState.status = "ready";
  });

  it("preserves the V1 view order and exposes the monthly sales structure", () => {
    render(<DashboardSalesLayout />);

    expect(screen.getAllByRole("tab").map((tab) => tab.textContent)).toEqual([
      "月別売上",
      "日別売上",
      "月別ポイント消費",
      "日別ポイント消費",
      "返金/CB履歴",
    ]);
    expect(screen.getByRole("tab", { name: "月別売上" })).toHaveAttribute(
      "aria-selected",
      "true",
    );
    expect(screen.getByLabelText("対象年月")).toHaveValue("2026-08");

    const summary = screen.getByRole("heading", { name: "月別売上Summary" }).parentElement
      ?.nextElementSibling;
    expect(summary).not.toBeNull();
    expect(within(summary as HTMLElement).getAllByRole("article").map((card) =>
      card.querySelector("span")?.textContent)).toEqual([
      "総売上",
      "返金額",
      "CB額",
      "純売上",
    ]);
    expect(screen.getByRole("heading", { name: "日別売上Calendar" })).toBeVisible();
    expect(screen.getAllByRole("columnheader").map((header) => header.textContent)).toEqual([
      "日", "月", "火", "水", "木", "金", "土",
    ]);
  });

  it("switches daily and point views without inventing values", () => {
    render(<DashboardSalesLayout />);

    fireEvent.click(screen.getByRole("tab", { name: "日別売上" }));
    expect(screen.getByLabelText("対象日")).toHaveValue("2026-08-03");
    expect(screen.getByRole("heading", { name: "日別サマリー" })).toBeVisible();
    expect(screen.getByRole("heading", { name: "決済一覧" })).toBeVisible();

    fireEvent.click(screen.getByRole("tab", { name: "月別ポイント消費" }));
    expect(screen.getByText("有償P消費")).toBeVisible();
    expect(screen.getByText("無償P消費")).toBeVisible();
    expect(screen.getByRole("heading", { name: "日別ポイント消費Calendar" })).toBeVisible();

    expect(screen.queryByText(/0(?:円|件|pt)/u)).toBeNull();
    expect(screen.getAllByText("集計API未接続").length).toBeGreaterThan(0);
  });

  it("moves and activates tabs with the standard keyboard controls", () => {
    render(<DashboardSalesLayout />);

    const monthlySales = screen.getByRole("tab", { name: "月別売上" });
    monthlySales.focus();
    fireEvent.keyDown(monthlySales, { key: "ArrowRight" });

    const dailySales = screen.getByRole("tab", { name: "日別売上" });
    expect(dailySales).toHaveFocus();
    expect(dailySales).toHaveAttribute("aria-selected", "true");

    fireEvent.keyDown(dailySales, { key: "End" });
    expect(screen.getByRole("tab", { name: "返金/CB履歴" })).toHaveFocus();
    expect(screen.getByRole("heading", { name: "返金・チャージバック履歴" })).toBeVisible();
  });

  it("makes deferred operations explicitly unavailable", () => {
    render(<DashboardSalesLayout />);

    expect(screen.getByRole("button", { name: "CSV（後続Taskで実装）" })).toBeDisabled();
    fireEvent.click(screen.getByRole("tab", { name: "返金/CB履歴" }));
    expect(screen.getByRole("button", { name: "検索（後続Taskで実装）" })).toBeDisabled();
    expect(screen.getByRole("group", { name: "対象期間" })).toBeVisible();
  });

  it("announces loading and error states and supports retry", () => {
    const retry = vi.fn();
    const { rerender } = render(<DashboardSalesLayout state="loading" />);
    expect(screen.getByText("売上管理データの接続状態を確認しています。")).toBeVisible();

    rerender(<DashboardSalesLayout onRetry={retry} retryAfter={30} state="error" />);
    expect(screen.getByRole("alert")).toHaveTextContent("売上管理を表示できません");
    expect(screen.getByRole("alert")).toHaveTextContent("30秒後に再試行できます。");
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(retry).toHaveBeenCalledOnce();
  });

  it("keeps the Dashboard route permission state fail closed", () => {
    permissionState.status = "rate_limited";
    permissionState.error = { retryAfter: 60 };
    render(<DashboardHome />);

    expect(screen.getByRole("heading", { name: "ダッシュボード" })).toBeVisible();
    expect(screen.getByRole("alert")).toHaveTextContent("60秒後に再試行できます。");
    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(permissionState.retry).toHaveBeenCalledOnce();
  });

  it("keeps wide calendar and table content inside scroll regions", () => {
    const { container } = render(<DashboardSalesLayout />);
    expect(container.querySelectorAll(".dashboard-sales-scroll-region")).toHaveLength(1);

    fireEvent.click(screen.getByRole("tab", { name: "日別売上" }));
    expect(container.querySelectorAll(".dashboard-sales-scroll-region")).toHaveLength(2);
  });
});
