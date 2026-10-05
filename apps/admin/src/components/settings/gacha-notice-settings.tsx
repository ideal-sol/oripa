"use client";

import { LoaderCircle, Save } from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { usePermissions } from "@/components/permissions/permission-provider";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminGachaNoticeDefaults } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

const sections = [
  { scope: "standard", title: "通常ガチャ", label: "通常ガチャのデフォルト注意事項" },
  { scope: "login", title: "ログイン・新規登録限定ガチャ", label: "ログイン・新規登録限定ガチャのデフォルト注意事項" },
] as const;

export function GachaNoticeSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { permissions } = usePermissions();
  const canManage = permissions.has("catalog.manage");
  const [setting, setSetting] = useState<AdminGachaNoticeDefaults | null>(null);
  const [draft, setDraft] = useState({ standard: "", login: "" });
  const [busy, setBusy] = useState<"load" | "save" | null>("load");
  const [error, setError] = useState<AdminApiError | null>(null);
  const [saved, setSaved] = useState(false);
  const pending = useRef<{ fingerprint: string; key: string } | null>(null);
  const apply = useCallback((next: AdminGachaNoticeDefaults) => {
    setSetting(next);
    setDraft({ standard: next.standard.default_notices ?? "", login: next.login.default_notices ?? "" });
    pending.current = null;
  }, []);
  const load = useCallback(async (signal?: AbortSignal) => {
    try {
      const response = await client.getGachaNoticeDefaults(signal);
      if (!signal?.aborted) { apply(response.data); setError(null); }
    } catch (cause) {
      if (!signal?.aborted) setError(asApiError(cause));
    } finally {
      if (!signal?.aborted) setBusy(null);
    }
  }, [apply, client]);

  useEffect(() => {
    const controller = new AbortController();
    client.getGachaNoticeDefaults(controller.signal)
      .then((response) => { if (!controller.signal.aborted) apply(response.data); })
      .catch((cause: unknown) => { if (!controller.signal.aborted) setError(asApiError(cause)); })
      .finally(() => { if (!controller.signal.aborted) setBusy(null); });
    return () => controller.abort();
  }, [apply, client]);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canManage || !setting || busy !== null) return;
    const body = {
      standard: { default_notices: draft.standard.normalize("NFC").trim() || null, expected_revision: setting.standard.revision },
      login: { default_notices: draft.login.normalize("NFC").trim() || null, expected_revision: setting.login.revision },
    };
    const fingerprint = JSON.stringify(body);
    if (pending.current?.fingerprint !== fingerprint) pending.current = { fingerprint, key: crypto.randomUUID() };
    setBusy("save"); setError(null); setSaved(false);
    try {
      const response = await client.updateGachaNoticeDefaults(body, pending.current.key);
      apply(response.data);
      setSaved(true);
    } catch (cause) {
      const next = asApiError(cause);
      if (!next.retryable) pending.current = null;
      setError(next);
    } finally {
      setBusy(null);
    }
  }

  return <AdminShell><ProtectedAdminRoute permission="catalog.read"><div className="workspace">
    <Breadcrumb item={navigationItem("gacha-notice-settings")} />
    <AdminPageHeader eyebrow="各種設定" title="ガチャ注意事項設定"
      description="新しいガチャの登録開始時に注意事項へコピーします。既存のガチャ・下書き・コピー元には反映されません。" />
    {busy === "load" ? <p role="status">設定を読み込んでいます</p> : <>
      {error ? <div className="notice notice-error" role="alert">
        <p>{error.status === 409 ? "設定が更新されています。最新内容を再取得してください。" : "設定を取得または保存できませんでした。再試行してください。"}</p>
        <button className="secondary-button" disabled={busy !== null} type="button" onClick={() => { setBusy("load"); setSaved(false); void load(); }}>再読み込み</button>
      </div> : null}
      {setting ? <form className="referral-settings-form" onSubmit={save}>
        {saved ? <p className="notice notice-success" role="status">設定を保存しました。</p> : null}
        {sections.map(({ scope, title, label }) => <section className="settings-panel referral-settings-section" key={scope}>
          <h2>{title}</h2>
          <label><span>{label}</span><textarea disabled={!canManage || busy !== null} maxLength={10000} rows={10}
            value={draft[scope]} onChange={(event) => { setDraft({ ...draft, [scope]: event.target.value }); setSaved(false); }} /></label>
        </section>)}
        {canManage ? <div className="referral-settings-actions"><button className="primary-button" type="submit" disabled={busy !== null || error?.status === 409}>
          {busy === "save" ? <LoaderCircle aria-hidden="true" className="spin" size={17} /> : <Save aria-hidden="true" size={17} />}
          {busy === "save" ? "保存中" : "保存"}
        </button></div> : null}
      </form> : null}
    </>}
  </div></ProtectedAdminRoute></AdminShell>;
}

function asApiError(value: unknown): AdminApiError {
  return value instanceof AdminApiError ? value : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}
