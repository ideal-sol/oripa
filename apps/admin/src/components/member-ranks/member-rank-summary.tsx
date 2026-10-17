"use client";

import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminMemberRankHistoryCollection, AdminMemberRankSummaryResponse } from "@/lib/admin-api/generated";
import { MemberRankHistory } from "./member-rank-history";
import { manualKinds, rankDate, rankName } from "./member-rank-labels";
import styles from "./member-ranks.module.css";

export function MemberRankSummary() {
  return <AdminShell><ProtectedAdminRoute permission="member_rank.settings.read"><Summary /></ProtectedAdminRoute></AdminShell>;
}

function Summary() {
  const client = useMemo(() => new AdminApiClient(), []);
  const [summary, setSummary] = useState<AdminMemberRankSummaryResponse["data"] | null>(null);
  const [history, setHistory] = useState<AdminMemberRankHistoryCollection | null>(null);
  const [filters, setFilters] = useState({ kind: "", date_from: "", date_to: "" });
  const [applied, setApplied] = useState(filters);
  const [error, setError] = useState<string | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState(0);
  const historyRequest = useRef<AbortController | null>(null);
  const load = useCallback(async (signal?: AbortSignal) => {
    try {
      const result = await client.getMemberRankSummary(signal);
      if (!signal?.aborted) { setSummary(result.data); setError(null); }
    } catch (caught) { if (!signal?.aborted) setError(message(caught)); }
  }, [client]);
  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => { if (!controller.signal.aborted) void load(controller.signal); });
    return () => controller.abort();
  }, [load, retry]);
  useEffect(() => {
    const controller = new AbortController();
    historyRequest.current = controller;
    void client.listMemberRankManualChanges(applied, controller.signal).then((result) => {
      if (!controller.signal.aborted) { setHistory(result); setHistoryError(null); }
    }).catch((caught: unknown) => { if (!controller.signal.aborted) setHistoryError(message(caught)); });
    return () => controller.abort();
  }, [client, applied, retry]);
  async function more() {
    const controller = historyRequest.current;
    if (!history?.next_cursor || busy || !controller || controller.signal.aborted) return;
    setBusy(true);
    try {
      const next = await client.listMemberRankManualChanges({ ...applied, cursor: history.next_cursor }, controller.signal);
      if (!controller.signal.aborted) { setHistory({ ...next, items: [...history.items, ...next.items] }); setHistoryError(null); }
    } catch (caught) { if (!controller.signal.aborted) setHistoryError(message(caught)); } finally { setBusy(false); }
  }
  function search(event: FormEvent) {
    event.preventDefault();
    if (filters.date_from && filters.date_to && filters.date_from > filters.date_to) { setHistoryError("開始日は終了日以前にしてください。"); return; }
    historyRequest.current?.abort(); setHistory(null); setHistoryError(null); setApplied({ ...filters });
  }
  return <section className={`workspace ${styles.stack}`}>
    <AdminPageHeader eyebrow="Users" title="会員ランク" description="会員数・特典費用・手動変更と回収不足を確認します。" action={<button className="secondary-button" type="button" onClick={() => setRetry((value) => value + 1)}>再取得</button>} />
    {error ? <p role="alert" className={styles.error}>{error}</p> : null}
    {!summary && !error ? <p>集計を取得中です。</p> : null}
    {summary ? <>
      {!summary.enabled ? <p className={styles.warning}>会員ランク機能は運用開始前です。</p> : null}
      {summary.progress.provisional ? <p className={styles.warning} role="status">月次処理中のため暫定集計です。未処理の会員も月初判定後のランクで表示しています。未処理 {summary.progress.remaining.toLocaleString()}人</p> : null}
      <div className={styles.panel}><h2>有効会員のランク別人数</h2><dl className={styles.grid}>
        {summary.ranks.map((item) => <div key={item.rank}><dt>{rankName(item.rank)}</dt><dd>{item.count.toLocaleString()}人</dd></div>)}
        <div><dt>有効会員合計</dt><dd>{summary.active_total.toLocaleString()}人</dd></div>
      </dl></div>
      <div className={styles.panel}><h2>{summary.month.slice(0, 7)} 特典費用（無償）</h2><dl className={styles.grid}>
        <div><dt>昇格ボーナス</dt><dd>{summary.promotion_bonus.count.toLocaleString()}件 / {summary.promotion_bonus.amount.toLocaleString()}pt</dd></div>
        <div><dt>購入ボーナス</dt><dd>{summary.purchase_bonus.count.toLocaleString()}件 / {summary.purchase_bonus.amount.toLocaleString()}pt</dd></div>
      </dl></div>
      <div className={styles.panel}><h2>月次処理の状態</h2><p>最終完了月：{summary.progress.last_completed_month?.slice(0, 7) ?? "完了記録なし"}</p>
        <p>状態：{summary.progress.status ? ({ pending: "未処理", processing: "処理中", failed: "失敗", completed: "完了" }[summary.progress.status]) : "ジョブなし"} / 残り {summary.progress.remaining.toLocaleString()}人</p>
        {summary.progress.lease_expired ? <p className={styles.warning}>処理のlease期限切れです。</p> : null}
      </div>
      <div className={styles.panel}><h2>要確認 — 昇格ボーナスの回収不足</h2>
        {summary.reviews.length === 0 ? <p>要確認の回収不足はありません。</p> : <div className={styles.scroll} tabIndex={0}><table className={styles.table}>
          <thead><tr><th>日時</th><th>会員</th><th>ランク</th><th>回収額</th><th>不足額</th></tr></thead>
          <tbody>{summary.reviews.map((item) => <tr key={item.id}><td>{rankDate(item.occurred_at)}</td><td><Link href={`/users/${item.user_id}`}>{item.user_id}</Link></td><td>{rankName(item.rank)}</td><td>{item.recovered_amount.toLocaleString()}pt</td><td>{item.shortfall_amount.toLocaleString()}pt</td></tr>)}</tbody>
        </table></div>}
      </div>
    </> : null}
    <section className={`${styles.panel} ${styles.stack}`}><h2>全会員の手動変更履歴</h2>
      <form onSubmit={search} className={styles.form} aria-label="手動変更履歴の検索"><div className={styles.grid}>
        <label>種別<select value={filters.kind} onChange={(event) => setFilters({ ...filters, kind: event.target.value })}><option value="">すべて</option>{Object.entries(manualKinds).map(([code, label]) => <option key={code} value={code}>{label}</option>)}</select></label>
        <label>変更日（開始）<input type="date" value={filters.date_from} onChange={(event) => setFilters({ ...filters, date_from: event.target.value })} /></label>
        <label>変更日（終了）<input type="date" value={filters.date_to} onChange={(event) => setFilters({ ...filters, date_to: event.target.value })} /></label>
      </div><button className="secondary-button" type="submit">履歴を検索</button></form>
      {historyError ? <p className={styles.error} role="alert">{historyError}</p> : null}
      {history ? <MemberRankHistory items={history.items} showUser /> : <p>手動変更履歴を取得中です。</p>}
      {history?.next_cursor ? <button className="secondary-button" type="button" disabled={busy} onClick={() => void more()}>次の変更履歴を表示</button> : null}
    </section>
  </section>;
}

function message(error: unknown): string { return error instanceof Error ? error.message : "情報を取得できませんでした。再取得してください。"; }
