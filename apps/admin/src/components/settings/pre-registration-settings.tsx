"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminPreRegistrationResponse, AdminPreRegistrationUpdate } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Setting = AdminPreRegistrationResponse["data"];
const labels: Record<keyof Setting["summary"], string> = {
  applicants: "申込人数", email_pending: "メール未完了", sms_pending: "SMS未完了",
  eligible: "切替対象", pending: "切替待ち", activated: "成功", failed: "再実行待ち",
  excluded: "対象外", granted_points: "付与合計ポイント",
};

export function PreRegistrationSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { permissions } = usePermissions();
  const canManage = permissions.has("pre_registration.settings.manage");
  const [setting, setSetting] = useState<Setting | null>(null);
  const [draft, setDraft] = useState<AdminPreRegistrationUpdate | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState<AdminApiError | null>(null);
  const [confirmation, setConfirmation] = useState<"save" | "activate" | null>(null);
  const [affected, setAffected] = useState(0);
  const [notice, setNotice] = useState("");
  const pending = useRef<{ action: string; key: string; fingerprint: string } | null>(null);

  function apply(data: Setting) {
    setSetting(data);
    setDraft({ expected_revision: data.revision, is_enabled: data.is_enabled, cutoff_at: data.cutoff_at, point_amount: data.point_amount });
  }

  useEffect(() => {
    const controller = new AbortController();
    client.getPreRegistration(controller.signal).then(({ data }) => {
      if (!controller.signal.aborted) apply(data);
    }).catch((caught: unknown) => {
      if (!controller.signal.aborted) setError(apiError(caught));
    }).finally(() => { if (!controller.signal.aborted) setBusy(false); });
    return () => controller.abort();
  }, [client]);

  async function load() {
    setBusy(true);
    try { apply((await client.getPreRegistration()).data); setError(null); }
    catch (caught) { setError(apiError(caught)); }
    finally { setBusy(false); }
  }

  async function confirmSave() {
    if (!draft || !canManage) return;
    setBusy(true);
    try { setAffected((await client.previewPreRegistration(draft)).affected_applicants); setConfirmation("save"); setError(null); }
    catch (caught) { setError(apiError(caught)); }
    finally { setBusy(false); }
  }

  async function execute() {
    if (!draft || !setting || !canManage || !confirmation || busy) return;
    const action = confirmation;
    const fingerprint = JSON.stringify(action === "save" ? draft : { expected_revision: setting.revision });
    if (pending.current?.action !== action || pending.current.fingerprint !== fingerprint) {
      pending.current = { action, fingerprint, key: crypto.randomUUID() };
    }
    setBusy(true);
    setError(null);
    try {
      const response = action === "save"
        ? await client.updatePreRegistration(draft, pending.current.key)
        : await client.activatePreRegistration(setting.revision, pending.current.key);
      apply(response.data);
      pending.current = null;
      setConfirmation(null);
      setNotice(action === "save" ? "設定を保存しました。" : "切替結果を更新しました。残件がある場合は、結果を確認して続行してください。");
    } catch (caught) { setError(apiError(caught)); }
    finally { setBusy(false); }
  }

  const amountLocked = setting?.started_at !== null && setting?.completed_at === null;
  const invalid = draft !== null && (!Number.isInteger(draft.point_amount) || draft.point_amount < 1 || draft.point_amount > 1_000_000 || (draft.is_enabled && !draft.cutoff_at));
  return <AdminShell><ProtectedAdminRoute permission="pre_registration.settings.read"><div className="workspace">
    <Breadcrumb item={navigationItem("pre-registration-settings")} />
    <AdminPageHeader eyebrow="Point Settings" title="事前登録特典設定" />
    {notice ? <p role="status" className="notice notice-success">{notice}</p> : null}
    {error ? <div role="alert" className="notice notice-error"><p>{error.message}</p><button type="button" disabled={busy} onClick={() => void load()}>再読み込み</button></div> : null}
    {busy ? <p role="status">処理中です</p> : null}
    {setting && draft ? <>
      <section className="settings-panel referral-settings-section">
        <h2>受付設定</h2>
        <p>専用申込・メール・初回SMSを締切前に完了した会員が対象です。ポイントはOPEN後の手動切替成功時に付与します。</p>
        <div className="referral-settings-grid">
          <label><input type="checkbox" checked={draft.is_enabled} disabled={!canManage || busy || confirmation !== null} onChange={(event) => setDraft({ ...draft, is_enabled: event.target.checked })} />事前登録受付を有効にする</label>
          <label>締切・OPEN日時（日本時間）<input type="datetime-local" value={toJstInput(draft.cutoff_at)} disabled={!canManage || busy || setting.started_at !== null || confirmation !== null} onChange={(event) => setDraft({ ...draft, cutoff_at: event.target.value ? new Date(`${event.target.value}:00+09:00`).toISOString() : null })} /></label>
          <label>付与する無償ポイント<input type="number" min={1} max={1_000_000} value={draft.point_amount} disabled={!canManage || busy || amountLocked || confirmation !== null} onChange={(event) => setDraft({ ...draft, point_amount: Number(event.target.value) })} /></label>
        </div>
        <p>有効期限は付与から180日。初回切替の固定額：{setting.snapshot_amount ?? "未確定"}。{amountLocked ? "全件完了まで付与額は変更できません。" : ""}</p>
        {canManage ? <button className="primary-button" type="button" disabled={busy || invalid || confirmation !== null} onClick={() => void confirmSave()}>変更内容を確認</button> : <p>閲覧のみ可能です。</p>}
      </section>
      <section className="settings-panel referral-settings-section"><h2>本登録切替</h2>
        <dl className="referral-settings-summary">{Object.entries(setting.summary).map(([key, value]) => <div key={key}><dt>{labels[key as keyof Setting["summary"]]}</dt><dd>{value.toLocaleString("ja-JP")}</dd></div>)}</dl>
        {canManage ? <button className="primary-button" type="button" disabled={busy || confirmation !== null || setting.completed_at !== null} onClick={() => setConfirmation("activate")}>一括切替を確認（最大100件）</button> : null}
        {setting.results.length ? <div className="admin-user-table-region" tabIndex={0} aria-label="切替結果"><table><thead><tr><th>会員ID</th><th>結果</th><th>理由</th></tr></thead><tbody>{setting.results.map((result) => <tr key={result.user_id}><td>{result.user_id}</td><td>{{ pending: "切替待ち", activated: "成功", failed: "再実行待ち", excluded: "対象外" }[result.status]}</td><td>{result.reason === "temporary_failure" ? "一時的な失敗。再実行できます。" : result.reason === "phone_already_rewarded" ? "電話番号への付与済み" : result.reason ?? "—"}</td></tr>)}</tbody></table></div> : null}
      </section>
      {confirmation ? <section className="settings-panel" aria-label="操作確認"><h2>操作の確認</h2>
        <p>{confirmation === "save" ? `締切短縮により対象外となる申込：${affected}人。設定を保存します。` : `切替待ち ${setting.summary.pending}人、再実行待ち ${setting.summary.failed}人。1人あたり ${setting.snapshot_amount ?? setting.point_amount}ptを付与します。成功済み会員は再処理しません。`}</p>
        <button className="primary-button" type="button" disabled={busy} onClick={() => void execute()}>確定する</button>
        <button className="secondary-button" type="button" disabled={busy} onClick={() => setConfirmation(null)}>キャンセル</button>
      </section> : null}
    </> : null}
  </div></ProtectedAdminRoute></AdminShell>;
}

function apiError(caught: unknown): AdminApiError {
  return caught instanceof AdminApiError ? caught : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}

function toJstInput(value: string | null): string {
  return value === null ? "" : new Date(new Date(value).getTime() + 9 * 60 * 60 * 1000).toISOString().slice(0, 16);
}
