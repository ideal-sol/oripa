"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { Building2, LogOut, Settings } from "lucide-react";
import Link from "next/link";
import { AdminPageHeader } from "../../../admin/src/components/shell/admin-page-header";
import { AgencyAggregateTable } from "../../../admin/src/components/agencies/agency-aggregate-table";
import { agencyApi, AgencyApiError } from "../lib/agency-api/client";
import type { AgencyProfile, AgencyProfileResponse } from "../lib/agency-api/generated";

const labels: Record<keyof Omit<AgencyProfile, "id">, string> = {
  company_name: "会社名", contact_name: "担当者名", phone: "電話番号", email: "メールアドレス",
  address: "住所", login_id: "Login ID", advertising_code: "Advertising Code", status: "状態",
};

export function AgencyPortal({ view = "account" }: { view?: "account" | "users" | "sales" }) {
  const router = useRouter();
  const [agency, setAgency] = useState<AgencyProfile | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  useEffect(() => {
    let active = true;
    agencyApi.session().then(session => {
      if (!active) return;
      setAgency(session.agency);
      if (!session.authenticated) router.replace("/login");
      else if (view === "account") router.replace("/");
    }).catch(() => { if (active) setError("接続できませんでした。ページを再読み込みしてください。"); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [router, view]);

  async function submit(event: FormEvent<HTMLFormElement>, operation: string) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const value = (key: string) => String(data.get(key) ?? "");
    setBusy(true); setError(""); setNotice("");
    try {
      let result: AgencyProfileResponse;
      if (operation === "login") result = await agencyApi.login({ login_id: value("login_id"), password: value("password") });
      else if (operation === "contact") result = await agencyApi.contact({ contact_name: value("contact_name"), phone: value("phone") });
      else if (operation === "email") result = await agencyApi.email({ current_password: value("current_password"), email: value("email") });
      else result = await agencyApi.password({ current_password: value("current_password"), password: value("password"), password_confirmation: value("password_confirmation") });
      setAgency(result.data); form.reset(); setNotice(operation === "login" ? "" : "変更を保存しました。");
      if (operation === "login") router.replace("/");
    } catch (failure) {
      setError(failure instanceof Error ? failure.message : "接続できませんでした。");
      if (failure instanceof AgencyApiError && (failure.status === 401 || failure.code === "AUTHORIZATION_DENIED")) {
        setAgency(null); router.replace("/login");
      }
    } finally {
      form.querySelectorAll<HTMLInputElement>('input[type="password"]').forEach(input => { input.value = ""; });
      setBusy(false);
    }
  }

  async function logout() {
    setBusy(true); setError("");
    try { await agencyApi.logout(); await agencyApi.session(); setAgency(null); router.replace("/login"); }
    catch (failure) {
      if (failure instanceof AgencyApiError && failure.status === 401) { setAgency(null); router.replace("/login"); }
      else setError("ログアウトできませんでした。再度お試しください。");
    } finally { setBusy(false); }
  }

  const messages = <>{error && <p role="alert" className="form-error">{error}</p>}{notice && <p role="status" className="form-success">{notice}</p>}</>;
  if (loading) return <main className="agency-login" role="status">代理店管理を読み込み中…</main>;
  if (!agency) return <main className="agency-login agency-card">
    <Building2 color="var(--forest)" size={32} /><h1>代理店管理</h1><p>Agency Portal</p>{messages}
    <form aria-label="代理店ログイン" className="agency-form" onSubmit={event => submit(event, "login")}>
      <label>Login ID<input name="login_id" autoComplete="username" required inputMode="numeric" maxLength={6} /></label>
      <label>パスワード<input name="password" type="password" autoComplete="current-password" required /></label>
      <button className="agency-primary" disabled={busy}>ログイン</button>
    </form>
  </main>;

  return <><a className="skip-link" href="#main-content">メインコンテンツへ</a><div className="admin-shell agency-shell">
    <aside className="admin-sidebar" aria-label="代理店ナビゲーション">
      <div className="sidebar-heading"><div className="sidebar-brand"><Building2 color="var(--forest)" /><span className="sidebar-brand-copy"><strong>代理店管理</strong><small>Agency Portal</small></span></div></div>
      <nav>
        <Link className={`nav-item${view === "users" ? " active" : ""}`} href="/aggregates/users" aria-current={view === "users" ? "page" : undefined}>ユーザー集計</Link>
        <Link className={`nav-item${view === "sales" ? " active" : ""}`} href="/aggregates/sales" aria-current={view === "sales" ? "page" : undefined}>売上集計</Link>
        <Link className={`nav-item${view === "account" ? " active" : ""}`} href="/" aria-current={view === "account" ? "page" : undefined}><Settings size={18} />アカウント設定</Link>
        {view === "account" ? <><a className="nav-item" href="#contact">担当者情報</a><a className="nav-item" href="#email">メールアドレス変更</a><a className="nav-item" href="#password">パスワード変更</a></> : null}
      </nav>
    </aside><div className="admin-shell-body"><header className="admin-header"><span className="agency-identifier">代理店管理</span><button type="button" className="icon-button agency-logout" disabled={busy} onClick={logout}><LogOut size={18} />ログアウト</button></header>
    <main id="main-content" className="admin-main"><div className="workspace">
      <nav aria-label="パンくず"><ol className="breadcrumb"><li>ホーム / {view === "account" ? "アカウント設定" : view === "users" ? "ユーザー集計" : "売上集計"}</li></ol></nav>
      {view !== "account" ? <>{messages}<AgencyAggregateTable key={view} kind={view} load={view === "users" ? agencyApi.userAggregate : agencyApi.salesAggregate} /></> : <>
      <AdminPageHeader eyebrow="AGENCY PORTAL" title="アカウント設定" description="自社情報の確認と担当者・ログイン情報の変更ができます。" />{messages}
      <section id="account" className="agency-card"><h2>自社情報</h2><dl className="agency-profile">{Object.entries(labels).map(([key, label]) => <ProfileField key={key} label={label} value={key === "status" ? "有効" : agency[key as keyof AgencyProfile]} />)}</dl></section>
      <section id="contact" className="agency-card"><h2>担当者情報</h2><form key={agency.contact_name + agency.phone} aria-label="担当者情報" className="agency-form" onSubmit={event => submit(event, "contact")}>
        <label>担当者名<input name="contact_name" defaultValue={agency.contact_name} required maxLength={200} autoComplete="name" /></label>
        <label>電話番号<input name="phone" defaultValue={agency.phone} required maxLength={40} autoComplete="tel" /></label>
        <button className="agency-primary" disabled={busy}>担当者情報を保存</button>
      </form></section>
      <section id="email" className="agency-card"><h2>メールアドレス変更</h2><p>変更後のメールアドレスに通知します。</p><form aria-label="メールアドレス変更" className="agency-form" onSubmit={event => submit(event, "email")}>
        <label>現在のパスワード<input name="current_password" type="password" autoComplete="current-password" required /></label>
        <label>新しいメールアドレス<input name="email" type="email" autoComplete="email" required maxLength={320} /></label>
        <button className="agency-primary" disabled={busy}>メールアドレスを変更</button>
      </form></section>
      <section id="password" className="agency-card"><h2>パスワード変更</h2><p>半角英数字6〜20文字。英字のみ・数字のみでも設定できます。</p><form aria-label="パスワード変更" className="agency-form" onSubmit={event => submit(event, "password")}>
        <label>現在のパスワード<input name="current_password" type="password" autoComplete="current-password" required /></label>
        <label>新しいパスワード<input name="password" type="password" autoComplete="new-password" required /></label>
        <label>新しいパスワード（確認）<input name="password_confirmation" type="password" autoComplete="new-password" required /></label>
        <button className="agency-primary" disabled={busy}>パスワードを変更</button>
      </form></section>
      </>}
    </div></main></div>
  </div></>;
}

function ProfileField({ label, value }: { label: string; value: string }) {
  return <><dt>{label}</dt><dd>{label === "状態" ? <span className="agency-status">{value}</span> : value}</dd></>;
}
