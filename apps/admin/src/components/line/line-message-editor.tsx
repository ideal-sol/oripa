"use client";

import styles from "./line-admin.module.css";
import { useEffect, useRef, useState } from "react";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { LineDeliveryMessage, LineMediaAsset, LineMediaIntentInput } from "@/lib/admin-api/generated";

const types = ["text", "image", "video", "imagemap", "image_carousel"] as const;
const names = ["テキスト", "画像", "動画", "イメージマップ", "画像カルーセル"];
function MediaPreview({ url }: { url: string }) {
  if (!url.startsWith("https://")) return null;
  // Verified CDN media must not be relayed through the Admin image optimizer.
  // eslint-disable-next-line @next/next/no-img-element
  return <img src={url} alt="送信内容の参考プレビュー" referrerPolicy="no-referrer" className={styles.mediaPreview} />;
}
export function newLineMessage(type: typeof types[number]): LineDeliveryMessage {
  switch (type) {
    case "text": return { type, text: "" };
    case "image": case "video": return { type, originalContentUrl: "", previewImageUrl: "" };
    case "imagemap": return { type, baseUrl: "", altText: "", height: 1040, layout: "full", links: [""] };
    case "image_carousel": return { type, altText: "", columns: [] };
  }
}

export function MediaUpload({ client, kind, onUploaded, selectionOnly = false }: { client: AdminApiClient; kind: LineMediaIntentInput["kind"]; onUploaded: (asset: LineMediaAsset) => void; selectionOnly?: boolean }) {
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [status, setStatus] = useState("");
  const [failed, setFailed] = useState(false);
  const [assets, setAssets] = useState<LineMediaAsset[]>([]);
  const [page, setPage] = useState<number | null>(1);
  const [scope, setScope] = useState("");
  const generation = useRef(0);
  useEffect(() => () => { generation.current++; }, [kind]);
  async function load(reset = false) {
    const version = generation.current;
    setBusy(true); setFailed(false); setStatus("");
    try {
      const r = await client.lineChatMedia(reset ? 1 : page ?? 1, kind);
      if (version !== generation.current) return;
      const nextScope = `${r.bot_id}:${r.authentication_revision}`;
      if (!reset && scope && scope !== nextScope) {
        setAssets([]); setPage(1); setScope(""); setStatus("BOTが変更されました。素材を再読み込みしてください。"); return;
      }
      setAssets((old) => reset ? r.data : [...old, ...r.data]); setScope(nextScope); setPage(r.next_page);
      if (!r.data.length && (reset || page === 1)) setStatus("この種類のメディアはまだ登録されていません。");
    } catch { if (version === generation.current) { setFailed(true); setStatus("メディアを取得できませんでした。再読み込みしてください。"); } }
    finally { if (version === generation.current) setBusy(false); }
  }
  async function select(asset: LineMediaAsset) {
    const version = generation.current;
    setBusy(true); setFailed(false);
    try {
      const current = await client.lineChatMedia(1, kind);
      if (version !== generation.current) return;
      if (scope !== `${current.bot_id}:${current.authentication_revision}`) {
        setAssets(current.data); setPage(current.next_page); setScope(`${current.bot_id}:${current.authentication_revision}`);
        setFailed(true); setStatus("BOTが変更されました。現在の素材を選び直してください。"); return;
      }
      onUploaded(asset);
    } catch { if (version === generation.current) { setFailed(true); setStatus("素材の有効性を確認できませんでした。再読み込みしてください。"); } }
    finally { if (version === generation.current) setBusy(false); }
  }
  async function metadata(value: File) {
    const digest = await crypto.subtle.digest("SHA-256", await value.arrayBuffer());
    return { bytes: value.size, mime: value.type as "image/jpeg" | "image/png" | "video/mp4", sha256: Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("") };
  }
  async function upload() {
    if (!file || (kind === "video" && !preview)) return;
    setBusy(true); setFailed(false); setStatus("ファイルを検証中…");
    try {
      if (file.size > (kind === "video" ? 200 : 10) * 1024 * 1024 || (preview && preview.size > 1024 * 1024)) throw new Error();
      const intent = await client.createLineMedia({ kind, original: await metadata(file), ...(preview ? { preview: await metadata(preview) } : {}) });
      setStatus("アップロード中…");
      for (const target of intent.uploads) {
        const body = target.slot === "original" ? file : preview!;
        const headers = Object.fromEntries(Object.entries(target.headers).filter(([key]) => !["content-length", "host"].includes(key.toLowerCase())));
        const response = await fetch(target.url, { method: "PUT", body, headers: headers as Record<string, string>, credentials: "omit", referrerPolicy: "no-referrer" });
        if (!response.ok) throw new Error();
      }
      setStatus("アップロード完了・実体検証中…");
      const asset = await client.finalizeLineMedia(intent.id); onUploaded(asset); setStatus("実体検証が完了し、素材を登録しました。");
    } catch { setFailed(true); setStatus("アップロードまたは実体検証に失敗しました。形式・容量・縦横比、権限、CDN設定を確認してください。"); }
    finally { setBusy(false); }
  }
  if (selectionOnly) return <fieldset className={styles.surface} disabled={busy}><legend>検証済みメディアを選択</legend>
    <p>有効BOTの検証済みファイルを使用します。新規ファイルはLINE関連のアップロード画面でOwnerが登録できます。</p>
    <button className="secondary-button" type="button" disabled={page === null} onClick={() => void load()}>メディアを読み込む</button>
    <button className="secondary-button" type="button" onClick={() => void load(true)}>素材を再読み込み</button>
    {assets.filter((a) => a.kind === kind).map((a) => <button className="secondary-button" type="button" key={a.id} onClick={() => void select(a)}><MediaPreview url={a.preview_url} />{a.width}×{a.height} を選択</button>)}<p role={failed ? "alert" : "status"}>{status}</p>
  </fieldset>;
  return <fieldset className={styles.surface} disabled={busy}>
    <legend>{kind === "video" ? "MP4（200MB以下）と同じ縦横比のプレビュー" : kind === "carousel" ? "正方形のJPEG/PNG（10MB以下）" : "JPEG/PNG（10MB以下）"}</legend>
    <input aria-label="アップロードするファイル" type="file" accept={kind === "video" ? "video/mp4" : "image/jpeg,image/png"} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
    {kind === "video" && <label>プレビュー（JPEG/PNG・1MB以下）<input type="file" accept="image/jpeg,image/png" onChange={(e) => setPreview(e.target.files?.[0] ?? null)} /></label>}
    <button className="secondary-button" type="button" disabled={!file || (kind === "video" && !preview)} onClick={() => void upload()}>アップロードして実体検証</button>
    <p role={failed ? "alert" : "status"}>{status}</p>
  </fieldset>;
}

/** Shared D5/D6 editor. It only emits the fixed Admin message contract. */
export function LineMessageEditor({ messages, onChange, client, disabled = false, chat = false }: { messages: LineDeliveryMessage[]; onChange: (m: LineDeliveryMessage[]) => void; client: AdminApiClient; disabled?: boolean; chat?: boolean }) {
  const replace = (index: number, m: LineDeliveryMessage) => onChange(messages.map((old, i) => i === index ? m : old));
  const move = (i: number, delta: number) => { const copy = [...messages]; [copy[i], copy[i + delta]] = [copy[i + delta], copy[i]]; onChange(copy); };
  return <fieldset className={styles.surface} disabled={disabled}><legend>送信メッセージ（1〜5件・混在可能）</legend>
    <p>表示は参考プレビューです。実LINE表示を保証しません。{chat ? "この返信は送信数にカウントされます。" : "タグありは1人ずつPush、タグなしは最大500人ずつMulticastです。"}</p>
    {messages.map((m, i) => <section key={i} aria-label={`メッセージ ${i + 1}`}>
      <label>形式<select value={m.type} onChange={(e) => replace(i, newLineMessage(e.target.value as typeof types[number]))}>{types.map((t, index) => <option key={t} value={t}>{names[index]}</option>)}</select></label>
      {(m.type === "image" || m.type === "video") && <MediaPreview url={m.previewImageUrl} />}
      {m.type === "imagemap" && m.baseUrl && <MediaPreview url={m.baseUrl + "/240"} />}
      {m.type === "image_carousel" && <div className={styles.carousel}>{m.columns.map((c, j) => <MediaPreview key={j} url={c.imageUrl} />)}</div>}
      {m.type === "text" && <><label>本文<textarea value={m.text} maxLength={5000} onChange={(e) => replace(i, { ...m, text: e.target.value })} /></label><p>{m.text.length}/5000（UTF-16単位）</p><div className={styles.textPreview}>{m.text}</div></>}
      {(m.type === "imagemap" || m.type === "image_carousel") && <label>通知・代替テキスト<textarea maxLength={1500} value={m.altText} onChange={(e) => replace(i, { ...m, altText: e.target.value })} /></label>}
      {(m.type === "text" || m.type === "imagemap" || m.type === "image_carousel") && <div>{["%username%", "%rank%", "%points%"].map((tag) => <button className="secondary-button" type="button" key={tag} onClick={() => replace(i, m.type === "text" ? { ...m, text: m.text + tag } : { ...m, altText: m.altText + tag })}>{tag}を挿入</button>)}</div>}
      {(m.type === "image" || m.type === "video") && <><MediaUpload key={m.type} selectionOnly client={client} kind={m.type} onUploaded={(a) => replace(i, { ...m, originalContentUrl: a.original_url, previewImageUrl: a.preview_url })} />{m.previewImageUrl && <p>検証済みメディアを選択済み</p>}</>}
      {m.type === "imagemap" && <><MediaUpload key={m.type} selectionOnly client={client} kind="imagemap" onUploaded={(a) => replace(i, { ...m, baseUrl: a.original_url, height: a.height })} /><label>画像全体のリンク先<input type="url" value={m.links[0]} onChange={(e) => replace(i, { ...m, links: [e.target.value] })} /></label><p>画像全体の1領域です。HTTPS・許可ホストのみ。URLは入力どおりに使用します。</p></>}
      {m.type === "image_carousel" && <>{m.columns.map((c, j) => <fieldset className={styles.surface} key={j}><legend>画像 {j + 1}</legend><label>HTTPSリンク<input type="url" value={c.uri} onChange={(e) => replace(i, { ...m, columns: m.columns.map((v, k) => k === j ? { ...v, uri: e.target.value } : v) })} /></label><label>ボタン文言（任意）<input maxLength={20} value={c.label ?? ""} onChange={(e) => replace(i, { ...m, columns: m.columns.map((v, k) => k === j ? { ...v, label: e.target.value } : v) })} /></label><button className="danger-button" type="button" onClick={() => replace(i, { ...m, columns: m.columns.filter((_, k) => j !== k) })}>画像を削除</button></fieldset>)}{m.columns.length < 10 && <MediaUpload key={m.type} selectionOnly client={client} kind="carousel" onUploaded={(a) => replace(i, { ...m, columns: [...m.columns, { imageUrl: a.original_url, uri: "" }] })} />}</>}
      <button className="secondary-button" type="button" disabled={i === 0} onClick={() => move(i, -1)}>上へ</button><button className="secondary-button" type="button" disabled={i === messages.length - 1} onClick={() => move(i, 1)}>下へ</button><button className="danger-button" type="button" disabled={messages.length === 1} onClick={() => onChange(messages.filter((_, j) => j !== i))}>メッセージを削除</button>
    </section>)}
    <button className="secondary-button" type="button" disabled={messages.length >= 5} onClick={() => onChange([...messages, newLineMessage("text")])}>メッセージを追加</button>
  </fieldset>;
}
