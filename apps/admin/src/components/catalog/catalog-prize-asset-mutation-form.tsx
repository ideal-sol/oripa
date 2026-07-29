"use client";

import { LoaderCircle, X } from "lucide-react";
import { type FormEvent, useEffect, useMemo, useRef, useState } from "react";

import { AdminApiClient } from "@/lib/admin-api/client";
import type {
  AdminCatalogPresentationAsset,
  AdminCatalogPrize,
  AdminCatalogRank,
} from "@/lib/admin-api/generated";

export interface CatalogPrizeDraft {
  kind: "prize";
  code: string;
  rankId: string;
  presentationAssetId: string | null;
  name: string;
  description: string | null;
  displayPrice: number;
  exchangePoints: number;
  isVisible: boolean;
}

export interface CatalogAssetDraft {
  kind: "asset";
  storageIdentifier: string;
  publicPath: string;
  checksumSha256: string;
  mediaType: "image" | "video";
  mimeType: string;
  byteSize: number;
  altText: string | null;
  isPublic: boolean;
}

export type CatalogPrizeAssetDraft = CatalogPrizeDraft | CatalogAssetDraft;

export function CatalogPrizeAssetMutationForm({
  current,
  mode,
  resource,
  onCancel,
  onSubmit,
}: {
  current?: AdminCatalogPrize | AdminCatalogPresentationAsset;
  mode: "create" | "edit";
  resource: "prizes" | "presentation-assets";
  onCancel: () => void;
  onSubmit: (draft: CatalogPrizeAssetDraft) => Promise<void>;
}) {
  const initial = useMemo(
    () => initialDraft(resource, current),
    [current, resource],
  );
  const [draft, setDraft] = useState(initial);
  const [ranks, setRanks] = useState<AdminCatalogRank[]>([]);
  const [assets, setAssets] = useState<AdminCatalogPresentationAsset[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial);

  useEffect(() => {
    heading.current?.focus();
  }, []);
  useEffect(() => {
    if (resource !== "prizes") return;
    const controller = new AbortController();
    const client = new AdminApiClient();
    Promise.all([
      client.listCatalogRanks(
        { direction: "asc", limit: 100, sort: "sort_order", visibility: "visible" },
        controller.signal,
      ),
      client.listCatalogPresentationAssets(
        { direction: "desc", limit: 100, sort: "created_at", visibility: "visible" },
        controller.signal,
      ),
    ])
      .then(([rankResponse, assetResponse]) => {
        setRanks(rankResponse.items.filter((item) => !item.is_archived));
        setAssets(assetResponse.items.filter((item) => !item.is_archived));
      })
      .catch(() => setError("選択肢を取得できませんでした。"));
    return () => controller.abort();
  }, [resource]);
  useEffect(() => {
    if (!dirty) return;
    const guard = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener("beforeunload", guard);
    return () => window.removeEventListener("beforeunload", guard);
  }, [dirty]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    if (!validDraft(draft, mode)) {
      setError("入力内容を確認してください。HTMLや不正なPathは入力できません。");
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit(trimDraft(draft));
    } catch {
      setError("保存できませんでした。画面の案内に従って再試行してください。");
    } finally {
      setSubmitting(false);
    }
  }

  function cancel() {
    if (dirty && !window.confirm("未保存の変更を破棄しますか。")) return;
    onCancel();
  }

  return (
    <div className="dialog-backdrop" role="presentation">
      <section
        aria-labelledby="catalog-prize-asset-heading"
        aria-modal="true"
        className="dialog-panel catalog-mutation-panel"
        role="dialog"
      >
        <header className="dialog-header">
          <div>
            <span className="eyebrow">Catalog Master</span>
            <h2 id="catalog-prize-asset-heading" ref={heading} tabIndex={-1}>
              {mode === "create" ? "新規作成" : "編集"}
            </h2>
          </div>
          <button
            aria-label="閉じる"
            className="icon-button"
            disabled={submitting}
            onClick={cancel}
            type="button"
          >
            <X size={18} aria-hidden="true" />
          </button>
        </header>
        <form className="catalog-mutation-form" onSubmit={submit}>
          {draft.kind === "prize" ? (
            <>
              {mode === "create" ? (
                <label>
                  Code
                  <input
                    maxLength={64}
                    onChange={(event) => setDraft({ ...draft, code: event.target.value })}
                    pattern="[a-z][a-z0-9_-]*"
                    required
                    value={draft.code}
                  />
                </label>
              ) : (
                <p className="catalog-immutable-code">
                  Code <code>{draft.code}</code>
                </p>
              )}
              <label>
                Rank
                <select
                  onChange={(event) => setDraft({ ...draft, rankId: event.target.value })}
                  required
                  value={draft.rankId}
                >
                  <option value="">選択してください</option>
                  {ranks.map((rank) => (
                    <option key={rank.id} value={rank.id}>{rank.name}</option>
                  ))}
                </select>
              </label>
              <label>
                Presentation Asset
                <select
                  onChange={(event) =>
                    setDraft({
                      ...draft,
                      presentationAssetId: event.target.value || null,
                    })
                  }
                  value={draft.presentationAssetId ?? ""}
                >
                  <option value="">未設定</option>
                  {assets.map((asset) => (
                    <option key={asset.id} value={asset.id}>
                      {asset.alt_text ?? asset.public_path ?? asset.id}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                名称
                <input
                  maxLength={191}
                  onChange={(event) => setDraft({ ...draft, name: event.target.value })}
                  required
                  value={draft.name}
                />
              </label>
              <label>
                説明
                <textarea
                  maxLength={2000}
                  onChange={(event) =>
                    setDraft({ ...draft, description: event.target.value })
                  }
                  rows={4}
                  value={draft.description ?? ""}
                />
              </label>
              <label>
                表示価格
                <input
                  min={0}
                  onChange={(event) =>
                    setDraft({ ...draft, displayPrice: Number(event.target.value) })
                  }
                  required
                  type="number"
                  value={draft.displayPrice}
                />
              </label>
              <label>
                交換Point
                <input
                  min={0}
                  onChange={(event) =>
                    setDraft({ ...draft, exchangePoints: Number(event.target.value) })
                  }
                  required
                  type="number"
                  value={draft.exchangePoints}
                />
              </label>
              <BooleanField
                checked={draft.isVisible}
                label="公開Catalogへ表示する"
                onChange={(value) => setDraft({ ...draft, isVisible: value })}
              />
            </>
          ) : (
            <>
              {mode === "create" ? (
                <>
                  <TextField label="Storage識別子" value={draft.storageIdentifier}
                    onChange={(value) => setDraft({ ...draft, storageIdentifier: value })} />
                  <TextField label="Public Path" value={draft.publicPath}
                    onChange={(value) => setDraft({ ...draft, publicPath: value })} />
                  <TextField label="SHA-256" value={draft.checksumSha256} maxLength={64}
                    onChange={(value) => setDraft({ ...draft, checksumSha256: value })} />
                  <label>
                    Media種別
                    <select
                      onChange={(event) =>
                        setDraft({
                          ...draft,
                          mediaType: event.target.value as "image" | "video",
                        })
                      }
                      value={draft.mediaType}
                    >
                      <option value="image">Image</option>
                      <option value="video">Video</option>
                    </select>
                  </label>
                  <TextField label="MIME Type" value={draft.mimeType}
                    onChange={(value) => setDraft({ ...draft, mimeType: value })} />
                  <label>
                    Byte Size
                    <input min={0} onChange={(event) =>
                      setDraft({ ...draft, byteSize: Number(event.target.value) })
                    } required type="number" value={draft.byteSize} />
                  </label>
                </>
              ) : (
                <p className="catalog-immutable-code">
                  Object識別情報は作成後変更できません。
                </p>
              )}
              <TextField label="Alt" required={false} value={draft.altText ?? ""}
                onChange={(value) => setDraft({ ...draft, altText: value || null })} />
              <BooleanField checked={draft.isPublic} label="Public Assetとして公開する"
                onChange={(value) => setDraft({ ...draft, isPublic: value })} />
            </>
          )}
          {error ? <p aria-live="assertive" className="form-error" role="alert">{error}</p> : null}
          <div className="catalog-dialog-actions">
            <button className="secondary-button" disabled={submitting} onClick={cancel} type="button">
              取り消し
            </button>
            <button className="primary-button" disabled={submitting || !dirty} type="submit">
              {submitting ? <LoaderCircle className="spin" size={16} aria-hidden="true" /> : null}
              保存
            </button>
          </div>
        </form>
      </section>
    </div>
  );
}

function TextField({ label, value, onChange, maxLength = 512, required = true }: {
  label: string; value: string; onChange: (value: string) => void;
  maxLength?: number; required?: boolean;
}) {
  return <label>{label}<input maxLength={maxLength} onChange={(event) =>
    onChange(event.target.value)
  } required={required} value={value} /></label>;
}

function BooleanField({ checked, label, onChange }: {
  checked: boolean; label: string; onChange: (value: boolean) => void;
}) {
  return <label className="catalog-checkbox"><input checked={checked}
    onChange={(event) => onChange(event.target.checked)} type="checkbox" />{label}</label>;
}

function initialDraft(
  resource: "prizes" | "presentation-assets",
  current?: AdminCatalogPrize | AdminCatalogPresentationAsset,
): CatalogPrizeAssetDraft {
  if (resource === "prizes") {
    const prize = current as AdminCatalogPrize | undefined;
    return {
      kind: "prize",
      code: prize?.code ?? "",
      rankId: prize?.rank.id ?? "",
      presentationAssetId: prize?.presentation_asset?.id ?? null,
      name: prize?.name ?? "",
      description: prize?.description ?? null,
      displayPrice: prize?.display_price ?? 0,
      exchangePoints: prize?.exchange_points ?? 0,
      isVisible: prize?.is_visible ?? true,
    };
  }
  const asset = current as AdminCatalogPresentationAsset | undefined;
  return {
    kind: "asset",
    storageIdentifier: "",
    publicPath: "",
    checksumSha256: "",
    mediaType: asset?.media_type ?? "image",
    mimeType: asset?.mime_type ?? "image/png",
    byteSize: asset?.byte_size ?? 0,
    altText: asset?.alt_text ?? null,
    isPublic: asset?.is_public ?? false,
  };
}

function validDraft(draft: CatalogPrizeAssetDraft, mode: "create" | "edit"): boolean {
  if (draft.kind === "prize") {
    return Boolean(
      draft.name.trim() && draft.rankId &&
      (mode === "edit" || /^[a-z][a-z0-9_-]{0,63}$/u.test(draft.code)) &&
      Number.isSafeInteger(draft.displayPrice) && draft.displayPrice >= 0 &&
      Number.isSafeInteger(draft.exchangePoints) && draft.exchangePoints >= 0 &&
      !/[<>]/u.test(`${draft.name}${draft.description ?? ""}`),
    );
  }
  return Boolean(
    (mode === "edit" || (
      draft.storageIdentifier && draft.publicPath.startsWith("/") &&
      /^[0-9a-f]{64}$/u.test(draft.checksumSha256) &&
      draft.mimeType.startsWith(`${draft.mediaType}/`) &&
      Number.isSafeInteger(draft.byteSize) && draft.byteSize >= 0
    )) && !/[<>]/u.test(draft.altText ?? ""),
  );
}

function trimDraft(draft: CatalogPrizeAssetDraft): CatalogPrizeAssetDraft {
  return draft.kind === "prize"
    ? { ...draft, name: draft.name.trim(), description: draft.description?.trim() || null }
    : { ...draft, altText: draft.altText?.trim() || null };
}
