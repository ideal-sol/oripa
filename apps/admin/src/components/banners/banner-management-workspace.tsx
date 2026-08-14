"use client";

import {
  ChevronLeft,
  ChevronRight,
  Clipboard,
  ExternalLink,
  ImagePlus,
  LoaderCircle,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  Trash2,
  X,
} from "lucide-react";
import Image from "next/image";
import {
  type FormEvent,
  type ReactNode,
  type RefObject,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminBannerCategory, AdminManagedBanner } from "@/lib/admin-api/generated";

interface BannerDraft {
  categoryId: string;
  file: File | null;
  linkUrl: string;
  showOnTop: boolean;
  title: string;
}

const EMPTY_DRAFT: BannerDraft = {
  categoryId: "",
  file: null,
  linkUrl: "",
  showOnTop: false,
  title: "",
};

export function BannerManagementWorkspace() {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="content.read">
        <BannerManagement />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function BannerManagement() {
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const [categories, setCategories] = useState<AdminBannerCategory[]>([]);
  const [items, setItems] = useState<AdminManagedBanner[]>([]);
  const [filterCategory, setFilterCategory] = useState("");
  const [cursor, setCursor] = useState<string>();
  const [cursorStack, setCursorStack] = useState<(string | undefined)[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [draft, setDraft] = useState<BannerDraft>(EMPTY_DRAFT);
  const [editing, setEditing] = useState<AdminManagedBanner | null>(null);
  const [deleting, setDeleting] = useState<AdminManagedBanner | null>(null);
  const [categoryDialog, setCategoryDialog] = useState(false);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const previewUrl = useObjectUrl(draft.file);

  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listBannerCategories(controller.signal),
      client.listManagedBanners(
        { category_id: filterCategory || undefined, cursor },
        controller.signal,
      ),
    ])
      .then(([categoryResult, bannerResult]) => {
        setError(null);
        setCategories(categoryResult.items);
        setItems(bannerResult.items);
        setNextCursor(bannerResult.next_cursor);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(bannerError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [cursor, filterCategory, reload]);

  async function submitBanner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!draft.categoryId || !draft.title.trim() || !draft.file || (draft.showOnTop && !draft.linkUrl.trim())) {
      setError("カテゴリ、タイトル、画像、トップ表示時のクリック先URLを入力してください。");
      return;
    }
    setSubmitting(true);
    setError(null);
    try {
      const client = new AdminApiClient();
      const asset = await client.uploadBannerAsset(
        await fileInput(draft.file),
        crypto.randomUUID(),
      );
      await client.createManagedBanner(
        {
          asset_id: asset.id,
          category_id: draft.categoryId,
          link_url: draft.showOnTop ? draft.linkUrl.normalize("NFC").trim() : null,
          show_on_top: draft.showOnTop,
          title: draft.title.normalize("NFC").trim(),
        },
        crypto.randomUUID(),
      );
      setDraft(EMPTY_DRAFT);
      setLoading(true);
      setCursor(undefined);
      setCursorStack([]);
      setReload((value) => value + 1);
    } catch (reason) {
      setError(bannerError(reason));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <main className="workspace announcement-workspace">
      <AdminPageHeader
        eyebrow="Content"
        title="バナー管理"
        description="カテゴリ別に画像バナーを登録し、公開URLと登録内容を管理します。"
      />

      {canManage ? (
        <form className="announcement-form" id="banner-create" onSubmit={submitBanner}>
          <h2>バナー登録</h2>
          {error ? <div className="form-error" role="alert">{error}</div> : null}
          <div className="announcement-form-grid">
            <label>
              カテゴリ
              <select required value={draft.categoryId} onChange={(event) => setDraft({ ...draft, categoryId: event.target.value })}>
                <option value="">選択してください</option>
                {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
              </select>
            </label>
            <button className="secondary-button" onClick={() => setCategoryDialog(true)} type="button">
              <Plus aria-hidden="true" size={16} />カテゴリ追加
            </button>
          </div>
          <label>タイトル<input maxLength={191} required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
          <label>画像<input accept="image/gif,image/jpeg,image/png,image/webp" required type="file" onChange={(event) => setDraft({ ...draft, file: event.target.files?.[0] ?? null })} /></label>
          {previewUrl ? <Image alt="登録するバナー画像のプレビュー" height={120} src={previewUrl} unoptimized width={320} /> : null}
          <label className="check-row"><input checked={draft.showOnTop} onChange={(event) => setDraft({ ...draft, linkUrl: event.target.checked ? draft.linkUrl : "", showOnTop: event.target.checked })} type="checkbox" /><span>トップに表示</span></label>
          {draft.showOnTop ? <label>クリック先URL<input maxLength={2048} placeholder="/gachas または https://..." required value={draft.linkUrl} onChange={(event) => setDraft({ ...draft, linkUrl: event.target.value })} /></label> : null}
          <div className="announcement-form-actions">
            <button className="primary-button" disabled={submitting} type="submit">
              {submitting ? <LoaderCircle className="spin" aria-hidden="true" size={17} /> : <ImagePlus aria-hidden="true" size={17} />}
              {submitting ? "登録中" : "バナー登録"}
            </button>
          </div>
        </form>
      ) : null}

      <section className="announcement-table-section" aria-label="バナー一覧">
        <div className="announcement-form-grid">
          <label>
            カテゴリ絞り込み
            <select value={filterCategory} onChange={(event) => { setLoading(true); setFilterCategory(event.target.value); setCursor(undefined); setCursorStack([]); }}>
              <option value="">すべて</option>
              {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
            </select>
          </label>
          <button aria-label="バナー一覧を再取得" className="icon-button" onClick={() => { setLoading(true); setReload((value) => value + 1); }} title="再取得" type="button"><RefreshCw aria-hidden="true" size={17} /></button>
        </div>
        {loading ? (
          <div className="module-state" aria-live="polite"><LoaderCircle className="spin" aria-hidden="true" size={22} /><p>バナーを読み込んでいます。</p></div>
        ) : error && !canManage ? (
          <div className="module-state is-error" role="alert"><p>{error}</p></div>
        ) : items.length === 0 ? (
          <div className="module-state"><h2>登録済みのバナーはありません</h2><p>選択中のカテゴリに表示できるバナーがありません。</p></div>
        ) : (
          <div className="table-container">
            <table className="announcement-table">
              <thead><tr><th>アップロード画像</th><th>タイトル</th><th>カテゴリ</th><th>トップ表示</th><th>画像URL</th><th>登録日</th><th>編集</th><th>削除</th></tr></thead>
              <tbody>{items.map((item) => (
                <tr key={item.id}>
                  <td><Image alt={item.title} className="announcement-thumbnail" height={48} src={item.asset.public_url} unoptimized width={88} /></td>
                  <td><strong>{item.title}</strong></td><td>{item.category.name}</td><td>{item.show_on_top ? <span>ON<br /><small>{item.link_url}</small></span> : "OFF"}</td>
                  <td><div className="announcement-form-actions"><code>{item.asset.public_url}</code><button aria-label={`${item.title}の画像URLをコピー`} className="icon-button" onClick={() => void navigator.clipboard.writeText(item.asset.public_url)} title="URLをコピー" type="button"><Clipboard aria-hidden="true" size={16} /></button><a aria-label={`${item.title}の画像を新しいタブで開く`} className="icon-button" href={item.asset.public_url} rel="noreferrer" target="_blank" title="画像を開く"><ExternalLink aria-hidden="true" size={16} /></a></div></td>
                  <td>{formatJst(item.created_at)}</td>
                  <td>{canManage ? <button aria-label={`${item.title}を編集`} className="icon-button" onClick={() => setEditing(item)} title="編集" type="button"><Pencil aria-hidden="true" size={16} /></button> : <span className="muted-text">参照のみ</span>}</td>
                  <td>{canManage ? <button aria-label={`${item.title}を削除`} className="icon-button" onClick={() => setDeleting(item)} title="削除" type="button"><Trash2 aria-hidden="true" size={16} /></button> : <span className="muted-text">参照のみ</span>}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        )}
        <div className="announcement-pagination" aria-label="ページ操作">
          <button className="secondary-button" disabled={cursorStack.length === 0 || loading} onClick={() => { const previous = [...cursorStack]; setLoading(true); setCursor(previous.pop()); setCursorStack(previous); }} type="button"><ChevronLeft aria-hidden="true" size={16} />前へ</button>
          <button className="secondary-button" disabled={!nextCursor || loading} onClick={() => { setLoading(true); setCursorStack((current) => [...current, cursor]); setCursor(nextCursor ?? undefined); }} type="button">次へ<ChevronRight aria-hidden="true" size={16} /></button>
        </div>
      </section>

      {categoryDialog ? <CategoryDialog onClose={() => setCategoryDialog(false)} onCreated={(category) => { setCategories((current) => [...current, category].sort((a, b) => a.name.localeCompare(b.name, "ja"))); setDraft((current) => ({ ...current, categoryId: category.id })); setCategoryDialog(false); }} /> : null}
      {editing ? <EditDialog banner={editing} categories={categories} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); setLoading(true); setReload((value) => value + 1); }} /> : null}
      {deleting ? <DeleteDialog banner={deleting} onClose={() => setDeleting(null)} onDeleted={() => { setDeleting(null); setLoading(true); setReload((value) => value + 1); }} /> : null}
    </main>
  );
}

function CategoryDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (category: AdminBannerCategory) => void }) {
  const [name, setName] = useState(""); const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const close = useDialogFocus(onClose);
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); setBusy(true); setError(null); try { onCreated(await new AdminApiClient().createBannerCategory({ name: name.normalize("NFC").trim() }, crypto.randomUUID())); } catch (reason) { setError(bannerError(reason)); setBusy(false); } }
  return <Dialog closeRef={close} id="banner-category-title" onClose={onClose} title="カテゴリ追加"><form className="announcement-form" onSubmit={submit}>{error ? <div className="form-error" role="alert">{error}</div> : null}<label>カテゴリ名<input autoFocus maxLength={100} required value={name} onChange={(event) => setName(event.target.value)} /></label><div className="announcement-form-actions"><button className="secondary-button" disabled={busy} onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit"><Save aria-hidden="true" size={16} />{busy ? "登録中" : "登録"}</button></div></form></Dialog>;
}

function EditDialog({ banner, categories, onClose, onSaved }: { banner: AdminManagedBanner; categories: AdminBannerCategory[]; onClose: () => void; onSaved: () => void }) {
  const [draft, setDraft] = useState<BannerDraft>({ categoryId: banner.category.id, file: null, linkUrl: banner.link_url ?? "", showOnTop: banner.show_on_top, title: banner.title }); const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const close = useDialogFocus(onClose); const preview = useObjectUrl(draft.file) ?? banner.asset.public_url;
  async function submit(event: FormEvent<HTMLFormElement>) { event.preventDefault(); if (draft.showOnTop && !draft.linkUrl.trim()) { setError("トップ表示時はクリック先URLを入力してください。"); return; } setBusy(true); setError(null); try { const client = new AdminApiClient(); const assetId = draft.file ? (await client.uploadBannerAsset(await fileInput(draft.file), crypto.randomUUID())).id : null; await client.updateManagedBanner(banner.id, { asset_id: assetId, category_id: draft.categoryId, link_url: draft.showOnTop ? draft.linkUrl.normalize("NFC").trim() : null, show_on_top: draft.showOnTop, title: draft.title.normalize("NFC").trim() }, crypto.randomUUID()); onSaved(); } catch (reason) { setError(bannerError(reason)); setBusy(false); } }
  return <Dialog closeRef={close} id="banner-edit-title" onClose={onClose} title="バナー編集"><form className="announcement-form" onSubmit={submit}>{error ? <div className="form-error" role="alert">{error}</div> : null}<label>カテゴリ<select required value={draft.categoryId} onChange={(event) => setDraft({ ...draft, categoryId: event.target.value })}>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label><label>タイトル<input maxLength={191} required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label><label>画像（変更する場合のみ）<input accept="image/gif,image/jpeg,image/png,image/webp" type="file" onChange={(event) => setDraft({ ...draft, file: event.target.files?.[0] ?? null })} /></label><Image alt="編集するバナー画像" height={120} src={preview} unoptimized width={320} /><label className="check-row"><input checked={draft.showOnTop} onChange={(event) => setDraft({ ...draft, linkUrl: event.target.checked ? draft.linkUrl : "", showOnTop: event.target.checked })} type="checkbox" /><span>トップに表示</span></label>{draft.showOnTop ? <label>クリック先URL<input maxLength={2048} required value={draft.linkUrl} onChange={(event) => setDraft({ ...draft, linkUrl: event.target.value })} /></label> : null}<div className="announcement-form-actions"><button className="secondary-button" disabled={busy} onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit"><Save aria-hidden="true" size={16} />{busy ? "更新中" : "更新"}</button></div></form></Dialog>;
}

function DeleteDialog({ banner, onClose, onDeleted }: { banner: AdminManagedBanner; onClose: () => void; onDeleted: () => void }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState<string | null>(null); const close = useDialogFocus(onClose);
  async function remove() { setBusy(true); setError(null); try { await new AdminApiClient().deleteManagedBanner(banner.id, crypto.randomUUID()); onDeleted(); } catch (reason) { setError(bannerError(reason)); setBusy(false); } }
  return <Dialog closeRef={close} id="banner-delete-title" onClose={onClose} title="バナー削除">{error ? <div className="form-error" role="alert">{error}</div> : null}<p>「{banner.title}」を一覧から削除します。Versionと共有画像Assetは保持されます。</p><Image alt={banner.title} height={100} src={banner.asset.public_url} unoptimized width={240} /><div className="announcement-form-actions"><button className="secondary-button" disabled={busy} onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} onClick={() => void remove()} type="button"><Trash2 aria-hidden="true" size={16} />{busy ? "削除中" : "削除"}</button></div></Dialog>;
}

function Dialog({ children, closeRef, id, onClose, title }: { children: ReactNode; closeRef: RefObject<HTMLButtonElement | null>; id: string; onClose: () => void; title: string }) {
  return <div className="dialog-backdrop" role="presentation"><section aria-labelledby={id} aria-modal="true" className="dialog-panel" role="dialog"><header><h2 id={id}>{title}</h2><button aria-label={`${title}を閉じる`} className="icon-button" onClick={onClose} ref={closeRef} type="button"><X aria-hidden="true" size={18} /></button></header>{children}</section></div>;
}

function useDialogFocus(onClose: () => void) {
  const close = useRef<HTMLButtonElement>(null);
  useEffect(() => { const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null; close.current?.focus(); return () => previous?.focus(); }, []);
  useEffect(() => { const handler = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); }; window.addEventListener("keydown", handler); return () => window.removeEventListener("keydown", handler); }, [onClose]);
  return close;
}

function useObjectUrl(file: File | null): string | null { const url = useMemo(() => file ? URL.createObjectURL(file) : null, [file]); useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]); return url; }
async function fileInput(file: File) { const content = new Uint8Array(await file.arrayBuffer()); let binary = ""; for (const byte of content) binary += String.fromCharCode(byte); return { content_base64: btoa(binary), file_name: file.name, mime_type: file.type as "image/gif" | "image/jpeg" | "image/png" | "image/webp" }; }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function bannerError(reason: unknown): string { if (reason instanceof AdminApiError) { if (reason.status === 409) return "同名カテゴリ、再利用Key、または別更新と競合しました。最新状態を再取得してください。"; if (reason.status === 422) return "入力内容、画像形式、画像サイズを確認してください。"; if (reason.status === 429) return "操作回数が上限に達しました。時間を置いて再試行してください。"; if (reason.status === 403) return "この操作を行う権限がありません。"; } return "バナーを処理できませんでした。再試行してください。"; }
