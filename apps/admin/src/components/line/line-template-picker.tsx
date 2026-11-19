"use client";
import styles from "./line-admin.module.css";
import { useState } from "react";
import type { AdminApiClient } from "@/lib/admin-api/client";
import type { LineChatTemplateList, LineDeliveryMessage } from "@/lib/admin-api/generated";

export function LineTemplatePicker({ client, botId, onSelect }: { client: AdminApiClient; botId?: string; onSelect: (m: LineDeliveryMessage[]) => void }) {
  const [data, setData] = useState<LineChatTemplateList | null>(null);
  const [category, setCategory] = useState(""); const [error, setError] = useState("");
  async function load(page = 1) {
    try { const r = await client.lineTemplates("", category, page); if (botId && botId !== r.bot_id) throw new Error(); setData(r); setError(""); }
    catch { setData(null); setError("有効BOTのテンプレートを取得できませんでした。"); }
  }
  return <fieldset className={styles.surface}><legend>テンプレートから読み込む</legend><button className="secondary-button" type="button" onClick={() => void load()}>テンプレートを選択</button>
    {data && <><label>カテゴリ<select value={category} onChange={(e) => setCategory(e.target.value)}><option value="">すべて</option>{data.categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></label><button className="secondary-button" type="button" onClick={() => void load()}>絞り込む</button>
      {data.data.map((t) => <button className="secondary-button" type="button" key={t.id} onClick={() => onSelect(structuredClone(t.messages))}>{t.name}</button>)}{data.data.length === 0 && <p>テンプレートはありません。</p>}{data.next_page && <button className="secondary-button" type="button" onClick={() => void load(data.next_page!)}>次へ</button>}</>}
    <p role="status">{error}</p></fieldset>;
}
