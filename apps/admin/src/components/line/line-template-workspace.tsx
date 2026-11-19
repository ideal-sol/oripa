"use client";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatTemplateList, LineChatTemplate, LineDeliveryMessage } from "@/lib/admin-api/generated";
import { LineMessageEditor, newLineMessage } from "./line-message-editor";

export function LineTemplateWorkspace() {
  const { permissions } = usePermissions(); const read = permissions.has("line.template.read"); const manage = permissions.has("line.template.manage");
  const client = useMemo(() => new AdminApiClient(), []); const [data, setData] = useState<LineChatTemplateList | null>(null);
  const [bot, setBot] = useState(""); const [category, setCategory] = useState(""); const [name, setName] = useState("");
  const [editCategory, setEditCategory] = useState("");
  const [editing, setEditing] = useState<LineChatTemplate | null>(null); const [messages, setMessages] = useState<LineDeliveryMessage[]>([newLineMessage("text")]);
  const [categoryName, setCategoryName] = useState(""); const [busy, setBusy] = useState(false); const [error, setError] = useState("");
  const scope = useRef({ bot: "", version: 0 }); const request = useRef(0); const mounted = useRef(true);
  const [scopeVersion, setScopeVersion] = useState(0); const [loadedVersion, setLoadedVersion] = useState<number | null>(null); const [fetching, setFetching] = useState(true);
  const fetchList = useCallback(async (requestedBot: string, filter: string, page: number, version: number) => {
    const serial = ++request.current; setFetching(true); setError("");
    const current = () => mounted.current && serial === request.current && version === scope.current.version && requestedBot === scope.current.bot;
    try {
      const result = await client.lineTemplates(requestedBot, filter, page);
      if (!current()) return;
      if (requestedBot && result.bot_id !== requestedBot) throw new Error("BOT mismatch");
      if (!requestedBot) { scope.current.bot = result.bot_id; setBot(result.bot_id); }
      setData(result); setLoadedVersion(version);
    } catch { if (current()) { setLoadedVersion(null); setError("テンプレートを取得できませんでした。BOTと接続状態を確認してください。"); } }
    finally { if (mounted.current && serial === request.current) setFetching(false); }
  }, [client]);
  useEffect(() => {
    mounted.current = true;
    request.current++;
    if (read) void fetchList(scope.current.bot, "", 1, scope.current.version);
    return () => { mounted.current = false; };
  }, [read, fetchList]);
  const aligned = !!data && data.bot_id === bot && loadedVersion === scopeVersion && !fetching;
  const current = () => mounted.current && aligned && data?.bot_id === scope.current.bot && loadedVersion === scope.current.version;
  async function run(fn: () => Promise<void>) {
    if (!manage || !current() || busy) return;
    setBusy(true); setError("");
    try { await fn(); } catch { if (current()) setError("保存・取得できませんでした。権限、BOT、変更競合、使用中カテゴリを確認してください。"); }
    finally { setBusy(false); }
  }
  async function load(page = 1) {
    if (!mounted.current || (data && (data.bot_id !== scope.current.bot || loadedVersion !== scope.current.version))) return;
    await fetchList(scope.current.bot, category, page, scope.current.version);
  }
  function resetEditor(t: LineChatTemplate | null, copy = false) { setEditing(copy ? null : t); setName(t ? t.name + (copy ? "（複製）" : "") : ""); setMessages(t ? structuredClone(t.messages) : [newLineMessage("text")]); setEditCategory(t?.category_id ?? ""); }
  function select(t: LineChatTemplate | null, copy = false) { if (current() && !busy) resetEditor(t, copy); }
  function switchBot(id: string) {
    if (busy) return;
    const version = scope.current.version + 1; scope.current = { bot: id, version };
    setScopeVersion(version); setBot(id); setCategory(""); setCategoryName(""); resetEditor(null); setLoadedVersion(null);
    setData((old) => old ? { ...old, data: [], categories: [], next_page: null } : old);
    void fetchList(id, "", 1, version);
  }
  async function removeTemplate(t: LineChatTemplate) {
    if (!data || !manage || !current() || busy) return;
    const target = { id: t.id, bot: data.bot_id, revision: t.revision, version: loadedVersion };
    if (!window.confirm(`テンプレート「${t.name}」を削除しますか？（BOT: ${target.bot}）`)) return;
    if (!current() || target.version !== scope.current.version || target.bot !== scope.current.bot) return;
    await run(async () => { await client.deleteLineTemplate("templates", target.id, { bot_id: target.bot, expected_revision: target.revision }); if (current()) resetEditor(null); await load(); });
  }
  if (!read) return <p role="alert">テンプレートの閲覧権限がありません。</p>;
  return <div><p role="alert">{error}</p>{fetching && <p role="status">選択中BOTのテンプレートを取得中…</p>}{!data ? <p>{error ? "テンプレートを取得できませんでした。ページを再読み込みしてください。" : "読み込み中…"}</p> : <>
    <fieldset disabled={busy}><legend>絞り込み</legend><label>BOT<select value={bot} onChange={(e) => switchBot(e.target.value)}>{data.bots.map((b) => <option key={b.id} value={b.id}>{b.name}{b.is_active ? "（有効）" : ""}</option>)}</select></label><label>カテゴリ<select disabled={!aligned} value={category} onChange={(e) => setCategory(e.target.value)}><option value="">すべて</option>{data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><button className="secondary-button" disabled={fetching} onClick={() => void fetchList(scope.current.bot, category, 1, scope.current.version)}>表示</button></fieldset>
    {data.data.length === 0 && <p>テンプレートはありません。</p>}{data.data.map((t) => <article className="settings-panel" key={t.id}><h3>{t.name}</h3><div><button className="secondary-button" disabled={busy || !aligned} onClick={() => select(t)}>表示・編集</button>{manage && <><button className="secondary-button" disabled={busy || !aligned} onClick={() => select(t, true)}>複製</button><button className="danger-button" disabled={busy || !aligned} onClick={() => void removeTemplate(t)}>削除</button></>}</div></article>)}
    {data.next_page && <button className="secondary-button" disabled={busy || fetching || !aligned} onClick={() => void load(data.next_page!)}>次のテンプレート</button>}
    <button className="secondary-button" disabled={!manage || busy || !aligned} onClick={() => select(null)}>新規テンプレート</button>
    <fieldset disabled={!manage || busy || !aligned}><legend>{editing ? "テンプレート編集" : "テンプレート登録"}</legend><label>名前<input value={name} maxLength={100} onChange={(e) => setName(e.target.value)} /></label><label>保存カテゴリ<select value={editCategory} onChange={(e) => setEditCategory(e.target.value)}><option value="">カテゴリなし</option>{data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label>
      <LineMessageEditor client={client} messages={messages} onChange={setMessages} chat />
      <button className="primary-button" onClick={() => void run(async () => { await client.saveLineTemplate("templates", editing?.id ?? null, { bot_id: bot, expected_revision: editing?.revision ?? 0, name, category_id: editCategory || null, messages }); select(null); await load(); })}>保存</button>
    </fieldset>
    <fieldset disabled={!manage || busy || !aligned}><legend>整理用カテゴリ（取引／販促区分とは別）</legend><label>新しいカテゴリ<input value={categoryName} maxLength={100} onChange={(e) => setCategoryName(e.target.value)} /></label><button className="secondary-button" onClick={() => void run(async () => { await client.saveLineTemplate("template-categories", null, { bot_id: bot, expected_revision: 0, name: categoryName, sort_order: 0 }); if (current()) setCategoryName(""); await load(); })}>追加</button>
      {data.categories.map((c) => <div key={c.id}><label>カテゴリ名<input value={c.name} maxLength={100} onChange={(e) => setData({ ...data, categories: data.categories.map((v) => v.id === c.id ? { ...v, name: e.target.value } : v) })} /></label><label>表示順<input type="number" min={0} max={10000} value={c.sort_order} onChange={(e) => setData({ ...data, categories: data.categories.map((v) => v.id === c.id ? { ...v, sort_order: Number(e.target.value) } : v) })} /></label><button className="secondary-button" onClick={() => void run(async () => { await client.saveLineTemplate("template-categories", c.id, { bot_id: bot, expected_revision: c.revision, name: c.name, sort_order: c.sort_order }); await load(); })}>カテゴリ更新</button><button className="danger-button" onClick={() => { if (!window.confirm("カテゴリを削除しますか？")) return; void run(async () => { await client.deleteLineTemplate("template-categories", c.id, { bot_id: bot, expected_revision: c.revision }); if (current()) { setCategory(""); if (editCategory === c.id) setEditCategory(""); await fetchList(bot, "", 1, scope.current.version); } }); }}>カテゴリ削除</button></div>)}
    </fieldset>
  </>}</div>;
}
