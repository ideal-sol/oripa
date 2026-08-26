"use client";

import { ArrowLeft, LoaderCircle, Pencil, Plus, RotateCcw, Upload } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { FormEvent, useCallback, useEffect, useMemo, useRef, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type { AdminRankEffect } from "@/lib/admin-api/generated";

type Mode = "list" | "create" | "edit";
type LoadState =
  | { kind: "loading" }
  | { kind: "error"; message: string }
  | { kind: "list"; items: AdminRankEffect[]; nextCursor: string | null }
  | { kind: "form"; effect: AdminRankEffect | null };

export function RankEffectSettingsWorkspace({
  id,
  initialVisibility = "visible",
  mode,
}: {
  id?: string;
  initialVisibility?: "all" | "hidden" | "visible";
  mode: Mode;
}) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <RankEffectWorkspace id={id} initialVisibility={initialVisibility} mode={mode} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function RankEffectWorkspace({ id, initialVisibility, mode }: { id?: string; initialVisibility: "all" | "hidden" | "visible"; mode: Mode }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const { hasPermission } = usePermissions();
  const [state, setState] = useState<LoadState>({ kind: "loading" });
  const [cursor, setCursor] = useState<string | null>(null);
  const [reload, setReload] = useState(0);
  const [visibility, setVisibility] = useState(initialVisibility);

  useEffect(() => {
    const controller = new AbortController();
    const promise = mode === "list"
      ? client.listRankEffects({
          cursor: cursor ?? undefined,
          direction: "desc",
          limit: 20,
          sort: "created_at",
          visibility,
        }, controller.signal).then((result) => ({
          kind: "list" as const,
          items: result.items,
          nextCursor: result.next_cursor,
        }))
      : (mode === "edit" && id
          ? client.getRankEffect(id, controller.signal)
          : Promise.resolve(null)
        ).then((detail) => ({
          kind: "form" as const,
          effect: detail?.data ?? null,
        }));
    void promise.then(setState).catch((cause: unknown) => {
      if (!controller.signal.aborted) {
        setState({ kind: "error", message: errorMessage(cause) });
      }
    });
    return () => controller.abort();
  }, [client, cursor, id, mode, reload, visibility]);

  const retry = useCallback(() => {
    setState({ kind: "loading" });
    setReload((value) => value + 1);
  }, []);

  const loadNext = useCallback((nextCursor: string | null) => {
    setState({ kind: "loading" });
    setCursor(nextCursor);
  }, []);

  return (
    <section className="workspace rank-effect-workspace">
      <RankEffectBreadcrumb mode={mode} />
      <AdminPageHeader
        action={mode === "list" && hasPermission("catalog.manage") ? (
          <Link className="primary-button" href="/catalog/presentation-assets/new">
            <Plus aria-hidden="true" size={17} />新規登録
          </Link>
        ) : mode !== "list" ? (
          <Link className="secondary-button" href="/catalog/presentation-assets">
            <ArrowLeft aria-hidden="true" size={17} />一覧へ戻る
          </Link>
        ) : undefined}
        description="ガチャRankで使用する画像・動画演出素材を管理します。"
        eyebrow="Settings"
        title={mode === "list" ? "ランク演出" : mode === "create" ? "ランク演出登録" : "ランク演出編集"}
      />
      {mode === "list" ? (
        <label className="announcement-filter">
          状態
          <select value={visibility} onChange={(event) => { setState({ kind: "loading" }); setVisibility(event.target.value as typeof visibility); setCursor(null); }}>
            <option value="visible">有効</option>
            <option value="hidden">無効</option>
            <option value="all">すべて</option>
          </select>
        </label>
      ) : null}
      {state.kind === "loading" ? <RankEffectState loading message="ランク演出を読み込んでいます。" /> : null}
      {state.kind === "error" ? <RankEffectState error message={state.message} retry={retry} /> : null}
      {state.kind === "list" ? (
        <RankEffectList
          items={state.items}
          nextCursor={state.nextCursor}
          onNext={() => loadNext(state.nextCursor)}
          onReset={() => loadNext(null)}
        />
      ) : null}
      {state.kind === "form" ? (
        <RankEffectForm client={client} effect={state.effect} mode={mode} />
      ) : null}
    </section>
  );
}

function RankEffectList({
  items,
  nextCursor,
  onNext,
  onReset,
}: {
  items: AdminRankEffect[];
  nextCursor: string | null;
  onNext: () => void;
  onReset: () => void;
}) {
  if (items.length === 0) return <RankEffectState message="登録済みのランク演出はありません。" />;
  return (
    <section className="rank-effect-list" aria-labelledby="rank-effect-list-heading">
      <div className="rank-effect-section-heading">
        <div><span className="eyebrow">Asset Master</span><h2 id="rank-effect-list-heading">登録済み演出</h2></div>
      </div>
      <div className="table-container rank-effect-table-container">
        <table>
          <thead><tr><th>種別</th><th>タイトル</th><th>ランク</th><th>プレビュー</th><th>表示順</th><th>状態</th><th>更新日時</th><th>操作</th></tr></thead>
          <tbody>{items.map((item) => (
            <tr key={item.id}>
              <td>{item.media_type === "image" ? "画像" : "動画"}</td>
              <td>{item.alt_text ?? "未設定"}</td>
              <td>{item.rank_assignments.map((assignment) => assignment.rank.name).join("、")}</td>
              <td><RankEffectPreview compact effect={item} /></td>
              <td>{item.rank_assignments.map((assignment) => assignment.sort_order).join("、")}</td>
              <td><span className={`status-badge ${item.is_public ? "is-success" : "is-muted"}`}>{item.is_public ? "有効" : "無効"}</span></td>
              <td>{formatDate(item.updated_at)}</td>
              <td><Link aria-label={`${item.alt_text ?? "ランク演出"}を編集`} className="icon-button" href={`/catalog/presentation-assets/${item.id}/edit`} title="編集"><Pencil aria-hidden="true" size={17} /></Link></td>
            </tr>
          ))}</tbody>
        </table>
      </div>
      <div className="rank-effect-pagination">
        <button className="secondary-button" disabled={!nextCursor} onClick={onNext} type="button">次へ</button>
        <button className="secondary-button" onClick={onReset} type="button"><RotateCcw aria-hidden="true" size={16} />先頭へ</button>
      </div>
    </section>
  );
}

function RankEffectForm({
  client,
  effect,
  mode,
}: {
  client: AdminApiClient;
  effect: AdminRankEffect | null;
  mode: Mode;
}) {
  const router = useRouter();
  const [currentEffect, setCurrentEffect] = useState(effect);
  const [title, setTitle] = useState(effect?.alt_text ?? "");
  const [assetType, setAssetType] = useState<"image" | "video">(effect?.media_type ?? "image");
  const [active, setActive] = useState(effect?.is_public ?? true);
  const [file, setFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const key = useRef<string | null>(null);

  useEffect(() => () => {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
  }, [previewUrl]);

  function selectFile(next: File | null) {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    setFile(next);
    setPreviewUrl(next ? URL.createObjectURL(next) : null);
    setError(null);
    if (next?.type.startsWith("image/")) setAssetType("image");
    if (next?.type.startsWith("video/")) setAssetType("video");
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setMessage(null);
    if (!title.trim() || (!currentEffect && !file)) {
      setError("タイトルと登録ファイルを確認してください。");
      return;
    }
    if (file && !validFile(file, assetType)) {
      setError(assetType === "image" ? "画像はGIF/JPEG/PNG/WebP、5MB以下です。" : "動画はMP4/WebM/QuickTime、50MB以下です。");
      return;
    }
    setBusy(true);
    try {
      const encodedFile = file ? await fileToBase64(file) : null;
      const filePayload = file && encodedFile ? {
        file_name: file.name,
        mime_type: file.type,
        content_base64: encodedFile,
      } : {};
      key.current ??= crypto.randomUUID();
      const common = {
        asset_type: assetType,
        is_active: active,
        title: title.trim(),
        ...filePayload,
      };
      const result = currentEffect
        ? await client.updateRankEffect(currentEffect.id, {
            expected_revision: currentEffect.revision ?? 1,
            ...common,
          }, key.current)
        : await client.createRankEffect({
            ...common,
            file_name: file!.name,
            mime_type: file!.type,
            content_base64: encodedFile!,
          }, key.current);
      key.current = null;
      setCurrentEffect(result.data);
      setMessage(result.idempotent_replay ? "保存済みの結果を再表示しました。" : "ランク演出を保存しました。");
      router.replace(`/catalog/presentation-assets/${result.data.id}/edit`);
    } catch (cause) {
      if (!(cause instanceof AdminApiError) || !cause.retryable) key.current = null;
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="rank-effect-form" noValidate onSubmit={submit}>
      <section className="rank-effect-form-section" aria-labelledby="rank-effect-basic-heading">
        <div className="rank-effect-section-heading"><div><span className="eyebrow">Basic</span><h2 id="rank-effect-basic-heading">演出情報</h2></div></div>
        {error ? <p className="error-alert" role="alert">{error}</p> : null}
        {message ? <p className="status-alert" role="status">{message}</p> : null}
        <div className="rank-effect-fields">
          <label><span>タイトル</span><input maxLength={191} onChange={(event) => setTitle(event.target.value)} required value={title} /></label>
          <fieldset><legend>種別</legend><div className="segmented-control"><label><input checked={assetType === "image"} disabled={Boolean(currentEffect && !file)} name="asset-type" onChange={() => setAssetType("image")} type="radio" />画像</label><label><input checked={assetType === "video"} disabled={Boolean(currentEffect && !file)} name="asset-type" onChange={() => setAssetType("video")} type="radio" />動画</label></div></fieldset>
          <label><span>状態</span><select onChange={(event) => setActive(event.target.value === "active")} value={active ? "active" : "inactive"}><option value="active">有効</option><option value="inactive">無効</option></select></label>
          <div className="rank-effect-file"><label htmlFor="rank-effect-file">{currentEffect ? "ファイル差し替え（任意）" : "ファイル"}</label><input accept="image/gif,image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" id="rank-effect-file" onChange={(event) => selectFile(event.target.files?.[0] ?? null)} required={!currentEffect} type="file" /><small>画像5MB以下、動画50MB以下</small></div>
        </div>
        <div className="rank-effect-preview-panel">
          <h3>プレビュー</h3>
          {previewUrl ? <LocalPreview mediaType={assetType} url={previewUrl} /> : currentEffect ? <RankEffectPreview effect={currentEffect} /> : <p className="empty-state">ファイルを選択するとPreviewを表示します。</p>}
        </div>
      </section>
      <div className="rank-effect-actions"><button className="primary-button" disabled={busy} type="submit">{busy ? <LoaderCircle aria-hidden="true" className="spin" size={17} /> : <Upload aria-hidden="true" size={17} />}{busy ? "保存中" : "保存"}</button><Link className="secondary-button" href="/catalog/presentation-assets">キャンセル</Link></div>
    </form>
  );
}

function RankEffectPreview({ compact = false, effect }: { compact?: boolean; effect: AdminRankEffect }) {
  return <LocalPreview compact={compact} mediaType={effect.media_type} url={effect.content_path} />;
}

function LocalPreview({ compact = false, mediaType, url }: { compact?: boolean; mediaType: "image" | "video"; url: string }) {
  const encodedUrl = encodeURI(url);
  return mediaType === "image"
    // eslint-disable-next-line @next/next/no-img-element
    ? <img alt="ランク演出プレビュー" className={compact ? "rank-effect-thumbnail" : "rank-effect-preview"} src={encodedUrl} />
    : <video aria-label="ランク演出プレビュー" className={compact ? "rank-effect-thumbnail" : "rank-effect-preview"} controls={!compact} muted playsInline preload="metadata" src={encodedUrl} />;
}

function RankEffectBreadcrumb({ mode }: { mode: Mode }) {
  return <nav aria-label="パンくず" className="breadcrumb"><ol><li><Link href="/">ダッシュボード</Link></li><li><span aria-hidden="true">/</span><Link href="/catalog/presentation-assets">ランク演出</Link></li>{mode !== "list" ? <li aria-current="page"><span aria-hidden="true">/</span>{mode === "create" ? "新規登録" : "編集"}</li> : null}</ol></nav>;
}

function RankEffectState({ error = false, loading = false, message, retry }: { error?: boolean; loading?: boolean; message: string; retry?: () => void }) {
  return <section className={`module-state ${error ? "is-error" : ""}`} role={error ? "alert" : "status"}>{loading ? <LoaderCircle aria-hidden="true" className="spin" size={22} /> : null}<p>{message}</p>{retry ? <button className="secondary-button" onClick={retry} type="button"><RotateCcw aria-hidden="true" size={16} />再試行</button> : null}</section>;
}

function validFile(file: File, type: "image" | "video") {
  const allowed = type === "image" ? ["image/gif", "image/jpeg", "image/png", "image/webp"] : ["video/mp4", "video/webm", "video/quicktime"];
  return allowed.includes(file.type) && file.size > 0 && file.size <= (type === "image" ? 5 : 50) * 1024 * 1024;
}

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onerror = () => reject(reader.error); reader.onload = () => resolve(String(reader.result).split(",", 2)[1] ?? ""); reader.readAsDataURL(file); });
}

function errorMessage(cause: unknown) {
  if (cause instanceof AdminApiError) return cause.status === 409 ? "別の更新と競合しました。再読み込みしてください。" : "ランク演出を処理できませんでした。";
  return "通信に失敗しました。";
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Tokyo" }).format(new Date(value));
}
