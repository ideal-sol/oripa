"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminShippingMinimumPointSetting } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

export function ShippingMinimumPointSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { permissions } = usePermissions();
  const canRead = permissions.has("shipping.settings.read");
  const canManage = permissions.has("shipping.settings.manage");
  const [setting, setSetting] = useState<AdminShippingMinimumPointSetting | null>(null);
  const [draft, setDraft] = useState("");
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const pending = useRef<{ key: string; points: string; revision: number } | null>(null);
  const apply = useCallback((data: AdminShippingMinimumPointSetting) => {
    setSetting(data); setDraft(data.minimum_points); pending.current = null; setConfirming(false);
  }, []);
  const load = useCallback(async (signal?: AbortSignal) => {
    setBusy(true); setError(null); setSaved(false);
    try { const response = await client.getShippingMinimumPointSetting(signal); if (!signal?.aborted) apply(response.data); }
    catch (caught) { if (!signal?.aborted) setError(message(caught)); }
    finally { if (!signal?.aborted) setBusy(false); }
  }, [apply, client]);
  useEffect(() => {
    if (!canRead) return;
    const controller = new AbortController(); void load(controller.signal);
    return () => controller.abort();
  }, [canRead, load]);
  const valid = /^(0|[1-9][0-9]{0,15})$/.test(draft) && BigInt(draft) <= 9007199254740991n;
  const dirty = setting !== null && draft !== setting.minimum_points;
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canManage || !setting || busy || !valid || !dirty) return;
    if (!confirming) { setConfirming(true); return; }
    setBusy(true); setError(null); setSaved(false);
    if (!pending.current || pending.current.points !== draft || pending.current.revision !== setting.revision) {
      pending.current = { key: crypto.randomUUID(), points: draft, revision: setting.revision };
    }
    try {
      const response = await client.updateShippingMinimumPointSetting({ minimum_points: draft, expected_revision: setting.revision }, pending.current.key);
      apply(response.data); setSaved(true);
    } catch (caught) { setError(message(caught)); }
    finally { setBusy(false); }
  }
  return <AdminShell><ProtectedAdminRoute permission="shipping.settings.read"><div className="workspace">
    <Breadcrumb item={navigationItem("shipping-minimum-settings")} />
    <AdminPageHeader eyebrow="Shipping Settings" title="発送下限ポイント設定" />
    {error && <p className="notice notice-error" role="alert">{error}</p>}
    {saved && <p className="notice notice-success" role="status">設定を保存しました。</p>}
    {busy && <p role="status">設定を処理しています…</p>}
    {setting ? <form className="referral-settings-form" onSubmit={save}>
      <section className="settings-panel referral-settings-section">
        <h2>現在の下限ポイント</h2><p>{BigInt(setting.minimum_points).toLocaleString("ja-JP")}pt</p>
        <p>下限0ptは「下限制限なし。ただし合計0pt景品は発送不可」です。</p>
        <label htmlFor="shipping-minimum">発送下限ポイント（0〜9,007,199,254,740,991pt）</label>
        <input id="shipping-minimum" inputMode="numeric" type="text" value={draft} disabled={!canManage || busy} aria-invalid={!valid} aria-describedby="shipping-minimum-help" onChange={(event) => { setDraft(event.target.value); setConfirming(false); setSaved(false); }} />
        <p id="shipping-minimum-help">半角整数で入力してください。0pt景品だけの発送はできません。</p>
        {!valid && <p className="field-error" role="alert">範囲内の整数を入力してください。</p>}
        {!canManage && <p>参照のみ可能です。変更はOwner/Adminが行えます。</p>}
      </section>
      <section className="settings-panel referral-settings-section"><h2>適用範囲</h2>
        <p>すべてのガチャ、配送専用景品、過去・今後の獲得景品に共通です。異なるガチャの景品も合算できます。保存後に成立する新規発送申請に適用され、ポイントの消費・付与はありません。</p>
        <dl className="referral-settings-summary">
          <div><dt>Revision</dt><dd>{setting.revision}</dd></div>
          <div><dt>最終更新者</dt><dd>{setting.updated_by_admin_id ?? "初期設定"}</dd></div>
          <div><dt>最終更新日時</dt><dd>{new Date(setting.updated_at).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" })} JST</dd></div>
        </dl>
      </section>
      {confirming && <p className="notice" role="status">発送下限を{BigInt(draft).toLocaleString("ja-JP")}ptに変更します。「変更を確定」で保存します。</p>}
      <div className="referral-settings-actions">
        <button type="button" className="secondary-button" disabled={busy} onClick={() => void load()}>最新値を再取得</button>
        {canManage && <button className="primary-button" type="submit" disabled={busy || !valid || !dirty}>{confirming ? "変更を確定" : "保存内容を確認"}</button>}
      </div>
    </form> : !busy && <section className="module-state"><p>設定を取得できませんでした。</p><button type="button" onClick={() => void load()}>再読み込み</button></section>}
  </div></ProtectedAdminRoute></AdminShell>;
}
function message(error: unknown): string {
  if (error instanceof AdminApiError) {
    if (error.status === 403) return "この操作を行う権限がありません。";
    if (error.status === 401) return "セッションが切れました。再ログインしてください。";
    if (error.code === "SHIPPING_SETTING_REVISION_CONFLICT") return "他の操作で設定が変更されました。最新値を再取得してください。";
    if (error.status === 422) return "入力値を確認してください。";
  }
  return "処理を完了できませんでした。同じ内容で再試行するか、最新値を再取得してください。";
}
