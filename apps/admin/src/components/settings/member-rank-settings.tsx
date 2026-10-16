"use client";

import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminMemberRankSettings, AdminMemberRankSettingsHistory, AdminMemberRankSettingsVersion, AdminMemberRankValue } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";
import styles from "./member-rank-settings.module.css";

type Values = Pick<AdminMemberRankSettingsVersion, "ranks" | "default_skip_count">;
const names: Record<AdminMemberRankValue["code"], string> = {
  beginner: "ビギナー", bronze: "ブロンズ", silver: "シルバー", gold: "ゴールド",
  platinum: "プラチナ", diamond: "ダイヤモンド", vip: "VIP",
};
const fields = [
  ["reach_line", "到達ライン"], ["maintain_line", "維持ライン"],
  ["promotion_bonus", "昇格ボーナス（無償）"], ["purchase_bonus_rate_bp", "購入ボーナス率（無償）"],
] as const;
type Field = (typeof fields)[number][0];

export function MemberRankSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { permissions } = usePermissions();
  const canManage = permissions.has("member_rank.settings.manage");
  const [setting, setSetting] = useState<AdminMemberRankSettings | null>(null);
  const [draft, setDraft] = useState<Values | null>(null);
  const [rates, setRates] = useState<string[]>([]);
  const [history, setHistory] = useState<AdminMemberRankSettingsHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [historyError, setHistoryError] = useState(false);
  const [busy, setBusy] = useState(false);
  const [historyBusy, setHistoryBusy] = useState(false);
  const [confirmation, setConfirmation] = useState<"save" | "cancel" | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const pending = useRef<{ signature: string; key: string } | null>(null);
  const confirmationHeading = useRef<HTMLHeadingElement>(null);

  const apply = useCallback((next: AdminMemberRankSettings) => {
    setSetting(next);
    const source = next.scheduled ?? next.current;
    setDraft({ default_skip_count: source.default_skip_count, ranks: source.ranks.map((rank) => ({ ...rank })) });
    setRates(source.ranks.map((rank) => percent(rank.purchase_bonus_rate_bp)));
    setConfirmation(null);
    pending.current = null;
  }, []);
  const loadHistory = useCallback(async (before?: number, signal?: AbortSignal) => {
    setHistoryBusy(true);
    setHistoryError(false);
    try {
      const result = await client.getMemberRankSettingsHistory(before, signal);
      if (!signal?.aborted) setHistory((previous) => before && previous ? { ...result, items: [...previous.items, ...result.items] } : result);
    } catch {
      if (!signal?.aborted) setHistoryError(true);
    } finally {
      if (!signal?.aborted) setHistoryBusy(false);
    }
  }, [client]);
  const load = useCallback(async (signal?: AbortSignal) => {
    setBusy(true);
    setError(null);
    try {
      const result = await client.getMemberRankSettings(signal);
      if (!signal?.aborted) apply(result.data);
    } catch (caught) {
      if (!signal?.aborted) setError(message(caught));
    } finally {
      if (!signal?.aborted) setBusy(false);
    }
  }, [apply, client]);
  useEffect(() => {
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) {
        void load(controller.signal);
        void loadHistory(undefined, controller.signal);
      }
    });
    return () => controller.abort();
  }, [load, loadHistory]);
  useEffect(() => {
    if (confirmation) confirmationHeading.current?.focus();
  }, [confirmation]);

  const invalid = draft !== null && (
    !Number.isInteger(draft.default_skip_count) || draft.default_skip_count < 0 || draft.default_skip_count > 12
    || draft.ranks.some((rank, index) => index > 0 && (
      !Number.isSafeInteger(rank.reach_line) || rank.reach_line! < 1
      || !Number.isSafeInteger(rank.maintain_line) || rank.maintain_line! < 0 || rank.maintain_line! > rank.reach_line!
      || (index > 1 && (rank.reach_line! <= draft.ranks[index - 1].reach_line! || rank.maintain_line! < draft.ranks[index - 1].maintain_line!))
      || !Number.isInteger(rank.promotion_bonus) || rank.promotion_bonus < 0 || rank.promotion_bonus > 1_000_000
      || !Number.isInteger(rank.purchase_bonus_rate_bp) || rank.purchase_bonus_rate_bp < 0 || rank.purchase_bonus_rate_bp > 10000
    ))
  );
  const changed = setting && draft ? differences(setting.scheduled ?? setting.current, draft).length > 0 : false;
  function edit(index: number, field: Field, value: string) {
    if (!draft) return;
    let amount = value.trim() === "" ? Number.NaN : Number(value);
    if (field === "purchase_bonus_rate_bp") {
      setRates((previous) => previous.map((rate, rankIndex) => rankIndex === index ? value : rate));
      const match = /^(\d+)(?:\.(\d{1,2}))?$/u.exec(value);
      amount = match ? Number(match[1]) * 100 + Number((match[2] ?? "").padEnd(2, "0")) : Number.NaN;
    }
    setDraft({ ...draft, ranks: draft.ranks.map((rank, rankIndex) => rankIndex === index ? { ...rank, [field]: amount } : rank) });
    pending.current = null;
  }
  function confirm(event: FormEvent) {
    event.preventDefault();
    if (canManage && !invalid && changed && !busy) setConfirmation("save");
  }
  async function persist() {
    if (!setting || !draft || !confirmation || busy || !canManage) return;
    const action = confirmation;
    const body = { expected_revision: setting.revision, enabled: setting.enabled, ...draft };
    const signature = JSON.stringify({ action, body });
    if (pending.current?.signature !== signature) pending.current = { signature, key: crypto.randomUUID() };
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const result = action === "save"
        ? await client.updateMemberRankSettings(body, pending.current.key)
        : await client.cancelMemberRankSettings(setting.revision, pending.current.key);
      apply(result.data);
      setNotice(action === "save" ? "翌月の設定を予約しました。" : "翌月の予約を取り消しました。");
      await loadHistory();
    } catch (caught) {
      setError(message(caught));
      if (caught instanceof AdminApiError && caught.status > 0 && caught.status < 500) pending.current = null;
    } finally {
      setBusy(false);
    }
  }

  return <AdminShell><ProtectedAdminRoute permission="member_rank.settings.read"><div className="workspace">
    <Breadcrumb item={navigationItem("member-rank-settings")} />
    <AdminPageHeader eyebrow="Member Rank" title="会員ランク設定" />
    <div className={styles.panel}>
      {error ? <div role="alert" className="notice notice-error">{error}<button type="button" disabled={busy} onClick={() => void load()}>最新の設定を再読み込み</button></div> : null}
      {notice ? <p role="status" className="notice notice-success">{notice}</p> : null}
      {!setting || !draft ? <p role="status">{busy ? "設定を読み込んでいます" : "設定を取得できませんでした。"}</p> : <>
        <section className={styles.section}>
          <h2>{setting.enabled ? "運用開始済み" : "準備中・未開始"}</h2>
          <p>{setting.enabled ? "運用開始後の停止・無効化はできません。" : "全機能の実装とHuman承認が完了するまで有効化できません。"}</p>
          {setting.activated_at ? <p>開始日時：{formatDate(setting.activated_at)}</p> : null}
          <p>昇格ボーナス・購入ボーナスは無償ポイントです。</p>
          <h2>現在の設定（版 {setting.current.revision}）</h2>
          <RankTable ranks={setting.current.ranks} />
          <p>降格判定の見送り既定：{setting.current.default_skip_count}回</p>
        </section>
        <form className={styles.section} onSubmit={confirm}>
          <h2>{setting.scheduled ? "翌月の予約設定を編集" : "翌月の設定を予約"}</h2>
          <p>適用日時：{formatDate(setting.next_effective_at)}（日本時間）</p>
          <p>{setting.scheduled ? "予約あり。同じ月の保存は最後の内容が翌月から適用されます。" : "予約はありません。"}</p>
          <div className={styles.scroll}><table className={styles.table}><thead><tr><th>会員ランク</th>{fields.map(([field, label]) => <th key={field}>{label}</th>)}</tr></thead><tbody>
            {draft.ranks.map((rank, index) => <tr key={rank.code}><th scope="row">{names[rank.code]}</th>{fields.map(([field, label]) => <td key={field}>
              {index === 0 ? display(rank[field], field) : <input
                aria-label={names[rank.code] + " " + label}
                type="number" required step={field === "purchase_bonus_rate_bp" ? "0.01" : "1"}
                min={field === "reach_line" ? 1 : 0}
                max={field === "purchase_bonus_rate_bp" ? 100 : field === "promotion_bonus" ? 1000000 : Number.MAX_SAFE_INTEGER}
                disabled={!canManage || busy || confirmation !== null}
                value={field === "purchase_bonus_rate_bp" ? rates[index] : !Number.isFinite(rank[field]) ? "" : rank[field]!}
                onChange={(event) => edit(index, field, event.target.value)} />}
            </td>)}</tr>)}
          </tbody></table></div>
          <label>降格判定の見送り既定（回） <input type="number" min={0} max={12} step={1} required
            disabled={!canManage || busy || confirmation !== null} value={Number.isFinite(draft.default_skip_count) ? draft.default_skip_count : ""}
            onChange={(event) => { setDraft({ ...draft, default_skip_count: event.target.value === "" ? Number.NaN : Number(event.target.value) }); pending.current = null; }} /></label>
          {invalid ? <p role="alert">到達ラインは昇順、維持ラインは非減少かつ到達ライン以下、ボーナスは0〜1,000,000、率は0〜100.00%、見送りは0〜12回で指定してください。</p> : null}
          {canManage && !confirmation ? <div className={styles.actions}>
            <button className="primary-button" type="submit" disabled={busy || invalid || !changed}>変更内容を確認</button>
            <button className="secondary-button" type="button" disabled={busy || !changed} onClick={() => apply(setting)}>編集を戻す</button>
            {setting.scheduled ? <button className="secondary-button" type="button" disabled={busy} onClick={() => setConfirmation("cancel")}>予約を取り消す</button> : null}
          </div> : null}
        </form>
        {confirmation ? <section aria-labelledby="member-rank-confirmation" className={styles.section}>
          <h2 id="member-rank-confirmation" tabIndex={-1} ref={confirmationHeading}>{confirmation === "save" ? "保存前の差分確認" : "予約取消の確認"}</h2>
          <p>適用日時：{formatDate(setting.next_effective_at)}。現在の設定は今月中変わりません。</p>
          {confirmation === "save" ? <>
            <h3>現在の設定 → 翌月に適用する設定</h3>
            <DifferenceList before={setting.current} after={draft} />
            {setting.scheduled ? <><h3>既存の予約 → 保存する予約</h3><DifferenceList before={setting.scheduled} after={draft} /></> : null}
          </> : <p>予約を取り消し、翌月も現在の設定を継続します。保存履歴は残ります。</p>}
          <div className={styles.actions}><button className="primary-button" type="button" disabled={busy || (confirmation === "save" && invalid)} onClick={() => void persist()}>{busy ? "処理中…" : confirmation === "save" ? "確認して予約を保存" : "確認して予約を取消"}</button>
            <button className="secondary-button" type="button" disabled={busy} onClick={() => setConfirmation(null)}>戻る</button></div>
        </section> : null}
      </>}
      <section className={styles.section}><h2>設定の変更履歴</h2>
        {historyError ? <p role="alert">履歴を取得できませんでした。<button type="button" disabled={historyBusy} onClick={() => void loadHistory()}>履歴を再読み込み</button></p> : null}
        {historyBusy ? <p role="status">履歴を読み込んでいます</p> : null}
        {history?.items.length === 0 ? <p>履歴はありません。</p> : null}
        {history?.items.map(({ before, after }) => <details className={styles.history} key={after.revision}><summary>版 {after.revision} · {after.action === "cancel" ? "予約取消" : after.action === "initial" ? "初期設定" : "予約保存"} · {formatDate(after.saved_at)}</summary>
          <p>変更者：{after.saved_by ?? "システム"}／適用日時：{formatDate(after.effective_at)}</p>
          {before ? <DifferenceList before={before} after={after} /> : <RankTable ranks={after.ranks} />}
        </details>)}
        {history?.next_before_revision ? <button type="button" disabled={historyBusy} onClick={() => void loadHistory(history.next_before_revision!)}>以前の履歴を読み込む</button> : null}
      </section>
    </div>
  </div></ProtectedAdminRoute></AdminShell>;
}

function RankTable({ ranks }: { ranks: AdminMemberRankValue[] }) {
  return <div className={styles.scroll}><table className={styles.table}><thead><tr><th>会員ランク</th>{fields.map(([field, label]) => <th key={field}>{label}</th>)}</tr></thead><tbody>{ranks.map((rank) => <tr key={rank.code}><th scope="row">{names[rank.code]}</th>{fields.map(([field]) => <td key={field}>{display(rank[field], field)}</td>)}</tr>)}</tbody></table></div>;
}

function differences(before: Values, after: Values): string[] {
  const result: string[] = [];
  for (const rank of after.ranks) {
    const previous = before.ranks.find((candidate) => candidate.code === rank.code);
    if (!previous) continue;
    for (const [field, label] of fields) {
      if (previous[field] !== rank[field]) result.push(names[rank.code] + " " + label + "：" + display(previous[field], field) + " → " + display(rank[field], field));
    }
  }
  if (before.default_skip_count !== after.default_skip_count) result.push("降格判定の見送り既定：" + before.default_skip_count + " → " + after.default_skip_count + "回");
  return result;
}

function DifferenceList({ before, after }: { before: Values; after: Values }) {
  const items = differences(before, after);
  return items.length ? <ul className={styles.diff}>{items.map((item) => <li key={item}>{item}</li>)}</ul> : <p>数値の変更はありません。</p>;
}

function percent(value: number) {
  return Math.trunc(value / 100) + "." + String(value % 100).padStart(2, "0");
}

function display(value: number | null, field: Field) {
  return value === null ? "—" : field === "purchase_bonus_rate_bp" ? percent(value) + "%" : value.toLocaleString("ja-JP");
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}

function message(caught: unknown) {
  if (caught instanceof AdminApiError) {
    if (caught.status === 401) return "ログインが必要です。";
    if (caught.status === 403) return "この操作を行う権限がありません。";
    if (caught.status === 409) return "設定が更新されたか、予約を取り消せません。最新の設定を再読み込みしてください。";
    if (caught.status === 422) return "入力値を確認してください。";
  }
  return "通信に失敗しました。同じ操作を再試行できます。";
}
