"use client";

import { useId, useMemo, useRef, useState } from "react";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminPrizeImportInput, AdminPrizeImportPlan, AdminPrizeImportHistory, AdminPrizeImportValidation } from "@/lib/admin-api/generated";
import styles from "./catalog-prize-import.module.css";

export function CatalogPrizeImport({ gachaId, versionId, revision, canManage, enabled, onApplied }: {
  gachaId: string; versionId: string; revision: number; canManage: boolean; enabled: boolean; onApplied: () => Promise<void>;
}) {
  const client = useMemo(() => new AdminApiClient(), []);
  const confirmationId = useId();
  const dialog = useRef<HTMLDialogElement>(null);
  const selection = useRef(0);
  const [input, setInput] = useState<AdminPrizeImportInput | null>(null);
  const [plan, setPlan] = useState<AdminPrizeImportPlan | null>(null);
  const [planRevision, setPlanRevision] = useState(0);
  const [key, setKey] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validation, setValidation] = useState<AdminPrizeImportValidation | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [history, setHistory] = useState<AdminPrizeImportHistory | null>(null);
  const currentPlan = planRevision === revision ? plan : null;

  function failure(cause: unknown) {
    setValidation(cause instanceof AdminApiError ? cause.csvValidation : null);
    setError(cause instanceof AdminApiError && cause.code === "CATALOG_PRIZE_IMPORT_PLAN_STALE"
      ? "取込対象が変更されました。もう一度プレビューしてください。"
      : cause instanceof Error ? cause.message : "CSVの処理に失敗しました。");
    if (cause instanceof AdminApiError && cause.status === 409) setPlan(null);
  }

  async function select(file?: File) {
    const serial = ++selection.current;
    setInput(null); setPlan(null); setValidation(null); setError(null); setSuccess(null);
    if (!file) return;
    if (file.size > 1048576) { setError("CSVは1MiB以下で指定してください。"); return; }
    setBusy(true);
    try {
      const bytes = new Uint8Array(await file.arrayBuffer());
      let binary = "";
      for (const byte of bytes) binary += String.fromCharCode(byte);
      if (serial === selection.current) setInput({ file_name: file.name, content_base64: btoa(binary), expected_version_revision: revision });
    } catch (cause) { failure(cause); }
    finally { if (serial === selection.current) setBusy(false); }
  }

  async function preview() {
    if (!input) return;
    setBusy(true); setError(null); setValidation(null); setPlan(null); setSuccess(null);
    try {
      const result = await client.previewPrizeImport(gachaId, versionId, { ...input, expected_version_revision: revision });
      setPlan(result); setPlanRevision(revision); setKey(crypto.randomUUID());
    } catch (cause) { failure(cause); }
    finally { setBusy(false); }
  }

  async function apply() {
    if (!input || !currentPlan || !enabled) return;
    setBusy(true); setError(null);
    try {
      const result = await client.applyPrizeImport(gachaId, versionId, { ...input, expected_version_revision: revision, plan_checksum: currentPlan.plan_checksum }, key);
      const summary = result.data.summary;
      setSuccess(`取込完了：追加 ${summary.create}件・更新 ${summary.update}件・変更なし ${summary.unchanged}件`);
      setPlan(null); dialog.current?.close();
      await onApplied();
      setHistory(await client.listPrizeImports(gachaId, versionId));
    } catch (cause) { failure(cause); dialog.current?.close(); }
    finally { setBusy(false); }
  }

  async function loadHistory(before?: string) {
    setBusy(true); setError(null);
    try {
      const result = await client.listPrizeImports(gachaId, versionId, before);
      setHistory(before && history ? { ...result, items: [...history.items, ...result.items] } : result);
    } catch (cause) { failure(cause); }
    finally { setBusy(false); }
  }

  if (!canManage) return null;

  return <div className={styles.root}>
    {enabled ? <details>
      <summary>CSVで取り込む</summary>
      <p>管理IDで追加・差分更新します。既存景品を省略したCSVは取り込めません。</p>
      <label>景品CSV（UTF-8・1MiB／1,000行まで）<input accept=".csv,text/csv" disabled={busy} onChange={(event) => void select(event.target.files?.[0])} type="file" /></label>
      <button className="secondary-button" disabled={busy || !input} onClick={() => void preview()} type="button">プレビュー</button>
    </details> : null}
    {busy ? <p role="status">処理中です…</p> : null}
    {error ? <p role="alert">{error}</p> : null}
    {validation ? <div role="alert"><p>エラー {validation.error_count}件（先頭200件まで表示）</p><ul>{validation.errors.map((item, index) => <li key={index}>{item.row ? `${item.row}行目 ` : ""}{item.column}：{item.message}{item.items?.map((missing, itemIndex) => <p key={itemIndex}>{missing.external_id ?? "管理IDなし"} / {missing.name} / {missing.rank} / {missing.quantity}枚</p>)}</li>)}</ul></div> : null}
    {success ? <p role="status">{success}</p> : null}
    {enabled && currentPlan ? <>
      <p>追加 {currentPlan.summary.create}件・更新 {currentPlan.summary.update}件・変更なし {currentPlan.summary.unchanged}件</p>
      <ul>{currentPlan.warnings.map((warning, index) => <li key={index}>{warning.row}行目：{warning.message}</li>)}</ul>
      <div className={styles.table}><table><thead><tr><th>行</th><th>管理ID</th><th>処理</th><th>変更前 → 変更後</th></tr></thead><tbody>{currentPlan.rows.map((row) => <tr key={row.row}><td>{row.row}</td><td>{row.external_id}</td><td>{{ create: "追加", update: "更新", unchanged: "変更なし" }[row.action]}</td><td>{row.changes.map((change) => <p key={change.field}>{fieldLabel(change.field)}：{display(change.before)} → {display(change.after)}</p>)}</td></tr>)}</tbody></table></div>
      <button className="primary-button" disabled={busy} onClick={() => dialog.current?.showModal()} type="button">取込を確認</button>
    </> : null}
    <dialog aria-labelledby={confirmationId} className={styles.dialog} onCancel={(event) => { if (busy) event.preventDefault(); }} ref={dialog}>
      <h3 id={confirmationId}>CSVの差分を適用しますか</h3><p>確認した景品をまとめて更新します。</p>
      <button className="secondary-button" disabled={busy} onClick={() => dialog.current?.close()} type="button">キャンセル</button>
      <button className="primary-button" disabled={busy || !currentPlan} onClick={() => void apply()} type="button">取り込む</button>
    </dialog>
    <button className="secondary-button" disabled={busy} onClick={() => void loadHistory()} type="button">取込履歴</button>
    {history ? <section aria-label="CSV取込履歴"><h3>取込履歴</h3>{history.items.length === 0 ? <p>取込履歴はありません。</p> : <ul>{history.items.map((item) => <li key={item.id}><p>{new Date(item.occurred_at).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" })} / 実行者 {item.actor_public_id}</p><p>{item.file_name}：追加 {item.summary.create}件・更新 {item.summary.update}件・変更なし {item.summary.unchanged}件</p></li>)}</ul>}{history.next_before ? <button disabled={busy} onClick={() => void loadHistory(history.next_before ?? undefined)} type="button">以前の履歴</button> : null}</section> : null}
  </div>;
}

function display(value: string | number | boolean | null) {
  return value === null ? "—" : typeof value === "boolean" ? (value ? "はい" : "いいえ") : String(value);
}

function fieldLabel(field: string) {
  return ({ quantity: "枚数", exchange_points: "交換ポイント", cost_price: "原価", shipping_only: "発送のみ", sort_order: "表示順", name: "景品名", image: "画像", rank: "ランク" } as Record<string, string>)[field] ?? field;
}
