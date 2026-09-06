"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { PermissionGate } from "@/components/permissions/permission-gate";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminAgency, AdminAgencyDraft, AdminAgencyInput, AdminAgencyMutationResult } from "@/lib/admin-api/generated";

type Mode = "list" | "new" | "detail" | "edit";
type Action = "suspend" | "reactivate" | "password-reset" | "login-information-reissue";
const labels: Record<Action, string> = {
  suspend: "停止", reactivate: "再有効化", "password-reset": "PW再設定", "login-information-reissue": "ログイン情報を再発行",
};
const fields = [
  ["company_name", "会社名", 200], ["contact_name", "担当者名", 200], ["phone", "電話番号", 40],
  ["email", "担当者メールアドレス", 320], ["address", "住所", 1000],
] as const;

export function AgencyWorkspace({ mode = "list", agencyId }: { mode?: Mode; agencyId?: string }) {
  return <AdminShell><ProtectedAdminRoute permission={mode === "new" || mode === "edit" ? "agency.manage" : "agency.read"}>
    <AgencyContent agencyId={agencyId} mode={mode} />
  </ProtectedAdminRoute></AdminShell>;
}

function AgencyContent({ mode, agencyId }: { mode: Mode; agencyId?: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const router = useRouter();
  const [items, setItems] = useState<AdminAgency[]>([]);
  const [agency, setAgency] = useState<AdminAgency | null>(null);
  const [cursor, setCursor] = useState<string | undefined>();
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(mode !== "new");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [fresh, setFresh] = useState(false);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    if (mode === "new") return;
    const controller = new AbortController();
    const operation = agencyId
      ? client.getAgency(agencyId, controller.signal).then((result) => setAgency(result.data))
      : client.listAgencies(cursor, controller.signal).then((result) => { setItems(result.items); setNextCursor(result.next_cursor); });
    operation.then(() => { if (!controller.signal.aborted) setError(null); }).catch((cause: unknown) => { if (!controller.signal.aborted) setError(errorMessage(cause)); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [client, agencyId, cursor, reload, mode]);

  function saved(result: AdminAgencyMutationResult) {
    setAgency(result.data);
    setMessage(result.idempotent_replay ? "実行済みの結果を表示しています。" : "代理店情報を保存しました。");
    setAction(null);
    if (mode === "new" || mode === "edit") router.push(`/agencies/${result.data.id}`);
    else setReload((value) => value + 1);
  }

  return <main className="workspace">
    <AdminPageHeader eyebrow="Agency" title={mode === "new" ? "代理店登録" : mode === "edit" ? "代理店編集" : agencyId ? "代理店詳細" : "代理店一覧"}
      action={mode === "list" ? <PermissionGate permission="agency.manage"><Link className="primary-button" href="/agencies/new">新規代理店登録</Link></PermissionGate> : <Link className="secondary-button" href="/agencies">代理店一覧へ</Link>} />
    {message ? <p className="status-alert" role="status">{message}</p> : null}
    {error ? <p className="error-alert" role="alert">{error}<button className="secondary-button" onClick={() => setReload((value) => value + 1)} type="button">再読み込み</button></p> : null}
    {loading ? <p role="status">代理店を読み込んでいます。</p> : null}
    {!loading && !error && mode === "list" ? <>
      {items.length === 0 ? <p>代理店はありません。</p> : <div className="table-container"><table>
        <thead><tr><th>会社名</th><th>担当者名</th><th>Login ID</th><th>Advertising Code</th><th>状態</th><th>詳細</th></tr></thead>
        <tbody>{items.map((item) => <tr key={item.id}><td>{item.company_name}</td><td>{item.contact_name}</td><td>{item.login_id}</td><td>{item.advertising_code}</td><td>{item.status === "active" ? "有効" : "停止"}</td><td><Link href={`/agencies/${item.id}`}>詳細</Link></td></tr>)}</tbody>
      </table></div>}
      <div className="catalog-dialog-actions">{cursor ? <button className="secondary-button" onClick={() => setCursor(undefined)} type="button">先頭へ</button> : null}{nextCursor ? <button className="secondary-button" onClick={() => setCursor(nextCursor)} type="button">次のページ</button> : null}</div>
    </> : null}
    {!loading && !error && (mode === "new" || (mode === "edit" && agency)) ? <AgencyForm client={client} current={agency} onFresh={() => setFresh(true)} onSaved={saved} /> : null}
    {!loading && !error && mode === "detail" && agency ? <>
      <section className="catalog-mutation-panel"><dl>{fields.map(([field, label]) => <div key={field}><dt>{label}</dt><dd>{agency[field]}</dd></div>)}
        <div><dt>Login ID</dt><dd>{agency.login_id}</dd></div><div><dt>Advertising Code</dt><dd>{agency.advertising_code}</dd></div>
        <div><dt>状態</dt><dd>{agency.status === "active" ? "有効" : "停止"}</dd></div><div><dt>メモ</dt><dd>{agency.memo || "なし"}</dd></div>
      </dl></section>
      <PermissionGate permission="agency.manage"><div className="catalog-dialog-actions">
        <Link className="secondary-button" href={`/agencies/${agency.id}/edit`}>編集</Link>
        {([agency.status === "active" ? "suspend" : "reactivate", "password-reset", "login-information-reissue"] as Action[]).map((operation) => <button className="secondary-button" key={operation} onClick={() => setAction(operation)} type="button">{labels[operation]}</button>)}
      </div></PermissionGate>
    </> : null}
    {action && agency ? <AgencyActionDialog action={action} agency={agency} client={client} onClose={() => setAction(null)} onFresh={() => setFresh(true)} onSaved={saved} /> : null}
    <FreshMfaDialog open={fresh} onClose={() => setFresh(false)} onSuccess={() => setFresh(false)} />
  </main>;
}

function AgencyForm({ client, current, onFresh, onSaved }: {
  client: AdminApiClient; current: AdminAgency | null; onFresh: () => void; onSaved: (result: AdminAgencyMutationResult) => void;
}) {
  const [draft, setDraft] = useState<AdminAgencyDraft | null>(null);
  const [issued, setIssued] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = useRef<string | null>(null);
  const passwordInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (current) return;
    let active = true;
    client.issueAgencyIdentifiers().then((result) => { if (active) setDraft(result); })
      .catch(() => { if (active) setError("広告コード発行からLogin IDと広告コードを生成してください。"); });
    return () => { active = false; };
  }, [client, current]);

  async function issue() {
    setBusy(true);
    setError(null);
    try {
      if (!draft) setDraft(await client.issueAgencyIdentifiers());
      setIssued(true);
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) onFresh();
      setError(errorMessage(cause));
    } finally { setBusy(false); }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const values = new FormData(event.currentTarget);
    const password = String(values.get("password") ?? "");
    if (!current && (!draft || !issued || !/^[A-Za-z0-9]{6,20}$/.test(password))) {
      setError("広告コードを発行し、半角英数字6〜20文字の初期PWを入力してください。");
      return;
    }
    const input = Object.fromEntries([...fields.map(([field]) => [field, String(values.get(field) ?? "")]), ["memo", String(values.get("memo") ?? "")]]) as unknown as AdminAgencyInput;
    setBusy(true);
    setError(null);
    key.current ??= crypto.randomUUID();
    try {
      const result = current
        ? await client.updateAgency(current.id, { ...input, login_id: String(values.get("login_id")), expected_revision: current.revision }, key.current)
        : await client.createAgency({ ...input, issuance_token: draft!.issuance_token, password }, key.current);
      key.current = null;
      onSaved(result);
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) onFresh();
      if (cause instanceof AdminApiError && !cause.retryable) key.current = null;
      setError(errorMessage(cause));
    } finally {
      if (passwordInput.current) passwordInput.current.value = "";
      setBusy(false);
    }
  }

  return <form className="catalog-mutation-form" onSubmit={(event) => void submit(event)}>
    {error ? <p className="error-alert" role="alert">{error}</p> : null}
    {fields.map(([field, label, maxLength]) => <label key={field}>{label}<input defaultValue={current?.[field] ?? ""} disabled={busy} maxLength={maxLength} name={field} required type={field === "email" ? "email" : field === "phone" ? "tel" : "text"} /></label>)}
    <label>メモ<textarea defaultValue={current?.memo ?? ""} disabled={busy} maxLength={5000} name="memo" /></label>
    {current ? <label>Login ID<input defaultValue={current.login_id} disabled={busy} inputMode="numeric" maxLength={6} minLength={6} name="login_id" pattern="[0-9]{6}" required /></label>
      : <label>Login ID（自動生成）<input readOnly value={draft?.login_id ?? ""} /></label>}
    <label>Advertising Code<input readOnly value={current?.advertising_code ?? (issued ? draft?.advertising_code ?? "" : "")} /></label>
    {current ? <p>広告コードは変更できません。</p> : <>
      <button className="secondary-button" disabled={busy || issued} onClick={() => void issue()} type="button">広告コード発行</button>
      <label>初期PW<input autoComplete="new-password" disabled={busy} maxLength={20} minLength={6} name="password" pattern="[A-Za-z0-9]{6,20}" ref={passwordInput} required type="password" /></label>
      <small>半角英数字6〜20文字。登録メールアドレスへ初期ログイン情報を通知します。</small>
    </>}
    <div className="catalog-dialog-actions"><Link className="secondary-button" href={current ? `/agencies/${current.id}` : "/agencies"}>キャンセル</Link><button className="primary-button" disabled={busy} type="submit">{busy ? "保存中" : "保存"}</button></div>
  </form>;
}

function AgencyActionDialog({ action, agency, client, onClose, onFresh, onSaved }: {
  action: Action; agency: AdminAgency; client: AdminApiClient; onClose: () => void; onFresh: () => void; onSaved: (result: AdminAgencyMutationResult) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const key = useRef<string | null>(null);
  const form = useRef<HTMLFormElement>(null);
  const credential = action === "password-reset" || action === "login-information-reissue";
  useEffect(() => {
    const previous = document.activeElement as HTMLElement | null;
    form.current?.querySelector<HTMLElement>("input, button")?.focus();
    return () => previous?.focus();
  }, []);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    const password = String(new FormData(event.currentTarget).get("password") ?? "");
    if (credential && !/^[A-Za-z0-9]{6,20}$/.test(password)) { setError("半角英数字6〜20文字で入力してください。"); return; }
    setBusy(true);
    setError(null);
    key.current ??= crypto.randomUUID();
    const revision = { expected_revision: agency.revision };
    try {
      const result = await (action === "suspend" ? client.suspendAgency(agency.id, revision, key.current)
        : action === "reactivate" ? client.reactivateAgency(agency.id, revision, key.current)
          : action === "password-reset" ? client.resetAgencyPassword(agency.id, { ...revision, password }, key.current)
            : client.reissueAgencyLoginInformation(agency.id, { ...revision, password }, key.current));
      onSaved(result);
    } catch (cause) {
      if (cause instanceof AdminApiError && cause.requiresFreshMfa) onFresh();
      if (cause instanceof AdminApiError && !cause.retryable) key.current = null;
      setError(errorMessage(cause));
    } finally { form.current?.reset(); setBusy(false); }
  }

  return <div className="dialog-backdrop" role="presentation" onKeyDown={(event) => {
    if (event.key === "Escape" && !busy) onClose();
    if (event.key !== "Tab") return;
    const controls = form.current?.querySelectorAll<HTMLElement>("input:not(:disabled), button:not(:disabled)");
    if (!controls?.length) return;
    const first = controls[0]; const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
  }}><section aria-labelledby="agency-action-title" aria-modal="true" className="dialog-panel" role="dialog">
    <h2 id="agency-action-title">{labels[action]}</h2>
    <p>{action === "login-information-reissue" ? "この操作を実行すると現在のパスワードは使用できなくなります。新しいログイン情報が登録メールアドレスへ送信されます。"
      : action === "password-reset" ? "現在のパスワードは使用できなくなります。新PWを含まない変更通知を送信します。"
        : `この代理店を${labels[action]}します。広告コードと過去の帰属情報は維持されます。`}</p>
    <form className="catalog-mutation-form" onSubmit={(event) => void submit(event)} ref={form}>
      {error ? <p className="error-alert" role="alert">{error}</p> : null}
      {credential ? <label>新しいPW<input autoComplete="new-password" disabled={busy} maxLength={20} minLength={6} name="password" pattern="[A-Za-z0-9]{6,20}" required type="password" /></label> : null}
      {credential ? <label><input disabled={busy} required type="checkbox" />パスワードが変更されることを確認しました</label> : null}
      <div className="catalog-dialog-actions"><button className="secondary-button" disabled={busy} onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit">確認して実行</button></div>
    </form>
  </section></div>;
}

function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError) {
    if (cause.requiresFreshMfa) return "本人確認後に入力し直して、もう一度実行してください。";
    if (cause.status === 403) return "この操作を行う権限がありません。";
    if (cause.status === 404) return "代理店が見つかりません。";
    if (cause.status === 409) return "メールアドレス・Login IDの重複、または別の更新との競合です。再読み込みして確認してください。";
    if (cause.status === 422) return "入力内容を確認してください。候補の有効期限は30分です。";
  }
  return "代理店情報を処理できませんでした。再試行してください。";
}
