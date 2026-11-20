"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineMediaAsset } from "@/lib/admin-api/generated";
import { usePermissions } from "@/components/permissions/permission-provider";
import { MediaUpload } from "./line-message-editor";
import styles from "./line-admin.module.css";

const kinds = { image: "画像", video: "動画", imagemap: "イメージマップ", carousel: "画像カルーセル" };
export function LineUploadWorkspace() {
  const client = useMemo(() => new AdminApiClient(), []);
  const { permissions } = usePermissions();
  const [kind, setKind] = useState<keyof typeof kinds>("image");
  const [assets, setAssets] = useState<LineMediaAsset[]>([]);
  const [page, setPage] = useState(1);
  const [next, setNext] = useState<number | null>(null);
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState("");
  const [refresh, setRefresh] = useState(0);
  const scope = useRef("");
  useEffect(() => {
    let active = true;
    void client.lineChatMedia(page, kind).then((r) => { if (active) {
      const current = `${r.bot_id}:${r.authentication_revision}`;
      if (scope.current && scope.current !== current && page !== 1) { scope.current = current; setPage(1); return; }
      scope.current = current; setAssets(r.data); setNext(r.next_page);
    } })
      .catch(() => { if (active) setError("メディア一覧を取得できませんでした。"); })
      .finally(() => { if (active) setBusy(false); });
    return () => { active = false; };
  }, [client, page, kind, refresh]);
  function reload(p: number) { setBusy(true); setError(""); setAssets([]); setNext(null); setPage(p); setRefresh((v) => v + 1); }
  return <div>
    <p>現在有効なBOT・認証世代で検証済みの素材を表示します。BOT切替後は再読み込みしてください。登録済み素材はチャット・テンプレート・一斉配信から選択できます。</p>
    <label>メディア種別<select value={kind} onChange={(e) => { setKind(e.target.value as keyof typeof kinds); reload(1); }}>{Object.entries(kinds).map(([k, label]) => <option key={k} value={k}>{label}</option>)}</select></label>
    {permissions.has("line.bot.manage") ? <MediaUpload key={kind} client={client} kind={kind} onUploaded={() => reload(1)} /> : <p>登録はOwnerのみ操作できます。</p>}
    {error && <p role="alert">{error}</p>}{busy && <p role="status">読み込み中…</p>}
    <button className="secondary-button" disabled={busy} onClick={() => reload(1)}>一覧を再読込</button>
    <div className={styles.tableScroll}><table className="catalog-table"><thead><tr><th>プレビュー</th><th>種別</th><th>サイズ</th></tr></thead><tbody>{!busy && assets.filter((a) => a.kind === kind).map((a) => <tr key={a.id}><td>{a.kind === "video" ? <video className={styles.mediaPreview} controls preload="none" poster={a.preview_url} src={a.original_url} /> :
      // eslint-disable-next-line @next/next/no-img-element
      <img className={styles.mediaPreview} alt={kinds[kind]} src={a.preview_url} referrerPolicy="no-referrer" />}</td><td>{kinds[kind]}</td><td>{a.width}×{a.height}</td></tr>)}</tbody></table></div>
    {!busy && !error && assets.length === 0 && <p>{page === 1 ? "この種類のメディアはまだ登録されていません。" : "このページに素材はありません。前のページへ戻ってください。"}</p>}
    <div><button className="secondary-button" disabled={busy || page === 1} onClick={() => reload(page - 1)}>前のページ</button><span>{page}ページ</span><button className="secondary-button" disabled={busy || next === null} onClick={() => next !== null && reload(next)}>次のページ</button></div>
  </div>;
}
