"use client";

import { GripVertical, LoaderCircle, Pencil, Plus, X } from "lucide-react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { assetContentPath } from "@/components/catalog/public-asset-preview";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogRank,
  AdminCatalogRankCreate,
  AdminCatalogRankUpdate,
  AdminDirectUpload,
} from "@/lib/admin-api/generated";

type RankStatusFilter = "active" | "inactive" | "all";
type LoadState =
  | { kind: "loading" }
  | { kind: "ready"; items: AdminCatalogRank[] }
  | { kind: "error"; message: string };

export function RankMasterWorkspace({ id }: { id?: string }) {
  return (
    <AdminShell>
      <ProtectedAdminRoute permission="catalog.read">
        <RankMasterContent id={id} />
      </ProtectedAdminRoute>
    </AdminShell>
  );
}

function RankMasterContent({ id }: { id?: string }) {
  const client = useMemo(() => new AdminApiClient(), []);
  const router = useRouter();
  const { hasPermission } = usePermissions();
  const canManage = hasPermission("catalog.manage");
  const [status, setStatus] = useState<RankStatusFilter>("active");
  const [state, setState] = useState<LoadState>({ kind: "loading" });
  const [reload, setReload] = useState(0);
  const [editing, setEditing] = useState<AdminCatalogRank | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [draggingId, setDraggingId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const mutationKey = useRef<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    const list = client.listCatalogRanks({
      direction: "asc",
      limit: 100,
      sort: "display_order",
      status,
    }, controller.signal);
    const detail = id ? client.getCatalogRank(id, controller.signal) : Promise.resolve(null);
    void Promise.all([list, detail])
      .then(([result, selected]) => {
        setState({ kind: "ready", items: result.items });
        if (selected) {
          setEditing(selected.data);
          setModalOpen(true);
        }
      })
      .catch((cause: unknown) => {
        if (!controller.signal.aborted) {
          setState({ kind: "error", message: errorMessage(cause) });
        }
      });
    return () => controller.abort();
  }, [client, id, reload, status]);

  async function save(input: AdminCatalogRankCreate | AdminCatalogRankUpdate) {
    setBusy(true);
    setError(null);
    try {
      mutationKey.current ??= crypto.randomUUID();
      const result = editing
        ? await client.updateCatalogRank(editing.id, input as AdminCatalogRankUpdate, mutationKey.current)
        : await client.createCatalogRank(input as AdminCatalogRankCreate, mutationKey.current);
      mutationKey.current = null;
      setMessage(result.idempotent_replay ? "保存済みの結果を再表示しました。" : "ランクを保存しました。");
      setModalOpen(false);
      setEditing(null);
      if (id) router.replace("/catalog/ranks");
      setReload((value) => value + 1);
    } catch (cause) {
      if (!(cause instanceof AdminApiError) || !cause.retryable) mutationKey.current = null;
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  async function reorder(targetId: string) {
    if (state.kind !== "ready" || draggingId === null || draggingId === targetId) return;
    const sourceIndex = state.items.findIndex((item) => item.id === draggingId);
    const targetIndex = state.items.findIndex((item) => item.id === targetId);
    if (sourceIndex < 0 || targetIndex < 0) return;
    const next = [...state.items];
    const [moved] = next.splice(sourceIndex, 1);
    next.splice(targetIndex, 0, moved);
    setState({ kind: "ready", items: next });
    setDraggingId(null);
    setBusy(true);
    setError(null);
    try {
      const result = await client.reorderCatalogRanks({
        items: next.map((item, displayOrder) => ({
          rank_id: item.id,
          expected_revision: item.revision,
          display_order: displayOrder,
        })),
      }, crypto.randomUUID());
      setState({ kind: "ready", items: result.data.items });
      setMessage(result.idempotent_replay ? "保存済みの表示順を再表示しました。" : "表示順を更新しました。");
    } catch (cause) {
      setError(errorMessage(cause));
      setReload((value) => value + 1);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="workspace">
      <AdminPageHeader
        action={canManage ? (
          <button className="primary-button" onClick={() => { setEditing(null); setModalOpen(true); setError(null); }} type="button">
            <Plus aria-hidden="true" size={17} />ランク登録
          </button>
        ) : undefined}
        description="全Gacha共通のRank Masterと現在のPresentation Revisionを管理します。"
        eyebrow="Catalog"
        title="ランク"
      />
      <label className="announcement-filter">
        状態
        <select value={status} onChange={(event) => setStatus(event.target.value as RankStatusFilter)}>
          <option value="active">有効</option>
          <option value="inactive">無効</option>
          <option value="all">すべて</option>
        </select>
      </label>
      {message ? <p className="status-alert" role="status">{message}</p> : null}
      {error ? <p className="error-alert" role="alert">{error}</p> : null}
      {state.kind === "loading" ? <RankState loading message="ランクを読み込んでいます。" /> : null}
      {state.kind === "error" ? <RankState message={state.message} /> : null}
      {state.kind === "ready" && state.items.length === 0 ? <RankState message="対象のランクはありません。" /> : null}
      {state.kind === "ready" && state.items.length > 0 ? (
        <div className="table-container">
          <table>
            <thead><tr><th aria-label="並べ替え" /><th>Rank名</th><th>ラインナップ画像</th><th>抽選結果画像</th><th>総在庫表示</th><th>状態</th><th>編集</th></tr></thead>
            <tbody>
              {state.items.map((item) => (
                <tr
                  draggable={canManage && status === "active" && !busy}
                  key={item.id}
                  onDragOver={(event) => event.preventDefault()}
                  onDragStart={() => setDraggingId(item.id)}
                  onDrop={() => void reorder(item.id)}
                >
                  <td><GripVertical aria-hidden="true" size={18} /></td>
                  <td>{item.rank_name}</td>
                  <td><RankImage asset={item.lineup_image} /></td>
                  <td><RankImage asset={item.result_image} /></td>
                  <td>{item.show_total_stock ? "表示" : "非表示"}</td>
                  <td><span className={`status-badge ${item.status === "active" ? "is-success" : "is-muted"}`}>{item.status === "active" ? "有効" : "無効"}</span></td>
                  <td>{canManage ? (
                    <button aria-label={`${item.rank_name}を編集`} className="icon-button" onClick={() => { setEditing(item); setModalOpen(true); setError(null); }} title="編集" type="button">
                      <Pencil aria-hidden="true" size={17} />
                    </button>
                  ) : "-"}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
      {modalOpen ? (
        <RankModal
          busy={busy}
          current={editing}
          error={error}
          onClose={() => { setModalOpen(false); setEditing(null); setError(null); if (id) router.replace("/catalog/ranks"); }}
          onSave={save}
        />
      ) : null}
    </section>
  );
}

function RankModal({
  busy,
  current,
  error,
  onClose,
  onSave,
}: {
  busy: boolean;
  current: AdminCatalogRank | null;
  error: string | null;
  onClose: () => void;
  onSave: (input: AdminCatalogRankCreate | AdminCatalogRankUpdate) => Promise<void>;
}) {
  const [name, setName] = useState(current?.rank_name ?? "");
  const [showTotalStock, setShowTotalStock] = useState(current?.show_total_stock ?? false);
  const [status, setStatus] = useState<"active" | "inactive">(current?.status ?? "active");
  const [lineup, setLineup] = useState<File | null>(null);
  const [result, setResult] = useState<File | null>(null);
  const [localError, setLocalError] = useState<string | null>(null);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLocalError(null);
    if (!name.trim() || (!current && (!lineup || !result))) {
      setLocalError("ランク名と2種類の画像を入力してください。");
      return;
    }
    if ((lineup && !validImage(lineup)) || (result && !validImage(result))) {
      setLocalError("画像はGIF/JPEG/PNG/WebP、5MB以下です。");
      return;
    }
    const presentationChanged = Boolean(
      current && (
        current.rank_name !== name.trim()
        || current.show_total_stock !== showTotalStock
        || lineup
        || result
      )
    );
    if (current?.used_by_published_gacha && presentationChanged && !window.confirm(
      "ランク設定を変更しますか？\n\nこのランクを使用している公開中のガチャにも変更内容が反映されます。\n変更前に実行された抽選結果には影響しません。",
    )) return;
    const images = await Promise.all([toUpload(lineup), toUpload(result)]);
    if (current) {
      await onSave({
        expected_revision: current.revision,
        rank_name: name.trim(),
        ...(images[0] ? { lineup_image: images[0] } : {}),
        ...(images[1] ? { result_image: images[1] } : {}),
        show_total_stock: showTotalStock,
        status,
      });
      return;
    }
    await onSave({
      rank_name: name.trim(),
      lineup_image: images[0]!,
      result_image: images[1]!,
      show_total_stock: showTotalStock,
      status,
    });
  }

  return (
    <div className="dialog-backdrop" onMouseDown={(event) => { if (event.currentTarget === event.target) onClose(); }} role="presentation">
      <section aria-modal="true" className="catalog-mutation-panel catalog-rank-prize-dialog" role="dialog">
        <header className="catalog-dialog-title"><h2>{current ? "ランク編集" : "ランク登録"}</h2><button aria-label="閉じる" className="icon-button" onClick={onClose} type="button"><X aria-hidden="true" size={18} /></button></header>
        <form className="catalog-mutation-form" onSubmit={(event) => void submit(event)}>
          {localError ? <p className="error-alert" role="alert">{localError}</p> : null}
          {error ? <p className="error-alert" role="alert">{error}</p> : null}
          <label>ランク名<input autoFocus maxLength={128} onChange={(event) => setName(event.target.value)} required value={name} /></label>
          <label>景品ラインナップ表示画像<input accept="image/gif,image/jpeg,image/png,image/webp" onChange={(event) => setLineup(event.target.files?.[0] ?? null)} required={!current} type="file" /></label>
          {current ? <RankImage asset={current.lineup_image} /> : null}
          <label>抽選結果表示画像<input accept="image/gif,image/jpeg,image/png,image/webp" onChange={(event) => setResult(event.target.files?.[0] ?? null)} required={!current} type="file" /></label>
          {current ? <RankImage asset={current.result_image} /> : null}
          <label className="catalog-checkbox-row"><input checked={showTotalStock} onChange={(event) => setShowTotalStock(event.target.checked)} type="checkbox" />総在庫数を表示</label>
          <label>状態<select disabled={Boolean(current?.has_usage)} onChange={(event) => setStatus(event.target.value as "active" | "inactive")} value={status}><option value="active">有効</option><option value="inactive">無効</option></select></label>
          {current?.has_usage ? <small>使用実績があるため無効化できません。</small> : null}
          <div className="catalog-dialog-actions"><button className="secondary-button" onClick={onClose} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit">{busy ? "保存中" : "保存"}</button></div>
        </form>
      </section>
    </div>
  );
}

function RankImage({ asset }: { asset: AdminCatalogRank["lineup_image"] }) {
  return <Image alt={asset.alt_text ?? "Rank image"} className="rank-effect-thumbnail" height={72} src={assetContentPath(asset.id)} unoptimized width={96} />;
}

function RankState({ loading = false, message }: { loading?: boolean; message: string }) {
  return <section className="module-state">{loading ? <LoaderCircle aria-hidden="true" className="spin" /> : null}<p>{message}</p></section>;
}

function validImage(file: File) {
  return ["image/gif", "image/jpeg", "image/png", "image/webp"].includes(file.type)
    && file.size > 0
    && file.size <= 5 * 1024 * 1024;
}

async function toUpload(file: File | null): Promise<AdminDirectUpload | null> {
  if (!file) return null;
  const dataUrl = await new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(reader.error);
    reader.onload = () => resolve(String(reader.result ?? ""));
    reader.readAsDataURL(file);
  });
  return {
    file_name: file.name,
    mime_type: file.type as AdminDirectUpload["mime_type"],
    content_base64: dataUrl.slice(dataUrl.indexOf(",") + 1),
  };
}

function errorMessage(cause: unknown) {
  if (cause instanceof AdminApiError) {
    if (cause.code === "CATALOG_RANK_IN_USE") return "使用実績があるランクは無効化できません。";
    if (cause.status === 409) return "別の更新と競合しました。再読み込みして再実行してください。";
    if (cause.status === 422) return "入力内容を確認してください。";
  }
  return "ランクを処理できませんでした。";
}
