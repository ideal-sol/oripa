"use client";

import { useCallback, useEffect, useMemo, useState } from "react";

import type {
  AdminDashboardDailyPoints,
  AdminDashboardDailySales,
  AdminDashboardMonthlyPoints,
  AdminDashboardMonthlySales,
  AdminDashboardReversalHistory,
} from "@/lib/admin-api/generated";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";

export type DashboardSalesView =
  | "monthly-sales"
  | "daily-sales"
  | "monthly-points"
  | "daily-points"
  | "reversals";

export type DashboardSalesData =
  | { kind: "monthly-sales"; report: AdminDashboardMonthlySales }
  | {
    kind: "daily-sales";
    report: AdminDashboardDailySales;
    reversals: AdminDashboardReversalHistory;
  }
  | { kind: "monthly-points"; report: AdminDashboardMonthlyPoints }
  | { kind: "daily-points"; report: AdminDashboardDailyPoints }
  | { kind: "reversals"; report: AdminDashboardReversalHistory };

export interface DashboardSalesDataState {
  data: DashboardSalesData | null;
  error: string | null;
  loading: boolean;
  loadingMore: boolean;
  loadMore: (target?: "primary" | "reversals") => Promise<void>;
  retryAfter: number | null;
  retry: () => void;
}

export function useDashboardSalesData({
  date,
  enabled,
  month,
  rangeEnd,
  rangeStart,
  view,
}: {
  date: string;
  enabled: boolean;
  month: string;
  rangeEnd: string;
  rangeStart: string;
  view: DashboardSalesView;
}): DashboardSalesDataState {
  const client = useMemo(() => new AdminApiClient(), []);
  const [revision, setRevision] = useState(0);
  const [state, setState] = useState<Omit<DashboardSalesDataState, "retry">>({
    data: null,
    error: null,
    loading: enabled,
    loadingMore: false,
    loadMore: async () => undefined,
    retryAfter: null,
  });

  useEffect(() => {
    if (!enabled) {
      setState((current) => ({ ...current, data: null, error: null, loading: false, retryAfter: null }));
      return;
    }
    if (view === "reversals" && rangeStart > rangeEnd) {
      setState((current) => ({
        ...current,
        data: null,
        error: "開始日は終了日以前を指定してください。",
        loading: false,
        retryAfter: null,
      }));
      return;
    }

    const controller = new AbortController();
    setState((current) => ({ ...current, data: null, error: null, loading: true, retryAfter: null }));
    void loadDashboardData(client, view, month, date, rangeStart, rangeEnd, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setState((current) => ({ ...current, data, error: null, loading: false, retryAfter: null }));
        }
      })
      .catch((error: unknown) => {
        if (controller.signal.aborted) {
          return;
        }
        setState((current) => ({
          ...current,
          data: null,
          error: error instanceof Error ? error.message : "売上管理データを取得できませんでした。",
          loading: false,
          retryAfter: error instanceof AdminApiError ? error.retryAfter : null,
        }));
      });

    return () => controller.abort();
  }, [client, date, enabled, month, rangeEnd, rangeStart, revision, view]);

  const retry = useCallback(() => setRevision((value) => value + 1), []);
  const loadMore = useCallback(async (target: "primary" | "reversals" = "primary") => {
    const current = state.data;
    if (!current || state.loadingMore) return;
    setState((value) => ({ ...value, loadingMore: true }));
    try {
      let data = current;
      if (current.kind === "daily-sales" && target === "primary" && current.report.next_cursor) {
        const next = await client.getDashboardDailySales(date, current.report.next_cursor);
        data = { ...current, report: { ...next, items: [...current.report.items, ...next.items] } };
      } else if (current.kind === "daily-sales" && target === "reversals" && current.reversals.next_cursor) {
        const next = await client.getDashboardReversals(date, date, current.reversals.next_cursor);
        data = { ...current, reversals: { ...next, items: [...current.reversals.items, ...next.items] } };
      } else if (current.kind === "daily-points" && current.report.next_cursor) {
        const next = await client.getDashboardDailyPoints(date, current.report.next_cursor);
        data = { ...current, report: { ...next, items: [...current.report.items, ...next.items] } };
      } else if (current.kind === "reversals" && current.report.next_cursor) {
        const next = await client.getDashboardReversals(rangeStart, rangeEnd, current.report.next_cursor);
        data = { ...current, report: { ...next, items: [...current.report.items, ...next.items] } };
      }
      setState((value) => ({ ...value, data, error: null, loadingMore: false, retryAfter: null }));
    } catch (error: unknown) {
      setState((value) => ({
        ...value,
        error: error instanceof Error ? error.message : "次のページを取得できませんでした。",
        loadingMore: false,
        retryAfter: error instanceof AdminApiError ? error.retryAfter : null,
      }));
    }
  }, [client, date, rangeEnd, rangeStart, state.data, state.loadingMore]);

  return { ...state, loadMore, retry };
}

async function loadDashboardData(
  client: AdminApiClient,
  view: DashboardSalesView,
  month: string,
  date: string,
  rangeStart: string,
  rangeEnd: string,
  signal: AbortSignal,
): Promise<DashboardSalesData> {
  if (view === "monthly-sales") {
    return { kind: view, report: await client.getDashboardMonthlySales(month, signal) };
  }
  if (view === "daily-sales") {
    const [report, reversals] = await Promise.all([
      client.getDashboardDailySales(date, undefined, signal),
      client.getDashboardReversals(date, date, undefined, signal),
    ]);
    return { kind: view, report, reversals };
  }
  if (view === "monthly-points") {
    return { kind: view, report: await client.getDashboardMonthlyPoints(month, signal) };
  }
  if (view === "daily-points") {
    return { kind: view, report: await client.getDashboardDailyPoints(date, undefined, signal) };
  }
  return {
    kind: view,
    report: await client.getDashboardReversals(rangeStart, rangeEnd, undefined, signal),
  };
}
