"use client";

import {
  ChevronLeft,
  ChevronRight,
  FilePlus2,
  LoaderCircle,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  X,
} from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, type RefObject, useEffect, useRef, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminManagedPage,
  AdminManagedPageInput,
  AdminPageCategory,
  AdminPageVisibility,
} from "@/lib/admin-api/generated";

type Mode = "list" | "create" | "edit";
const EMPTY_FORM: AdminManagedPageInput = {
  body_html: "",
  category_id: "",
  slug: "",
  title: "",
  visibility: "hidden",
};

export function PageManagementWorkspace({ mode, pageId }: { mode: Mode; pageId?: string }) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="content.read">
        {mode === "list" ? <PageList /> : <PageForm mode={mode} pageId={pageId} />}
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function PageList() {
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const [items, setItems] = useState<AdminManagedPage[]>([]);
  const [cursor, setCursor] = useState<string>();
  const [cursorStack, setCursorStack] = useState<(string | undefined)[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().listManagedPages(cursor, controller.signal)
      .then((result) => {
        setItems(result.items);
        setNextCursor(result.next_cursor);
        setError(null);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(pageError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [cursor, reload]);

  return (
    <main className="workspace announcement-workspace">
      <AdminPageHeader eyebrow="Settings" title="ページ設定" description="固定ページの本文、URL、カテゴリ、表示状態を管理します。" />
      <section className="announcement-table-section" aria-label="ページ一覧">
        <div className="announcement-form-actions">
          {canManage ? <Link className="primary-button" href="/settings/pages/new"><FilePlus2 aria-hidden="true" size={17} />新規追加</Link> : null}
          <button aria-label="ページ一覧を再取得" className="icon-button" onClick={() => { setLoading(true); setReload((value) => value + 1); }} title="再取得" type="button"><RefreshCw aria-hidden="true" size={17} /></button>
        </div>
        {loading ? <State text="ページを読み込んでいます。" /> : error ? <State error text={error} /> : items.length === 0 ? <State text="登録済みのページはありません。" /> : (
          <div className="table-container">
            <table className="announcement-table">
              <thead><tr><th>ページ</th><th>URL</th><th>カテゴリ</th><th>表示状態</th><th>更新日時</th><th>編集</th></tr></thead>
              <tbody>{items.map((item) => <tr key={item.id}>
                <td><strong>{item.title}</strong></td>
                <td><code>/{item.slug}</code></td>
                <td>{item.category ? <>{item.category.name} <Status value={item.category.visibility} /></> : "未設定"}</td>
                <td><Status value={item.visibility} /></td>
                <td>{formatJst(item.updated_at)}</td>
                <td>{canManage ? <Link aria-label={`${item.title}を編集`} className="icon-button" href={`/settings/pages/${item.id}`} title="編集"><Pencil aria-hidden="true" size={16} /></Link> : <span className="muted-text">参照のみ</span>}</td>
              </tr>)}</tbody>
            </table>
          </div>
        )}
        <div className="announcement-pagination" aria-label="ページ操作">
          <button className="secondary-button" disabled={cursorStack.length === 0 || loading} onClick={() => { const stack = [...cursorStack]; setLoading(true); setCursor(stack.pop()); setCursorStack(stack); }} type="button"><ChevronLeft aria-hidden="true" size={16} />前へ</button>
          <button className="secondary-button" disabled={!nextCursor || loading} onClick={() => { setLoading(true); setCursorStack((current) => [...current, cursor]); setCursor(nextCursor ?? undefined); }} type="button">次へ<ChevronRight aria-hidden="true" size={16} /></button>
        </div>
      </section>
    </main>
  );
}

function PageForm({ mode, pageId }: { mode: Exclude<Mode, "list">; pageId?: string }) {
  const router = useRouter();
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const [categories, setCategories] = useState<AdminPageCategory[]>([]);
  const [draft, setDraft] = useState<AdminManagedPageInput>(EMPTY_FORM);
  const [categoryDialog, setCategoryDialog] = useState(false);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listPageCategories(controller.signal),
      mode === "edit" && pageId ? client.getManagedPage(pageId, controller.signal) : Promise.resolve(null),
    ]).then(([categoryResult, page]) => {
      setCategories(categoryResult.items);
      if (page) setDraft({ body_html: page.body_html, category_id: page.category?.id ?? "", slug: page.slug, title: page.title, visibility: page.visibility });
      setError(null);
    }).catch((reason: unknown) => {
      if (!controller.signal.aborted) setError(pageError(reason));
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false);
    });
    return () => controller.abort();
  }, [mode, pageId]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canManage || !draft.category_id) return;
    setSubmitting(true);
    setError(null);
    const payload = { ...draft, title: draft.title.normalize("NFC").trim(), slug: canonicalSlug(draft.slug) };
    try {
      const client = new AdminApiClient();
      const result = mode === "create"
        ? await client.createManagedPage(payload, crypto.randomUUID())
        : await client.updateManagedPage(pageId ?? "", payload, crypto.randomUUID());
      router.push(`/settings/pages/${result.id}`);
      router.refresh();
    } catch (reason) {
      setError(pageError(reason));
      setSubmitting(false);
    }
  }

  return <main className="workspace announcement-workspace">
    <AdminPageHeader eyebrow="Settings" title={mode === "create" ? "ページ新規登録" : "ページ編集"} description="V1と同じタイトル・本文構成で固定ページを管理します。" />
    {loading ? <State text="ページ設定を読み込んでいます。" /> : error && mode === "edit" && !draft.title ? <State error text={error} /> : (
      <form className="announcement-form" onSubmit={submit}>
        {error ? <div className="form-error" role="alert">{error}</div> : null}
        <label>タイトル<input disabled={!canManage} maxLength={191} required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
        <label>本文内容<textarea className="admin-static-page-body" disabled={!canManage} maxLength={100000} required value={draft.body_html} onChange={(event) => setDraft({ ...draft, body_html: event.target.value })} /></label>
        <div className="announcement-form-grid">
          <label>slug<input disabled={!canManage} maxLength={128} pattern="[a-z0-9]+(?:-[a-z0-9]+)*" placeholder="guide" required value={draft.slug} onChange={(event) => setDraft({ ...draft, slug: event.target.value })} /></label>
          <label>表示状態<select disabled={!canManage} value={draft.visibility} onChange={(event) => setDraft({ ...draft, visibility: event.target.value as AdminPageVisibility })}><option value="visible">表示</option><option value="hidden">非表示</option></select></label>
        </div>
        <div className="announcement-form-grid">
          <label>カテゴリ<select disabled={!canManage} required value={draft.category_id} onChange={(event) => setDraft({ ...draft, category_id: event.target.value })}><option value="">選択してください</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}（{category.visibility === "visible" ? "表示" : "非表示"}）</option>)}</select></label>
          {canManage ? <button className="secondary-button" onClick={() => setCategoryDialog(true)} type="button"><Plus aria-hidden="true" size={16} />カテゴリ追加</button> : null}
        </div>
        <div className="announcement-form-actions"><Link className="secondary-button" href="/settings/pages">一覧へ戻る</Link>{canManage ? <button className="primary-button" disabled={submitting} type="submit"><Save aria-hidden="true" size={16} />{submitting ? "保存中" : "保存"}</button> : null}</div>
      </form>
    )}
    {categoryDialog ? <CategoryDialog onClose={() => setCategoryDialog(false)} onCreated={(category) => { setCategories((current) => [...current, category].sort((a, b) => a.name.localeCompare(b.name, "ja"))); setDraft((current) => ({ ...current, category_id: category.id })); setCategoryDialog(false); }} /> : null}
  </main>;
}

function CategoryDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (category: AdminPageCategory) => void }) {
  const [name, setName] = useState("");
  const [visibility, setVisibility] = useState<AdminPageVisibility>("visible");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const closeRef = useDialogFocus(onClose);
  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault(); setBusy(true); setError(null);
    try { onCreated(await new AdminApiClient().createPageCategory({ name: name.normalize("NFC").trim(), visibility }, crypto.randomUUID())); }
    catch (reason) { setError(pageError(reason)); setBusy(false); }
  }
  return <div className="dialog-backdrop" role="presentation"><section aria-labelledby="page-category-title" aria-modal="true" className="dialog-panel" role="dialog"><header><h2 id="page-category-title">カテゴリ追加</h2><button aria-label="カテゴリ追加を閉じる" className="icon-button" onClick={onClose} ref={closeRef} type="button"><X aria-hidden="true" size={18} /></button></header><form className="announcement-form" onSubmit={submit}>{error ? <div className="form-error" role="alert">{error}</div> : null}<label>カテゴリ名<input autoFocus maxLength={100} required value={name} onChange={(event) => setName(event.target.value)} /></label><label>表示状態<select value={visibility} onChange={(event) => setVisibility(event.target.value as AdminPageVisibility)}><option value="visible">表示</option><option value="hidden">非表示</option></select></label><div className="announcement-form-actions"><button className="secondary-button" disabled={busy} onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit"><Save aria-hidden="true" size={16} />{busy ? "登録中" : "登録"}</button></div></form></section></div>;
}

function State({ error = false, text }: { error?: boolean; text: string }) { return <div className={`module-state${error ? " is-error" : ""}`} role={error ? "alert" : undefined}>{!error ? <LoaderCircle className="spin" aria-hidden="true" size={22} /> : null}<p>{text}</p></div>; }
function Status({ value }: { value: AdminPageVisibility }) { return <span className={`status-badge ${value === "visible" ? "is-success" : "is-muted"}`}>{value === "visible" ? "表示" : "非表示"}</span>; }
function canonicalSlug(value: string): string { return value.trim().replace(/^\/+|\/+$/g, ""); }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function pageError(reason: unknown): string { if (reason instanceof AdminApiError) { if (reason.status === 409) return "slug、カテゴリ名、またはIdempotency-Keyが競合しました。"; if (reason.status === 422) return "入力内容を確認してください。"; if (reason.status === 403) return "この操作を行う権限がありません。"; if (reason.status === 429) return "操作回数が上限に達しました。時間を置いて再試行してください。"; } return "ページ設定を処理できませんでした。再試行してください。"; }
function useDialogFocus(onClose: () => void) { const close = useRef<HTMLButtonElement>(null); useEffect(() => { const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null; close.current?.focus(); return () => previous?.focus(); }, []); useEffect(() => { const handler = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); }; window.addEventListener("keydown", handler); return () => window.removeEventListener("keydown", handler); }, [onClose]); return close as RefObject<HTMLButtonElement | null>; }
