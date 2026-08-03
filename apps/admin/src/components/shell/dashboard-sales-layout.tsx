"use client";

import { AlertTriangle, Download, RotateCcw } from "lucide-react";
import { useState } from "react";

type SalesView =
  | "monthly-sales"
  | "daily-sales"
  | "monthly-points"
  | "daily-points"
  | "reversals";

type DashboardSalesState = "empty" | "error" | "loading";

const salesViews: { id: SalesView; label: string }[] = [
  { id: "monthly-sales", label: "月別売上" },
  { id: "daily-sales", label: "日別売上" },
  { id: "monthly-points", label: "月別ポイント消費" },
  { id: "daily-points", label: "日別ポイント消費" },
  { id: "reversals", label: "返金/CB履歴" },
];

const weekdays = ["日", "月", "火", "水", "木", "金", "土"];

export function DashboardSalesLayout({
  onRetry,
  retryAfter,
  state = "empty",
}: {
  onRetry?: () => void;
  retryAfter?: number;
  state?: DashboardSalesState;
}) {
  const initialPeriod = currentTokyoPeriod();
  const [view, setView] = useState<SalesView>("monthly-sales");
  const [month, setMonth] = useState(initialPeriod.month);
  const [date, setDate] = useState(initialPeriod.date);
  const [rangeStart, setRangeStart] = useState(initialPeriod.date);
  const [rangeEnd, setRangeEnd] = useState(initialPeriod.date);

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
                if (nextIndex === null) {
                  return;
                }

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
      </section>

      {state === "loading" ? (
        <section className="dashboard-sales-state" aria-live="polite">
          <p>売上管理データの接続状態を確認しています。</p>
        </section>
      ) : state === "error" ? (
        <section className="dashboard-sales-state is-error" role="alert">
          <AlertTriangle aria-hidden="true" size={22} />
          <div>
            <h2>売上管理を表示できません</h2>
            <p>安全のため集計領域を表示していません。</p>
            {retryAfter ? <p>{retryAfter}秒後に再試行できます。</p> : null}
          </div>
          {onRetry ? (
            <button className="secondary-button" onClick={onRetry} type="button">
              <RotateCcw aria-hidden="true" size={17} />
              再試行
            </button>
          ) : null}
        </section>
      ) : (
        <DashboardSalesPanel date={date} view={view} />
      )}
    </div>
  );
}

function nextSalesViewIndex(key: string, currentIndex: number): number | null {
  if (key === "Home") {
    return 0;
  }
  if (key === "End") {
    return salesViews.length - 1;
  }
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
  view: SalesView;
}) {
  if (view === "monthly-sales" || view === "monthly-points") {
    return (
      <label className="dashboard-sales-period">
        <span>対象年月</span>
        <input
          onChange={(event) => onMonthChange(event.target.value)}
          type="month"
          value={month}
        />
      </label>
    );
  }

  if (view === "reversals") {
    return (
      <div className="dashboard-sales-period-range" role="group" aria-label="対象期間">
        <label className="dashboard-sales-period">
          <span>開始日</span>
          <input
            onChange={(event) => onRangeStartChange(event.target.value)}
            type="date"
            value={rangeStart}
          />
        </label>
        <label className="dashboard-sales-period">
          <span>終了日</span>
          <input
            onChange={(event) => onRangeEndChange(event.target.value)}
            type="date"
            value={rangeEnd}
          />
        </label>
        <button className="secondary-button" disabled type="button">
          検索（後続Taskで実装）
        </button>
      </div>
    );
  }

  return (
    <label className="dashboard-sales-period">
      <span>対象日</span>
      <input
        onChange={(event) => onDateChange(event.target.value)}
        type="date"
        value={date}
      />
    </label>
  );
}

function DashboardSalesPanel({ date, view }: { date: string; view: SalesView }) {
  return (
    <section
      aria-labelledby={`dashboard-sales-tab-${view}`}
      id={`dashboard-sales-panel-${view}`}
      role="tabpanel"
      tabIndex={0}
    >
      {view === "monthly-sales" ? <MonthlySalesPanel /> : null}
      {view === "daily-sales" ? <DailySalesPanel date={date} /> : null}
      {view === "monthly-points" ? <MonthlyPointsPanel /> : null}
      {view === "daily-points" ? <DailyPointsPanel date={date} /> : null}
      {view === "reversals" ? <ReversalsPanel /> : null}
    </section>
  );
}

function MonthlySalesPanel() {
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="月別売上Summary" />
      <SummaryGrid
        items={[
          ["総売上", "支払日基準"],
          ["返金額", "返金日基準"],
          ["CB額", "CB発生日基準"],
          ["純売上", "総売上-返金-CB"],
        ]}
      />
      <SectionHeading action={<DeferredCsvButton />} title="日別売上Calendar" />
      <EmptyCalendar message="月別売上の集計APIは未接続です。" />
    </div>
  );
}

function DailySalesPanel({ date }: { date: string }) {
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading meta={date} title="日別サマリー" />
      <SummaryGrid
        items={[
          ["総売上", "paid_at基準"],
          ["返金額", "refunded_at基準"],
          ["CB額", "chargeback_at基準"],
          ["純売上", "総売上-返金-CB"],
        ]}
      />
      <SectionHeading action={<DeferredCsvButton />} meta="paid_at基準" title="決済一覧" />
      <EmptyTable
        headers={["決済日時", "決済種別", "購入プラン", "決済金額", "状態", "ユーザー", "返金可否", "返金日", "CB日", "操作"]}
        message="日別決済の集計APIは未接続です。"
      />
      <SectionHeading
        action={<DeferredCsvButton />}
        meta="refunded_at / chargeback_at基準"
        title="返金・チャージバック一覧"
      />
      <EmptyTable
        headers={["発生日", "区分", "決済", "金額", "状態"]}
        message="返金・チャージバックの集計APIは未接続です。"
      />
    </div>
  );
}

function MonthlyPointsPanel() {
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="月別ポイント消費Summary" />
      <SummaryGrid
        items={[
          ["有償P消費", "pt"],
          ["無償P消費", "pt"],
        ]}
      />
      <SectionHeading action={<DeferredCsvButton />} title="日別ポイント消費Calendar" />
      <EmptyCalendar message="月別ポイント消費の集計APIは未接続です。" />
    </div>
  );
}

function DailyPointsPanel({ date }: { date: string }) {
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading action={<DeferredCsvButton />} meta={date} title="日別ポイント消費一覧" />
      <EmptyTable
        headers={["日時", "有償P", "無償P", "ユーザー", "ガチャ", "抽選回数", "状態", "詳細"]}
        message="日別ポイント消費の集計APIは未接続です。"
      />
    </div>
  );
}

function ReversalsPanel() {
  return (
    <div className="dashboard-sales-stack">
      <SectionHeading title="返金・チャージバック履歴" />
      <EmptyTable
        headers={["発生日", "区分", "決済", "金額", "状態", "詳細"]}
        message="返金・チャージバック履歴の集計APIは未接続です。"
      />
    </div>
  );
}

function SummaryGrid({ items }: { items: [string, string][] }) {
  return (
    <div className="dashboard-sales-summary-grid">
      {items.map(([label, caption]) => (
        <article className="dashboard-sales-summary-card" key={label}>
          <span>{label}</span>
          <strong>集計API未接続</strong>
          <small>{caption}</small>
        </article>
      ))}
    </div>
  );
}

function SectionHeading({
  action,
  meta,
  title,
}: {
  action?: React.ReactNode;
  meta?: string;
  title: string;
}) {
  return (
    <div className="dashboard-sales-section-heading">
      <h2>{title}</h2>
      <div>
        {meta ? <span>{meta}</span> : null}
        {action}
      </div>
    </div>
  );
}

function DeferredCsvButton() {
  return (
    <button className="secondary-button" disabled type="button">
      <Download aria-hidden="true" size={16} />
      CSV（後続Taskで実装）
    </button>
  );
}

function EmptyCalendar({ message }: { message: string }) {
  return (
    <div className="dashboard-sales-scroll-region" tabIndex={0}>
      <table className="dashboard-sales-calendar">
        <thead>
          <tr>
            {weekdays.map((weekday) => <th key={weekday} scope="col">{weekday}</th>)}
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colSpan={7}>
              <EmptyData message={message} />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function EmptyTable({ headers, message }: { headers: string[]; message: string }) {
  return (
    <div className="dashboard-sales-scroll-region" tabIndex={0}>
      <table className="dashboard-sales-table">
        <thead>
          <tr>
            {headers.map((header) => <th key={header} scope="col">{header}</th>)}
          </tr>
        </thead>
        <tbody>
          <tr>
            <td colSpan={headers.length}>
              <EmptyData message={message} />
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function EmptyData({ message }: { message: string }) {
  return (
    <div className="dashboard-sales-empty" role="status">
      <strong>集計API未接続</strong>
      <p>{message}</p>
      <small>実Dataの表示は後続Taskで実装します。</small>
    </div>
  );
}

function currentTokyoPeriod(): { date: string; month: string } {
  const parts = new Intl.DateTimeFormat("en-US", {
    day: "2-digit",
    month: "2-digit",
    timeZone: "Asia/Tokyo",
    year: "numeric",
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  const date = `${values.year}-${values.month}-${values.day}`;
  return { date, month: `${values.year}-${values.month}` };
}
