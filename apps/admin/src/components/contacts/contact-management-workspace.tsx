"use client";

import {
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  LoaderCircle,
  Mail,
  RefreshCw,
  Save,
  Search,
} from "lucide-react";
import Link from "next/link";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminContactDetail,
  AdminContactStatus,
  AdminContactSummary,
} from "@/lib/admin-api/generated";

type Mode = "list" | "detail";

const STATUS_OPTIONS: Array<{ label: string; value: AdminContactStatus | "all" }> = [
  { label: "すべて", value: "all" },
  { label: "未対応", value: "new" },
  { label: "対応中", value: "in_progress" },
  { label: "返信済み", value: "replied" },
  { label: "完了", value: "closed" },
];

const NEXT_STATUS: Record<AdminContactStatus, AdminContactStatus[]> = {
  new: ["in_progress", "replied", "closed"],
  in_progress: ["replied", "closed"],
  replied: ["closed"],
  closed: [],
};

export function ContactManagementWorkspace({
  contactId,
  initialStatus = "new",
  mode,
}: {
  contactId?: string;
  initialStatus?: AdminContactStatus | "all";
  mode: Mode;
}) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="contact.read">
        {mode === "list" ? <ContactList initialStatus={initialStatus} /> : <ContactDetail contactId={contactId ?? ""} key={contactId} />}
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function ContactList({ initialStatus }: { initialStatus: AdminContactStatus | "all" }) {
  const [items, setItems] = useState<AdminContactSummary[]>([]);
  const [cursor, setCursor] = useState<string | undefined>();
  const [cursorStack, setCursorStack] = useState<(string | undefined)[]>([]);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [status, setStatus] = useState<AdminContactStatus | "all">(initialStatus);
  const [email, setEmail] = useState("");
  const [appliedEmail, setAppliedEmail] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [reload, setReload] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient()
      .listContactInquiries(
        {
          cursor,
          email: appliedEmail || undefined,
          status: status === "all" ? undefined : status,
        },
        controller.signal,
      )
      .then((response) => {
        setItems(response.items);
        setNextCursor(response.next_cursor);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(contactError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [appliedEmail, cursor, reload, status]);

  function submitFilter(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(null);
    setCursor(undefined);
    setCursorStack([]);
    setAppliedEmail(email.trim());
    setReload((value) => value + 1);
  }

  return (
    <main className="workspace contact-workspace">
      <AdminPageHeader
        eyebrow="Support"
        title="お問い合わせ一覧"
        description="受付内容と対応状態を確認します。メール検索は完全一致です。"
      />
      <form className="contact-filter" onSubmit={submitFilter}>
        <label>
          <span>状態</span>
          <select
            aria-label="状態"
            value={status}
            onChange={(event) => {
              setLoading(true);
              setError(null);
              setCursor(undefined);
              setCursorStack([]);
              setStatus(event.target.value as AdminContactStatus | "all");
            }}
          >
            {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select>
        </label>
        <label>
          <span>メール</span>
          <input
            inputMode="email"
            placeholder="user@example.com"
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
          />
        </label>
        <button className="secondary-button" type="submit"><Search aria-hidden="true" size={16} />検索</button>
      </form>

      {error ? (
        <section className="module-state is-error" role="alert">
          <h2>お問い合わせを取得できませんでした</h2>
          <p>{error}</p>
          <button className="secondary-button" onClick={() => { setLoading(true); setError(null); setReload((value) => value + 1); }} type="button"><RefreshCw aria-hidden="true" size={16} />再取得</button>
        </section>
      ) : loading ? (
        <section className="module-state" aria-live="polite"><LoaderCircle className="spin" aria-hidden="true" size={22} /><p>お問い合わせを読み込んでいます。</p></section>
      ) : items.length === 0 ? (
        <section className="module-state"><h2>該当するお問い合わせはありません</h2><p>検索条件を変更して再確認してください。</p></section>
      ) : (
        <section aria-label="お問い合わせ一覧" className="contact-table-section">
          <div className="table-container">
            <table className="contact-table">
              <thead><tr><th>ID</th><th>氏名</th><th>メール</th><th>電話番号</th><th>状態</th><th>受付日時</th><th>詳細</th></tr></thead>
              <tbody>{items.map((item) => (
                <tr key={item.id}>
                  <td><strong>{item.receipt_code}</strong><code>{item.id}</code></td>
                  <td><strong>{item.name ?? "未設定"}</strong><small>{item.body_excerpt ?? ""}</small></td>
                  <td>{item.email ?? "未設定"}</td>
                  <td>{item.phone ?? "未設定"}</td>
                  <td><ContactStatusBadge status={item.status} /></td>
                  <td>{formatJst(item.received_at)}</td>
                  <td><Link className="secondary-button compact-button" href={`/contacts/${item.id}`}>詳細</Link></td>
                </tr>
              ))}</tbody>
            </table>
          </div>
          <div aria-label="ページ操作" className="contact-pagination">
            <button className="secondary-button" disabled={cursorStack.length === 0} onClick={() => { const previous = [...cursorStack]; setLoading(true); setError(null); setCursor(previous.pop()); setCursorStack(previous); }} type="button"><ChevronLeft aria-hidden="true" size={16} />前へ</button>
            <button className="secondary-button" disabled={!nextCursor} onClick={() => { setLoading(true); setError(null); setCursorStack((current) => [...current, cursor]); setCursor(nextCursor ?? undefined); }} type="button">次へ<ChevronRight aria-hidden="true" size={16} /></button>
          </div>
        </section>
      )}
    </main>
  );
}

function ContactDetail({ contactId }: { contactId: string }) {
  const { permissions } = usePermissions();
  const canManage = permissions.has("contact.manage");
  const [contact, setContact] = useState<AdminContactDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [reply, setReply] = useState("");
  const [nextStatus, setNextStatus] = useState<AdminContactStatus | "">("");
  const [reload, setReload] = useState(0);
  const heading = useRef<HTMLHeadingElement>(null);

  useEffect(() => heading.current?.focus(), []);
  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().getContactInquiry(contactId, controller.signal)
      .then((response) => {
        setContact(response);
        setNextStatus(NEXT_STATUS[response.status][0] ?? "");
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(contactError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [contactId, reload]);

  async function submitReply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const message = reply.trim();
    if (!message || message.length > 5000) {
      setError("返信内容は1文字以上5,000文字以内で入力してください。");
      return;
    }
    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const result = await new AdminApiClient().requestContactInquiryReply(
        contactId,
        { message },
        crypto.randomUUID(),
      );
      setReply("");
      setSuccess(result.idempotent_replay ? "既存の返信要求を再取得しました。" : "返信要求を記録しました。");
      setLoading(true);
      setReload((value) => value + 1);
    } catch (reason) {
      setError(contactError(reason));
    } finally {
      setSaving(false);
    }
  }

  async function submitStatus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!nextStatus) return;
    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const result = await new AdminApiClient().updateContactInquiryStatus(
        contactId,
        { reason_code: `admin_marked_${nextStatus}`, status: nextStatus },
        crypto.randomUUID(),
      );
      setSuccess(result.idempotent_replay ? "既存の状態更新を再取得しました。" : "対応状態を更新しました。");
      setLoading(true);
      setReload((value) => value + 1);
    } catch (reason) {
      setError(contactError(reason));
    } finally {
      setSaving(false);
    }
  }

  if (loading) return <main className="workspace"><section className="module-state" aria-live="polite"><LoaderCircle className="spin" aria-hidden="true" size={22} /><p>お問い合わせ詳細を読み込んでいます。</p></section></main>;
  if (!contact) return <main className="workspace"><section className="module-state is-error" role="alert"><h1>お問い合わせを表示できません</h1><p>{error ?? "対象データが見つかりません。"}</p><Link className="secondary-button" href="/contacts">一覧へ戻る</Link></section></main>;

  const history = buildHistory(contact);

  return (
    <main className="workspace contact-workspace">
      <AdminPageHeader
        eyebrow="Support"
        title="お問い合わせ詳細・返信"
        description={`${contact.receipt_code} の受付内容と対応履歴です。`}
        action={<Link className="secondary-button" href="/contacts"><ArrowLeft aria-hidden="true" size={16} />一覧へ戻る</Link>}
      />
      <h2 className="sr-only" ref={heading} tabIndex={-1}>お問い合わせ詳細</h2>
      {error ? <div className="form-error" role="alert">{error}</div> : null}
      {success ? <div className="form-success" role="status">{success}</div> : null}

      <section className="contact-detail-card" aria-labelledby="contact-summary-title">
        <div className="contact-detail-heading"><div><span>お問い合わせID</span><h2 id="contact-summary-title">{contact.receipt_code}</h2><code>{contact.id}</code></div><ContactStatusBadge status={contact.status} /></div>
        <dl className="contact-detail-list">
          <div><dt>氏名</dt><dd>{contact.name}</dd></div>
          <div><dt>メール</dt><dd>{contact.email}</dd></div>
          <div><dt>電話番号</dt><dd>{contact.phone ?? "未設定"}</dd></div>
          <div><dt>件名</dt><dd>{contact.subject}</dd></div>
          <div><dt>受付日時</dt><dd>{formatJst(contact.received_at)}</dd></div>
          <div><dt>更新日時</dt><dd>{formatJst(contact.updated_at)}</dd></div>
        </dl>
        <div className="contact-message-box"><h3>お問い合わせ内容</h3><p>{contact.body}</p></div>
      </section>

      {canManage ? (
        <div className="contact-actions-grid">
          <form className="contact-action-card" onSubmit={submitReply}>
            <div><Mail aria-hidden="true" size={20} /><h2>返信内容</h2></div>
            <p>既存Outboxへ返信要求を記録します。送信完了を推測表示しません。</p>
            <label><span>返信内容</span><textarea maxLength={5000} required rows={7} value={reply} onChange={(event) => setReply(event.target.value)} /></label>
            <button className="primary-button" disabled={saving} type="submit">{saving ? <LoaderCircle className="spin" aria-hidden="true" size={17} /> : <Mail aria-hidden="true" size={17} />}返信要求を保存</button>
          </form>
          <form className="contact-action-card" onSubmit={submitStatus}>
            <div><Save aria-hidden="true" size={20} /><h2>対応状態</h2></div>
            {NEXT_STATUS[contact.status].length ? (
              <>
                <label><span>次の状態</span><select value={nextStatus} onChange={(event) => setNextStatus(event.target.value as AdminContactStatus)}>{NEXT_STATUS[contact.status].map((value) => <option key={value} value={value}>{statusLabel(value)}</option>)}</select></label>
                <button className="secondary-button" disabled={saving || !nextStatus} type="submit"><Save aria-hidden="true" size={17} />状態を更新</button>
              </>
            ) : <p className="muted-text">完了済みのため変更できません。</p>}
          </form>
        </div>
      ) : <section className="module-state compact"><p>このアカウントは参照のみです。</p></section>}

      <section className="contact-history" aria-labelledby="contact-history-title">
        <h2 id="contact-history-title">対応履歴</h2>
        {history.length ? <ol>{history.map((item) => <li key={item.key}><span>{formatJst(item.at)}</span><strong>{item.title}</strong><p>{item.detail}</p></li>)}</ol> : <div className="module-state compact"><p>対応履歴はありません。</p></div>}
      </section>
    </main>
  );
}

function buildHistory(contact: AdminContactDetail) {
  return [
    ...contact.status_history.map((item, index) => ({
      at: item.occurred_at,
      detail: item.from_status ? `${statusLabel(item.from_status)}から${statusLabel(item.to_status)}へ変更` : "お問い合わせを受け付けました。",
      key: `status-${index}-${item.occurred_at}`,
      title: statusLabel(item.to_status),
    })),
    ...(contact.reply_requests ?? []).map((item) => ({
      at: item.created_at,
      detail: item.message,
      key: `reply-${item.id}`,
      title: "返信要求",
    })),
  ].sort((left, right) => left.at.localeCompare(right.at));
}

function ContactStatusBadge({ status }: { status: AdminContactStatus }) {
  const label = statusLabel(status);
  return <span aria-label={`状態: ${label}`} className={`status-badge contact-status-${status}`}>{label}</span>;
}

function statusLabel(status: AdminContactStatus): string {
  return { closed: "完了", in_progress: "対応中", new: "未対応", replied: "返信済み" }[status];
}

function formatJst(value?: string | null): string {
  if (!value) return "未設定";
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}

function contactError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 409) return "別の更新と競合しました。最新状態を再取得してください。";
    if (reason.status === 429) return "操作回数が上限に達しました。時間を置いて再試行してください。";
    if (reason.status === 403) return "この操作を行う権限または新しい認証がありません。";
    if (reason.status === 404) return "対象のお問い合わせが見つかりません。";
    if (reason.status === 422) return "入力内容を確認してください。";
  }
  return "お問い合わせを処理できませんでした。再試行してください。";
}
