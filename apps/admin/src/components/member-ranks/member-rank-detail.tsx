"use client";

import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminMemberRankCode, AdminMemberRankHistoryCollection, AdminMemberRankManualKind, AdminMemberRankPreview, AdminUserDetail } from "@/lib/admin-api/generated";
import { MemberRankHistory } from "./member-rank-history";
import { manualKinds, rankDate, rankName, rankNames } from "./member-rank-labels";
import styles from "./member-ranks.module.css";

export function MemberRankDetail({ user, onRefresh }: { user: AdminUserDetail; onRefresh: (notice: string) => void }) {
  const detail = user.member_rank_detail;
  const { hasPermission } = usePermissions();
  const [operation, setOperation] = useState<"rank" | "points" | null>(null);
  if (!detail) return null;
  return <section aria-labelledby="member-rank-heading" className={`${styles.panel} ${styles.stack}`}>
    <div className={styles.header}><h2 id="member-rank-heading">会員ランク</h2>
      {detail.enabled && user.status === "active" && hasPermission("member_rank.user.manage") ? <div className={styles.actions}>
        <button className="secondary-button" onClick={() => setOperation("rank")} type="button">ランクを変更</button>
        <button className="secondary-button" onClick={() => setOperation("points")} type="button">ランクポイントを調整</button>
      </div> : <p>{detail.enabled ? "閲覧のみ" : "運用開始前"}</p>}
    </div>
    <dl className={styles.grid}>
      <div><dt>現在の会員ランク</dt><dd>{rankName(detail.rank)}</dd></div>
      <div><dt>{detail.month.slice(0, 7)} ランクポイント</dt><dd>{detail.rank_points.toLocaleString()}</dd></div>
      <div><dt>次の到達ラインまで</dt><dd>{detail.next_rank ? `${rankName(detail.next_rank.rank)}：あと${detail.next_rank.remaining.toLocaleString()}（到達ライン ${detail.next_rank.line.toLocaleString()}）` : "最高ランク"}</dd></div>
      <div><dt>維持ライン</dt><dd>{detail.maintain ? `${detail.maintain.line.toLocaleString()} / あと${detail.maintain.remaining.toLocaleString()}（対象前月 ${detail.maintain.month.slice(0, 7)}）` : "—"}</dd></div>
      {detail.skip_count > 0 ? <div><dt>降格判定の見送り</dt><dd>残り{detail.skip_count}回 / {rankDate(detail.guaranteed_until)}まで保証</dd></div> : null}
      {detail.transferred_at ? <div><dt>引き継ぎ適用済み</dt><dd>初回 {rankDate(detail.transferred_at)}<br />最終 {rankDate(detail.last_transfer?.occurred_at)}</dd></div> : null}
      {detail.frozen_at ? <div><dt>退会時点の記録</dt><dd>{rankDate(detail.frozen_at)}</dd></div> : null}
    </dl>
    <h3>到達・資格消化の履歴（昇格ボーナスは無償）</h3>
    {detail.achievements.length ? <div className={styles.scroll} tabIndex={0}><table className={styles.table}>
      <thead><tr><th>ランク・世代</th><th>日時</th><th>根拠</th><th>ボーナス</th><th>回収・資格</th></tr></thead>
      <tbody>{detail.achievements.map((item) => <tr key={item.id}>
        <td>{rankName(item.rank)} / {item.generation}</td><td>{rankDate(item.occurred_at)}</td>
        <td>{item.basis === "manual_rank" ? "手動ランク付与" : item.basis === "manual_rank_points" ? "ランクポイント手動調整" : "有償購入到達"}<p>{item.month.slice(0, 7)} / 設定版 {item.setting_revision}</p><p>{item.origin_id}</p></td>
        <td>{item.bonus_amount.toLocaleString()}pt（無償）</td>
        <td>{item.consumed ? "資格消化済み" : "資格取消済み"}{item.recovered_amount !== null ? <p>回収 {item.recovered_amount.toLocaleString()} / 不足 {item.shortfall_amount?.toLocaleString()}</p> : null}</td>
      </tr>)}</tbody>
    </table></div> : <p>到達記録はありません。</p>}
    <UserRankHistory key={`${user.id}-${detail.revision}`} userId={user.id} />
    {operation ? <RankOperation key={operation} operation={operation} user={user} onClose={() => setOperation(null)} onSaved={() => {
      setOperation(null); onRefresh("会員ランクの操作を保存し、最新情報を取得しました。");
    }} /> : null}
  </section>;
}

function UserRankHistory({ userId }: { userId: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const [kind, setKind] = useState<"rank" | "points">("rank");
  const [data, setData] = useState<AdminMemberRankHistoryCollection | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [retry, setRetry] = useState(0);
  const historyRequest = useRef<AbortController | null>(null);
  useEffect(() => {
    const controller = new AbortController();
    historyRequest.current = controller;
    void client.getMemberRankHistory(userId, kind, undefined, controller.signal).then((result) => {
      if (!controller.signal.aborted) { setData(result); setError(null); }
    }).catch((caught: unknown) => { if (!controller.signal.aborted) setError(message(caught)); });
    return () => controller.abort();
  }, [client, userId, kind, retry]);
  async function more() {
    const controller = historyRequest.current;
    if (!data?.next_cursor || busy || !controller || controller.signal.aborted) return;
    setBusy(true);
    try {
      const next = await client.getMemberRankHistory(userId, kind, data.next_cursor, controller.signal);
      if (!controller.signal.aborted) { setData({ ...next, items: [...data.items, ...next.items] }); setError(null); }
    } catch (caught) { if (!controller.signal.aborted) setError(message(caught)); } finally { setBusy(false); }
  }
  return <div className={styles.stack}>
    <h3>ランク・ポイント履歴</h3>
    <label>履歴の種類 <select value={kind} onChange={(event) => { historyRequest.current?.abort(); setData(null); setKind(event.target.value as typeof kind); }}>
      <option value="rank">ランクの変化</option><option value="points">ランクポイントの増減</option>
    </select></label>
    {error ? <p role="alert" className={styles.error}>{error} <button type="button" onClick={() => setRetry((value) => value + 1)}>再取得</button></p> : null}
    {data ? <MemberRankHistory items={data.items} points={kind === "points"} /> : <p>履歴を取得中です。</p>}
    {data?.next_cursor ? <button type="button" disabled={busy} onClick={() => void more()} className="secondary-button">次の履歴を表示</button> : null}
  </div>;
}

function RankOperation({ operation, user, onClose, onSaved }: { operation: "rank" | "points"; user: AdminUserDetail; onClose: () => void; onSaved: () => void }) {
  const detail = user.member_rank_detail!;
  const client = useMemo(() => new AdminApiClient(), []);
  const dialog = useRef<HTMLDialogElement>(null);
  const [rank, setRank] = useState<AdminMemberRankCode>(detail.rank);
  const [kind, setKind] = useState<AdminMemberRankManualKind>("support");
  const [reason, setReason] = useState("");
  const [skip, setSkip] = useState(String(detail.default_skip_count));
  const [grant, setGrant] = useState(false);
  const [delta, setDelta] = useState("");
  const [preview, setPreview] = useState<AdminMemberRankPreview | null>(null);
  const [confirmed, setConfirmed] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const pending = useRef<{ signature: string; key: string } | null>(null);
  const codes = Object.keys(rankNames) as AdminMemberRankCode[];
  const lowering = codes.indexOf(rank) < codes.indexOf(detail.rank);
  const input = operation === "rank" ? { rank, kind, reason, skip_count: Number(skip), grant_bonus: !lowering && grant } : { delta: Number(delta), reason };
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    dialog.current?.showModal();
    return () => previous?.focus();
  }, []);
  function changed() { setPreview(null); setConfirmed(false); setError(null); pending.current = null; }
  async function check(event: FormEvent) {
    event.preventDefault();
    if (busy) return;
    if ((operation === "points" && (!Number.isSafeInteger(Number(delta)) || Number(delta) === 0))
      || (operation === "rank" && (!Number.isInteger(Number(skip)) || skip === "" || Number(skip) < 0 || Number(skip) > 12))) {
      setError("増減は0以外の整数、見送りは0〜12の整数で入力してください。"); return;
    }
    setBusy(true); setError(null);
    try { setPreview((await client.previewMemberRank(user.id, operation, input)).data); }
    catch (caught) { setError(message(caught)); } finally { setBusy(false); }
  }
  async function save() {
    if (!preview || busy || (kind === "transfer" && preview.last_transfer && !confirmed)) return;
    const body = { ...input, expected_revision: preview.expected_revision, preview_token: preview.preview_token,
      ...(operation === "rank" && kind === "transfer" && preview.last_transfer && confirmed ? { confirmed_transfer_id: preview.last_transfer.id } : {}) };
    const signature = JSON.stringify(body);
    if (pending.current?.signature !== signature) pending.current = { signature, key: crypto.randomUUID() };
    setBusy(true); setError(null);
    try { await client.saveMemberRank(user.id, operation, body, pending.current.key); onSaved(); }
    catch (caught) {
      setError(message(caught));
      if (caught instanceof AdminApiError && [409, 422, 403].includes(caught.status)) { setPreview(null); pending.current = null; }
    } finally { setBusy(false); }
  }
  return <dialog ref={dialog} className={styles.dialog} aria-labelledby="rank-operation-title" onCancel={(event) => { event.preventDefault(); if (!busy) onClose(); }}>
    <div className={styles.header}><h2 id="rank-operation-title">{operation === "rank" ? "会員ランクを変更" : "ランクポイントを調整"}</h2>
      <button type="button" disabled={busy} onClick={onClose} aria-label="会員ランク操作を閉じる">閉じる</button></div>
    <p>{user.display_name ?? user.id} / 現在 {rankName(detail.rank)}</p>
    <form className={styles.form} onSubmit={(event) => void check(event)} onChange={changed}>
      <fieldset disabled={busy} className={styles.form}>
        {operation === "rank" ? <>
          <label>変更後のランク<select value={rank} onChange={(event) => setRank(event.target.value as AdminMemberRankCode)}>{codes.map((code) => <option key={code} value={code}>{rankNames[code]}</option>)}</select></label>
          <label>種別<select value={kind} onChange={(event) => setKind(event.target.value as AdminMemberRankManualKind)}>{Object.entries(manualKinds).map(([code, name]) => <option key={code} value={code}>{name}</option>)}</select></label>
          <label>降格判定の見送り回数<input type="number" min="0" max="12" step="1" required value={skip} onChange={(event) => setSkip(event.target.value)} /></label>
          {!lowering ? <label>昇格ボーナス（無償）<select value={String(grant)} onChange={(event) => setGrant(event.target.value === "true")}><option value="false">付与しない</option><option value="true">付与する</option></select></label> : null}
        </> : <label>ランクポイント増減<input aria-label="ランクポイント増減" aria-describedby="rank-points-help" type="number" step="1" required value={delta} onChange={(event) => setDelta(event.target.value)} /><small id="rank-points-help">当月のみ。コイン残高の直接調整とは別の操作です。</small></label>}
        <label>理由<textarea aria-label="理由" aria-describedby="rank-reason-help" required value={reason} onChange={(event) => setReason(event.target.value)} /><small id="rank-reason-help">1〜500文字</small></label>
      </fieldset>
      <button className="secondary-button" disabled={busy || !reason.trim() || Array.from(reason).length > 500} type="submit">{busy ? "処理中" : "変更内容を確認"}</button>
    </form>
    {error ? <p className={styles.error} role="alert">{error}</p> : null}
    {preview ? <div className={styles.stack} aria-label="会員ランク変更の確認差分">
      <h3>保存前の確認</h3>
      <p>{rankName(preview.before_rank)} → {rankName(preview.after_rank)}</p>
      <p>ランクポイント {preview.before_points.toLocaleString()} → {preview.after_points.toLocaleString()}</p>
      <p>見送り {preview.before_skip} → {preview.after_skip}回 / 設定版 {preview.setting_revision} / {preview.month.slice(0, 7)}</p>
      <p>{operation === "rank" ? manualKinds[kind] : "ランクポイント手動調整"}</p><p className={styles.reason}>{reason}</p>
      <p>昇格ボーナス合計（無償） {preview.total_bonus.toLocaleString()}pt / 有効期限180日</p>
      {preview.grants.map((effect) => <p key={effect.rank}>{rankName(effect.rank)}：{effect.amount.toLocaleString()}pt（無償）</p>)}
      {preview.excluded_ranks.length > 0 ? <p>資格消化済み・今回対象外：{preview.excluded_ranks.map(rankName).join("、")}</p> : null}
      {preview.recoveries.map((effect) => <p key={effect.rank} className={effect.shortfall_amount > 0 ? styles.warning : undefined}>
        {rankName(effect.rank)} 昇格ボーナス回収 {effect.recovered_amount.toLocaleString()}pt / 不足 {effect.shortfall_amount.toLocaleString()}pt（要確認）
      </p>)}
      {operation === "rank" && kind === "transfer" && preview.last_transfer ? <div className={styles.warning}>
        <p>引き継ぎは適用済みです。前回 {rankDate(preview.last_transfer.occurred_at)}：{rankName(preview.last_transfer.from_rank)} → {rankName(preview.last_transfer.to_rank)}</p>
        <p className={styles.reason}>{preview.last_transfer.reason_text}</p>
        <label className={styles.checkbox}><input type="checkbox" checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />前回の内容を確認し、再度引き継ぎを適用します</label>
      </div> : null}
      <div className={styles.actions}><button className="primary-button" type="button" disabled={busy || (operation === "rank" && kind === "transfer" && !!preview.last_transfer && !confirmed)} onClick={() => void save()}>確認して保存</button>
        <button className="secondary-button" type="button" disabled={busy} onClick={() => { setPreview(null); pending.current = null; }}>入力に戻る</button></div>
    </div> : null}
  </dialog>;
}

function message(error: unknown): string {
  if (error instanceof AdminApiError && error.status === 409) return "状態が変わりました。最新の差分を再確認してください。";
  if (error instanceof AdminApiError && error.status === 403) return "操作する権限がありません。";
  return error instanceof Error ? error.message : "処理に失敗しました。再試行してください。";
}
