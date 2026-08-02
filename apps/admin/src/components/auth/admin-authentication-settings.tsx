"use client";

import {
  KeyRound,
  LoaderCircle,
  RotateCcw,
  Save,
  ShieldCheck,
  UserPlus,
  X,
} from "lucide-react";
import { type FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminAuthenticationPolicy } from "@/lib/admin-api/generated";
import { navigationItem } from "@/lib/permissions/admin-navigation";

type Draft = Pick<AdminAuthenticationPolicy, "mfa_required" | "invitation_required">;

export function AdminAuthenticationSettings() {
  const client = useMemo(() => new AdminApiClient(), []);
  const navigation = navigationItem("authentication-settings");
  const [policy, setPolicy] = useState<AdminAuthenticationPolicy | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [currentPassword, setCurrentPassword] = useState("");
  const [error, setError] = useState<AdminApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"load" | "save" | "create" | null>("load");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [freshOpen, setFreshOpen] = useState(false);
  const [adminEmail, setAdminEmail] = useState("");
  const [adminRole, setAdminRole] = useState<"admin" | "operator">("admin");
  const [temporaryPassword, setTemporaryPassword] = useState("");
  const [invitationToken, setInvitationToken] = useState<string | null>(null);
  const pendingKey = useRef<string | null>(null);

  const dirty = policy !== null && draft !== null && (
    policy.mfa_required !== draft.mfa_required
    || policy.invitation_required !== draft.invitation_required
  );

  const applyPolicy = useCallback((next: AdminAuthenticationPolicy) => {
    setPolicy(next);
    setDraft({
      mfa_required: next.mfa_required,
      invitation_required: next.invitation_required,
    });
    pendingKey.current = null;
  }, []);

  const load = useCallback(async () => {
    setBusy("load");
    setError(null);
    try {
      const response = await client.getAuthenticationPolicy();
      applyPolicy(response.data);
    } catch (caught) {
      setError(asApiError(caught));
    } finally {
      setBusy(null);
    }
  }, [applyPolicy, client]);

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0);
    return () => window.clearTimeout(timer);
  }, [load]);

  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function save() {
    if (!policy || !draft || !dirty || currentPassword.length === 0) return;
    setConfirmOpen(false);
    setBusy("save");
    setError(null);
    setNotice(null);
    pendingKey.current ??= crypto.randomUUID();
    const password = currentPassword;
    setCurrentPassword("");
    try {
      const response = await client.updateAuthenticationPolicy(
        {
          expected_revision: policy.revision,
          ...draft,
          current_password: password,
        },
        pendingKey.current,
      );
      applyPolicy(response.data);
      setNotice(response.idempotent_replay ? "保存済みの設定を再取得しました。" : "認証設定を保存しました。");
    } catch (caught) {
      const next = asApiError(caught);
      setError(next);
      if (next.requiresFreshMfa) setFreshOpen(true);
      if (next.status !== 0 && next.status !== 429) pendingKey.current = null;
    } finally {
      setBusy(null);
    }
  }

  async function createAdmin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!policy) return;
    setBusy("create");
    setError(null);
    setNotice(null);
    setInvitationToken(null);
    const password = temporaryPassword;
    setTemporaryPassword("");
    try {
      const response = await client.createAdminAccount({
        email: adminEmail,
        role: adminRole,
        ...(!policy.invitation_required ? { temporary_password: password } : {}),
      });
      setAdminEmail("");
      setInvitationToken(response.data.invitation_token);
      setNotice(policy.invitation_required
        ? "招待を作成しました。トークンはこの画面で一度だけ確認できます。"
        : "管理者を作成しました。一時パスワードを安全な経路で共有してください。");
      await load();
    } catch (caught) {
      const next = asApiError(caught);
      setError(next);
      if (next.requiresFreshMfa) setFreshOpen(true);
    } finally {
      setBusy(null);
    }
  }

  return (
    <AdminShell>
      <ProtectedAdminRoute permission="identity.admin.manage">
        <div className="workspace">
          <Breadcrumb item={navigation} />
          <AdminPageHeader
            action={<ShieldCheck size={26} aria-hidden="true" />}
            eyebrow="Authentication policy"
            title="管理者認証"
          />
          {busy === "load" ? (
            <section className="module-state" role="status">
              <LoaderCircle className="spin" size={24} aria-hidden="true" />
              <h2>認証設定を読み込んでいます</h2>
            </section>
          ) : policy && draft ? (
            <div className="admin-auth-settings">
              {error ? <SettingsError error={error} onReload={load} /> : null}
              {notice ? <div className="notice notice-success" role="status">{notice}</div> : null}
              <section className="settings-panel" aria-labelledby="policy-heading">
                <div className="section-heading">
                  <KeyRound size={21} aria-hidden="true" />
                  <div>
                    <h2 id="policy-heading">ログイン要件</h2>
                    <p>メールアドレスとパスワードは常に必要です。</p>
                  </div>
                </div>
                <label className="settings-toggle">
                  <input
                    checked={draft.mfa_required}
                    onChange={(event) => setDraft({ ...draft, mfa_required: event.target.checked })}
                    type="checkbox"
                  />
                  <span>多要素認証を必須にする</span>
                </label>
                <label className="settings-toggle">
                  <input
                    checked={draft.invitation_required}
                    onChange={(event) => setDraft({ ...draft, invitation_required: event.target.checked })}
                    type="checkbox"
                  />
                  <span>招待トークンを必須にする</span>
                </label>
                <dl className="settings-summary">
                  <div><dt>MFA登録済み管理者</dt><dd>{policy.mfa_enrolled_admin_count}人</dd></div>
                  <div><dt>有効Owner</dt><dd>{policy.active_owner_count}人</dd></div>
                  <div><dt>Revision</dt><dd>{policy.revision}</dd></div>
                </dl>
                <label>
                  <span>現在のパスワード</span>
                  <span className="input-shell">
                    <KeyRound size={18} aria-hidden="true" />
                    <input
                      autoComplete="current-password"
                      maxLength={128}
                      onChange={(event) => setCurrentPassword(event.target.value)}
                      required
                      type="password"
                      value={currentPassword}
                    />
                  </span>
                </label>
                <div className="settings-actions">
                  <button
                    className="secondary-button"
                    disabled={busy !== null || !dirty}
                    onClick={() => {
                      applyPolicy(policy);
                      setCurrentPassword("");
                    }}
                    type="button"
                  >
                    <RotateCcw size={17} aria-hidden="true" />
                    変更を戻す
                  </button>
                  <button
                    className="primary-button"
                    disabled={busy !== null || !dirty || currentPassword.length === 0}
                    onClick={() => setConfirmOpen(true)}
                    type="button"
                  >
                    <Save size={17} aria-hidden="true" />
                    保存
                  </button>
                </div>
              </section>

              <section className="settings-panel" aria-labelledby="admin-create-heading">
                <div className="section-heading">
                  <UserPlus size={21} aria-hidden="true" />
                  <div>
                    <h2 id="admin-create-heading">管理者を追加</h2>
                    <p>{policy.invitation_required ? "招待トークンを発行します。" : "一時パスワードで有効な管理者を作成します。"}</p>
                  </div>
                </div>
                <form className="settings-create-form" onSubmit={createAdmin}>
                  <label>
                    <span>メールアドレス</span>
                    <input
                      autoComplete="off"
                      maxLength={320}
                      onChange={(event) => setAdminEmail(event.target.value)}
                      required
                      type="email"
                      value={adminEmail}
                    />
                  </label>
                  <label>
                    <span>Role</span>
                    <select onChange={(event) => setAdminRole(event.target.value as "admin" | "operator")} value={adminRole}>
                      <option value="admin">Admin</option>
                      <option value="operator">Operator</option>
                    </select>
                  </label>
                  {!policy.invitation_required ? (
                    <label>
                      <span>一時パスワード</span>
                      <input
                        autoComplete="new-password"
                        maxLength={128}
                        minLength={12}
                        onChange={(event) => setTemporaryPassword(event.target.value)}
                        required
                        type="password"
                        value={temporaryPassword}
                      />
                    </label>
                  ) : null}
                  <button className="primary-button" disabled={busy !== null} type="submit">
                    {busy === "create" ? <LoaderCircle className="spin" size={17} aria-hidden="true" /> : <UserPlus size={17} aria-hidden="true" />}
                    管理者を追加
                  </button>
                </form>
                {invitationToken ? (
                  <div className="one-time-credential" role="status">
                    <strong>一度だけ表示される招待トークン</strong>
                    <code>{invitationToken}</code>
                    <button className="icon-button" onClick={() => setInvitationToken(null)} title="招待トークンを閉じる" type="button">
                      <X size={17} aria-hidden="true" />
                    </button>
                  </div>
                ) : null}
              </section>
            </div>
          ) : (
            <section className="module-state" role="alert">
              <h2>認証設定を取得できませんでした</h2>
              <button className="secondary-button" onClick={() => void load()} type="button">再読み込み</button>
            </section>
          )}
        </div>
        {confirmOpen ? (
          <div className="dialog-backdrop" role="presentation">
            <section aria-labelledby="auth-policy-confirm-title" aria-modal="true" className="dialog-panel" role="alertdialog">
              <ShieldCheck size={24} aria-hidden="true" />
              <h2 id="auth-policy-confirm-title">認証要件を変更しますか</h2>
              <p>次回以降の管理者ログインと新規管理者作成へ反映されます。</p>
              <div className="settings-actions">
                <button className="secondary-button" disabled={busy !== null} onClick={() => setConfirmOpen(false)} type="button">取り消し</button>
                <button className="primary-button" disabled={busy !== null} onClick={() => void save()} type="button">変更を確定</button>
              </div>
            </section>
          </div>
        ) : null}
        <FreshMfaDialog
          onClose={() => setFreshOpen(false)}
          onSuccess={() => {
            setFreshOpen(false);
            setNotice("本人確認が完了しました。操作をもう一度実行してください。");
          }}
          open={freshOpen}
        />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function SettingsError({ error, onReload }: { error: AdminApiError; onReload: () => Promise<void> }) {
  const conflict = error.status === 409;
  return (
    <div aria-live="assertive" className="notice notice-error" role="alert">
      <p>{conflict ? "設定が更新されています。最新状態を再取得してください。" : error.message}</p>
      {conflict ? (
        <button className="secondary-button" onClick={() => void onReload()} type="button">
          <RotateCcw size={17} aria-hidden="true" />
          再読み込み
        </button>
      ) : null}
    </div>
  );
}

function asApiError(value: unknown): AdminApiError {
  return value instanceof AdminApiError
    ? value
    : new AdminApiError(0, "NETWORK_ERROR", null, null, true);
}
