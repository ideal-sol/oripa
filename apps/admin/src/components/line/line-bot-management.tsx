"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { LineBot, LineLoginSetting } from "@/lib/admin-api/generated";
import { getWebauthnAssertion } from "@/lib/admin-api/webauthn";
import { LINE_BOT_SECTION_PERMISSIONS } from "@/lib/permissions/admin-navigation";

export function LineBotManagement() {
  const { permissions } = usePermissions();
  const readable = permissions.has(LINE_BOT_SECTION_PERMISSIONS.read);
  const writable = permissions.has(LINE_BOT_SECTION_PERMISSIONS.manage);
  const client = useMemo(() => new AdminApiClient(), []);
  const [bots, setBots] = useState<LineBot[]>([]);
  const [login, setLogin] = useState<LineLoginSetting | null>(null);
  const [loginId, setLoginId] = useState("");
  const [loginSecret, setLoginSecret] = useState("");
  const [editing, setEditing] = useState<LineBot | null>(null);
  const [name, setName] = useState("");
  const [channel, setChannel] = useState("");
  const [secret, setSecret] = useState("");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [needsMfa, setNeedsMfa] = useState(false);
  const [mfaCode, setMfaCode] = useState("");
  const load = useCallback(async () => {
    const [collection, setting] = await Promise.all([client.getLineBots(), client.getLineLoginSetting()]);
    setBots(collection.items);
    setLogin(setting);
    setLoginId(setting.channel_id ?? "");
  }, [client]);
  const failure = (caught: unknown) => {
    const code = caught instanceof AdminApiError ? caught.code : "";
    if (code === "LINE_CONFIGURATION_REVISION_CONFLICT") return "ほかの操作で更新されました。再読込して入力し直してください。";
    if (code === "FRESH_MFA_REQUIRED") return "管理者の再認証が必要です。再認証後に操作してください。";
    if (code === "AUTHORIZATION_DENIED") return "この操作の権限がありません。";
    if (code === "LINE_DELIVERY_SENDING") return "送信中の配信が終わってから切り替えてください。";
    if (code === "LINE_BOT_CONNECTION_REQUIRED") return "Channel IDとsecretを登録し、接続確認を完了してください。";
    return "処理に失敗しました。設定と接続状態を確認して再試行してください。";
  };
  useEffect(() => {
    if (!readable) return;
    let active = true;
    void Promise.all([client.getLineBots(), client.getLineLoginSetting()]).then(([collection, setting]) => {
      if (!active) return;
      setBots(collection.items); setLogin(setting); setLoginId(setting.channel_id ?? "");
    }).catch(() => { if (active) setError("LINE接続設定を取得できませんでした。"); });
    return () => { active = false; };
  }, [client, readable]);
  if (!readable) return null;
  async function perform(action: () => Promise<unknown>, success: string) {
    setBusy(true); setError(""); setMessage("");
    try { await action(); await load(); setMessage(success); }
    catch (caught) {
      if (caught instanceof AdminApiError && caught.code === "FRESH_MFA_REQUIRED") setNeedsMfa(true);
      setError(failure(caught));
    }
    finally { setBusy(false); setSecret(""); setLoginSecret(""); }
  }
  return <section className="settings-panel" aria-label="LINEログインとBOT管理">
    <h2>LINEログイン・BOT接続設定</h2>
    <p>LINEログインとMessaging APIは同じプロバイダーに配置してください。ログインチャネルの変更は既存のLINE ID照合に影響する場合があります。認証途中に設定が変わった場合はLINE連携を最初からやり直してください。</p>
    <p>BOT切替時は、LINE Developersの「リンクされた公式アカウント」と、このページの友だち追加URLを新BOTに合わせて変更してください。LINE Developers側の設定は自動変更されません。</p>
    {!writable && <p>参照のみです。登録・変更・接続確認はOwner権限が必要です。</p>}
    {error && <p role="alert" className="notice notice-error">{error}</p>}
    {needsMfa && writable && <form aria-label="管理者の再認証" onSubmit={(event) => {
      event.preventDefault();
      void perform(async () => {
        try { await client.reauthenticate({method: "totp", code: mfaCode}); setNeedsMfa(false); }
        finally { setMfaCode(""); }
      }, "再認証しました。secretを入力し直し、操作をもう一度実行してください。");
    }}>
      <label>認証アプリのコード<input type="password" autoComplete="one-time-code" inputMode="numeric" pattern="[0-9]{6}" value={mfaCode} onChange={(event) => setMfaCode(event.target.value)} required /></label>
      <button disabled={busy} type="submit">認証アプリで再認証</button>
      <button disabled={busy} type="button" onClick={() => void perform(async () => {
        const options = await client.createReauthenticationWebauthnOptions();
        const credential = await getWebauthnAssertion(options.options);
        await client.reauthenticate({method: "webauthn", challenge_token: options.challenge_token, credential});
        setNeedsMfa(false);
      }, "再認証しました。secretを入力し直し、操作をもう一度実行してください。")}>セキュリティキーで再認証</button>
    </form>}
    {(busy || message) && <p role="status">{busy ? "処理中です…" : message}</p>}
    <button type="button" disabled={busy} onClick={() => void perform(load, "最新の設定を取得しました。")}>再読込</button>
    <form onSubmit={(event) => { event.preventDefault(); if (!login) return; void perform(() => client.updateLineLoginSetting({channel_id: loginId, channel_secret: loginSecret, expected_revision: login.revision}, crypto.randomUUID()), "LINEログイン設定を保存しました。"); }}>
      <h3>サイト共通のLINEログイン</h3>
      <p>secret: {login?.secret_configured ? "設定済み（保存値は表示できません）" : "未設定"}／設定元: {login?.source === "database" ? "管理画面" : login?.source === "environment" ? "移行期の環境設定" : "未設定"}</p>
      <fieldset disabled={!writable || busy || !login}>
        <label>ログインChannel ID<input value={loginId} onChange={(event) => setLoginId(event.target.value)} required pattern="[0-9]{1,32}" /></label>
        <label>新しいログインChannel secret<input type="password" autoComplete="new-password" value={loginSecret} onChange={(event) => setLoginSecret(event.target.value)} required maxLength={255} /></label>
        <button type="submit">ログイン設定を保存</button>
      </fieldset>
    </form>
    <h3>Messaging API BOT</h3>
    {bots.length === 0 && <p>BOTはまだありません。</p>}
    {bots.map((bot) => <article key={bot.id} className="settings-panel">
      <h4>{bot.name} {bot.is_active ? "（有効）" : "（無効）"}</h4>
      <p>Channel ID: {bot.channel_id ?? "未設定・Human入力待ち"}／secret: {bot.secret_configured ? "設定済み" : "未設定"}</p>
      <p>接続: {({unconfigured: "未設定", pending: "確認待ち", succeeded: "成功", failed: "失敗"})[bot.connection_status]} {bot.display_name} {bot.basic_id}</p>
      <label>Webhookパス<input readOnly value={bot.webhook_path} /></label>
      <button type="button" onClick={() => void navigator.clipboard.writeText(bot.webhook_path).then(() => setMessage("Webhookパスをコピーしました。公開Webhookホストと組み合わせて設定してください。")).catch(() => setError("コピーできませんでした。パスを選択してコピーしてください。"))}>パスをコピー</button>
      <p>送信数: {bot.quota_snapshot ? `${bot.quota_snapshot.used ?? "—"} / ${bot.quota_snapshot.type === "none" ? "上限なし" : bot.quota_snapshot.limit ?? "—"}` : "未取得"}（取得日時: {bot.quota_fetched_at ?? "—"}）</p>
      <p>友だち: {bot.followers_snapshot?.status === "ready" ? `${bot.followers_snapshot.followers ?? "—"}／ブロック ${bot.followers_snapshot.blocks ?? "—"}／届く人数 ${bot.followers_snapshot.targetedReaches ?? "—"}` : bot.followers_snapshot ? "取得できませんでした（LINEの集計待ち）" : "未取得"}（取得日時: {bot.followers_fetched_at ?? "—"}）</p>
      <fieldset disabled={!writable || busy}>
        <button type="button" onClick={() => {setEditing(bot); setName(bot.name); setChannel(bot.channel_id ?? ""); setSecret("");}}>編集</button>
        {([ ["connection", "接続を確認"], ["activation", "このBOTを有効化"], ["quota", "送信数を取得"], ["followers", "友だち数を取得"] ] as const).map(([operation, label]) => <button key={operation} type="button" disabled={operation === "activation" && (bot.is_active || bot.connection_status !== "succeeded")} onClick={() => void perform(() => client.lineBotAction(bot.id, operation, bot.revision, crypto.randomUUID()), `${label}：完了しました。`)}>{label}</button>)}
      </fieldset>
    </article>)}
    <form onSubmit={(event) => { event.preventDefault(); void perform(async () => {
      if (editing) await client.updateLineBot(editing.id, {name, channel_id: channel, ...(secret ? {channel_secret: secret} : {}), expected_revision: editing.revision}, crypto.randomUUID());
      else await client.createLineBot({name, channel_id: channel, channel_secret: secret}, crypto.randomUUID());
      setEditing(null); setName(""); setChannel("");
    }, "BOTを保存しました。接続確認を行ってください。"); }}>
      <h3>{editing ? "BOTを編集" : "BOTを追加"}</h3>
      <fieldset disabled={!writable || busy}>
        <label>管理名<input value={name} onChange={(event) => setName(event.target.value)} required maxLength={100} /></label>
        <label>Messaging API Channel ID<input value={channel} readOnly={Boolean(editing?.channel_id)} onChange={(event) => setChannel(event.target.value)} required pattern="[0-9]{1,32}" /></label>
        <p>別の公式アカウントへ変更する場合は、新しいBOTを追加して切り替えてください。</p>
        <label>新しいMessaging API Channel secret<input type="password" autoComplete="new-password" value={secret} onChange={(event) => setSecret(event.target.value)} required={!editing} maxLength={255} /></label>
        <p>保存済みsecretは表示できません。変更する場合は新しい値を入力してください。送信用アクセストークンは自動取得します。</p>
        <button type="submit">BOTを保存</button>
        {editing && <button type="button" onClick={() => {setEditing(null); setName(""); setChannel(""); setSecret("");}}>編集をやめる</button>}
      </fieldset>
    </form>
  </section>;
}
