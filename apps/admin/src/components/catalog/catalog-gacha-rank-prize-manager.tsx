"use client";

import { Edit3, FileWarning, LoaderCircle, Plus, Settings2, X } from "lucide-react";
import Image from "next/image";
import { FormEvent, useEffect, useId, useMemo, useRef, useState } from "react";

import { PublicAssetPreview, safePublicPath } from "@/components/catalog/public-asset-preview";
import { CatalogBannerAssetPicker } from "@/components/catalog/catalog-banner-asset-picker";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminCatalogRank,
  AdminGachaVersionPrize,
  AdminRankEffect,
} from "@/lib/admin-api/generated";

type LoadState = "idle" | "loading" | "ready" | "error";

export function CatalogGachaRankPrizeManager({
  canManage,
  gachaId,
  heading = "ランク／景品管理",
  presentationOnly = false,
  versionLabel,
  version,
}: {
  canManage: boolean;
  gachaId: string;
  heading?: string;
  presentationOnly?: boolean;
  versionLabel?: string;
  version: AdminCatalogGachaVersion | null;
}) {
  const headingId = useId();
  const client = useMemo(() => new AdminApiClient(), []);
  const [loadState, setLoadState] = useState<LoadState>("idle");
  const [error, setError] = useState<string | null>(null);
  const [ranks, setRanks] = useState<AdminCatalogRank[]>([]);
  const [prizes, setPrizes] = useState<AdminGachaVersionPrize[]>([]);
  const [rankEffects, setRankEffects] = useState<AdminRankEffect[]>([]);
  const [versionRevision, setVersionRevision] = useState(version?.revision ?? 0);
  const [rankDialog, setRankDialog] = useState(false);
  const [rankEditing, setRankEditing] = useState<AdminCatalogRank | null>(null);
  const [rankFormOpen, setRankFormOpen] = useState(false);
  const [prizeEditing, setPrizeEditing] = useState<AdminGachaVersionPrize | null>(null);
  const [prizeDialog, setPrizeDialog] = useState(false);
  const [busy, setBusy] = useState(false);
  const firstDialogControl = useRef<HTMLInputElement>(null);

  async function load(signal?: AbortSignal) {
    if (!version) return;
    setLoadState("loading");
    setError(null);
    try {
      const [rankResult, prizeResult, rankEffectResult] = await Promise.all([
        client.listGachaVersionRanks(gachaId, version.id, signal),
        client.listGachaVersionPrizes(gachaId, version.id, signal),
        listAllRankEffects(client, signal),
      ]);
      setRanks(rankResult.items);
      setPrizes(prizeResult.items);
      setRankEffects(rankEffectResult);
      setVersionRevision(Math.max(rankResult.version_revision, prizeResult.version_revision));
      setLoadState("ready");
    } catch (cause) {
      if (signal?.aborted) return;
      setError(errorMessage(cause));
      setLoadState("error");
    }
  }

  useEffect(() => {
    if (!version) return;
    const controller = new AbortController();
    queueMicrotask(() => {
      if (!controller.signal.aborted) void load(controller.signal);
    });
    return () => controller.abort();
  }, [gachaId, version?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (rankDialog || prizeDialog) firstDialogControl.current?.focus();
  }, [rankDialog, prizeDialog, rankFormOpen]);

  if (!version) {
    return (
      <section className="catalog-rank-prize-section" aria-labelledby={headingId}>
        <h2 id={headingId}>{heading}</h2>
        <p className="catalog-version-empty">対象データはありません。</p>
      </section>
    );
  }
  const versionId = version.id;

  async function submitRank(form: HTMLFormElement) {
    const data = new FormData(form);
    const common = {
      name: String(data.get("name") ?? "").trim(),
      description: nullable(String(data.get("description") ?? "")),
      image_asset_id: nullable(String(data.get("image_asset_id") ?? "")),
      video_asset_id: nullable(String(data.get("video_asset_id") ?? "")),
      expected_version_revision: versionRevision,
    };
    setBusy(true);
    setError(null);
    try {
      if (rankEditing) {
        await client.updateGachaVersionRank(
          gachaId,
          versionId,
          rankEditing.id,
          { ...common, expected_revision: rankEditing.revision ?? 1 },
          crypto.randomUUID(),
        );
      } else {
        await client.createGachaVersionRank(
          gachaId,
          versionId,
          { ...common, code: String(data.get("code") ?? "").trim() },
          crypto.randomUUID(),
        );
      }
      setRankEditing(null);
      setRankFormOpen(false);
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  async function submitPrize(form: HTMLFormElement) {
    const data = new FormData(form);
    const common = {
      rank_id: String(data.get("rank_id") ?? ""),
      presentation_asset_id: nullable(String(data.get("presentation_asset_id") ?? "")),
      name: String(data.get("name") ?? "").trim(),
      total_inventory: Number(data.get("total_inventory")),
      exchange_points: Number(data.get("exchange_points")),
      cost_price: Number(data.get("cost_price")),
      is_active: data.get("is_active") === "true",
      expected_version_revision: versionRevision,
    };
    setBusy(true);
    setError(null);
    try {
      if (prizeEditing) {
        await client.updateGachaVersionPrize(
          gachaId,
          versionId,
          prizeEditing.id,
          {
            ...common,
            available_inventory: Number(data.get("available_inventory")),
            expected_revision: prizeEditing.revision,
            expected_inventory_revision: prizeEditing.inventory_revision ?? 0,
            inventory_reason: String(data.get("inventory_reason") ?? "").trim(),
          },
          crypto.randomUUID(),
        );
      } else {
        await client.createGachaVersionPrize(
          gachaId,
          versionId,
          common,
          crypto.randomUUID(),
        );
      }
      setPrizeDialog(false);
      setPrizeEditing(null);
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="catalog-rank-prize-section" aria-labelledby={headingId}>
      <header className="catalog-rank-prize-heading">
        <div>
          <span className="eyebrow">{versionLabel ?? `バージョン ${version.version_number}`}</span>
          <h2 id={headingId}>{heading}</h2>
        </div>
        {canManage && !presentationOnly ? (
          <button className="secondary-button" onClick={() => setRankDialog(true)} type="button">
            <Settings2 aria-hidden="true" size={17} /> ランク設定
          </button>
        ) : null}
      </header>
      {loadState === "loading" ? (
        <p className="catalog-inline-loading"><LoaderCircle aria-hidden="true" size={18} /> 読み込み中</p>
      ) : null}
      {error ? <p className="form-field-error" role="alert">{error}</p> : null}
      <div className="catalog-prize-heading">
        <h3>景品一覧</h3>
        {canManage && !presentationOnly ? (
          <button
            className="primary-button"
            disabled={ranks.length === 0}
            onClick={() => { setPrizeEditing(null); setPrizeDialog(true); }}
            type="button"
          >
            <Plus aria-hidden="true" size={17} /> 新規景品登録
          </button>
        ) : null}
      </div>
      {loadState === "ready" && prizes.length === 0 ? (
        <p className="catalog-version-empty">登録済み景品はありません。</p>
      ) : null}
      {prizes.length > 0 ? (
        <div className="catalog-table-wrap">
          <table className="catalog-table">
            <thead><tr><th>ランク</th><th>景品名</th><th>サムネイル</th><th>総在庫数</th><th>現在個数</th><th>交換ポイント</th><th>状態</th><th>登録日</th><th>編集</th></tr></thead>
            <tbody>{prizes.map((prize) => (
              <tr key={prize.id}>
                <td>{prize.rank.name}</td>
                <td>{prize.name}</td>
                <td><PublicAssetPreview asset={prize.presentation_asset} /></td>
                <td>{(prize.total_inventory ?? 0).toLocaleString()}</td>
                <td>{(prize.available_inventory ?? 0).toLocaleString()}</td>
                <td>{prize.exchange_points.toLocaleString()}</td>
                <td>{prize.is_visible ? "有効" : "無効"}</td>
                <td>{formatJst(prize.created_at)}</td>
                <td>{canManage ? (
                  <button
                    aria-label={`${prize.name}を編集`}
                    className="icon-button"
                    onClick={() => { setPrizeEditing(prize); setPrizeDialog(true); }}
                    title="編集"
                    type="button"
                  ><Edit3 aria-hidden="true" size={16} /></button>
                ) : "-"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}

      {rankDialog ? (
        <Dialog title="ランク設定" onClose={() => { setRankDialog(false); setRankFormOpen(false); }}>
          <div className="catalog-rank-list">
            <div className="catalog-prize-heading">
              <h3>登録済みランク</h3>
              <button className="primary-button" onClick={() => { setRankEditing(null); setRankFormOpen(true); }} type="button"><Plus aria-hidden="true" size={16} /> 追加</button>
            </div>
            {ranks.length === 0 ? <p className="catalog-version-empty">登録済みランクはありません。</p> : ranks.map((rank) => (
              <div className="catalog-rank-row" key={rank.id}>
                <div><strong>{rank.name}</strong><code>{rank.code}</code><p>{rank.description || "説明未設定"}</p></div>
                <button aria-label={`${rank.name}を編集`} className="icon-button" onClick={() => { setRankEditing(rank); setRankFormOpen(true); }} type="button"><Edit3 aria-hidden="true" size={16} /></button>
              </div>
            ))}
          </div>
          {rankFormOpen ? <RankForm effects={rankEffects} busy={busy} current={rankEditing} inputRef={firstDialogControl} onCancel={() => setRankFormOpen(false)} onSubmit={submitRank} /> : null}
        </Dialog>
      ) : null}
      {prizeDialog ? (
        <Dialog title={prizeEditing ? "景品編集" : "新規景品登録"} onClose={() => setPrizeDialog(false)}>
          <PrizeForm busy={busy} current={prizeEditing} inputRef={firstDialogControl} key={prizeEditing?.id ?? "new"} onCancel={() => setPrizeDialog(false)} onSubmit={submitPrize} presentationOnly={presentationOnly} ranks={ranks} />
        </Dialog>
      ) : null}
    </section>
  );
}

function Dialog({ children, onClose, title }: { children: React.ReactNode; onClose: () => void; title: string }) {
  const titleId = useId();
  const dialogRef = useRef<HTMLElement>(null);
  const returnFocus = useRef<HTMLElement | null>(null);
  useEffect(() => {
    returnFocus.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    dialogRef.current?.querySelector<HTMLElement>("button, input, select, textarea")?.focus();
    return () => returnFocus.current?.focus();
  }, []);
  return <div className="dialog-backdrop" onMouseDown={(event) => { if (event.currentTarget === event.target) onClose(); }} role="presentation">
    <section aria-labelledby={titleId} aria-modal="true" className="catalog-mutation-panel catalog-rank-prize-dialog" onKeyDown={(event) => { if (event.key === "Escape") onClose(); }} ref={dialogRef} role="dialog">
      <header className="catalog-dialog-title"><h2 id={titleId}>{title}</h2><button aria-label="閉じる" className="icon-button" onClick={onClose} type="button"><X aria-hidden="true" size={18} /></button></header>
      {children}
    </section>
  </div>;
}

function RankForm({ effects, busy, current, inputRef, onCancel, onSubmit }: { effects: AdminRankEffect[]; busy: boolean; current: AdminCatalogRank | null; inputRef: React.RefObject<HTMLInputElement | null>; onCancel: () => void; onSubmit: (form: HTMLFormElement) => Promise<void> }) {
  return <form className="catalog-mutation-form catalog-inline-form" onSubmit={(event: FormEvent<HTMLFormElement>) => { event.preventDefault(); void onSubmit(event.currentTarget); }}>
    <label>ランクキー<input defaultValue={current?.code ?? ""} disabled={current !== null} maxLength={32} name="code" pattern="[a-z][a-z0-9_-]*" ref={current ? undefined : inputRef} required /></label>
    <label>ランク表示<input defaultValue={current?.name ?? ""} maxLength={128} name="name" ref={current ? inputRef : undefined} required /></label>
    <label>説明<textarea defaultValue={current?.description ?? ""} maxLength={2000} name="description" /></label>
    <RankPresentationAssetPicker busy={busy} current={current?.image_asset ?? null} effects={effects} key={`image-${current?.image_asset?.id ?? "new"}`} label="ランク画像" mediaType="image" name="image_asset_id" />
    <RankPresentationAssetPicker busy={busy} current={current?.video_asset ?? null} effects={effects} key={`video-${current?.video_asset?.id ?? "new"}`} label="抽選演出動画" mediaType="video" name="video_asset_id" />
    <div className="catalog-dialog-actions"><button className="secondary-button" onClick={onCancel} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit">{busy ? "保存中" : "保存"}</button></div>
  </form>;
}

function PrizeForm({ busy, current, inputRef, onCancel, onSubmit, presentationOnly, ranks }: { busy: boolean; current: AdminGachaVersionPrize | null; inputRef: React.RefObject<HTMLInputElement | null>; onCancel: () => void; onSubmit: (form: HTMLFormElement) => Promise<void>; presentationOnly: boolean; ranks: AdminCatalogRank[] }) {
  const [presentationAssetId, setPresentationAssetId] = useState(current?.presentation_asset?.id ?? null);
  const [selectedBannerId, setSelectedBannerId] = useState<string | null>(null);
  const [bannerPickerChanged, setBannerPickerChanged] = useState(false);
  const [bannerPickerError, setBannerPickerError] = useState<string | null>(null);

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (bannerPickerChanged && selectedBannerId === null) {
      setBannerPickerError("選択したBanner CategoryからBannerを選択してください。");
      return;
    }
    setBannerPickerError(null);
    void onSubmit(event.currentTarget);
  }

  return <form className="catalog-mutation-form" onSubmit={submit}>
    <label>ランク<select defaultValue={current?.rank.id ?? ""} disabled={presentationOnly} name={presentationOnly ? undefined : "rank_id"} required><option disabled value="">選択してください</option>{ranks.map((rank) => <option key={rank.id} value={rank.id}>{rank.name}</option>)}</select>{presentationOnly ? <input name="rank_id" type="hidden" value={current?.rank.id ?? ""} /> : null}</label>
    <label>景品名<input defaultValue={current?.name ?? ""} maxLength={191} name="name" ref={inputRef} required /></label>
    <CatalogBannerAssetPicker
      assetId={presentationAssetId}
      disabled={busy}
      onSelectionChange={(selection) => {
        setBannerPickerChanged(selection.changed);
        setPresentationAssetId(selection.assetId);
        setSelectedBannerId(selection.bannerId);
      }}
    />
    <input name="presentation_asset_id" type="hidden" value={presentationAssetId ?? ""} />
    {bannerPickerError ? <p className="form-field-error" role="alert">{bannerPickerError}</p> : null}
    <div className="catalog-form-grid">
      <label>総在庫数<input defaultValue={current?.total_inventory ?? 0} min={0} name="total_inventory" required type="number" /></label>
      {current ? <label>現在個数<input defaultValue={current.available_inventory ?? 0} min={0} name="available_inventory" required type="number" /></label> : null}
      <label>交換ポイント<input defaultValue={current?.exchange_points ?? 0} min={0} name="exchange_points" readOnly={presentationOnly} required type="number" /></label>
      <label>原価<input defaultValue={current?.cost_price ?? 0} min={0} name="cost_price" readOnly={presentationOnly} required type="number" /></label>
      <label>状態<select defaultValue={String(current?.is_visible ?? true)} disabled={presentationOnly} name={presentationOnly ? undefined : "is_active"}><option value="true">有効</option><option value="false">無効</option></select>{presentationOnly ? <input name="is_active" type="hidden" value={String(current?.is_visible ?? true)} /> : null}</label>
    </div>
    {current ? <label>在庫変更理由<textarea maxLength={500} name="inventory_reason" required /></label> : null}
    <div className="catalog-dialog-actions"><button className="secondary-button" onClick={onCancel} type="button">キャンセル</button><button className="primary-button" disabled={busy} type="submit">{busy ? "保存中" : "保存"}</button></div>
  </form>;
}

function RankPresentationAssetPicker({
  busy,
  current,
  effects,
  label,
  mediaType,
  name,
}: {
  busy: boolean;
  current: AdminCatalogRank["image_asset"];
  effects: AdminRankEffect[];
  label: string;
  mediaType: "image" | "video";
  name: string;
}) {
  const options = effects.filter((effect) => effect.media_type === mediaType);
  const [selectedId, setSelectedId] = useState(current?.id ?? null);
  const selected = options.find((effect) => effect.id === selectedId) ?? null;
  const unavailable = selectedId !== null && selected === null;

  return (
    <fieldset className="catalog-banner-picker">
      <legend>{label}</legend>
      <input name={name} type="hidden" value={selectedId ?? ""} />
      {unavailable ? (
        <p className="catalog-banner-picker-note">
          現在のAssetはランク演出候補として解決できません。変更しなければ既存の値は保持されます。
        </p>
      ) : null}
      {options.length === 0 ? (
        <p className="catalog-banner-picker-note">選択可能なランク演出はありません。</p>
      ) : (
        <div aria-label={`${label}候補`} className="catalog-banner-options">
          <button
            aria-pressed={selectedId === null}
            className="catalog-banner-option"
            disabled={busy}
            onClick={() => setSelectedId(null)}
            type="button"
          >
            <span className="catalog-rank-effect-empty-preview"><FileWarning aria-hidden="true" size={20} /></span>
            <span>未設定</span>
          </button>
          {options.map((effect) => (
            <button
              aria-pressed={selectedId === effect.id}
              className="catalog-banner-option"
              disabled={busy}
              key={effect.id}
              onClick={() => setSelectedId(effect.id)}
              type="button"
            >
              <RankPresentationAssetPreview effect={effect} />
              <span>{effect.alt_text ?? effect.id}</span>
            </button>
          ))}
        </div>
      )}
    </fieldset>
  );
}

function RankPresentationAssetPreview({ effect }: { effect: AdminRankEffect }) {
  const path = safePublicPath(effect.content_path) ? effect.content_path : effect.public_path;
  if (!safePublicPath(path)) {
    return <span className="catalog-rank-effect-empty-preview"><FileWarning aria-hidden="true" size={20} /></span>;
  }
  if (effect.media_type === "video") {
    return <video aria-hidden="true" muted preload="metadata" src={path} />;
  }
  return <Image alt="" height={56} src={path} unoptimized width={96} />;
}

async function listAllRankEffects(client: AdminApiClient, signal?: AbortSignal): Promise<AdminRankEffect[]> {
  const effects: AdminRankEffect[] = [];
  let cursor: string | undefined;
  do {
    const response = await client.listRankEffects({ archive: "active", cursor, direction: "asc", limit: 100, sort: "created_at" }, signal);
    effects.push(...response.items);
    cursor = response.next_cursor ?? undefined;
  } while (cursor);
  return effects;
}

function nullable(value: string): string | null { return value === "" ? null : value; }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function errorMessage(cause: unknown): string { return cause instanceof AdminApiError ? `${cause.code}（${cause.status}）` : "通信に失敗しました。"; }
