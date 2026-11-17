"use client";
import { useEffect, useMemo, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatTemplateList, LineChatTemplate, LineDeliveryMessage } from "@/lib/admin-api/generated";
import { LineMessageEditor, newLineMessage } from "./line-message-editor";

export function LineTemplateWorkspace() {
  const { permissions } = usePermissions(); const read = permissions.has("line.template.read"); const manage = permissions.has("line.template.manage");
  const client = useMemo(() => new AdminApiClient(), []); const [data, setData] = useState<LineChatTemplateList | null>(null);
  const [bot, setBot] = useState(""); const [category, setCategory] = useState(""); const [name, setName] = useState("");
  const [editing, setEditing] = useState<LineChatTemplate | null>(null); const [messages, setMessages] = useState<LineDeliveryMessage[]>([newLineMessage("text")]);
  const [categoryName, setCategoryName] = useState(""); const [busy, setBusy] = useState(false); const [error, setError] = useState("");
  useEffect(() => { if (!read) return; let active = true; void client.lineTemplates().then((r) => { if (active) { setData(r); setBot(r.bot_id); } }).catch(() => { if (active) setError("テンプレートを取得できませんでした。"); }); return () => { active = false; }; }, [client, read]);
  async function run(fn: () => Promise<void>) { setBusy(true); setError(""); try { await fn(); } catch { setError("保存・取得できませんでした。権限、BOT、変更競合、使用中カテゴリを確認してください。"); } finally { setBusy(false); } }
  async function load(page = 1) { if (data?.bot_id !== bot) select(null); setData(await client.lineTemplates(bot, category, page)); }
  function select(t: LineChatTemplate | null, copy = false) { setEditing(copy ? null : t); setName(t ? t.name + (copy ? "（複製）" : "") : ""); setMessages(t ? structuredClone(t.messages) : [newLineMessage("text")]); setCategory(t?.category_id ?? ""); }
  if (!read) return <p role="alert">テンプレートの閲覧権限がありません。</p>;
  return <main><h1>LINEテンプレート</h1><p role="alert">{error}</p>{!data ? <p>読み込み中…</p> : <>
    <fieldset disabled={busy}><legend>絞り込み</legend><label>BOT<select value={bot} onChange={(e) => { setBot(e.target.value); select(null); }}>{data.bots.map((b) => <option key={b.id} value={b.id}>{b.name}{b.is_active ? "（有効）" : ""}</option>)}</select></label><label>カテゴリ<select value={category} onChange={(e) => setCategory(e.target.value)}><option value="">すべて</option>{data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><button onClick={() => void run(() => load())}>表示</button></fieldset>
    {data.data.length === 0 && <p>テンプレートはありません。</p>}{data.data.map((t) => <p key={t.id}>{t.name} <button disabled={busy || data.bot_id !== bot} onClick={() => select(t)}>表示・編集</button>{manage && <><button disabled={busy || data.bot_id !== bot} onClick={() => select(t, true)}>複製</button><button disabled={busy} onClick={() => { if (window.confirm("テンプレートを削除しますか？")) void run(async () => { await client.deleteLineTemplate("templates", t.id, { bot_id: data.bot_id, expected_revision: t.revision }); select(null); await load(); }); }}>削除</button></>}</p>)}
    {data.next_page && <button disabled={busy} onClick={() => void run(() => load(data.next_page!))}>次のテンプレート</button>}
    <button disabled={!manage || busy} onClick={() => select(null)}>新規テンプレート</button>
    <fieldset disabled={!manage || busy || data.bot_id !== bot}><legend>{editing ? "テンプレート編集" : "テンプレート登録"}</legend><label>名前<input value={name} maxLength={100} onChange={(e) => setName(e.target.value)} /></label><label>保存カテゴリ<select value={category} onChange={(e) => setCategory(e.target.value)}><option value="">カテゴリなし</option>{data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
      <LineMessageEditor client={client} messages={messages} onChange={setMessages} chat />
      <button onClick={() => void run(async () => { await client.saveLineTemplate("templates", editing?.id ?? null, { bot_id: bot, expected_revision: editing?.revision ?? 0, name, category_id: category || null, messages }); select(null); await load(); })}>保存</button>
    </fieldset>
    <fieldset disabled={!manage || busy || data.bot_id !== bot}><legend>整理用カテゴリ（取引／販促区分とは別）</legend><label>新しいカテゴリ<input value={categoryName} maxLength={100} onChange={(e) => setCategoryName(e.target.value)} /></label><button onClick={() => void run(async () => { await client.saveLineTemplate("template-categories", null, { bot_id: bot, expected_revision: 0, name: categoryName, sort_order: 0 }); setCategoryName(""); await load(); })}>追加</button>
      {data.categories.map((c) => <div key={c.id}><label>カテゴリ名<input value={c.name} maxLength={100} onChange={(e) => setData({ ...data, categories: data.categories.map((v) => v.id === c.id ? { ...v, name: e.target.value } : v) })} /></label><label>表示順<input type="number" min={0} max={10000} value={c.sort_order} onChange={(e) => setData({ ...data, categories: data.categories.map((v) => v.id === c.id ? { ...v, sort_order: Number(e.target.value) } : v) })} /></label><button onClick={() => void run(async () => { await client.saveLineTemplate("template-categories", c.id, { bot_id: bot, expected_revision: c.revision, name: c.name, sort_order: c.sort_order }); await load(); })}>カテゴリ更新</button><button onClick={() => void run(async () => { await client.deleteLineTemplate("template-categories", c.id, { bot_id: bot, expected_revision: c.revision }); setCategory(""); setData(await client.lineTemplates(bot)); })}>カテゴリ削除</button></div>)}
    </fieldset>
  </>}</main>;
}
