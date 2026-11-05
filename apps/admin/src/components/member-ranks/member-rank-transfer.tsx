"use client";

import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminMemberRankCompensationHistory, AdminMemberRankTransferHistory, AdminMemberRankTransferWrite, AdminUserDetail } from "@/lib/admin-api/generated";
import { rankDate, rankNames } from "./member-rank-labels";
import styles from "./member-ranks.module.css";

export function TransferAllowance({ user, onRefresh }: { user: AdminUserDetail; onRefresh: (notice: string) => void }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const detail = user.member_rank_detail!;
  const value = detail.transfer;
  const { hasPermission } = usePermissions();
  const writable = detail.enabled && user.status === "active" && !detail.frozen_at && hasPermission("member_rank.user.manage");
  const [editing, setEditing] = useState(false);
  const [status, setStatus] = useState(value.status);
  const [level, setLevel] = useState(value.equivalent_level ?? 1);
  const [points, setPoints] = useState(String(value.points));
  const [reason, setReason] = useState("");
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [history, setHistory] = useState<AdminMemberRankTransferHistory | null>(null);
  const [historyError, setHistoryError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  const pending = useRef<{ signature: string; key: string } | null>(null);
  const request = useRef<AbortController | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    request.current = controller;
    void client.getMemberRankTransferHistory(user.id, undefined, controller.signal).then(result => {
      if (!controller.signal.aborted) { setHistory(result); setHistoryError(null); }
    }).catch((caught: unknown) => { if (!controller.signal.aborted) setHistoryError(message(caught)); });
    return () => controller.abort();
  }, [client, user.id, retry]);
  async function more() {
    const controller = request.current;
    if (!history?.next_cursor || busy || !controller || controller.signal.aborted) return;
    setBusy(true);
    try {
      const result = await client.getMemberRankTransferHistory(user.id, history.next_cursor, controller.signal);
      if (!controller.signal.aborted) { setHistory({ ...result, items: [...history.items, ...result.items] }); setHistoryError(null); }
    } catch (caught) { if (!controller.signal.aborted) setHistoryError(message(caught)); }
    finally { setBusy(false); }
  }
  async function save(event: FormEvent) {
    event.preventDefault();
    if (busy || !writable) return;
    if (status === "transferred" && (points === "" || !Number.isSafeInteger(Number(points)) || Number(points) < 0)) {
      setError("移行ポイントは0以上の整数で入力してください。"); return;
    }
    if (!confirmed) { setConfirmed(true); return; }
    const body: AdminMemberRankTransferWrite = { status, equivalent_level: status === "none" ? null : level,
      points: status === "none" ? 0 : Number(points), expected_revision: value.revision, reason };
    const signature = JSON.stringify(body);
    if (pending.current?.signature !== signature) pending.current = { signature, key: crypto.randomUUID() };
    setBusy(true); setError(null);
    try {
      await client.updateMemberRankTransfer(user.id, body, pending.current.key);
      onRefresh("他サイト移行欄を更新しました。");
    } catch (caught) { setError(message(caught)); if (caught instanceof AdminApiError && caught.status === 409) setConfirmed(false); }
    finally { setBusy(false); }
  }
  const names = Object.values(rankNames);
  return <section className={styles.stack} aria-labelledby="rank-transfer-heading">
    <h3 id="rank-transfer-heading">他サイト移行</h3>
    <p>{value.status === "transferred" ? `${names[(value.equivalent_level ?? 1) - 1]}相当・${value.points.toLocaleString()}ポイント` : "移行なし"}</p>
    <p>累計の内訳：購入・調整 {detail.components.entries.toLocaleString()} / 移行 {detail.components.transfer.toLocaleString()} / 月次補填 {detail.components.compensation.toLocaleString()}</p>
    {writable && !editing ? <button className="secondary-button" type="button" onClick={() => setEditing(true)}>移行欄を編集</button> : null}
    {editing ? <form className={styles.form} onSubmit={event => void save(event)} onChange={() => { setConfirmed(false); pending.current = null; }}>
      <fieldset disabled={busy} className={styles.form}>
        <label>移行状態<select value={status} onChange={event => setStatus(event.target.value as typeof status)}><option value="none">移行なし</option><option value="transferred">他サイトから引き継ぎ</option></select></label>
        {status === "transferred" ? <>
          <label>相当ランク<select value={level} onChange={event => setLevel(Number(event.target.value))}>{names.map((name, index) => <option key={name} value={index + 1}>{name}</option>)}</select></label>
          <label>移行ポイント<input type="number" min="0" max={Number.MAX_SAFE_INTEGER} step="1" required value={points} onChange={event => setPoints(event.target.value)} /></label>
        </> : null}
        <label>変更理由<textarea required maxLength={500} value={reason} onChange={event => setReason(event.target.value)} /></label>
      </fieldset>
      <p>移行ポイントを置き換えます。コインは付与されません。条件を満たすと自動昇格しますが、自動降格はありません。引き継ぎ済みランク以下の昇格ボーナスは、移行なしに戻しても付与されません。</p>
      {confirmed ? <p role="status">{value.points.toLocaleString()} → {(status === "none" ? 0 : Number(points)).toLocaleString()}ポイント。入力した移行状態・相当ランク・理由で保存します。</p> : null}
      {error ? <p role="alert" className={styles.error}>{error}</p> : null}
      <div className={styles.actions}><button className="primary-button" type="submit" disabled={busy || !reason.trim()}>{confirmed ? "確認して保存" : "変更内容を確認"}</button><button className="secondary-button" type="button" disabled={busy} onClick={() => { setEditing(false); setConfirmed(false); }}>閉じる</button></div>
    </form> : null}
    <details><summary>移行欄の変更履歴</summary>
      {historyError ? <p role="alert">{historyError}<button className="secondary-button" type="button" onClick={() => setRetry(v => v + 1)}>再取得</button></p> : null}
      {!history && !historyError ? <p>履歴を取得中です。</p> : null}
      {history?.items.length === 0 ? <p>変更履歴はありません。</p> : null}
      {history?.items.map(item => <div key={item.id}><p>{rankDate(item.occurred_at)} / {item.actor_id ?? "システム"}</p>
        <p>{item.before.status === "none" ? "移行なし" : names[(item.before.equivalent_level ?? 1) - 1]} {item.before.points.toLocaleString()} → {item.after.status === "none" ? "移行なし" : names[(item.after.equivalent_level ?? 1) - 1]} {item.after.points.toLocaleString()}</p>
        <p className={styles.reason}>{item.reason}</p></div>)}
      {history?.next_cursor ? <button className="secondary-button" type="button" disabled={busy} onClick={() => void more()}>以前の履歴を表示</button> : null}
    </details>
    <CompensationHistory userId={user.id} />
  </section>;
}

function CompensationHistory({ userId }: { userId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [open, setOpen] = useState(false);
  const [cursor, setCursor] = useState<string | undefined>();
  const [history, setHistory] = useState<AdminMemberRankCompensationHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    void client.getMemberRankCompensationHistory(userId, cursor, controller.signal).then(result => {
      if (!controller.signal.aborted) { setHistory(result); setError(null); }
    }).catch((caught: unknown) => { if (!controller.signal.aborted) setError(message(caught)); });
    return () => controller.abort();
  }, [client, userId, cursor, open, retry]);
  return <details onToggle={event => setOpen(event.currentTarget.open)}><summary>月次補填・取消履歴</summary>
    {error ? <p role="alert">{error}<button className="secondary-button" type="button" onClick={() => setRetry(value => value + 1)}>再取得</button></p> : null}
    {open && !history && !error ? <p role="status">履歴を取得中です。</p> : null}
    {history?.items.length === 0 ? <p>月次補填・取消はありません。</p> : null}
    {history?.items.map(item => <p key={item.id}>{item.month.slice(0, 7)} ／ {item.delta > 0 ? "補填" : "取消"} {item.delta.toLocaleString()}ポイント ／ 補填残 {item.remaining.toLocaleString()}ポイント ／ 設定 {item.setting_revision} ／ {rankDate(item.occurred_at)}</p>)}
    {history?.next_cursor ? <button className="secondary-button" type="button" onClick={() => { setCursor(history.next_cursor ?? undefined); setHistory(null); }}>以前の補填を表示</button> : null}
    {cursor ? <button className="secondary-button" type="button" onClick={() => { setCursor(undefined); setHistory(null); }}>最新の補填へ戻る</button> : null}
  </details>;
}

function message(caught: unknown): string {
  if (caught instanceof AdminApiError && caught.status === 409) return "移行欄が更新されています。会員詳細を再取得してから編集してください。";
  return caught instanceof Error ? caught.message : "処理に失敗しました。再試行してください。";
}
