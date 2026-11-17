"use client";
import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatDetail, LineChatList, LineDeliveryMessage } from "@/lib/admin-api/generated";
import { LineMessageEditor, newLineMessage } from "./line-message-editor";
import { LineTemplatePicker } from "./line-template-picker";

function Attachment({ client, conversation, message, type }: { client: AdminApiClient; conversation: string; message: string; type: string }) {
  const [url, setUrl] = useState(""); const [error, setError] = useState(""); const [busy, setBusy] = useState(false);
  useEffect(() => () => { if (url) URL.revokeObjectURL(url); }, [url]);
  return <div><button disabled={busy} onClick={() => { setBusy(true); void client.lineChatAttachment(conversation, message).then((blob) => {
    const source = URL.createObjectURL(blob);
    if (["image", "video", "audio"].includes(type) && /^(image|video|audio)\//.test(blob.type)) setUrl(source);
    else { const a = document.createElement("a"); a.href = source; a.download = "line-attachment"; a.click(); setTimeout(() => URL.revokeObjectURL(source), 1000); }
  }).catch(() => setError("添付を取得できませんでした。取消状態を更新してください。")).finally(() => setBusy(false)); }}>添付を表示・ダウンロード</button>
    {/* Authenticated, bounded local blob only; SVG/HTML are never accepted. */}
    {/* eslint-disable-next-line @next/next/no-img-element */}
    {url && type === "image" && <img src={url} alt="受信画像" style={{ maxWidth: 320, maxHeight: 320 }} />}
    {url && type === "video" && <video src={url} controls preload="metadata" style={{ maxWidth: 320 }} />}
    {url && type === "audio" && <audio src={url} controls preload="metadata" />}<p role="status">{error}</p></div>;
}

export function LineChatWorkspace() {
  const { permissions } = usePermissions(); const read = permissions.has("line.chat.read"); const reply = permissions.has("line.chat.reply");
  const client = useMemo(() => new AdminApiClient(), []);
  const [list, setList] = useState<LineChatList>({ data: [], next_page: null });
  const [detail, setDetail] = useState<LineChatDetail | null>(null); const [status, setStatus] = useState(""); const [unread, setUnread] = useState(false);
  const [search, setSearch] = useState("");
  const [messages, setMessages] = useState<LineDeliveryMessage[]>([newLineMessage("text")]);
  const [pending, setPending] = useState<{ id: string; key: string; messages: LineDeliveryMessage[] } | null>(null);
  const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const selection = useRef(0);
  async function load(page = 1) { setList(await client.listLineChat(page, status, unread, search)); }
  useEffect(() => { if (!read) return; let active = true; void client.listLineChat().then((r) => { if (active) setList(r); }).catch(() => { if (active) setError("会話を取得できませんでした。"); }); return () => { active = false; }; }, [client, read]);
  async function perform(fn: () => Promise<void>) { setBusy(true); setError(""); try { await fn(); } catch { setError("処理できませんでした。権限・BOT・友だち状態・送信枠を確認してください。"); } finally { setBusy(false); } }
  async function open(id: string, before = 0) {
    const serial = ++selection.current; setDetail(null);
    const r = await client.getLineChat(id, before); if (serial !== selection.current) return;
    setDetail(r);
    if (before === 0 && r.messages.length) { await client.readLineChat(id, Math.max(...r.messages.map((m) => m.sequence))); await load(); }
  }
  async function send() {
    if (!detail) return;
    const request = pending ?? { id: detail.conversation.id, key: crypto.randomUUID(), messages: structuredClone(messages) };
    setPending(request);
    await client.replyLineChat(request.id, { request_key: request.key, messages: request.messages });
    setPending(null); setMessages([newLineMessage("text")]); await open(request.id);
  }
  if (!read) return <p role="alert">LINEチャットの閲覧権限がありません。</p>;
  return <main><h1>LINEチャット</h1><p role="alert">{error}</p>
    <label>会員名で検索<input maxLength={80} value={search} onChange={(e) => setSearch(e.target.value)} /></label>
    <fieldset disabled={busy}><legend>会話一覧</legend><label>状態<select value={status} onChange={(e) => setStatus(e.target.value)}><option value="">すべて</option><option value="open">対応中</option><option value="closed">対応済み</option></select></label><label><input type="checkbox" checked={unread} onChange={(e) => setUnread(e.target.checked)} />未読のみ</label><button onClick={() => void perform(() => load())}>更新</button>
      {list.data.length === 0 && <p>会話はありません。</p>}{list.data.map((c) => <div key={c.id}><button disabled={pending !== null} onClick={() => void perform(() => open(c.id))}>{c.name}・未読 {c.unread_count}・{c.status}</button><p>{c.summary}</p></div>)}{list.next_page && <button onClick={() => void perform(() => load(list.next_page!))}>次の会話</button>}
    </fieldset>
    {detail && <section><h2>{detail.conversation.name}</h2>{detail.conversation.member_id && <p><Link href={`/users/${detail.conversation.member_id}`}>会員詳細</Link>／ランク {detail.conversation.rank_level ?? "—"}／{detail.conversation.points}ポイント</p>}
      <button disabled={busy} onClick={() => void perform(() => open(detail.conversation.id))}>最新履歴を取得</button>
      <button disabled={!reply || busy} onClick={() => void perform(async () => { await client.statusLineChat(detail.conversation.id, detail.conversation.status === "open" ? "closed" : "open", detail.conversation.revision); await open(detail.conversation.id); })}>{detail.conversation.status === "open" ? "対応済みにする" : "対応中にする"}</button>
      <p>acceptedはLINE APIの受付済みです。到達・既読を意味しません。</p>
      {detail.next_before && <button disabled={busy} onClick={() => void perform(() => open(detail.conversation.id, detail.next_before!))}>以前の履歴</button>}
      {detail.messages.map((m) => <article key={m.id}><p>{m.direction === "inbound" ? "受信" : "送信"}・{m.received_at} {m.batch_status}</p>
        {m.unsent ? <p>取消済み</p> : <><p>{m.type}</p>{m.body && <div style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>{Array.isArray(m.body) ? m.body.map((v) => v.type === "text" ? v.text : `【${v.type}】`).join("\n") : m.body.text ?? m.body.title ?? ""}</div>}
          {m.has_attachment && <Attachment client={client} conversation={detail.conversation.id} message={m.id} type={m.type} />}{["unavailable", "pending_external"].includes(m.media_state) && <p>ファイルを取得できませんでした。</p>}</>}
      </article>)}
      {reply && <fieldset disabled={busy || pending !== null}><legend>返信内容</legend><LineTemplatePicker client={client} botId={detail.conversation.bot_id} onSelect={setMessages} /><LineMessageEditor client={client} messages={messages} onChange={setMessages} chat /></fieldset>}
      {reply && <button disabled={busy} onClick={() => void perform(send)}>{pending ? "同じ送信要求の結果を確認" : "返信を送信キューに登録"}</button>}{pending && <p>結果を確認するまで同じ送信内容を保持します。再実行しても同じ受付キーを使います。</p>}
    </section>}
  </main>;
}
