"use client";

import { useEffect, useRef, useState, type FormEvent } from "react";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminBannerCategory, AdminBannerImportJob, AdminBannerImportValidation } from "@/lib/admin-api/generated";
import styles from "./banner-import.module.css";

export function BannerImportWorkspace({ categories, canManage }: { categories: AdminBannerCategory[]; canManage: boolean }) {
  const [enabled, setEnabled] = useState(false);
  const [jobs, setJobs] = useState<AdminBannerImportJob[]>([]);
  const [selected, setSelected] = useState<AdminBannerImportJob | null>(null);
  const [category, setCategory] = useState("");
  const [refresh, setRefresh] = useState(false);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [validation, setValidation] = useState<AdminBannerImportValidation | null>(null);
  const key = useRef<string | null>(null);
  const submitting = useRef(false);
  const fileInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().listBannerImports(controller.signal).then((result) => {
      setEnabled(result.enabled === true);
      setJobs(result.items);
    }).catch(() => { if (!controller.signal.aborted) setEnabled(false); });
    return () => controller.abort();
  }, []);

  useEffect(() => {
    if (!selected || !["queued", "processing"].includes(selected.status)) return;
    const controller = new AbortController();
    const timer = window.setTimeout(() => {
      new AdminApiClient().bannerImport(selected.id, controller.signal).then((job) => {
        setSelected(job);
        setJobs((current) => [job, ...current.filter((item) => item.id !== job.id)]);
      }).catch(() => { if (!controller.signal.aborted) setError("進捗の取得に失敗しました。履歴から再取得してください。"); });
    }, 2000);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [selected]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!enabled || !canManage || !file || !category || submitting.current) return;
    if (file.size > 2097152) { setError("CSVは2MiB以下で指定してください。"); return; }
    submitting.current = true;
    setBusy(true);
    setError(null);
    setValidation(null);
    key.current ??= crypto.randomUUID();
    try {
      const bytes = new Uint8Array(await file.arrayBuffer());
      let binary = "";
      for (const byte of bytes) binary += String.fromCharCode(byte);
      const job = await new AdminApiClient().createBannerImport({
        file_name: file.name, content_base64: btoa(binary), default_category_id: category, refresh_existing_images: refresh,
      }, key.current);
      setSelected(job);
      setJobs((current) => [job, ...current.filter((item) => item.id !== job.id)]);
      setFile(null);
      if (fileInput.current) fileInput.current.value = "";
      key.current = null;
    } catch (reason) {
      setError(reason instanceof AdminApiError ? reason.message : "CSVを読み込めませんでした。");
      if (reason instanceof AdminApiError) setValidation(reason.csvValidation);
    } finally {
      setBusy(false);
      submitting.current = false;
    }
  }

  async function history(id: string) {
    try { setSelected(await new AdminApiClient().bannerImport(id)); setError(null); }
    catch { setError("履歴を取得できませんでした。"); }
  }

  async function failures(id: string) {
    try {
      const blob = await new AdminApiClient().bannerImportFailures(id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = "banner-import-failures.csv";
      anchor.click();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch { setError("失敗行CSVを取得できませんでした。"); }
  }

  if (!enabled) return <p role="status">CSV取込は現在利用できません。</p>;
  return <section className={styles.workspace} aria-label="バナーCSV取込">
    <h2>バナーCSV取込</h2>
    <p>UTF-8・最大200行・2MiB。受付後にバックグラウンド処理します。新規バナーは下書き・トップ非表示として登録し、既存バナーを自動公開しません。画像URL自体のアクセス制限を意味するものではありません。</p>
    <p>画像URLの保持期限は30分です。大量登録やURL失効で一部失敗する場合があります。成功行は保持されます。失敗行CSVに新しい画像URLを入力して再取込できます。画像差し替えは登録済み景品の画像を自動変更しません。</p>
    {canManage ? <form onSubmit={submit}>
      <label>CSVファイル<input ref={fileInput} type="file" accept=".csv,text/csv" disabled={busy} onChange={(event) => { setFile(event.target.files?.[0] ?? null); key.current = null; }} /></label>
      <label>既定カテゴリ<select value={category} disabled={busy} onChange={(event) => { setCategory(event.target.value); key.current = null; }}><option value="">選択してください</option>{categories.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      <label><input type="checkbox" checked={refresh} disabled={busy} onChange={(event) => { setRefresh(event.target.checked); key.current = null; }} />既存画像を再取得する</label>
      <button type="submit" disabled={busy || !file || !category}>{busy ? "検証・受付中…" : "CSVを取り込む"}</button>
    </form> : <p>参照専用です。取込にはcontent.manage権限が必要です。</p>}
    {error ? <p role="alert">{error}</p> : null}
    {validation ? <div role="alert"><p>エラー{validation.error_count}件（最大200件表示）。登録は行われていません。</p><ul>{validation.errors.map((item, index) => <li key={index}>{item.row ?? "—"}行 / {item.column ?? "CSV"} / {item.code}</li>)}</ul></div> : null}
    {selected ? <div aria-live="polite"><p>{selected.status}：{selected.processed}/{selected.total}行</p><p>新規 {selected.created} / 更新 {selected.updated} / 変更なし {selected.unchanged} / 失敗 {selected.failed}</p>
      {selected.failed > 0 ? <><button type="button" onClick={() => void failures(selected.id)}>失敗行CSVを取得</button><p>画像URLは空欄です。新しい署名付きURLを取得・再入力して再取込してください。</p></> : null}
      <div className={styles.scroll}><table><thead><tr><th>行</th><th>管理ID</th><th>タイトル</th><th>結果</th><th>失敗理由</th></tr></thead><tbody>{selected.rows.map((row) => <tr key={row.row}><td>{row.row}</td><td>{row.external_id}</td><td>{row.title}</td><td>{row.result ?? row.status}</td><td>{row.error_code ?? "—"}</td></tr>)}</tbody></table></div>
    </div> : null}
    <h3>取込履歴（30日間・最新50件）</h3>
    {jobs.length === 0 ? <p>取込履歴はありません。</p> : <ul>{jobs.map((job) => <li key={job.id}><button type="button" onClick={() => void history(job.id)}>{job.created_at} / {job.status} / {job.processed}/{job.total}行</button></li>)}</ul>}
  </section>;
}
