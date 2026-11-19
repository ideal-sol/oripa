"use client";
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import styles from "./line-admin.module.css";
import Link from "next/link";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatDetail, LineChatList, LineDeliveryMessage } from "@/lib/admin-api/generated";
import { LineMessageEditor, newLineMessage } from "./line-message-editor";
import { useLineChatReplies } from "./line-chat-reply-provider";
import { LineTemplatePicker } from "./line-template-picker";

function Attachment({ client, conversation, message, type }: { client: AdminApiClient; conversation: string; message: string; type: string }) {
  const [url, setUrl] = useState(""); const [error, setError] = useState(""); const [busy, setBusy] = useState(false);
  useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
  return <div><button className="secondary-button" disabled={busy} onClick={() => { setBusy(true); void client.lineChatAttachment(conversation, message).then((blob) => {
    const source = URL.createObjectURL(blob);
    if (["image", "video", "audio"].includes(type) && /^(image|video|audio)\//.test(blob.type)) setUrl(source);
    else { const a = document.createElement("a"); a.href = source; a.download = "line-attachment"; a.click(); setTimeout(() => URL.revokeObjectURL(source), 1000); }
  }).catch(() => setError("添付を取得できませんでした。取消状態を更新してください。")).finally(() => setBusy(false)); }}>添付を表示・ダウンロード</button>
    {/* Authenticated, bounded local blob only; SVG/HTML are never accepted. */}
    {/* eslint-disable-next-line @next/next/no-img-element */}
    {url && type === "image" && <img src={url} alt="受信画像" className={styles.mediaPreview} />}
    {url && type === "video" && <video src={url} controls preload="metadata" className={styles.mediaPreview} />}
    {url && type === "audio" && <audio src={url} controls preload="metadata" />}<p role="status">{error}</p></div>;
}

const jst = (v: string, day = false) => new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", dateStyle: "medium", ...(day ? {} : { timeStyle: "short" as const }) }).format(new Date(v));
const states: Record<string, string> = { pending: "送信待ち", retrying: "再確認待ち", queued: "送信待ち", sending: "送信処理中", accepted: "LINE API受付済み（配達・既読未確認）", failed: "送信失敗", unknown: "結果不明", skipped: "送信対象外", cancelled: "取消済み" };
const mediaNames: Record<string, string> = { image: "画像", video: "動画", audio: "音声", file: "ファイル", sticker: "スタンプ", location: "位置情報", imagemap: "イメージマップ", image_carousel: "画像カルーセル" };
export function LineChatWorkspace({ conversationId }: { conversationId?: string }) {
  return <ConversationWorkspace key={conversationId ?? "list"} conversationId={conversationId} />;
}
function ConversationWorkspace({ conversationId }: { conversationId?: string }) {
  const { permissions } = usePermissions(); const read = permissions.has("line.chat.read"); const reply = permissions.has("line.chat.reply");
  const client = useMemo(() => new AdminApiClient(), []);
  const [list, setList] = useState<LineChatList>({ data: [], next_page: null });
  const [detail, setDetail] = useState<LineChatDetail | null>(null); const [status, setStatus] = useState(""); const [unread, setUnread] = useState(false);
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const [loading, setLoading] = useState(true);
  const timeline = useRef<HTMLDivElement>(null); const scroll = useRef<{ height: number; top: number } | "bottom" | null>(null);
  const { entries, submit, historyToken, historyConfirmed } = useLineChatReplies();
  const entry = conversationId ? entries.get(conversationId) : undefined;
  const pending = entry && entry.phase !== "complete" && entry.phase !== "rejected" ? entry.request : null;
  const registered = entry?.phase === "registered";
  const submitting = entry?.phase === "submitting";
  const hasOutstanding = [...entries.values()].some((v) => v.phase !== "complete" && v.phase !== "rejected");
  const ConversationLink = hasOutstanding ? Link : "a";
  const active = useRef(true);
  useEffect(() => { active.current = true; return () => { active.current = false; }; }, []);
  const [reloadReview, setReloadReview] = useState(() => typeof performance !== "undefined" && performance.getEntriesByType?.("navigation").some((v) => "type" in v && v.type === "reload") === true);
  useLayoutEffect(() => {
    if (!timeline.current || !scroll.current) return;
    const node = timeline.current;
    node.scrollTop = scroll.current === "bottom" ? node.scrollHeight : scroll.current.top + node.scrollHeight - scroll.current.height;
    scroll.current = null;
  }, [detail]);
  const [messages, setMessages] = useState<LineDeliveryMessage[]>([newLineMessage("text")]);
  const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const selection = useRef(0);
  async function load(page = 1) { setList(await client.listLineChat(page, status, unread, search)); setPage(page); }
  useEffect(() => {
    if (!read) return; let active = true;
    async function initial() {
      try {
        if (conversationId) {
          const registeredKey = historyToken(conversationId);
          const r = await client.getLineChat(conversationId); if (!active) return;
          scroll.current = "bottom"; setDetail(r);
          if (r.messages.length) await client.readLineChat(conversationId, Math.max(...r.messages.map((m) => m.sequence)));
          if (active && historyConfirmed(conversationId, registeredKey)) setMessages([newLineMessage("text")]);
        } else { const r = await client.listLineChat(); if (active) setList(r); }
      } catch { if (active) setError("会話を取得できませんでした。"); }
      finally { if (active) setLoading(false); }
    }
    void initial(); return () => { active = false; };
  }, [client, read, conversationId, historyToken, historyConfirmed]);
  async function perform(fn: () => Promise<void>) { setBusy(true); setError(""); try { await fn(); } catch { setError(registered ? "返信は送信キューに登録されましたが、履歴の更新に失敗しました。履歴を再取得してください。" : "処理できませんでした。権限・BOT・友だち状態・送信枠を確認してください。"); } finally { setBusy(false); } }
  async function open(id: string, before = 0) {
    const registeredKey = historyToken(id);
    const serial = ++selection.current;
    const r = await client.getLineChat(id, before); if (!active.current || serial !== selection.current) return;
    if (before) {
      if (timeline.current) scroll.current = { height: timeline.current.scrollHeight, top: timeline.current.scrollTop };
      setDetail((old) => ({ ...r, messages: [...r.messages, ...(old?.messages ?? []).filter((m) => !r.messages.some((v) => v.id === m.id))] }));
    } else { scroll.current = "bottom"; setDetail(r); }
    if (before === 0) { if (r.messages.length) await client.readLineChat(id, Math.max(...r.messages.map((m) => m.sequence))); if (active.current && historyConfirmed(id, registeredKey)) setMessages([newLineMessage("text")]); }
  }
  async function send() {
    if (!detail || registered || busy || submitting || loading || reloadReview) return;
    setBusy(true); setError("");
    try {
      const accepted = await submit(client, detail.conversation.id, messages);
      if (!active.current || !accepted) return;
      try { await open(detail.conversation.id); }
      catch { if (active.current) setError("返信は送信キューに登録されましたが、履歴の更新に失敗しました。履歴を再取得してください。"); }
    } finally { setBusy(false); }
  }
  const draft = pending?.messages ?? messages;
  if (!read) return <p role="alert">LINEチャットの閲覧権限がありません。</p>;
  return <div className={styles.surface} aria-busy={busy || loading || submitting}>{error && <p role="alert">{error}</p>}{loading && <p role="status">会話を読み込み中…</p>}
    {entry?.phase === "unknown" && <p className="notice notice-warning" role="alert">返信登録の結果が不明です。新しい要求は送らず、同じ送信要求の結果を確認してください。{entry.error}</p>}
    {entry?.phase === "rejected" && <p role="alert">返信登録APIが要求を拒否しました。入力・権限を確認してください。{entry.error}</p>}
    {registered && <p role="status">返信は送信キューに登録されています。履歴の再取得のみ実行できます。</p>}
    {entry?.phase === "complete" && <p className="notice notice-success" role="status">送信キューに登録しました。</p>}
    {!conversationId ? <>
    <fieldset disabled={busy || loading}><legend>会話の絞り込み</legend>
      <label>会員名で検索<input maxLength={80} value={search} onChange={(e) => setSearch(e.target.value)} /></label>
      <label>状態<select value={status} onChange={(e) => setStatus(e.target.value)}><option value="">すべて</option><option value="open">対応中</option><option value="closed">対応済み</option></select></label>
      <label><input type="checkbox" checked={unread} onChange={(e) => setUnread(e.target.checked)} />未読のみ</label><button className="secondary-button" onClick={() => void perform(() => load())}>更新</button>
    </fieldset>
    {!loading && list.data.length === 0 && <p>会話はありません。</p>}
    <div className={styles.conversations}>{list.data.map((c) => <ConversationLink className={styles.conversation} key={c.id} href={`/line-chat/${encodeURIComponent(c.id)}`}>
      <strong>{c.name} <span className={styles.badge}>{c.member_id ? "会員" : "未登録"}</span></strong><time dateTime={c.last_message_at}>{jst(c.last_message_at)} JST</time>
      <span className={styles.preview}>{c.summary || "メッセージなし"}</span><span>{c.status === "open" ? "対応中" : "対応済み"}・未読 {c.unread_count}</span>
    </ConversationLink>)}</div>
    <nav aria-label="会話のページ送り"><button className="secondary-button" disabled={busy || page === 1} onClick={() => void perform(() => load(page - 1))}>前の会話</button><span>{page}ページ</span><button className="secondary-button" disabled={busy || !list.next_page} onClick={() => void perform(() => load(list.next_page!))}>次の会話</button></nav>
    </> : <>{pending ? <button className="secondary-button" disabled>一覧へ戻る（送信結果を確認中）</button> : <Link className="secondary-button" href="/line-chat">一覧へ戻る</Link>}
    {!detail && !loading && <button className="secondary-button" disabled={busy || submitting} onClick={() => void perform(() => open(conversationId))}>最新履歴を取得</button>}
    {detail && <section><h2>{detail.conversation.name} <span className={styles.badge}>{detail.conversation.status === "open" ? "対応中" : "対応済み"}</span></h2>{detail.conversation.member_id && <p><Link href={`/users/${detail.conversation.member_id}`}>会員詳細</Link>／ランク {detail.conversation.rank_level ?? "—"}／{detail.conversation.points}ポイント</p>}
      <button className="secondary-button" disabled={busy || submitting} onClick={() => void perform(() => open(detail.conversation.id))}>最新履歴を取得</button>
      <button className="secondary-button" disabled={!reply || busy || submitting || registered} onClick={() => void perform(async () => { await client.statusLineChat(detail.conversation.id, detail.conversation.status === "open" ? "closed" : "open", detail.conversation.revision); await open(detail.conversation.id); })}>{detail.conversation.status === "open" ? "対応済みにする" : "対応中にする"}</button>
      <p>acceptedはLINE APIの受付済みです。到達・既読を意味しません。</p>
      <div ref={timeline} className={styles.timeline} role="region" aria-label="メッセージ履歴" tabIndex={0}>{detail.next_before && <button className="secondary-button" disabled={busy || submitting || registered} onClick={() => void perform(() => open(detail.conversation.id, detail.next_before!))}>以前の履歴</button>}
      {detail.messages.map((m, index) => <Fragment key={m.id}>
        {(index === 0 || jst(detail.messages[index - 1].received_at, true) !== jst(m.received_at, true)) && <div className={styles.day}>{jst(m.received_at, true)}</div>}
        <article className={`${styles.message} ${m.direction === "outbound" ? styles.outbound : ""}`} aria-label={m.direction === "inbound" ? "受信メッセージ" : "送信メッセージ"}>
        {m.unsent ? <p>取消済み</p> : <>{m.body && (Array.isArray(m.body) ? m.body.map((v, i) => <p key={i}>{v.type === "text" ? v.text : `【${mediaNames[v.type] ?? "メッセージ"}】`}</p>) : <p>{m.body.text ?? m.body.title ?? `【${mediaNames[m.type] ?? "メッセージ"}】`}</p>)}
          {m.has_attachment && <Attachment client={client} conversation={detail.conversation.id} message={m.id} type={m.type} />}{["unavailable", "pending_external"].includes(m.media_state) && <p>ファイルを取得できませんでした。</p>}</>}
        <span className={styles.meta}><time dateTime={m.received_at}>{jst(m.received_at)} JST</time>・{m.direction === "inbound" ? "受信" : m.batch_status ? states[m.batch_status] ?? "送信状態を確認中" : "送信状態を確認中"}</span>
      </article></Fragment>)}
      </div><div className={styles.composer}>
      <p className="notice notice-warning">送信要求はメモリ内だけで保持します。再読み込み・終了後は復元できません。送信結果が不明な場合は、履歴と送信状態を確認し、安易に再送しないでください。</p>
      {reloadReview && <button className="secondary-button" disabled={busy || loading || !!error} onClick={() => setReloadReview(false)}>履歴を確認しました（新しい返信を作成）</button>}
      {reply && <fieldset disabled={busy || loading || pending !== null || reloadReview}><legend>返信内容</legend>{draft.length === 1 && draft[0].type === "text" && <label>本文<textarea aria-label="返信本文" maxLength={5000} value={draft[0].text} onChange={(e) => setMessages([{ type: "text", text: e.target.value }])} /></label>}
        <details><summary>メディア・複数メッセージ・タグを編集</summary><LineMessageEditor client={client} messages={draft} onChange={setMessages} chat /></details>
        <details><summary>テンプレートを使う</summary><LineTemplatePicker client={client} botId={detail.conversation.bot_id} onSelect={setMessages} /></details></fieldset>}
      {reply && !registered && <button className="primary-button" disabled={busy || submitting || loading || reloadReview} onClick={() => void send()}>{pending ? "同じ送信要求の結果を確認" : "返信を送信キューに登録"}</button>}{pending && <p>結果を確認するまで同じ送信内容を保持します。再実行しても同じ受付キーを使います。</p>}
    </div></section>}
    </>}
  </div>;
}
