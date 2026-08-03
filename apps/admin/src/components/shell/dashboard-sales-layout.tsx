"use client";

import { AlertTriangle, Download, RotateCcw } from "lucide-react";
import { useState } from "react";

import type {
  AdminDashboardDailyPoints,
  AdminDashboardDailySales,
  AdminDashboardMonthlyPoints,
  AdminDashboardMonthlySales,
  AdminDashboardReversalHistory,
  AdminDashboardSalesSummary,
} from "@/lib/admin-api/generated";
import {
  type DashboardSalesData,
  type DashboardSalesView,
  useDashboardSalesData,
} from "@/components/shell/use-dashboard-sales-data";

type DashboardSalesState = "error" | "loading" | "ready";

const salesViews: { id: DashboardSalesView; label: string }[] = [
  { id: "monthly-sales", label: "月別売上" },
  { id: "daily-sales", label: "日別売上" },
  { id: "monthly-points", label: "月別ポイント消費" },
  { id: "daily-points", label: "日別ポイント消費" },
  { id: "reversals", label: "返金/CB履歴" },
];

const weekdays = ["日", "月", "火", "水", "木", "金", "土"];
const currency = new Intl.NumberFormat("ja-JP", {
  currency: "JPY",
  maximumFractionDigits: 0,
  style: "currency",
});
const number = new Intl.NumberFormat("ja-JP");
const tokyoDateTime = new Intl.DateTimeFormat("ja-JP", {
  dateStyle: "medium",
  timeStyle: "short",
  timeZone: "Asia/Tokyo",
});

export function DashboardSalesLayout({
  onRetry,
  retryAfter,
  state = "ready",
}: {
  onRetry?: () => void;
  retryAfter?: number;
  state?: DashboardSalesState;
}) {
  const initialPeriod = currentTokyoPeriod();
  const [view, setView] = useState<DashboardSalesView>("monthly-sales");
  const [month, setMonth] = useState(initialPeriod.month);
  const [date, setDate] = useState(initialPeriod.date);
  const [rangeStart, setRangeStart] = useState(initialPeriod.date);
  const [rangeEnd, setRangeEnd] = useState(initialPeriod.date);
  const report = useDashboardSalesData({
    date,
    enabled: state === "ready",
    month,
    rangeEnd,
    rangeStart,
    view,
  });
  const loading = state === "loading" || report.loading;
  const error = state === "error" ? "売上管理を表示できません。" : report.error;
  const retry = state === "error" ? onRetry : report.retry;
  const effectiveRetryAfter = state === "error" ? retryAfter : report.retryAfter ?? undefined;

  return (
    <div className="dashboard-sales-layout">
      <section className="dashboard-sales-toolbar" aria-labelledby="sales-view-heading">
        <div>
          <h2 id="sales-view-heading">売上管理</h2>
          <p>決済売上とガチャで消費されたポイントを確認できます。</p>
        </div>
        <div
          className="dashboard-sales-tabs"
          role="tablist"
          aria-label="売上管理表示切替"
        >
          {salesViews.map((item, index) => (
            <button
              aria-controls={`dashboard-sales-panel-${item.id}`}
              aria-selected={view === item.id}
              className={view === item.id ? "is-active" : undefined}
              id={`dashboard-sales-tab-${item.id}`}
              key={item.id}
              onClick={() => setView(item.id)}
              onKeyDown={(event) => {
                const nextIndex = nextSalesViewIndex(event.key, index);
                if (nextIndex === null) return;
                event.preventDefault();
                setView(salesViews[nextIndex].id);
                event.currentTarget.parentElement
                  ?.querySelectorAll<HTMLButtonElement>("[role='tab']")
                  .item(nextIndex)
                  .focus();
              }}
              role="tab"
              tabIndex={view === item.id ? 0 : -1}
              type="button"
            >
              {item.label}
            </button>
          ))}
        </div>
        <div className="dashboard-sales-controls">
          <PeriodControl
            date={date}
            month={month}
            onDateChange={setDate}
            onMonthChange={setMonth}
            onRangeEndChange={setRangeEnd}
            onRangeStartChange={setRangeStart}
            rangeEnd={rangeEnd}
            rangeStart={rangeStart}
            view={view}
          />
          <button className="secondary-button" onClick={report.retry} type="button">
            <RotateCcw aria-hidden="true" size={17} />
            再取得
          </button>
        </div>
      </section>

      {loading ? (
        <section className="dashboard-sales-state" aria-live="polite">
          <p>売上管理データを取得しています。</p>
        </section>
      ) : error ? (
        <section className="dashboard-sales-state is-error" role="alert">
          <AlertTriangle aria-hidden="true" size={22} />
          <div>
            <h2>売上管理を表示できません</h2>
            <p>{error}</p>
            {effectiveRetryAfter ? <p>{effectiveRetryAfter}秒後に再試行できます。</p> : null}
          </div>
          {retry ? (
            <button className="secondary-button" onClick={retry} type="button">
              <RotateCcw aria-hidden="true" size={17} />
              再試行
            </button>
          ) : null}
        </section>
      ) : (
        <DashboardSalesPanel
          data={report.data}
          loadMore={report.loadMore}
          loadingMore={report.loadingMore}
          month={month}
          view={view}
        />
      )}
    </div>
  );
}

function nextSalesViewIndex(key: string, currentIndex: number): number | null {
  if (key === "Home") return 0;
  if (key === "End") return salesViews.length - 1;
  if (key === "ArrowRight" || key === "ArrowDown") {
    return (currentIndex + 1) % salesViews.length;
  }
  if (key === "ArrowLeft" || key === "ArrowUp") {
    return (currentIndex - 1 + salesViews.length) % salesViews.length;
  }
  return null;
}

function PeriodControl({
  date,
  month,
  onDateChange,
  onMonthChange,
  onRangeEndChange,
  onRangeStartChange,
  rangeEnd,
  rangeStart,
  view,
}: {
  date: string;
  month: string;
  onDateChange: (value: string) => void;
  onMonthChange: (value: string) => void;
  onRangeEndChange: (value: string) => void;
  onRangeStartChange: (value: string) => void;
  rangeEnd: string;
  rangeStart: string;
  view: DashboardSalesView;
}) {
  if (view === "monthly-sales" || view === "monthly-points") {
    return (
      <label className="dashboard-sales-period">
        <span>対象年月</span>
        <input onChange={(event) => onMonthChange(event.target.value)} type="month" value={month} />
      </label>
    );
  }
  if (view === "reversals") {
    return (
      <div className="dashboard-sales-period-range" role="group" aria-label="対象期間">
        <label className="dashboard-sales-period">
          <span>開始日</span>
          <input onChange={(event) => onRangeStartChange(event.target.value)} type="date" value={rangeStart} />
        </label>
        <label className="dashboard-sales-period">
          <span>終了日</span>
          <input onChange={(event) => onRangeEndChange(event.target.value)} type="date" value={rangeEnd} />
        </label>
      </div>
    );
  }
  return (
    <label className="dashboard-sales-period">
      <span>対象日</span>
      <input onChange={(event) => onDateChange(event.target.value)} type="date" value={date} />
    </label>
  );
}

function DashboardSalesPanel({
  data,
  loadMore,
  loadingMore,
  month,
  view,
}: {
  data: DashboardSalesData | null;
  loadMore: (target?: "primary" | "reversals") => Promise<void>;
  loadingMore: boolean;
  month: string;
  view: DashboardSalesView;
}) {
  return (
    <section
      aria-labelledby={`dashboard-sales-tab-${view}`}
      id={`dashboard-sales-panel-${view}`}
      role="tabpanel"
      tabIndex={0}
    >
      {view === "monthly-sales" ? (
        <MonthlySalesPanel month={month} report={data?.kind === view ? data.report : null} />
      ) : null}
      {view === "daily-sales" ? (
        <DailySalesPanel data={data?.kind === view ? data : null} loadMore={loadMore} loadingMore={loadingMore} />
      ) : null}
      {view === "monthly-points" ? (
        <MonthlyPointsPanel month={month} report={data?.kind === view ? data.report : null} />
      ) : null}
      {view === "daily-points" ? (
        <DailyPointsPanel loadMore={loadMore} loadingMore={loadingMore} report={data?.kind === view ? data.report : null} />
      ) : null}
      {view === "reversals" ? (
        <ReversalsPanel loadMore={loadMore} loadingMore={loadingMore} report={data?.kind === view ? data.report : null} />
      ) : null}
    </section>
  );
}

function MonthlySalesPanel({ month, report }: { month: string; report: AdminDashboardMonthlySales | null }) {
  if (!report || !hasSales(report.summary)) {
    return <EmptyData message="対象月の売上・返金・チャージバックはありません。" />;
  }
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="月別売上Summary" />
      <SalesSummary summary={report.summary} />
      <SectionHeading action={<DeferredCsvButton />} title="日別売上Calendar" />
      <SalesCalendar month={month} report={report} />
    </div>
  );
}

function DailySalesPanel({ data, loadMore, loadingMore }: {
  data: Extract<DashboardSalesData, { kind: "daily-sales" }> | null;
  loadMore: (target?: "primary" | "reversals") => Promise<void>;
  loadingMore: boolean;
}) {
  if (!data || (!hasSales(data.report.summary) && data.reversals.items.length === 0)) {
    return <EmptyData message="対象日の決済・返金・チャージバックはありません。" />;
  }
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading meta={data.report.date} title="日別サマリー" />
      <SalesSummary summary={data.report.summary} />
      <SectionHeading action={<DeferredCsvButton />} meta="成功日時基準" title="決済一覧" />
      <PaymentTable loading={loadingMore} onLoadMore={() => void loadMore("primary")} report={data.report} />
      <SectionHeading action={<DeferredCsvButton />} meta="成功時刻／要求時刻基準" title="返金・チャージバック一覧" />
      <ReversalTable loading={loadingMore} onLoadMore={() => void loadMore("reversals")} report={data.reversals} />
    </div>
  );
}

function MonthlyPointsPanel({ month, report }: { month: string; report: AdminDashboardMonthlyPoints | null }) {
  if (!report || !hasPoints(report)) {
    return <EmptyData message="対象月の通常Draw Point消費はありません。" />;
  }
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="月別ポイント消費Summary" />
      <PointSummary report={report} />
      <SectionHeading action={<DeferredCsvButton />} title="日別ポイント消費Calendar" />
      <PointCalendar month={month} report={report} />
    </div>
  );
}

function DailyPointsPanel({ loadMore, loadingMore, report }: {
  loadMore: (target?: "primary" | "reversals") => Promise<void>;
  loadingMore: boolean;
  report: AdminDashboardDailyPoints | null;
}) {
  if (!report || report.items.length === 0) {
    return <EmptyData message="対象日の通常Draw Point消費はありません。" />;
  }
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading action={<DeferredCsvButton />} meta={report.date} title="日別ポイント消費一覧" />
      <div className="dashboard-sales-scroll-region" tabIndex={0}>
        <table className="dashboard-sales-table">
          <thead><tr>{["日時", "有償P", "無償P", "ユーザー", "ガチャ", "抽選回数"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
          <tbody>{report.items.map((item) => (
            <tr key={item.operation_id}>
              <td>{formatDateTime(item.occurred_at)}</td>
              <td>{formatPoints(item.paid_consumed)}</td>
              <td>{formatPoints(item.free_consumed)}</td>
              <td><PublicId value={item.user_id} /></td>
              <td>{item.gacha_title ?? item.source_type}</td>
              <td>{item.draw_count === null ? "-" : number.format(item.draw_count)}</td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <CursorNotice cursor={report.next_cursor} loading={loadingMore} onLoadMore={() => void loadMore("primary")} />
    </div>
  );
}

function ReversalsPanel({ loadMore, loadingMore, report }: {
  loadMore: (target?: "primary" | "reversals") => Promise<void>;
  loadingMore: boolean;
  report: AdminDashboardReversalHistory | null;
}) {
  if (!report || report.items.length === 0) {
    return <EmptyData message="対象期間の返金・チャージバック履歴はありません。" />;
  }
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="返金・チャージバック履歴" />
      <ReversalTable loading={loadingMore} onLoadMore={() => void loadMore("primary")} report={report} />
    </div>
  );
}

function SalesSummary({ summary }: { summary: AdminDashboardSalesSummary }) {
  return <SummaryGrid items={[
    ["総売上", currency.format(summary.gross_sales_amount), `${number.format(summary.payment_count)}件`],
    ["返金額", currency.format(summary.refund_amount), `${number.format(summary.refund_count)}件`],
    ["CB額", currency.format(summary.chargeback_amount), `${number.format(summary.chargeback_count)}件`],
    ["純売上", currency.format(summary.net_sales_amount), "総売上-返金-CB"],
  ]} />;
}

function PointSummary({ report }: { report: AdminDashboardMonthlyPoints }) {
  return <SummaryGrid items={[
    ["有償P消費", formatPoints(report.summary.paid_consumed), "QA除外"],
    ["無償P消費", formatPoints(report.summary.free_consumed), "QA除外"],
  ]} />;
}

function SummaryGrid({ items }: { items: [string, string, string][] }) {
  return (
    <div className="dashboard-sales-summary-grid">
      {items.map(([label, value, caption]) => (
        <article className="dashboard-sales-summary-card" key={label}>
          <span>{label}</span><strong>{value}</strong><small>{caption}</small>
        </article>
      ))}
    </div>
  );
}

function SalesCalendar({ month, report }: { month: string; report: AdminDashboardMonthlySales }) {
  const days = new Map(report.days.map((day) => [day.date, day.summary]));
  return <Calendar month={month} renderDay={(date) => {
    const summary = days.get(date);
    return summary ? <><strong>{currency.format(summary.gross_sales_amount)}</strong><small>純売上 {currency.format(summary.net_sales_amount)}</small></> : null;
  }} />;
}

function PointCalendar({ month, report }: { month: string; report: AdminDashboardMonthlyPoints }) {
  const days = new Map(report.days.map((day) => [day.date, day.summary]));
  return <Calendar month={month} renderDay={(date) => {
    const summary = days.get(date);
    return summary ? <><strong>有償 {formatPoints(summary.paid_consumed)}</strong><small>無償 {formatPoints(summary.free_consumed)}</small></> : null;
  }} />;
}

function Calendar({ month, renderDay }: { month: string; renderDay: (date: string) => React.ReactNode }) {
  const [year, monthNumber] = month.split("-").map(Number);
  const count = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
  const first = new Date(Date.UTC(year, monthNumber - 1, 1)).getUTCDay();
  const cells: Array<string | null> = Array.from({ length: first }, () => null);
  for (let day = 1; day <= count; day += 1) cells.push(`${month}-${String(day).padStart(2, "0")}`);
  while (cells.length % 7 !== 0) cells.push(null);
  return (
    <div className="dashboard-sales-scroll-region" tabIndex={0}>
      <table className="dashboard-sales-calendar">
        <thead><tr>{weekdays.map((weekday) => <th key={weekday} scope="col">{weekday}</th>)}</tr></thead>
        <tbody>{Array.from({ length: cells.length / 7 }, (_, week) => (
          <tr key={week}>{cells.slice(week * 7, week * 7 + 7).map((date, index) => (
            <td key={`${week}-${index}`}><span>{date ? Number(date.slice(-2)) : ""}</span>{date ? renderDay(date) : null}</td>
          ))}</tr>
        ))}</tbody>
      </table>
    </div>
  );
}

function PaymentTable({ loading, onLoadMore, report }: {
  loading: boolean;
  onLoadMore: () => void;
  report: AdminDashboardDailySales;
}) {
  if (report.items.length === 0) return <EmptyData message="対象日の成功決済はありません。" />;
  return (
    <div className="dashboard-sales-scroll-region" tabIndex={0}>
      <table className="dashboard-sales-table">
        <thead><tr>{["決済日時", "決済種別", "購入プラン", "決済金額", "状態", "ユーザー"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
        <tbody>{report.items.map((item) => (
          <tr key={item.payment_id}>
            <td>{formatDateTime(item.succeeded_at)}</td><td>{item.provider}</td><td>{item.plan_name}</td>
            <td>{currency.format(item.amount)}</td><td>成功</td><td><PublicId value={item.user_id} /></td>
          </tr>
        ))}</tbody>
      </table>
      <CursorNotice cursor={report.next_cursor} loading={loading} onLoadMore={onLoadMore} />
    </div>
  );
}

function ReversalTable({ loading, onLoadMore, report }: {
  loading: boolean;
  onLoadMore: () => void;
  report: AdminDashboardReversalHistory;
}) {
  if (report.items.length === 0) return <EmptyData message="対象期間の返金・チャージバックはありません。" />;
  return (
    <div className="dashboard-sales-scroll-region" tabIndex={0}>
      <table className="dashboard-sales-table">
        <thead><tr>{["発生日", "区分", "決済", "金額", "状態"].map((label) => <th key={label} scope="col">{label}</th>)}</tr></thead>
        <tbody>{report.items.map((item) => (
          <tr key={item.adjustment_id}>
            <td>{formatDateTime(item.occurred_at)}</td><td>{reversalLabel(item.type)}</td>
            <td><PublicId value={item.payment_id} /></td><td>{currency.format(item.amount)}</td><td>{item.status}</td>
          </tr>
        ))}</tbody>
      </table>
      <CursorNotice cursor={report.next_cursor} loading={loading} onLoadMore={onLoadMore} />
    </div>
  );
}

function SectionHeading({ action, meta, title }: { action?: React.ReactNode; meta?: string; title: string }) {
  return <div className="dashboard-sales-section-heading"><h2>{title}</h2><div>{meta ? <span>{meta}</span> : null}{action}</div></div>;
}

function DeferredCsvButton() {
  return <button className="secondary-button" disabled type="button"><Download aria-hidden="true" size={16} />CSV（後続Taskで実装）</button>;
}

function EmptyData({ message }: { message: string }) {
  return <div className="dashboard-sales-empty" role="status"><strong>データがありません</strong><p>{message}</p></div>;
}

function CursorNotice({ cursor, loading, onLoadMore }: {
  cursor: string | null;
  loading: boolean;
  onLoadMore: () => void;
}) {
  return cursor ? (
    <button className="secondary-button" disabled={loading} onClick={onLoadMore} type="button">
      {loading ? "読み込み中" : "次の50件を表示"}
    </button>
  ) : null;
}

function PublicId({ value }: { value: string }) {
  return <code title={value}>{`${value.slice(0, 8)}…`}</code>;
}

function hasSales(summary: AdminDashboardSalesSummary): boolean {
  return summary.payment_count > 0 || summary.refund_count > 0 || summary.chargeback_count > 0;
}

function hasPoints(report: AdminDashboardMonthlyPoints): boolean {
  return report.days.length > 0 || report.summary.paid_consumed > 0 || report.summary.free_consumed > 0;
}

function formatDateTime(value: string): string {
  return tokyoDateTime.format(new Date(value));
}

function formatPoints(value: number): string {
  return `${number.format(value)} pt`;
}

function reversalLabel(value: string): string {
  if (value === "refund") return "返金";
  if (value === "chargeback") return "CB";
  return "CB取消";
}

function currentTokyoPeriod(): { date: string; month: string } {
  const parts = new Intl.DateTimeFormat("en-US", {
    day: "2-digit", month: "2-digit", timeZone: "Asia/Tokyo", year: "numeric",
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  const date = `${values.year}-${values.month}-${values.day}`;
  return { date, month: `${values.year}-${values.month}` };
}
