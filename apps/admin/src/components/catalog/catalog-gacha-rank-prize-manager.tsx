"use client";

import { Edit3, LoaderCircle, Plus, X } from "lucide-react";
import Image from "next/image";
import { FormEvent, useEffect, useId, useMemo, useRef, useState } from "react";

import { catalogProblemMessage } from "@/components/catalog/catalog-api-error-boundary";
import { CatalogBannerAssetPicker } from "@/components/catalog/catalog-prize-asset-mutation-form";
import { assetContentPath, PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import type {
  AdminCatalogGachaVersion,
  AdminGachaRankListItem,
  AdminGachaVersionPrize,
  AdminRankEffect,
} from "@/lib/admin-api/generated";

type LoadState = "idle" | "loading" | "ready" | "error";

export function CatalogGachaRankPrizeManager({
  canManage,
  gachaId,
  heading = "Rank設定",
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
  const [ranks, setRanks] = useState<AdminGachaRankListItem[]>([]);
  const [prizes, setPrizes] = useState<AdminGachaVersionPrize[]>([]);
  const [rankEffects, setRankEffects] = useState<AdminRankEffect[]>([]);
  const [versionRevision, setVersionRevision] = useState(version?.revision ?? 0);
  const [busyRankId, setBusyRankId] = useState<string | null>(null);
  const [busyPrize, setBusyPrize] = useState(false);
  const [prizeRank, setPrizeRank] = useState<AdminGachaRankListItem | null>(null);
  const [prizeEditing, setPrizeEditing] = useState<AdminGachaVersionPrize | null>(null);
  const [prizeDialog, setPrizeDialog] = useState(false);
  const firstDialogControl = useRef<HTMLInputElement>(null);

  async function load(signal?: AbortSignal) {
    if (!version) return;
    setLoadState("loading");
    setError(null);
    try {
      const [rankResult, prizeResult, rankEffectResult] = await Promise.all([
        client.listGachaRanks(gachaId, signal),
        client.listGachaVersionPrizes(gachaId, version.id, signal),
        listAllRankEffects(client, signal),
      ]);
      setRanks(rankResult.items);
      setPrizes(prizeResult.items);
      setRankEffects(rankEffectResult.filter((effect) => effect.media_type === "video" && effect.is_public));
      setVersionRevision(prizeResult.version_revision);
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
    if (prizeDialog) firstDialogControl.current?.focus();
  }, [prizeDialog]);

  if (!version) {
    return (
      <section aria-labelledby={headingId} className="catalog-rank-prize-section">
        <h2 id={headingId}>{heading}</h2>
        <p className="catalog-version-empty">対象データはありません。</p>
      </section>
    );
  }
  const versionId = version.id;

  async function saveVideo(rank: AdminGachaRankListItem, assetId: string) {
    if (!canManage || assetId === rank.current_video?.id) return;
    if (presentationOnly && !confirmVideoChange(assetId === "")) return;
    if (assetId === "" && rank.current_video === null) return;
    setBusyRankId(rank.rank.id);
    setError(null);
    try {
      if (assetId === "") {
        if (!rank.can_unset_video || rank.gacha_rank_revision === null) {
          throw new AdminApiError(409, "CATALOG_GACHA_RANK_VIDEO_REQUIRED", null, null, false);
        }
        await client.unsetGachaRankVideo(
          gachaId,
          rank.rank.id,
          { expected_revision: rank.gacha_rank_revision },
          crypto.randomUUID(),
        );
      } else {
        await client.setGachaRankVideo(
          gachaId,
          rank.rank.id,
          {
            video_asset_id: assetId,
            ...(rank.gacha_rank_revision === null
              ? {}
              : { expected_revision: rank.gacha_rank_revision }),
          },
          crypto.randomUUID(),
        );
      }
      await load();
    } catch (cause) {
      const message = errorMessage(cause);
      await load();
      setError(message);
    } finally {
      setBusyRankId(null);
    }
  }

  function openPrizeCreate(rank: AdminGachaRankListItem) {
    if (rank.current_video === null) {
      setError("抽選演出動画が設定されていません。先に抽選演出動画を選択してください。");
      return;
    }
    setError(null);
    setPrizeRank(rank);
    setPrizeEditing(null);
    setPrizeDialog(true);
  }

  function openPrizeEdit(prize: AdminGachaVersionPrize) {
    const rank = ranks.find((item) => item.rank.id === prize.rank.id) ?? null;
    if (!rank) {
      setError("景品のCanonical Rankを解決できませんでした。");
      return;
    }
    setPrizeRank(rank);
    setPrizeEditing(prize);
    setPrizeDialog(true);
  }

  async function submitPrize(form: HTMLFormElement) {
    if (!prizeRank) return;
    const data = new FormData(form);
    const common = {
      presentation_asset_id: nullable(String(data.get("presentation_asset_id") ?? "")),
      name: String(data.get("name") ?? "").trim(),
      total_inventory: Number(data.get("total_inventory")),
      exchange_points: Number(data.get("exchange_points")),
      cost_price: Number(data.get("cost_price")),
      is_active: data.get("is_active") === "true",
      expected_version_revision: versionRevision,
    };
    setBusyPrize(true);
    setError(null);
    try {
      if (prizeEditing) {
        await client.updateGachaRankPrize(
          gachaId,
          versionId,
          prizeRank.rank.id,
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
        await client.createGachaRankPrize(
          gachaId,
          versionId,
          prizeRank.rank.id,
          common,
          crypto.randomUUID(),
        );
      }
      setPrizeDialog(false);
      setPrizeEditing(null);
      setPrizeRank(null);
      await load();
    } catch (cause) {
      setError(errorMessage(cause));
    } finally {
      setBusyPrize(false);
    }
  }

  return (
    <section aria-labelledby={headingId} className="catalog-rank-prize-section">
      <header className="catalog-rank-prize-heading">
        <div>
          <span className="eyebrow">{versionLabel ?? `バージョン ${version.version_number}`}</span>
          <h2 id={headingId}>{heading}</h2>
        </div>
      </header>
      {loadState === "loading" ? <p className="catalog-inline-loading"><LoaderCircle aria-hidden="true" size={18} /> 読み込み中</p> : null}
      {error ? <p className="form-field-error" role="alert">{error}</p> : null}
      {loadState === "ready" && ranks.length === 0 ? <p className="catalog-version-empty">有効なRank Masterはありません。</p> : null}
      {ranks.length > 0 ? (
        <div className="catalog-table-wrap">
          <table className="catalog-table">
            <thead><tr><th>Rank</th><th>ラインナップ画像</th><th>抽選結果画像</th><th>抽選演出動画</th><th>景品登録</th></tr></thead>
            <tbody>{ranks.map((rank) => (
              <tr key={rank.rank.id}>
                <td>{rank.rank.rank_name}</td>
                <td><RankImage asset={rank.rank.lineup_image} /></td>
                <td><RankImage asset={rank.rank.result_image} /></td>
                <td>
                  <div className="catalog-rank-video-control">
                    {rank.current_video ? <video aria-label={`${rank.rank.rank_name}の抽選演出`} controls muted playsInline preload="metadata" src={rank.current_video.path} /> : <span>未設定</span>}
                    <select
                      aria-label={`${rank.rank.rank_name}の動画`}
                      disabled={!canManage || busyRankId === rank.rank.id}
                      onChange={(event) => void saveVideo(rank, event.target.value)}
                      value={rank.current_video?.id ?? ""}
                    >
                      <option disabled={!rank.can_unset_video || rank.current_video === null} value="">未設定に戻す</option>
                      {rankEffects.map((effect) => <option key={effect.id} value={effect.id}>{effect.alt_text ?? effect.id}</option>)}
                    </select>
                  </div>
                </td>
                <td>{canManage && !presentationOnly ? (
                  <button className="secondary-button" onClick={() => openPrizeCreate(rank)} type="button"><Plus aria-hidden="true" size={16} />景品登録</button>
                ) : "-"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}

      <div className="catalog-prize-heading"><h3>登録済み景品</h3></div>
      {loadState === "ready" && prizes.length === 0 ? <p className="catalog-version-empty">登録済み景品はありません。</p> : null}
      {prizes.length > 0 ? (
        <div className="catalog-table-wrap">
          <table className="catalog-table">
            <thead><tr><th>ランク</th><th>景品名</th><th>サムネイル</th><th>交換ポイント</th><th>状態</th><th>登録日</th><th>編集</th></tr></thead>
            <tbody>{prizes.map((prize) => (
              <tr key={prize.id}>
                <td>{prize.rank.name}</td>
                <td>{prize.name}</td>
                <td><PublicAssetPreview allowAuthenticatedContent asset={prize.presentation_asset} /></td>
                <td>{prize.exchange_points.toLocaleString()}</td>
                <td>{prize.is_visible ? "有効" : "無効"}</td>
                <td>{formatJst(prize.created_at)}</td>
                <td>{canManage && !presentationOnly ? <button aria-label={`${prize.name}を編集`} className="icon-button" onClick={() => openPrizeEdit(prize)} title="編集" type="button"><Edit3 aria-hidden="true" size={16} /></button> : "-"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}

      {prizeDialog && prizeRank ? (
        <Dialog onClose={() => { setPrizeDialog(false); setPrizeEditing(null); setPrizeRank(null); }} title={prizeEditing ? "景品編集" : "新規景品登録"}>
          <PrizeForm
            busy={busyPrize}
            current={prizeEditing}
            inputRef={firstDialogControl}
            key={prizeEditing?.id ?? `new-${prizeRank.rank.id}`}
            onCancel={() => { setPrizeDialog(false); setPrizeEditing(null); setPrizeRank(null); }}
            onSubmit={submitPrize}
            prizes={prizes}
            rankName={prizeRank.rank.rank_name}
            totalCount={version.total_count}
          />
        </Dialog>
      ) : null}
    </section>
  );
}

function Dialog({ children, onClose, title }: { children: React.ReactNode; onClose: () => void; title: string }) {
  const titleId = useId();
  const dialogRef = useRef<HTMLElement>(null);
  useEffect(() => {
    dialogRef.current?.querySelector<HTMLElement>("button, input, select, textarea")?.focus();
  }, []);
  return <div className="dialog-backdrop" onMouseDown={(event) => { if (event.currentTarget === event.target) onClose(); }} role="presentation">
    <section aria-labelledby={titleId} aria-modal="true" className="catalog-mutation-panel catalog-rank-prize-dialog" onKeyDown={(event) => { if (event.key === "Escape") onClose(); }} ref={dialogRef} role="dialog">
      <header className="catalog-dialog-title"><h2 id={titleId}>{title}</h2><button aria-label="閉じる" className="icon-button" onClick={onClose} type="button"><X aria-hidden="true" size={18} /></button></header>
      {children}
    </section>
  </div>;
}

function PrizeForm({ busy, current, inputRef, onCancel, onSubmit, prizes, rankName, totalCount }: { busy: boolean; current: AdminGachaVersionPrize | null; inputRef: React.RefObject<HTMLInputElement | null>; onCancel: () => void; onSubmit: (form: HTMLFormElement) => Promise<void>; prizes: AdminGachaVersionPrize[]; rankName: string; totalCount: number }) {
  const [presentationAssetId, setPresentationAssetId] = useState(current?.presentation_asset?.id ?? null);
  const [selectedBannerId, setSelectedBannerId] = useState<string | null>(null);
  const [bannerPickerChanged, setBannerPickerChanged] = useState(false);
  const [bannerPickerError, setBannerPickerError] = useState<string | null>(null);
  const [totalInventory, setTotalInventory] = useState(current?.total_inventory ?? 0);
  const otherPrizeInventory = prizes.filter((prize) => prize.id !== current?.id).reduce((total, prize) => total + prize.total_inventory, 0);
  const remainingTotalCount = totalCount - otherPrizeInventory - totalInventory;

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (bannerPickerChanged && selectedBannerId === null) {
      setBannerPickerError("選択したBanner CategoryからBannerを選択してください。");
      return;
    }
    if (remainingTotalCount < 0) {
      setBannerPickerError("景品の総在庫数がガチャの総口数を超えています。総在庫数を減らしてください。");
      return;
    }
    setBannerPickerError(null);
    void onSubmit(event.currentTarget);
  }

  return <form className="catalog-mutation-form" onSubmit={submit}>
    <label>ランク<input readOnly value={rankName} /></label>
    <label>景品名<input defaultValue={current?.name ?? ""} maxLength={191} name="name" ref={inputRef} required /></label>
    <CatalogBannerAssetPicker assetId={presentationAssetId} disabled={busy} onSelectionChange={(selection) => { setBannerPickerChanged(selection.changed); setPresentationAssetId(selection.assetId); setSelectedBannerId(selection.bannerId); }} />
    <input name="presentation_asset_id" type="hidden" value={presentationAssetId ?? ""} />
    {bannerPickerError ? <p className="form-field-error" role="alert">{bannerPickerError}</p> : null}
    <div className="catalog-form-grid">
      <label>総在庫数<input aria-label="総在庫数" min={0} name="total_inventory" onChange={(event) => setTotalInventory(Number(event.target.value))} required type="number" value={totalInventory} /><span className="field-hint">（総口数残り{remainingTotalCount.toLocaleString()}）</span></label>
      {current ? <label>現在個数<input defaultValue={current.available_inventory ?? 0} min={0} name="available_inventory" required type="number" /></label> : null}
      <label>交換ポイント<input defaultValue={current?.exchange_points ?? 0} min={0} name="exchange_points" required type="number" /></label>
      <label>原価<input defaultValue={current?.cost_price ?? 0} min={0} name="cost_price" required type="number" /></label>
      <label>状態<select defaultValue={String(current?.is_visible ?? true)} name="is_active"><option value="true">有効</option><option value="false">無効</option></select></label>
    </div>
    {remainingTotalCount < 0 ? <p className="form-field-error" role="alert">景品の総在庫数がガチャの総口数を超えています。総在庫数を減らしてください。</p> : null}
    {current ? <label>変更理由<textarea maxLength={500} name="inventory_reason" required /></label> : null}
    <div className="catalog-dialog-actions"><button className="secondary-button" onClick={onCancel} type="button">キャンセル</button><button className="primary-button" disabled={busy || remainingTotalCount < 0} type="submit">{busy ? "保存中" : "保存"}</button></div>
  </form>;
}

function RankImage({ asset }: { asset: AdminGachaRankListItem["rank"]["lineup_image"] }) {
  return <Image alt={asset.alt_text ?? "Rank image"} height={56} src={assetContentPath(asset.id)} unoptimized width={96} />;
}

async function listAllRankEffects(client: AdminApiClient, signal?: AbortSignal): Promise<AdminRankEffect[]> {
  const effects: AdminRankEffect[] = [];
  let cursor: string | undefined;
  do {
    const response = await client.listRankEffects({ cursor, direction: "asc", limit: 100, sort: "created_at", visibility: "visible" }, signal);
    effects.push(...response.items);
    cursor = response.next_cursor ?? undefined;
  } while (cursor);
  return effects;
}

function confirmVideoChange(unset: boolean) {
  return window.confirm(
    `抽選演出動画を${unset ? "解除" : "変更"}しますか？\n\n公開中のガチャです。\n変更後に開始される抽選から新しい動画が適用されます。\n変更前の抽選結果には影響しません。`,
  );
}

function nullable(value: string): string | null { return value === "" ? null : value; }
function formatJst(value: string): string { return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeZone: "Asia/Tokyo" }).format(new Date(value)); }
function errorMessage(cause: unknown): string {
  if (cause instanceof AdminApiError && cause.code === "CATALOG_GACHA_RANK_VIDEO_REQUIRED") return "景品が登録されているため、抽選演出動画を未設定に戻せません。";
  return cause instanceof AdminApiError ? catalogProblemMessage(cause) : "通信に失敗しました。";
}
