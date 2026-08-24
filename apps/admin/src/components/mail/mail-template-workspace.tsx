"use client";

import { ArrowLeft, Eye, LoaderCircle, Pencil, Save } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useEffect, useRef, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import {
  RichTextEditor,
  type RichTextEditorHandle,
} from "@/components/rich-text/rich-text-editor";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminMailTemplate,
  AdminMailTemplateVariable,
  MailTemplateKey,
} from "@/lib/admin-api/generated";

const TEMPLATE_KEYS = new Set<MailTemplateKey>([
  "email_verification",
  "registration_completed",
  "coin_purchase_completed",
  "shipping_requested",
  "shipping_completed",
  "user_closed",
  "contact_received",
]);

const PREVIEW_TAGS = new Set([
  "a", "br", "em", "h1", "h2", "h3", "hr", "img", "li", "ol", "p", "s",
  "strong", "table", "tbody", "td", "th", "thead", "tr", "u", "ul",
]);

export function MailTemplateWorkspace({ templateKey }: { templateKey?: string }) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="content.read">
        {templateKey ? (
          isMailTemplateKey(templateKey)
            ? <MailTemplateEditor templateKey={templateKey} />
            : <main className="workspace"><ErrorState text="メールTemplateが見つかりません。" /></main>
        ) : <MailTemplateList />}
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function MailTemplateList() {
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const [templates, setTemplates] = useState<AdminMailTemplate[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().listMailTemplates(controller.signal)
      .then((result) => {
        setTemplates(result.items);
        setError(null);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(mailTemplateError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, []);

  return (
    <main className="workspace mail-template-workspace">
      <AdminPageHeader eyebrow="Settings" title="メール設定" description="送信イベントごとの件名と本文を管理します。" />
      {loading ? <Loading text="メール設定を読み込んでいます。" /> : error ? <ErrorState text={error} /> : (
        <section aria-label="メールTemplate一覧" className="announcement-table-section">
          <div className="table-container">
            <table className="announcement-table mail-template-table">
              <thead><tr><th>メール種類</th><th>件名</th><th>更新日時</th><th>編集</th></tr></thead>
              <tbody>{templates.map((template) => (
                <tr key={template.key}>
                  <td><strong>{template.label}</strong></td>
                  <td>{template.subject}</td>
                  <td>{formatJst(template.updated_at)}</td>
                  <td>{canManage ? <Link aria-label={`${template.label}を編集`} className="icon-button" href={`/settings/mail/${template.key}`} title="編集"><Pencil aria-hidden="true" size={16} /></Link> : <span className="muted-text">参照のみ</span>}</td>
                </tr>
              ))}</tbody>
            </table>
          </div>
        </section>
      )}
    </main>
  );
}

function MailTemplateEditor({ templateKey }: { templateKey: MailTemplateKey }) {
  const router = useRouter();
  const { permissions } = usePermissions();
  const canManage = permissions.has("content.manage");
  const key = templateKey;
  const subjectRef = useRef<HTMLInputElement>(null);
  const bodyRef = useRef<RichTextEditorHandle>(null);
  const [template, setTemplate] = useState<AdminMailTemplate | null>(null);
  const [subject, setSubject] = useState("");
  const [bodyHtml, setBodyHtml] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [previewing, setPreviewing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().getMailTemplate(key, controller.signal)
      .then((result) => {
        setTemplate(result);
        setSubject(result.subject);
        setBodyHtml(result.body_html);
        setError(null);
      })
      .catch((reason: unknown) => {
        if (!controller.signal.aborted) setError(mailTemplateError(reason));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [key]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!template || !semanticText(subject) || !semanticHtml(bodyHtml)) {
      setError("件名と本文を入力してください。");
      return;
    }
    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const result = await new AdminApiClient().updateMailTemplate(key, {
        body_html: bodyHtml,
        expected_revision: template.revision,
        subject,
      }, crypto.randomUUID());
      setTemplate(result);
      setSubject(result.subject);
      setBodyHtml(result.body_html);
      setSuccess("メールTemplateを保存しました。");
      router.refresh();
    } catch (reason) {
      setError(mailTemplateError(reason));
    } finally {
      setSaving(false);
    }
  }

  async function preview() {
    if (!semanticHtml(bodyHtml)) {
      setError("本文を入力してください。");
      return;
    }
    const target = window.open("about:blank", "_blank");
    if (!target) {
      setError("プレビュー用の別タブを開けませんでした。Popup設定を確認してください。");
      return;
    }
    target.opener = null;
    target.document.title = "メール本文プレビュー - 読み込み中";
    target.document.body.textContent = "プレビューを生成しています。";
    setPreviewing(true);
    setError(null);
    try {
      const result = await new AdminApiClient().previewMailTemplate(key, { body_html: bodyHtml });
      renderMailPreview(target.document, result.body_html);
    } catch (reason) {
      target.close();
      setError(mailTemplateError(reason));
    } finally {
      setPreviewing(false);
    }
  }

  function insertSubjectVariable(token: string) {
    if (!token) return;
    const input = subjectRef.current;
    const start = input?.selectionStart ?? subject.length;
    const end = input?.selectionEnd ?? start;
    setSubject(`${subject.slice(0, start)}${token}${subject.slice(end)}`);
    requestAnimationFrame(() => {
      input?.focus();
      input?.setSelectionRange(start + token.length, start + token.length);
    });
  }

  if (loading) return <main className="workspace"><Loading text="メールTemplateを読み込んでいます。" /></main>;
  if (!template) return <main className="workspace"><ErrorState text={error ?? "メールTemplateが見つかりません。"} /></main>;

  return (
    <main className="workspace mail-template-workspace">
      <AdminPageHeader eyebrow="Settings" title="メールTemplate編集" description={template.label} action={<Link className="secondary-button" href="/settings/mail"><ArrowLeft aria-hidden="true" size={16} />一覧へ戻る</Link>} />
      <form className="announcement-form mail-template-form" onSubmit={submit}>
        {error ? <div className="form-error" role="alert">{error}</div> : null}
        {success ? <div className="form-success" role="status">{success}</div> : null}
        <label>件名<input disabled={!canManage} maxLength={191} ref={subjectRef} required value={subject} onChange={(event) => setSubject(event.target.value)} /></label>
        {canManage ? <VariableSelect label="件名へ変数を挿入" onSelect={insertSubjectVariable} variables={template.variables} /> : null}
        <div className="rich-text-field"><span>本文</span><RichTextEditor disabled={!canManage} label="メール本文" onChange={setBodyHtml} ref={bodyRef} value={bodyHtml} /></div>
        {canManage ? <VariableSelect label="本文へ変数を挿入" onSelect={(token) => bodyRef.current?.insertText(token)} variables={template.variables} /> : null}
        <p className="form-hint">未定義または値がない変数は、プレビューと送信時に空文字へ置換されます。</p>
        <div className="announcement-form-actions">
          <Link className="secondary-button" href="/settings/mail">一覧へ戻る</Link>
          <button className="secondary-button" disabled={previewing || saving} onClick={() => void preview()} type="button"><Eye aria-hidden="true" size={16} />{previewing ? "生成中" : "プレビュー"}</button>
          {canManage ? <button className="primary-button" disabled={saving || previewing} type="submit"><Save aria-hidden="true" size={16} />{saving ? "保存中" : "保存"}</button> : null}
        </div>
      </form>
    </main>
  );
}

function VariableSelect({ label, onSelect, variables }: { label: string; onSelect: (token: string) => void; variables: AdminMailTemplateVariable[] }) {
  return (
    <label className="mail-variable-select">{label}
      <select aria-label={label} onChange={(event) => { onSelect(event.target.value); event.target.value = ""; }} defaultValue="">
        <option disabled value="">変数を挿入 ▼</option>
        {variables.map((variable) => <option key={variable.key} value={variable.token}>{variable.label}</option>)}
      </select>
    </label>
  );
}

function isMailTemplateKey(value: string): value is MailTemplateKey {
  return TEMPLATE_KEYS.has(value as MailTemplateKey);
}

function semanticText(value: string): boolean {
  return value.replace(/[\s\u00a0\u200b-\u200d\ufeff]/gu, "") !== "";
}

function semanticHtml(value: string): boolean {
  const parsed = new DOMParser().parseFromString(value, "text/html");
  if (parsed.body.querySelector("img[src]")) return true;
  return semanticText(parsed.body.textContent ?? "");
}

function renderMailPreview(target: Document, html: string): void {
  const parsed = new DOMParser().parseFromString(html, "text/html");
  const fragment = target.createDocumentFragment();
  for (const child of Array.from(parsed.body.childNodes)) {
    const previewNode = createPreviewNode(target, child);
    if (previewNode) fragment.append(previewNode);
  }
  const referrer = target.createElement("meta");
  referrer.name = "referrer";
  referrer.content = "no-referrer";
  target.documentElement.lang = "ja";
  target.head.replaceChildren(referrer);
  target.title = "メール本文プレビュー";
  target.body.replaceChildren(fragment);
}

function createPreviewNode(target: Document, source: Node): Node | null {
  if (source.nodeType === Node.TEXT_NODE) return target.createTextNode(source.textContent ?? "");
  if (!(source instanceof Element)) return null;
  const tag = source.tagName.toLowerCase();
  if (!PREVIEW_TAGS.has(tag)) return null;
  const element = target.createElement(tag);

  if (tag === "a") copyPreviewLinkAttributes(source, element);
  if (tag === "img" && !copyPreviewImageAttributes(source, element)) return null;
  if (["p", "h1", "h2", "h3"].includes(tag)) copyPreviewAlignment(source, element);
  if (["td", "th"].includes(tag)) copyPreviewTableAttributes(source, element);

  for (const child of Array.from(source.childNodes)) {
    const previewNode = createPreviewNode(target, child);
    if (previewNode) element.append(previewNode);
  }
  return element;
}

function copyPreviewLinkAttributes(source: Element, target: HTMLElement): void {
  const href = source.getAttribute("href")?.trim();
  if (href && isSafePreviewHref(href)) target.setAttribute("href", href);
  const title = source.getAttribute("title");
  if (title) target.setAttribute("title", title);
  if (source.getAttribute("target") === "_blank") {
    target.setAttribute("target", "_blank");
    target.setAttribute("rel", "noopener noreferrer");
  }
}

function copyPreviewImageAttributes(source: Element, target: HTMLElement): boolean {
  const sourceUrl = source.getAttribute("src")?.trim();
  if (!sourceUrl || !isSafePreviewImage(sourceUrl)) return false;
  target.setAttribute("src", sourceUrl);
  for (const attribute of ["alt", "title"]) {
    const value = source.getAttribute(attribute);
    if (value) target.setAttribute(attribute, value);
  }
  return true;
}

function copyPreviewAlignment(source: Element, target: HTMLElement): void {
  const alignment = source.getAttribute("style")?.trim().match(/^text-align:\s*(left|center|right);?$/iu)?.[1];
  if (alignment) target.style.textAlign = alignment;
}

function copyPreviewTableAttributes(source: Element, target: HTMLElement): void {
  for (const attribute of ["colspan", "rowspan"]) {
    const value = source.getAttribute(attribute);
    if (value && /^[1-9][0-9]?$/u.test(value)) target.setAttribute(attribute, value);
  }
}

function isSafePreviewHref(value: string): boolean {
  if (/[\u0000-\u0020]/u.test(value)) return false;
  if (value.startsWith("/") || value.startsWith("#")) return !value.startsWith("//");
  try {
    return ["http:", "https:", "mailto:"].includes(new URL(value).protocol.toLowerCase());
  } catch {
    return false;
  }
}

function isSafePreviewImage(value: string): boolean {
  if (/[\u0000-\u0020]/u.test(value)) return false;
  try {
    const image = new URL(value);
    return image.protocol.toLowerCase() === "https:"
      && image.hostname !== ""
      && image.username === ""
      && image.password === "";
  } catch {
    return false;
  }
}

function formatJst(value: string): string {
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}

function mailTemplateError(reason: unknown): string {
  if (reason instanceof AdminApiError) {
    if (reason.status === 404) return "メールTemplateが見つかりません。";
    if (reason.status === 409) return "他の更新と競合しました。画面を再読み込みしてください。";
    if (reason.status === 422) return "件名と本文の入力内容を確認してください。";
    if (reason.status === 403) return "この操作を行う権限がありません。";
  }
  return "メール設定を処理できませんでした。再試行してください。";
}

function Loading({ text }: { text: string }) {
  return <section aria-live="polite" className="module-state"><LoaderCircle aria-hidden="true" className="spin" size={22} /><p>{text}</p></section>;
}

function ErrorState({ text }: { text: string }) {
  return <section className="module-state is-error" role="alert"><p>{text}</p></section>;
}
