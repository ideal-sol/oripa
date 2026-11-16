"use client";

import { useEffect, useMemo, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { LineAutomatedSetting } from "@/lib/admin-api/generated";
import { getWebauthnAssertion } from "@/lib/admin-api/webauthn";

const names = { shipping: "発送完了", payment: "決済完了", first_purchase: "初回購入", rank_promotion: "会員ランク昇格" };

export function LineAutomatedSettings() {
  const { permissions } = usePermissions();
  const readable = permissions.has("line.bot.read");
  const writable = permissions.has("line.bot.manage");
  const client = useMemo(() => new AdminApiClient(), []);
  const [rows, setRows] = useState<LineAutomatedSetting[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("読み込み中…");
  const [mfa, setMfa] = useState(false);
  const [code, setCode] = useState("");
  useEffect(() => {
    if (!readable) return;
    let active = true;
    void client.getLineAutomatedSettings().then((result) => {
      if (active) { setRows(result.data); setStatus(""); }
    }).catch(() => { if (active) { setError("通知設定を取得できませんでした。"); setStatus(""); } });
    return () => { active = false; };
  }, [client, readable]);
  if (!readable) return null;
  const edit = (event: LineAutomatedSetting["event"], changes: Partial<LineAutomatedSetting>) => setRows((current) => current.map((r) => r.event === event ? { ...r, ...changes } : r));
  async function perform(action: () => Promise<unknown>, message: string) {
    setBusy(true); setError(""); setStatus("");
    try { await action(); setStatus(message); }
    catch (caught) {
      const c = caught instanceof AdminApiError ? caught.code : "";
      if (c === "FRESH_MFA_REQUIRED") { setMfa(true); setError("保存には管理者の再認証が必要です。"); }
      else setError(c === "LINE_AUTOMATED_REVISION_CONFLICT" ? "設定が変更されています。再読込して編集し直してください。"
        : c === "LINE_AUTOMATED_INPUT_INVALID" ? "本文の文字数・置換タグ・区分を確認してください。"
        : c === "AUTHORIZATION_DENIED" ? "操作権限がありません。" : "処理に失敗しました。再試行してください。");
    } finally { setBusy(false); setCode(""); }
  }
  return <section aria-label="自動LINE通知" className="settings-panel">
    <h2>自動LINE通知</h2>
    <p>全通知は初期OFFです。設定変更は変更後に発生したイベントへ適用します。以前のイベントには発生時の設定を使い、後からONにしても過去の通知は送信しません。</p>
    <p>使用できる置換タグ：%username%・%rank%・%points%。%rank%は送信時点の現在ランクです。</p>
    <p>通知のONは送信開始を意味しません。送信処理の有効化と共通送信枠の準備が別途必要です。</p>
    {error && <p role="alert">{error}</p>}{status && <p role="status">{status}</p>}
    {mfa && writable && <form onSubmit={(e) => { e.preventDefault(); void perform(async () => {
      await client.reauthenticate({ method: "totp", code }); setMfa(false);
    }, "再認証しました。保存をもう一度実行してください。"); }}>
      <label>認証コード<input type="password" autoComplete="one-time-code" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} required /></label>
      <button disabled={busy}>再認証</button>
      <button type="button" disabled={busy} onClick={() => void perform(async () => {
        const options = await client.createReauthenticationWebauthnOptions();
        const credential = await getWebauthnAssertion(options.options);
        await client.reauthenticate({ method: "webauthn", challenge_token: options.challenge_token, credential }); setMfa(false);
      }, "再認証しました。保存をもう一度実行してください。")}>セキュリティキーで再認証</button>
    </form>}
    <button type="button" disabled={busy} onClick={() => void perform(async () => setRows((await client.getLineAutomatedSettings()).data), "再読込しました。")}>通知設定を再読込</button>
    {rows.map((row) => {
      const unsupported = row.messages.some((m) => m.text.includes("%shipping_url%"));
      return <form key={row.event} onSubmit={(e) => { e.preventDefault(); void perform(async () => {
        const result = await client.updateLineAutomatedSettings(row.event, { enabled: row.enabled, category: row.category, messages: row.messages, expected_revision: row.revision });
        setRows((current) => current.map((r) => r.event === row.event ? result.data.find((saved) => saved.event === row.event)! : r));
      }, `${names[row.event]}を保存しました。`); }}>
        <h3>{names[row.event]}</h3>
        <p>設定 revision {row.revision} ／ {unsupported ? "送信不可：配送詳細URL未対応" : row.enabled ? "通知ON" : "通知OFF"}</p>
        {unsupported && <p role="alert">%shipping_url%は配送詳細ページのURLが未確定のため未対応です。このタグを含む通知は送信されません。タグを除くと通常の発送完了通知として利用できます。</p>}
        <fieldset disabled={!writable || busy}>
          <label><input type="checkbox" checked={row.enabled} onChange={(e) => edit(row.event, { enabled: e.target.checked })} />通知ON</label>
          <label>区分<select value={row.category} disabled={row.event === "shipping" || row.event === "payment"} onChange={(e) => edit(row.event, { category: e.target.value as LineAutomatedSetting["category"] })}>
            <option value="transactional">取引</option><option value="promotional">販促</option>
          </select></label>
          {row.messages.map((message, i) => <div key={i}>
            <label>本文 {i + 1}<textarea required value={message.text} onChange={(e) => edit(row.event, { messages: row.messages.map((m, index) => index === i ? { type: "text", text: e.target.value } : m) })} /></label>
            <p>{message.text.length} / 5000（UTF-16単位）</p>
            {row.messages.length > 1 && <button type="button" onClick={() => edit(row.event, { messages: row.messages.filter((_, index) => index !== i) })}>本文 {i + 1} を削除</button>}
          </div>)}
          {row.messages.length < 5 && <button type="button" onClick={() => edit(row.event, { messages: [...row.messages, { type: "text", text: "" }] })}>本文を追加</button>}
          <button disabled={row.messages.some((m) => !m.text.trim() || m.text.length > 5000)}>通知設定を保存</button>
        </fieldset>
      </form>;
    })}
  </section>;
}
