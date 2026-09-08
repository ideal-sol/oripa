"use client";

import { useEffect, useState, type FormEvent } from "react";
import { AdminPageHeader } from "../shell/admin-page-header";
import { jpy } from "../../lib/format/jpy";

type AggregateRow = { advertising_code: string; company_name?: string } & (
  { temporary_users: number; full_users: number } |
  { temporary_paying_users: number; temporary_amount: number; full_paying_users: number; full_amount: number }
);
export type AggregateResult = {
  items: AggregateRow[];
  period: { start_date: string; end_date: string; timezone: "Asia/Tokyo" };
  next_cursor: string | null;
};

export function AgencyAggregateTable({ kind, admin = false, load }: {
  kind: "users" | "sales";
  admin?: boolean;
  load: (query: Record<string, string>, signal?: AbortSignal) => Promise<AggregateResult>;
}) {
  const [query, setQuery] = useState<Record<string, string>>({});
  const [draft, setDraft] = useState<{ start: string; end: string } | null>(null);
  const [result, setResult] = useState<AggregateResult | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    load(query, controller.signal).then(response => {
      if (!controller.signal.aborted) { setResult(response); setError(""); }
    }).catch((failure: unknown) => {
      if (!controller.signal.aborted) {
        const status = typeof failure === "object" && failure !== null && "status" in failure ? failure.status : null;
        setError(status === 401 ? "セッションの有効期限が切れました。もう一度ログインしてください。"
          : status === 403 ? "この集計を閲覧する権限がありません。"
            : status === 422 ? "期間の入力内容を確認してください。"
              : "集計を取得できませんでした。再度お試しください。");
      }
    }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [load, query]);

  const dates = draft ?? { start: result?.period.start_date ?? "", end: result?.period.end_date ?? "" };
  function apply(next: Record<string, string>) { setLoading(true); setError(""); setQuery(next); }
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!dates.start || !dates.end || dates.start > dates.end) { setError("開始日は終了日以前の日付を指定してください。"); return; }
    apply({ start_date: dates.start, end_date: dates.end });
  }

  return <section className="workspace">
    <AdminPageHeader eyebrow="AGENCY" title={kind === "users" ? "本登録・仮登録ユーザー集計" : "広告コード別売上集計"} />
    <form aria-label="集計期間" className="dashboard-sales-controls" onSubmit={submit}>
      <label className="dashboard-sales-period"><span>開始日</span><input type="date" required value={dates.start} onChange={event => setDraft({ ...dates, start: event.target.value })} /></label>
      <label className="dashboard-sales-period"><span>終了日</span><input type="date" required value={dates.end} onChange={event => setDraft({ ...dates, end: event.target.value })} /></label>
      <button className="primary-button" disabled={loading} type="submit">適用</button>
      <button className="secondary-button" disabled={loading} type="button" onClick={() => { setDraft(null); apply({}); }}>当月</button>
    </form>
    <p>期間は日本時間（終了日を含む）です。{kind === "users" ? "登録日で集計します。" : "決済成功日で集計します。確定返金は元の決済期間から差し引きます。"}認証区分は現在の状態です。</p>
    {loading ? <p role="status">集計を読み込んでいます。</p> : null}
    {error ? <p className="error-alert" role="alert">{error}<button className="secondary-button" type="button" onClick={() => apply({ ...query })}>再読み込み</button></p> : null}
    {!loading && !error && result ? <>
      <p role="status">対象期間：{result.period.start_date} ～ {result.period.end_date}</p>
      {result.items.length === 0 ? <p>表示できる広告コードはありません。</p> : <div className="dashboard-sales-scroll-region" role="region" aria-label="集計結果" tabIndex={0}><table className="dashboard-sales-table">
        <thead><tr>{admin ? <th>代理店名</th> : null}<th>広告コード</th>
          {kind === "users" ? <><th>仮登録ユーザー人数</th><th>本登録ユーザー人数</th></>
            : <><th>仮登録ユーザーの課金人数</th><th>仮登録ユーザーの総課金額</th><th>本登録ユーザーの課金人数</th><th>本登録ユーザーの総課金額</th></>}
        </tr></thead><tbody>{result.items.map(row => <tr key={row.advertising_code}>
          {admin ? <td>{row.company_name}</td> : null}<td>{row.advertising_code}</td>
          {"temporary_users" in row ? <><td>{row.temporary_users.toLocaleString("ja-JP")}人</td><td>{row.full_users.toLocaleString("ja-JP")}人</td></>
            : <><td>{row.temporary_paying_users.toLocaleString("ja-JP")}人</td><td>{jpy.format(row.temporary_amount)}</td><td>{row.full_paying_users.toLocaleString("ja-JP")}人</td><td>{jpy.format(row.full_amount)}</td></>}
        </tr>)}</tbody>
      </table></div>}
      <div className="catalog-dialog-actions">
        {query.cursor ? <button className="secondary-button" type="button" onClick={() => { const first = { ...query }; delete first.cursor; apply(first); }}>先頭へ</button> : null}
        {result.next_cursor ? <button className="secondary-button" type="button" onClick={() => apply({ start_date: result.period.start_date, end_date: result.period.end_date, cursor: result.next_cursor! })}>次のページ</button> : null}
      </div>
    </> : null}
  </section>;
}
