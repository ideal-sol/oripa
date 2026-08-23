"use client";

import {
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  Eye,
  LoaderCircle,
  Pencil,
  Plus,
  RefreshCw,
  Save,
  X,
} from "lucide-react";
import Link from "next/link";
import Image from "next/image";
import { useRouter } from "next/navigation";
import {
  type FormEvent,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import {
  AdminApiClient,
  AdminApiError,
} from "@/lib/admin-api/client";
import type {
  AdminCatalogPresentationAsset,
  AdminContentDetail,
  AdminContentPreview,
  AdminContentSummary,
  AdminContentVersion,
  AdminContentVersionInput,
} from "@/lib/admin-api/generated";

type Mode = "list" | "create" | "edit";
type Publication = "draft" | "published";

interface Draft {
  assetId: string;
  bodyHtml: string;
  isImportant: boolean;
  publishEndAt: string;
  publishStartAt: string;
  publication: Publication;
  title: string;
}

const INITIAL_DRAFT: Draft = {
  assetId: "",
  bodyHtml: "",
  isImportant: false,
  publishEndAt: "",
  publishStartAt: currentJstMinute(),
  publication: "draft",
  title: "",
};

export function AnnouncementManagementWorkspace({
  announcementId,
  initialStatus = "published,draft",
  mode,
}: {
  announcementId?: string;
  initialStatus?: "all" | "archived" | "draft" | "published" | "published,draft";
  mode: Mode;
}) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission={mode === "list" ? "content.read" : "content.manage"}>
        {mode === "list" ? (
          <AnnouncementList initialStatus={initialStatus} />
        ) : (
          <AnnouncementEditor announcementId={announcementId} mode={mode} />
        )}
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function AnnouncementList({ initialStatus }: { initialStatus: "all" | "archived" | "draft" | "published" | "published,draft" }) {
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const [items, setItems] = useState<AdminContentSummary[]>([]);
  const [assets, setAssets] = useState<AdminCatalogPresentationAsset[]>([]);
  const [cursor, setCursor] = useState<string | undefined>();
  const [cursorStack, setCursorStack] = useState<(string | undefined)[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const [preview, setPreview] = useState<AdminContentPreview | null>(null);
  const [status, setStatus] = useState(initialStatus);

  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listContentNotices({ cursor, status: status === "all" ? undefined : status }, controller.signal),
      client.listCatalogPresentationAssets(
        { direction: "asc", limit: 100, media_type: "image", sort: "created_at" },
        controller.signal,
      ),
    ])
      .then(([response, assetResponse]) => {
        setItems(response.items);
        setNextCursor(response.next_cursor);
        setAssets(assetResponse.items);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(contentError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [cursor, reload, status]);

  const assetPaths = useMemo(
    () => new Map(assets.map((asset) => [asset.id, asset.public_path])),
    [assets],
  );

  return (
    <main className="workspace announcement-workspace">
      <AdminPageHeader
        eyebrow="Content"
        title="お知らせ一覧"
        description="公開期間と表示状態を確認し、お知らせを登録・編集します。"
        action={
          canManage ? (
            <Link className="primary-button" href="/announcements/new">
              <Plus aria-hidden="true" size={17} />
              お知らせ登録
            </Link>
          ) : null
        }
      />

      <label className="announcement-filter">
        公開状態
        <select value={status} onChange={(event) => { setLoading(true); setStatus(event.target.value as typeof status); setCursor(undefined); setCursorStack([]); }}>
          <option value="published,draft">公開 + 下書き</option>
          <option value="published">公開</option>
          <option value="draft">下書き</option>
          <option value="archived">アーカイブ</option>
          <option value="all">すべて</option>
        </select>
      </label>

      {error ? (
        <section className="module-state is-error" role="alert">
          <h2>お知らせを取得できませんでした</h2>
          <p>{error}</p>
          <button className="secondary-button" onClick={() => { setLoading(true); setError(null); setReload((value) => value + 1); }} type="button">
            <RefreshCw aria-hidden="true" size={16} />再取得
          </button>
        </section>
      ) : loading ? (
        <section className="module-state" aria-live="polite">
          <LoaderCircle className="spin" aria-hidden="true" size={22} />
          <p>お知らせを読み込んでいます。</p>
        </section>
      ) : items.length === 0 ? (
        <section className="module-state">
          <h2>登録済みのお知らせはありません</h2>
          <p>必要な場合は「お知らせ登録」から下書きを作成してください。</p>
        </section>
      ) : (
        <section className="announcement-table-section" aria-label="お知らせ一覧">
          <div className="table-container">
            <table className="announcement-table">
              <thead><tr><th>ID</th><th>サムネイル</th><th>カテゴリ</th><th>タイトル</th><th>公開状態</th><th>公開開始日時</th><th>公開終了日時</th><th>更新日時</th><th>プレビュー</th><th>編集</th></tr></thead>
              <tbody>
                {items.map((item) => {
                  const version = item.latest_version;
                  const thumbnail = version?.asset_id ? assetPaths.get(version.asset_id) : null;
                  return (
                    <tr key={item.id}>
                      <td><code className="announcement-public-id">{item.id}</code></td>
                      <td>{thumbnail ? <Image alt="" className="announcement-thumbnail" height={38} src={thumbnail} unoptimized width={52} /> : <span className="muted-text">未設定</span>}</td>
                      <td>お知らせ</td>
                      <td><strong>{version?.title ?? "未設定"}</strong>{version?.is_important ? <small className="announcement-important">トップ表示</small> : null}</td>
                      <td><StatusBadge label={publicationLabel(item)} /></td>
                      <td>{formatJst(version?.publish_start_at)}</td>
                      <td>{formatJst(version?.publish_end_at)}</td>
                      <td>{formatJst(item.updated_at)}</td>
                      <td><button aria-label={`${version?.title ?? "お知らせ"}をプレビュー`} className="icon-button" disabled={!version} onClick={() => version && setPreview(previewFromVersion(version))} title="プレビュー" type="button"><Eye aria-hidden="true" size={17} /></button></td>
                      <td>{canManage ? <Link aria-label={`${version?.title ?? "お知らせ"}を編集`} className="icon-button" href={`/announcements/${item.id}`} title="編集"><Pencil aria-hidden="true" size={17} /></Link> : <span className="muted-text">参照のみ</span>}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <div className="announcement-pagination" aria-label="ページ操作">
            <button className="secondary-button" disabled={cursorStack.length === 0} onClick={() => { const previous = [...cursorStack]; setLoading(true); setError(null); setCursor(previous.pop()); setCursorStack(previous); }} type="button"><ChevronLeft aria-hidden="true" size={16} />前へ</button>
            <button className="secondary-button" disabled={!nextCursor} onClick={() => { setLoading(true); setError(null); setCursorStack((current) => [...current, cursor]); setCursor(nextCursor ?? undefined); }} type="button">次へ<ChevronRight aria-hidden="true" size={16} /></button>
          </div>
        </section>
      )}
      {preview ? <AnnouncementPreview preview={preview} onClose={() => setPreview(null)} /> : null}
    </main>
  );
}

function AnnouncementEditor({ announcementId, mode }: { announcementId?: string; mode: "create" | "edit" }) {
  const router = useRouter();
  const [draft, setDraft] = useState<Draft>(INITIAL_DRAFT);
  const [current, setCurrent] = useState<AdminContentDetail | null>(null);
  const [assets, setAssets] = useState<AdminCatalogPresentationAsset[]>([]);
  const [loading, setLoading] = useState(mode === "edit");
  const [submitting, setSubmitting] = useState(false);
  const [previewing, setPreviewing] = useState(false);
  const [preview, setPreview] = useState<AdminContentPreview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => heading.current?.focus(), []);
  useEffect(() => {
    const controller = new AbortController();
    const client = new AdminApiClient();
    const detail = mode === "edit" && announcementId
      ? client.getContentNotice(announcementId, controller.signal)
      : Promise.resolve(null);
    Promise.all([
      detail,
      client.listCatalogPresentationAssets(
        { direction: "asc", limit: 100, media_type: "image", sort: "created_at" },
        controller.signal,
      ),
    ])
      .then(([response, assetResponse]) => {
        setAssets(assetResponse.items.filter((asset) => !asset.is_archived));
        if (response) {
          const version = response.versions[0];
          if (!version) throw new Error("CONTENT_VERSION_NOT_FOUND");
          setCurrent(response);
          setDraft(draftFromDetail(response, version));
        }
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(contentError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [announcementId, mode]);

  const input = useMemo(() => versionInput(draft), [draft]);

  async function showPreview() {
    const validation = validate(draft);
    setFieldErrors(validation);
    if (Object.keys(validation).length) return;
    setPreviewing(true);
    setError(null);
    try {
      setPreview(await new AdminApiClient().previewContentNotice(input));
    } catch (reason) {
      setError(contentError(reason));
    } finally {
      setPreviewing(false);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const validation = validate(draft);
    setFieldErrors(validation);
    if (Object.keys(validation).length) return;
    setSubmitting(true);
    setError(null);
    const client = new AdminApiClient();
    try {
      if (mode === "create") {
        const result = await client.createContentNotice(
          { ...input, slug: `notice-${crypto.randomUUID()}` },
          crypto.randomUUID(),
        );
        const version = result.versions[0];
        if (draft.publication === "published" && version) {
          await client.publishContentNotice(result.id, version.id);
        }
        router.push(`/announcements/${result.id}`);
      } else if (announcementId && current) {
        const version = await client.createContentNoticeVersion(
          announcementId,
          input,
          crypto.randomUUID(),
        );
        if (draft.publication === "published") {
          await client.publishContentNotice(announcementId, version.id);
        } else if (current.status === "published") {
          await client.unpublishContentNotice(announcementId);
        }
        router.refresh();
        router.push(`/announcements/${announcementId}`);
      }
    } catch (reason) {
      setError(contentError(reason));
    } finally {
      setSubmitting(false);
    }
  }

  if (loading) return <main className="workspace"><section className="module-state" aria-live="polite"><LoaderCircle className="spin" aria-hidden="true" size={22} /><p>お知らせを読み込んでいます。</p></section></main>;

  return (
    <main className="workspace announcement-workspace">
      <AdminPageHeader
        eyebrow="Content"
        title={mode === "create" ? "お知らせ登録" : "お知らせ編集"}
        description="本文は保存時とプレビュー時にServer側で安全化されます。"
        action={<Link className="secondary-button" href="/announcements"><ArrowLeft aria-hidden="true" size={16} />一覧へ戻る</Link>}
      />
      {error ? <div className="form-error" role="alert">{error}</div> : null}
      <form className="announcement-form" onSubmit={submit}>
        <h2 ref={heading} tabIndex={-1}>掲載内容</h2>
        <label>タイトル<input maxLength={191} required value={draft.title} onChange={(event) => setDraft({ ...draft, title: event.target.value })} /></label>
        <FieldError message={fieldErrors.title} />
        <label>本文（HTML）<textarea required rows={12} value={draft.bodyHtml} onChange={(event) => setDraft({ ...draft, bodyHtml: event.target.value })} /></label>
        <FieldError message={fieldErrors.body} />
        <label>サムネイル<select value={draft.assetId} onChange={(event) => setDraft({ ...draft, assetId: event.target.value })}><option value="">未設定</option>{assets.map((asset) => <option key={asset.id} value={asset.id}>{asset.alt_text ?? asset.public_path ?? asset.id}</option>)}</select></label>
        <label className="check-row"><input checked={draft.isImportant} onChange={(event) => setDraft({ ...draft, isImportant: event.target.checked })} type="checkbox" /><span>トップのお知らせ一覧で重要表示する</span></label>
        <div className="announcement-form-grid">
          <label>公開状態<select value={draft.publication} onChange={(event) => setDraft({ ...draft, publication: event.target.value as Publication })}><option value="draft">下書き</option><option value="published">公開</option></select></label>
          <label>公開開始日時（Asia/Tokyo）<input required type="datetime-local" value={draft.publishStartAt} onChange={(event) => setDraft({ ...draft, publishStartAt: event.target.value })} /></label>
          <label>公開終了日時（Asia/Tokyo）<input type="datetime-local" value={draft.publishEndAt} onChange={(event) => setDraft({ ...draft, publishEndAt: event.target.value })} /></label>
        </div>
        <FieldError message={fieldErrors.period} />
        <div className="announcement-form-actions">
          <button className="secondary-button" disabled={previewing || submitting} onClick={() => void showPreview()} type="button"><Eye aria-hidden="true" size={17} />{previewing ? "生成中" : "プレビュー"}</button>
          <button className="primary-button" disabled={submitting} type="submit">{submitting ? <LoaderCircle className="spin" aria-hidden="true" size={17} /> : <Save aria-hidden="true" size={17} />}{mode === "create" ? "登録" : "更新"}</button>
        </div>
      </form>
      {preview ? <AnnouncementPreview preview={preview} onClose={() => setPreview(null)} /> : null}
    </main>
  );
}

function AnnouncementPreview({ preview, onClose }: { preview: AdminContentPreview; onClose: () => void }) {
  const close = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    const previous = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    close.current?.focus();
    return () => previous?.focus();
  }, []);
  useEffect(() => {
    const handler = (event: KeyboardEvent) => { if (event.key === "Escape") onClose(); };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, [onClose]);
  return (
    <div className="dialog-backdrop" role="presentation">
      <section aria-labelledby="announcement-preview-title" aria-modal="true" className="dialog-panel announcement-preview" role="dialog">
        <header><div><span>お知らせプレビュー</span><h2 id="announcement-preview-title">{preview.title}</h2></div><button aria-label="プレビューを閉じる" className="icon-button" onClick={onClose} ref={close} type="button"><X aria-hidden="true" size={18} /></button></header>
        <dl><div><dt>カテゴリ</dt><dd>お知らせ</dd></div><div><dt>公開期間</dt><dd>{formatJst(preview.publish_start_at)} - {formatJst(preview.publish_end_at)}</dd></div><div><dt>表示</dt><dd>{preview.is_important ? "重要表示" : "通常表示"}</dd></div></dl>
        <article className="announcement-preview-body" dangerouslySetInnerHTML={{ __html: preview.body_html }} />
      </section>
    </div>
  );
}

function StatusBadge({ label }: { label: string }) {
  return <span className={`status-badge status-${label === "公開" ? "active" : "neutral"}`}>{label}</span>;
}

function FieldError({ message }: { message?: string }) {
  return message ? <p className="form-field-error" role="alert">{message}</p> : null;
}

function versionInput(draft: Draft): AdminContentVersionInput {
  return {
    asset_id: draft.assetId || null,
    body_html: draft.bodyHtml,
    is_important: draft.isImportant,
    publish_end_at: draft.publishEndAt ? jstInputToIso(draft.publishEndAt) : null,
    publish_start_at: jstInputToIso(draft.publishStartAt),
    sort_order: 0,
    summary: null,
    title: draft.title.normalize("NFC").trim(),
  };
}

function validate(draft: Draft): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!draft.title.trim() || draft.title.trim().length > 191) errors.title = "タイトルは1文字以上191文字以内で入力してください。";
  if (!draft.bodyHtml.trim() || draft.bodyHtml.length > 100_000) errors.body = "本文は1文字以上100,000文字以内で入力してください。";
  if (!draft.publishStartAt) errors.period = "公開開始日時を入力してください。";
  if (draft.publishEndAt && jstInputToIso(draft.publishEndAt) <= jstInputToIso(draft.publishStartAt)) errors.period = "公開終了日時は公開開始日時より後にしてください。";
  return errors;
}

function draftFromDetail(detail: AdminContentDetail, version: AdminContentVersion): Draft {
  return {
    assetId: version.asset_id ?? "",
    bodyHtml: version.body_html ?? "",
    isImportant: version.is_important,
    publishEndAt: isoToJstInput(version.publish_end_at),
    publishStartAt: isoToJstInput(version.publish_start_at),
    publication: detail.status === "published" ? "published" : "draft",
    title: version.title,
  };
}

function previewFromVersion(version: AdminContentVersion): AdminContentPreview {
  return {
    asset_id: version.asset_id,
    body_html: version.body_html ?? "",
    is_important: version.is_important,
    publish_end_at: version.publish_end_at,
    publish_start_at: version.publish_start_at,
    summary: version.summary,
    title: version.title,
  };
}

function publicationLabel(item: AdminContentSummary): string {
  if (item.status === "archived") return "アーカイブ";
  if (item.status !== "published" || !item.latest_version) return "下書き";
  const now = Date.now();
  if (new Date(item.latest_version.publish_start_at).getTime() > now) return "公開予約";
  if (item.latest_version.publish_end_at && new Date(item.latest_version.publish_end_at).getTime() <= now) return "公開終了";
  return "公開";
}

function formatJst(value?: string | null): string {
  if (!value) return "設定なし";
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}

function jstInputToIso(value: string): string {
  return new Date(`${value}:00+09:00`).toISOString();
}

function isoToJstInput(value: string | null): string {
  if (!value) return "";
  const parts = new Intl.DateTimeFormat("en-CA", { day: "2-digit", hour: "2-digit", hour12: false, minute: "2-digit", month: "2-digit", timeZone: "Asia/Tokyo", year: "numeric" }).formatToParts(new Date(value));
  const get = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? "";
  return `${get("year")}-${get("month")}-${get("day")}T${get("hour")}:${get("minute")}`;
}

function currentJstMinute(): string {
  return isoToJstInput(new Date().toISOString());
}

function contentError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 409) return "別の更新と競合しました。最新状態を再取得してください。";
    if (reason.status === 429) return "操作回数が上限に達しました。時間を置いて再試行してください。";
    if (reason.status === 403) return "この操作を行う権限または新しい認証がありません。";
    if (reason.status === 422) return "入力内容を確認してください。";
  }
  return "お知らせを処理できませんでした。再試行してください。";
}
